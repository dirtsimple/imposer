<?php

namespace dirtsimple\imposer;
use WP_CLI;

function get(&$var, $default=false) {
	return isset($var) ? $var : $default;
}

function aget(&$var, $key, $default=false) {
	return (!empty($var) && array_key_exists($key, $var)) ? $var[$key] : $default;
}

function array_patch_recursive($array, $object) {
	if ( ! is_array($object) ) {
		if ( ! is_object($object) ) return $object;
		if ( is_object($array) ) {
			foreach ($object as $k=>$v) $array->$k = array_patch_recursive( get($array->$k, null), $v );
			return $array;
		} elseif ( is_array($array) ) {
			foreach ($object as $k=>$v) $array[$k] = array_patch_recursive( aget($array, $k, null), $v );
			return $array;
		}
	}
	return json_decode(json_encode($object), true);
}

class Imposer {

	/***** Public API *****/

	static function run($json_stream) {
		$spec = json_decode( file_get_contents($json_stream) );
		static::instance()->run_with( file_get_contents('php://stdin'), $spec );
	}

	function run_with($php, $spec) {
		do_action("imposer_tasks");
		eval( "?>$php" );
		foreach ( $spec as $key => $val ) {
			$spec->{$key} = apply_filters( "imposer_spec_$key", $val, $spec);
		}
		$spec = apply_filters( 'imposer_spec', $spec );
		# XXX validate that readers exist for all keys?
		return $this->scheduler->run($spec);
	}

	/***** Internals *****/

	protected static $instance;

	static function instance() {
		return static::$instance ?: static::$instance = new Imposer();
	}

	static function __callStatic($name, $args) {
		# Delegate unknown static methods to instance
		return call_user_func_array(array(static::instance(), $name), $args);
	}

	function __call($name, $args) {
		# Delegate unknown instance methods to scheduler
		return call_user_func_array(array($this->scheduler, $name), $args);
	}

	protected $scheduler;

	function __construct() {
		$cls = static::class;
		$this->scheduler = new Scheduler(Task::class, Resource::class);

		$this -> task('Theme Selection')
			-> reads('theme')
			-> steps("$cls::impose_theme");

		$this -> task('Plugin Selection')
			-> reads('plugins')
			-> steps("$cls::impose_plugins");

		$this -> task('Wordpress Options')
			-> reads('options')
			-> steps("$cls::impose_options");

		$this -> task('Wordpress Menus')
			-> produces('@wp-menus', '@wp-menuitems')
			-> reads('menus')
			-> steps('dirtsimple\imposer\Menu::build_menus');
	}

	static function sanitize_option($option, $value) {
		global $wp_settings_errors;
		$ret = sanitize_option($option, $value);
		foreach ( (array) $wp_settings_errors as $error ) {
			WP_CLI::error($error['setting'] . ": " . $error['message']);
		}
		return $ret;
	}

	static function impose_options($options) {
		foreach ( $options as $opt => $new ) {
			WP_CLI::debug("Imposing option $opt", 'imposer-options');
			$old = static::sanitize_option($opt, get_option($opt));
			$new = static::sanitize_option($opt, array_patch_recursive($old, $new));
			if ($new !== $old) {
				update_option($opt, $new);
				if (($saved = get_option($opt)) !== $new) {
					WP_CLI::error("Option $opt was set to " . json_encode($new) . " but new value is " . json_encode($saved));
				} else {
					WP_CLI::success("Updated option $opt");
					Imposer::request_restart();   # Avoid theme/plugin cache issues
				}
			}
		}
	}

	static function impose_theme($key) {
		$theme = wp_get_theme($key);
		if ( $theme->get_stylesheet_directory() != get_stylesheet_directory() ) {
			$cmd = new \Theme_Command;   # from WP_CLI
			$cmd->activate(array($key));
			Imposer::request_restart();
		}
	}

	static function impose_plugins($plugins) {
		$plugins = (array) $plugins;
		if ( ! empty( $plugins ) ) {
			$fetcher = new \WP_CLI\Fetchers\Plugin;
			$plugin_files = array_column( $fetcher->get_many(array_keys($plugins)), 'file', 'name' );
			$activate = $deactivate = array();
			foreach ($plugins as $plugin => $desired) {
				$desired = ($desired !== false);
				if ( empty($plugin_files[$plugin]) ) {
					if ( $desired ) WP_CLI::error("Plugin '$plugin' not found");
					else WP_CLI::debug("Skipping deactivation of unknown plugin '$plugin'", 'imposer');
					continue;
				}
				if ( is_plugin_active($plugin_files[$plugin]) == $desired ) continue;
				if ( $desired ) {
					$activate[] = $plugin_files[$plugin];
				} else {
					$deactivate[] = $plugin_files[$plugin];
				}
			}
			if ( $deactivate ) {
				WP_CLI::debug("deactivating plugins: " . implode(' ', $deactivate), 'imposer');
				deactivate_plugins($deactivate);  # deactivate first, in case of conflicts
				Imposer::request_restart();
			}
			if ( $activate ) {
				WP_CLI::debug("activating plugins: " . implode(' ', $activate), 'imposer');
				activate_plugins($activate);
				Imposer::request_restart();
			}
		}
	}
}

class_exists('Imposer') || class_alias(Imposer::class, 'Imposer');

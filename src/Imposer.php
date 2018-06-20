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

	static function task($taskOrName=null) { return $taskOrName ? Task::task($taskOrName) : Task::current(); }
	static function resource($resOrName)   { return Task::resource($resOrName); }
	static function blockOn($res, $msg)    { static::$bootstrapped ? Task::blockOn($res, $msg) : \WP_CLI::error($msg); }
	static function request_restart()      { return Task::request_restart(); }

	static function spec_has($key)            { return Specification::has($key); }
	static function spec($key, $default=null) { return Specification::get($key, $default); }

	static function run($json_stream) {
		Imposer::bootstrap();
		do_action("imposer_tasks");
		eval( '?>' . file_get_contents('php://stdin') );
		$spec = json_decode( file_get_contents($json_stream) );
		foreach ( $spec as $key => $val ) {
			$spec->{$key} = apply_filters( "imposer_spec_$key", $val, $spec);
		}
		$spec = apply_filters( 'imposer_spec', $spec );
		# XXX validate that readers exist for all keys?
		Task::__run_all($spec);
	}

	/***** Internals *****/

	protected static $bootstrapped=false;

	static function bootstrap() {
		if ( static::$bootstrapped ) return;

		static::$bootstrapped = true;
		$cls = static::class;

		Imposer::task('Theme Selection')
			-> reads('theme')
			-> steps("$cls::impose_theme");

		Imposer::task('Plugin Selection')
			-> reads('plugins')
			-> steps("$cls::impose_plugins");

		Imposer::task('Wordpress Options')
			-> reads('options')
			-> steps("$cls::impose_options");

		Imposer::task('Wordpress Menus')
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
				if ($old === false) add_option($opt, $new); else update_option($opt, $new);
				WP_CLI::success("Updated option $opt");
				if ($opt === 'template' || $opt === 'stylesheet') Imposer::request_restart();
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
			deactivate_plugins($deactivate);  # deactivate first, in case of conflicts
			activate_plugins($activate);
			if ($activate || $deactivate) Imposer::request_restart();
		}
	}
}

class_exists('Imposer') || class_alias(Imposer::class, 'Imposer');

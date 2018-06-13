<?php

namespace dirtsimple;

use dirtsimple\imposer\Task;

class Imposer {

	/***** Public API *****/

	static function task($taskOrName=null) { return $taskOrName ? Task::task($taskOrName) : Task::current(); }
	static function resource($resOrName)   { return Task::resource($resOrName); }
	static function blockOn($res, $msg)    { static::$bootstrapped ? Task::blockOn($res, $msg) : \WP_CLI::error($msg); }
	static function request_restart()      { return Task::request_restart(); }

	static function has_state($key)            { return State::has($key); }
	static function state($key, $default=null) { return State::get($key, $default); }

	static function run($json_stream) {
		Imposer::bootstrap();
		eval( '?>' . file_get_contents('php://stdin') );
		$state = json_decode( file_get_contents($json_stream), true );
		foreach ( $state as $key => $val ) {
			$state[$key] = apply_filters( "imposer_state_$key", $val, $state);
		}
		$state = apply_filters( 'imposer_state', $state );
		do_action("imposer_tasks");
		# XXX validate that readers exist for all keys?
		Task::__run_all($state);
	}

	/***** Internals *****/

	protected static $bootstrapped=false;

	static function bootstrap() {
		if ( static::$bootstrapped ) return;

		static::$bootstrapped = true;
		$cls = static::class;

		Imposer::task('Plugin Selection')
			-> reads('plugins')
			-> steps("$cls::impose_plugins");

		Imposer::task('Wordpress Options')
			-> reads('options')
			-> steps("$cls::impose_options");
	}

	static function impose_options($options) {
		foreach ( $options as $opt => $new ) {
			$old = get_option($opt);
			if ( is_array($old) && is_array($new) ) $new = array_replace_recursive($old, $new);
			if ($new !== $old) {
				if ($old === false) add_option($opt, $new); else update_option($opt, $new);
				if ($opt === 'template' || $opt === 'stylesheet') Imposer::request_restart();
			}
		}
	}

	static function impose_plugins($plugins) {
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

class_alias(Imposer::class, 'dirtsimple\imposer\Imposer');
class_exists('Imposer') || class_alias(Imposer::class, 'Imposer');

<?php

# dirtsimple\Imposer::impose_json( $args[0] );

namespace dirtsimple;

add_action('imposer_impose_options', 'dirtsimple\Imposer::impose_options', 10, 1);
add_action('imposer_impose_plugins', 'dirtsimple\Imposer::impose_plugins', 10, 1);

class Imposer {

	/***** Public API *****/

	static function impose_json($json) {
		$cls = static::class;
		return new $cls( json_decode($json, true) );
	}

	function impose($keys) {
		foreach (func_get_args() as $key) {
			if ( is_array($key) ) {
				call_user_func_array( array($this, 'impose'), $key );
			} elseif ( ! did_action($action = "imposer_impose_$key") ) {
				$data = isset($this->state[$key]) ? $this->state[$key] : null;
				do_action($action, $data, $this->state, $this);
			}
		}
	}

	/***** Internals *****/

	protected $state, $count=0;

	function __construct($state) {
		foreach ( $state as $key => $val ) {
			$state[$key] = apply_filters( "imposer_state_$key", $val);
		}
		$this->state = apply_filters( 'imposer_state', $state );
		$this->impose('options', 'plugins', array_keys($this->state));
		do_action('imposed_state', $this->state);
	}

	static function impose_options($options) {
		foreach ( $options as $opt => $new ) {
			$old = get_option($opt);
			if ( is_array($old) && is_array($new) ) $new = array_replace_recursive($old, $new);
			if ($new !== $old) {
				if ($old === false) add_option($opt, $new); else update_option($opt, $new);
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
					continue; # XXX warn plugin of that name isn't installed
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
		}
	}
}

<?php

# dirtsimple\Imposer::impose_json( $args[0] );

namespace dirtsimple;

class Imposer {

	static function impose_json($json) {
		impose( json_decode($json, true) );
	}
		
	static function impose($state) {
		foreach ( $state as $key => $val ) {
			$state[$key] = apply_filter( "imposer_state_$key", $val, $state );
		}

		$state = apply_filter( 'imposer_state', $state );

		static::impose_options( $state['options'] );
		static::impose_plugins( $state['plugins'] );

		do_action('imposer_impose', $state);
		do_action('imposed_state', $state); 
	}
	
	static function impose_options($options) {
		foreach ( $options as $opt => $new ) {
			$old = get_option($opt);
			if ( is_array($old) && is_array($new) ) $new = array_replace_recursive($old, $new);
			if ($new !== $old) {
				if ($old === false) add_option($opt, $new); else update_option($opt, $new);
			}
		}
		do_action('imposed_options', $options, $state);
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
		do_action('imposed_plugins', $plugins, $state);
	}
}

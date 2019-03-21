<?php

namespace dirtsimple\imposer;

class UserModel extends Model {

	const meta_type = 'user';

	static function lookup($key, $keyType='', $resource=null) {
		if ( $keyType ) {
			return ( $ob = get_user_by($keyType, $key) ) ? $ob->ID : null;
		}
		if ($resource) return $resource->lookup($key, 'email') ?: $resource->lookup($key, 'login') ?: null;
	}

	static function lookup_by_email($key) { return static::lookup($key, 'email'); }
	static function lookup_by_login($key) { return static::lookup($key, 'login'); }

	function save() {
		$args = $this->select( array(
			'user_email'      => 'wp_slash',
			'user_url'        => 'wp_slash',
			'user_nicename'   => 'wp_slash',
			'display_name'    => 'wp_slash',
			'user_registered' => 'wp_slash'
		)) + $this->items();

		$id = $this->id() ?: 0;
		if ( $id ) $args['ID'] = $id; else unset( $args['ID'] );
		return $this->check_save( $id ? 'wp_update_user' : 'wp_insert_user', $args );
	}

	use HasMeta;
}

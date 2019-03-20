<?php

namespace dirtsimple\imposer;

class PostModel extends Model {

	const meta_type = 'post';

	protected function save() {
		$id = $this->id() ?: 0;
		$args = wp_slash( $this->items() );
		if ($id) $args['ID'] = $id;

		$no_rev = function($num, $post) use ($id) {
			return ( $num && (int) $post->ID === (int) $id ) ? 0 : $num;
		};
		add_filter('wp_revisions_to_keep', $no_rev, 999999, 2);
		try {
			return $this->check_save($id ? 'wp_update_post' : 'wp_insert_post', $args, true);
		} finally {
			remove_filter('wp_revisions_to_keep', $no_rev, 999999, 2);
		}
	}

	private static $guid_cache, $excludes;

	static function on_setup() {
		\add_action('save_post', array(__CLASS__, "on_save_post"), 10, 2);
	}

	static function on_save_post($post_ID, $post) {
		if ( isset(self::$guid_cache) && ! isset( self::nonguid_post_types()[$post->post_type] ) ) {
			self::$guid_cache[$post->guid] = $post_ID;
		}
	}

	static function lookup($key, $type, $resource) {
		return $resource->lookup($key, 'guid') ?: $resource->lookup($key, 'path') ?: null;
	}

	static function lookup_by_path($path) {
		return \url_to_postid($path) ?: null;
	}

	static function lookup_by_guid($guid) {
		if ( ! isset(self::$guid_cache) ) self::$guid_cache = self::fetch_guids();
		if ( array_key_exists($guid, self::$guid_cache) ) return self::$guid_cache[$guid];
	}

	static function fetch_guids() {
		global $wpdb;
		$filter = self::posttype_exclusion_filter();
		return array_column(
			$wpdb->get_results("SELECT ID, guid FROM $wpdb->posts WHERE $filter", 'ARRAY_N'),
			0, 1
		);
	}

	static function posttype_exclusion_filter() {
		global $wpdb;
		$excludes = self::nonguid_post_types();
		$filter = 'post_type NOT IN (' . implode(', ', array_fill(0, count($excludes), '%s')) . ')';
		return $wpdb->prepare($filter, array_keys($excludes));
	}

	static function nonguid_post_types() {
		if ( ! isset(self::$excludes) ) {
			$excludes = array('revision','edd_log','edd_payment','shop_order','shop_subscription');
			$excludes = \apply_filters('imposer_nonguid_post_types', $excludes);
			$excludes = array_fill_keys($excludes, 1);
			ksort($excludes);
			self::$excludes = $excludes;
		}
		return self::$excludes;
	}

	use HasMeta;

}

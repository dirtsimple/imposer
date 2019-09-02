<?php

namespace dirtsimple\imposer;

class PostModel extends Model {

	const meta_type = 'post';

	protected function save() {

		global $wpdb;

		$id = $this->id() ?: 0;
		$args = wp_slash( $this->items() );
		if ($id) $args['ID'] = $id;

		$no_rev = function($num, $post) use ($id) {
			return ( $num && (int) $post->ID === (int) $id ) ? 0 : $num;
		};
		add_filter('wp_revisions_to_keep', $no_rev, 999999, 2);
		try {
			$res = $this->check_save($id ? 'wp_update_post' : 'wp_insert_post', $args, true);
			if ( $this->has('guid') && $this->guid != get_post_field('guid', $res, 'raw') ) {
				$post = array('guid'=>$this->guid);
				$wpdb->update( $wpdb->posts, $post, array('ID'=>$res) );
				$post['post_type'] = get_post_field('post_type', $res, 'raw');
				static::on_save_post($res, (object) $post);
				clean_post_cache($res);
			}
			return $res;
		} finally {
			remove_filter('wp_revisions_to_keep', $no_rev, 999999, 2);
		}
	}

	static function on_setup() {
		\add_action('save_post', array(__CLASS__, "on_save_post"), 10, 2);
	}

	static function on_save_post($post_ID, $post) {
		if ( static::is_cached('guids') && ! isset( self::nonguid_post_types()[$post->post_type] ) ) {
			static::guids()[$post->guid] = $post_ID;
		}
	}

	static function lookup($key, $type, $resource) {
		return $resource->lookup($key, 'guid') ?: $resource->lookup($key, 'path') ?: null;
	}

	static function lookup_by_path($path) {
		return \url_to_postid($path) ?: null;
	}

	static function lookup_by_guid($guid) {
		return static::guids()->get($guid);
	}


	// Memoized methods

	static function _cached_guids() {
		global $wpdb;
		$filter = self::posttype_exclusion_filter();
		return new Bag(array_column(
			$wpdb->get_results("SELECT ID, guid FROM $wpdb->posts WHERE $filter", 'ARRAY_N'),
			0, 1
		));
	}

	static function _cached_posttype_exclusion_filter() {
		global $wpdb;
		$excludes = self::nonguid_post_types();
		$filter = 'post_type NOT IN (' . implode(', ', array_fill(0, count($excludes), '%s')) . ')';
		return $wpdb->prepare($filter, array_keys($excludes));
	}

	static function _cached_nonguid_post_types() {
		$excludes = array('revision','edd_log','edd_payment','shop_order','shop_subscription');
		$excludes = \apply_filters('imposer_nonguid_post_types', $excludes);
		$excludes = array_fill_keys($excludes, 1);
		ksort($excludes);
		return $excludes;
	}

	use HasMeta;

}

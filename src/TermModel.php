<?php

namespace dirtsimple\imposer;

class TermModel extends Model {

	protected static function on_setup() {
		add_action( 'created_term', array(__CLASS__, 'on_created_term' ), 10, 3);
	}

	static function lookup($key, $keyType, $res) {
		if ( ! $keyType ) {
			return $res->lookup($key, 'slug') ?: $res->lookup($key, 'name');
		}
		$tax = static::taxonomy_for($res);
		if ( $id = self::terms()[$tax][$keyType]->get($key) ) {
			# Was the term's name or slug changed since last cache?
			if ( ! ( $term = \get_term($id, $tax) ) || $term->{$keyType} !== $key ) {
				# Cache is stale; refresh it and retry the lookup
				self::uncache('terms');
				$id = self::terms()[$tax][$keyType]->get($key);
			}
		}
		return $id ?: null;
	}

	static function lookup_by_slug($key, $keyType, $res) {
		return static::lookup($key, 'slug', $res);
	}

	static function lookup_by_name($key, $keyType, $res) {
		return static::lookup($key, 'name', $res);
	}

	static function taxonomy_for($resource) {
		$parts = explode('-', $resource->name());
		if ( count($parts)>2 && array_shift($parts) === '@wp' && array_pop($parts) === 'term' ) {
			return implode('-', $parts);
		}
	}

	static function on_created_term($term_id, $tt_id, $taxonomy) {
		if ( static::is_cached('terms') ) self::cache_term( self::terms(), get_term($term_id, $taxonomy) );
	}

	protected static function cache_term($terms, $term) {
		$tax = $terms[$term->taxonomy];
		$tax['slug'][$term->slug] = $term->term_id;
		$tax['name'][$term->name] = $term->term_id;
	}

	protected static function _cached_terms() {
		# returns Pool[taxonomy] -> Pool[keyType] -> Bag[key] -> id
		$terms = new Pool(function(){ return new Pool(function(){ return new Bag; }); });
		foreach ( \get_terms() as $term ) self::cache_term($terms, $term);
		return $terms;
	}

	static function has_changed($id, $tax, $data) {
		return ( ! $old = (array) \get_term($id, $tax) ) ||  array_merge($old, $data) !== $old;
	}

}

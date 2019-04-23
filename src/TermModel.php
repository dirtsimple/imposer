<?php

namespace dirtsimple\imposer;

class TermModel extends Model {

	const meta_type = 'term';

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

	protected function save() {
		$id = $this->id();
		$tax = static::taxonomy_for($resource = $this->ref()->resource);

		if ( $this->has('alias_of') ) {
			$alias_id = yield $resource->ref($this->alias_of, 'slug');
			$alias = \get_term($alias_id, $tax);
			if ( $alias && !empty($alias->term_group) ) {
				unset($this->alias_of);
				$this->term_group = $alias->term_group;
			} else unset($this['term_group']);
		}

		$data = $this->select(
			array(
				'name'=>1, 'slug'=>1, 'description'=>1, 'parent'=>1,
				'term_group'=>1, 'alias_of'=>1
			)
		);

		$args = $this->select(array('name'=>'wp_slash', 'description'=>'wp_slash')) + $data;

		if ($id) {
			# Only update if something's changed
			if ( static::has_changed($id, $tax, $data) ) {
				$this->check_save( 'wp_update_term', $id, $tax, $args );
			}
		}
		else if ( $this->has('name') )
			$id = $this->check_save( 'wp_insert_term', $args['name'], $tax, $args )['term_id'];
		else throw new \UnexpectedValueException(
			"missing name for nonexistent term with args " . json_encode($args)
		);

		foreach ($this->get('term_meta', array()) as $key => $val)
			if ( isset($val) ) $this->set_meta($key, $val);
			else $this->delete_meta($key);

		yield $id;
	}


	static function impose_taxonomy_terms($taxes) {
		if ( is_array($taxes) || $taxes instanceof \stdClass ) {
			foreach ($taxes as $tax => $terms) static::impose_terms($terms, $tax);
		} else throw new \DomainException(
			"Taxonomy term sets must be stdClass, or array (got " . var_export($taxes, true) . ")"
		);
	}

	static function impose_terms($terms, $tax, $parent=0) {
		if ( is_string($terms) ) $terms = array($terms);
		if ( is_array($terms) || $terms instanceof stdClass ) {
			foreach ( (array) $terms as $key => $term)
				static::impose_term($term, $tax, $key, $parent);
		} else throw new \DomainException(
			"Terms must be string, stdClass, or array (got " . var_export($terms, true) . ")"
		);
	}

	static function impose_term($term, $tax, $key=null, $parent=null) {
		if ( is_string($term) ) {
			$term = new Bag( array('name'=>$term) );
		} else if ( is_array($term) || $term instanceof \stdClass ) {
			# Convert to pure nested array form
			$term = new Bag( json_decode( json_encode($term), true ) );
		} else throw new \DomainException(
			"Term must be string, stdClass, or array (got " . var_export($term, true) . ")"
		);

		if ( isset($parent) ) $term->setdefault('parent', $parent);
		if ( is_string($key) && ! is_numeric($key) ) {
			if ( $term->has('name') ) $term->setdefault('slug', $key);
			else $term->setdefault('name', $key);
		}
		if ( ! $term->has('name') && ! $term->has('slug') ) throw new \UnexpectedValueException(
			"Term must have a name or slug (" . json_encode((array)$term) . ")"
		);

		$res = Imposer::resource("@wp-$tax-term")->set_model(static::class);
		$keyType = $term->has('slug')  ? 'slug' : 'name';
		$mdl = $res->define( $term[$keyType], $keyType );
		$mdl->set($term->items());

		do_action('imposer_term', $mdl, $key);
		do_action("imposer_term_$tax", $mdl, $key);

		$parent = $mdl->get('parent');
		if ( is_string($parent) && ! is_numeric($parent) )
			$mdl->parent = $res->ref($parent, 'slug');

		$children = $mdl->get('children');  # this will be gone upon apply, so save it
		$ret = $mdl->apply();
		if ($children) static::impose_terms($children, $tax, $ret);
		return $ret;
	}

	use HasMeta;

}

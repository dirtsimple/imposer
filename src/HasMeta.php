<?php
namespace dirtsimple\Imposer;

use WP_CLI\Entity\RecursiveDataStructureTraverser;

trait HasMeta {

	protected static function _get_meta($id, $key) {
		$cb = "get_" . static::meta_type . "_meta";
		return $cb($id, $key, true);
	}

	protected static function _set_meta($id, $key, $val) {
		$cb = "update_" . static::meta_type . "_meta";
		return $cb($id, $key, $val);
	}

	# Update or patch a meta value (patch if key is an array)
	function set_meta($meta_key, $meta_val) {
		if ( ! static::meta_type ) {
			throw new \BadMethodCallException(static::class . " does not support metadata");
		}
		$meta_key = (array) $meta_key;
		if ( ! $meta_key )
			throw new \UnexpectedValueException("meta_key must not be empty");
		if ( array_filter(array_filter($meta_key), 'is_string') !== $meta_key )
			throw new \DomainException("meta_key items must be non-empty strings");

		$this->also(function() use ($meta_key, $meta_val) {
			$id = yield $this->ref();
			$meta_val = yield $meta_val;
			$key = array_shift($meta_key);
			if ( $meta_key ) {
				# Patch -- retrieve old meta and expand
				$old = static::_get_meta($id, $key);  # XXX unserialize and count up?
				if ( $old === false ) $old = array();
				$traverser = new RecursiveDataStructureTraverser($old);
				while ( $meta_key ) {
					if ( ! $traverser->exists($meta_key[0]) )
						$traverser->insert($meta_key[0], array());
					$traverser = $traverser->traverse_to(array($meta_key[0]));
					array_shift($meta_key);
				}
				$traverser->set_value($meta_val);
				$meta_val = $old;  # XXX reserialize and count down?
			}
			static::_set_meta($id, $key, $meta_val);
		});

		return $this;
	}

}

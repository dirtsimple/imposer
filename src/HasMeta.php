<?php
namespace dirtsimple\Imposer;

use WP_CLI\Entity\RecursiveDataStructureTraverser;

trait HasMeta {

	/* hooks for metadata access: note that all arguments to these static
		methods are UNslashed, meaning that they must be slashed before
		passing to certain WP APIs.  The default implementations just delegate
		to `{get,update,delete}_{meta_type}_meta`, where `meta_type` is a
		constant defined by the class using this trait.  (E.g. `post`, `user`,
		`term`, etc.
	*/

	protected static function _get_meta($id, $key) {
		$cb = "get_" . static::meta_type . "_meta";
		return $cb($id, $key, true);
	}

	protected static function _set_meta($id, $key, $val) {
		$cb = "update_" . static::meta_type . "_meta";
		return $cb($id, wp_slash($key), wp_slash($val));
	}

	protected static function _delete_meta($id, $key, $val='') {
		$cb = "delete_" . static::meta_type . "_meta";
		return $cb($id, wp_slash($key), wp_slash($val));
	}


	/* Update or patch a meta value (patch if key is an array w/len > 1) */
	function set_meta($meta_key, $meta_val) {
		return $this->edit_meta($meta_key, function($id, $key, $path, $old) use ($meta_val) {
			$meta_val = yield $meta_val;
			if ( $path ) {
				# Patch -- XXX unserialize and count up?
				if ( $old === false ) $old = array();
				$traverser = new RecursiveDataStructureTraverser($old);
				while ( $path ) {
					if ( ! $traverser->exists($path[0]) )
						$traverser->insert($path[0], array());
					$traverser = $traverser->traverse_to(array($path[0]));
					array_shift($path);
				}
				$traverser->set_value($meta_val);
				$meta_val = $old;  # XXX reserialize and count down?
			}
			static::_set_meta($id, $key, $meta_val);
		});
	}

	/* Unset a meta value or a portion thereof (patch if key is array w/len > 1) */
	function delete_meta($meta_key) {
		return $this->edit_meta( $meta_key, function($id, $key, $path, $old) {
			if ( $path ) {
				# Patch -- XXX unserialize and count up?
				if ( $old === false ) $old = array();
				$traverser = new RecursiveDataStructureTraverser($old);
				while ( $path ) {
					# nothing to delete?
					if ( ! $traverser->exists($path[0]) ) return;
					$traverser = $traverser->traverse_to(array($path[0]));
					array_shift($path);
				}
				$traverser->unset_on_parent();
				static::_set_meta($id, $key, $old);  # XXX reserialize and count down?
			} else {
				static::_delete_meta($id, $key);
			}
		});
	}

	private $prevMetaEdit;

	protected function edit_meta($meta_key, $fn) {
		if ( ! static::meta_type ) {
			throw new \BadMethodCallException(static::class . " does not support metadata");
		}
		$path = (array) $meta_key;
		if ( ! $path )
			throw new \UnexpectedValueException("meta_key must not be empty");
		if ( array_filter(array_filter($path), 'is_string') !== $path )
			throw new \DomainException("meta_key items must be non-empty strings");

		$ed = $this->prevMetaEdit;
		return $this->prevMetaEdit = $this->also(function() use ($path, $fn, $ed) {
			yield $ed;  # wait for last edit to finish
			$id = yield $this->ref();
			$key = array_shift($path);
			yield $fn($id, $key, $path, $path ? static::_get_meta($id, $key) : null);
		});
	}

}

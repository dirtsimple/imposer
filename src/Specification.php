<?php

namespace dirtsimple\imposer;

use WP_CLI\Entity\RecursiveDataStructureTraverser;
use WP_CLI\Entity\NonExistentKeyException;


class Specification {
	protected static $traverser;

	static function load($data) {
		return static::$traverser = new RecursiveDataStructureTraverser($data);
	}

	static function get($key, $default=null) {
		try { return static::_get($key); }
		catch (NonExistentKeyException $e) { return $default; }
	}

	static function has($key) {
		try { static::_get($key); return true; }
		catch (NonExistentKeyException $e) { return false; }
	}

	protected static function _get($key) {
		return ( static::$traverser ?: static::load(array()) )->get($key);
	}

}
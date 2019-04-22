<?php

namespace dirtsimple\Imposer;

class Bag extends \ArrayObject {

	function __construct($input = array()) {
		parent::__construct($input, \ArrayObject::ARRAY_AS_PROPS);
	}

	/* shorthand for array_key_exists */
	function has($name) {
		return $this->offsetExists($name);
	}

	/* shorthand for getArrayCopy() */
	function items() {
		return $this->getArrayCopy();
	}

	/* Get a key or default */
	function get($key, $default=null) {
		return $this->offsetExists($key) ? $this[$key] : $default;
	}

	/* Get a key or SET default (like Python .setdefault()) */
	function setdefault($key, $default=null) {
		return $this->offsetExists($key) ? $this[$key] : $this[$key] = $default;
	}

	/* Allow unset of non-existing offset */
	function offsetUnset($name) {
		return $this->offsetExists($name) ? parent::offsetUnset($name) : null;
	}

	/* Update multiple argument values using an array (like Python .update())*/
	function set($data) {
		# Use a loop to retain overall key order
		foreach ($data as $k=>$v) $this[$k] = $v;
		return $this;
	}

	/* Apply function(s) to contents, return matching fields */
	function select($funcs, ...$args) {
		if ( is_string($funcs) ) $funcs = array($funcs=>array_shift($args));
		$res = array();
		foreach ($funcs as $k => $cb) {
			if ( ! $this->offsetExists($k) ) continue;
			$v = $this[$k];
			if ( is_array($cb) && ( array_keys($cb) !== array(0, 1) || ! is_callable($cb) ) ) {
				$v = new Bag( (array) $v );
				$res[$k] = $v->select($cb, ...$args);
			} else {
				$res[$k] = is_callable($cb) ? $cb($v, ...$args) : $v;
			}
		}
		return $res;
	}

}

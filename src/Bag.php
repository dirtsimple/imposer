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

	/* Get a key or default */
	function get($key, $default=null) {
		return $this->offsetExists($key) ? $this[$key] : $default;
	}

	/* Get a key or SET default (like Python .setdefault()) */
	function setdefault($key, $default=null) {
		return $this->offsetExists($key) ? $this[$key] : $this[$key] = $default;
	}

	/* Update multiple argument values using an array (like Python .update())*/
	function set($data) {
		# Use a loop to retain overall key order
		foreach ($data as $k=>$v) $this[$k] = $v;
		return $this;
	}

}

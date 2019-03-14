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

	/* Update multiple argument values using an array (like Python .update())*/
	function set($data) {
		# Use a loop to retain overall key order
		foreach ($data as $k=>$v) $this[$k] = $v;
		return $this;
	}

	/* Apply function(s) to contents, return matching fields */
	function select($funcs) {
		if (func_num_args()>1) $funcs = array($funcs=>func_get_arg(1));
		$res = array();
		foreach ($funcs as $k => $v) {
			if ( $this->offsetExists($k) ) $res[$k] = is_callable($v) ? $v($this[$k]) : $this[$k];
		}
		return $res;
	}

}

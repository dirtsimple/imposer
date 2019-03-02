<?php

namespace dirtsimple\imposer;

class Pool extends \ArrayObject {

	protected $factory;

	function __construct($factory='dirtsimple\imposer\Pool::child_pool') {
		$this->factory = $factory;
	}

	function has($name) {
		return $this->offsetExists($name);
	}

	function offsetGet($name) {
		if ( ! $this->offsetExists($name) ) {
			$factory = $this->factory;
			$this[$name] = $factory($name, $this);
		}
		return parent::offsetGet($name);
	}

	static function child_pool($name, $pool) {
		return new static;
	}

}
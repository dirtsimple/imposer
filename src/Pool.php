<?php

namespace dirtsimple\imposer;
use \UnexpectedValueException;

class Pool extends \ArrayObject {

	public $type, $factory;

	function __construct($type=Pool::class, $factory=null) {
		$this->type = $type;
		$this->factory = $factory ?: array($this, 'newItem');
	}

	function contents() {
		return $this->instances;
	}

	function has($name) {
		return $this->offsetExists($name);
	}

	function get($nameOrInstance) {
		if ($nameOrInstance instanceof $this->type) return $nameOrInstance;
		if (!is_string($nameOrInstance)) {
			throw new UnexpectedValueException("Not a string or $this->type");
		}
		return $this[$nameOrInstance];
	}

	function offsetGet($name) {
		if ( ! $this->offsetExists($name) ) {
			$factory = $this->factory;
			$this[$name] = $factory($this->type, $name, $this);
		}
		return parent::offsetGet($name);
	}

	function newItem($type, $name, $owner) {
		return new $type($name, $owner);
	}

}
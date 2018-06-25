<?php

namespace dirtsimple\imposer;
use \UnexpectedValueException;

class Pool {

	protected $type, $instances=array();

	function __construct($type=Pooled::class, $factory=null) {
		$this->type = $type;
		$this->factory = $factory ?: array($this, 'newItem');
	}

	function contents() {
		return $this->instances;
	}

	function has($name) {
		return array_key_exists($name, $this->instances);
	}

	function get($nameOrInstance) {
		if ($nameOrInstance instanceof $this->type) return $nameOrInstance;
		if (!is_string($nameOrInstance)) {
			throw new UnexpectedValueException("Not a string or $this->type");
		}
		$tmp =& $this->instances[$nameOrInstance];
		$factory = $this->factory;
		return $tmp ?: $this->instances[$nameOrInstance] = $factory($this->type, $nameOrInstance, $this);
	}

	function newItem($type, $name, $owner) {
		return new $type($name, $owner);
	}

}
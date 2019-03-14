<?php
namespace dirtsimple\imposer;

class Mapper implements \ArrayAccess, \IteratorAggregate, \Countable {

	/* I serialize the application of models, proxying a new one for each apply() */

	private $model, $lastSave=null;

	function __construct($model) { $this->model = $model; }
	function implements($cls) { return $this->model instanceof $cls; }
	function has_method($method) { return method_exists($this->model, $method); }

	function apply() {
		# Ensure that all future calls (even re-entrant ones)
		# will wait until this call has completely finished
		$lastSave = $this->lastSave;
		$this->lastSave = new WatchedPromise();

		$model = $this->model;
		$this->model = $model->next( $this->lastSave );

		$res = Promise::call( array($model, 'apply') );

		if ( $res instanceof WatchedPromise ) $res->then( array($this->lastSave, 'resolve') );
		else $this->lastSave->resolve($res);
		return $this->lastSave;
	}

	/* Delegate everything else to the underlying model */

	function __call($method, $args) {
		$ret = Promise::interpret( $this->model->$method(...$args) );
		Promise::now($ret);  # force rejections to become immediate errors
		return ($ret === $this->model) ? $this : $ret;
	}

	function __set($k, $v) { $this->model->$k = $v; }
	function __get($k) { return $this->model->$k; }
	function __isset($k)  { return isset($this->model->$k); }
	function __unset($k)  { unset($this->model->$k); }
	function offsetGet($k) { return $this->model[$k]; }
	function offsetSet($k,$v) { $this->model[$k] = $v; }
	function offsetExists($k) { return $this->model->offsetExists($k); }
	function offsetUnset($k) { $this->model->offsetUnset($k); }
	function getIterator() { return $this->model->getIterator(); }
	function count() { return $this->model->count(); }
}

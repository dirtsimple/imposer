<?php

namespace dirtsimple\imposer;

use GuzzleHttp\Promise as GP;

class WatchedPromise implements GP\PromiseInterface {

	/*  A Promise wrapper that invokes a hnadler for rejections of leaf promises
	 *  (i.e., those with no handlers and which have not been unwrapped)
	 */

	protected $promise, $handler, $checked=false;

	function __construct($promiseOrValue, callable $handler=null) {
		$this->promise = GP\promise_for($promiseOrValue);
		$handler = $handler ?: 'dirtsimple\imposer\Promise::deferred_throw';
		if ($this->handler = $handler) $this->promise->otherwise(
			function($reason) use($handler) {
				if (! $this->checked) $handler($reason);
			}
		);
	}

	/* Factory that avoids duplicating wrappers with the same handler */
	static function wrap($data, $handler=null) {
		$handler = $handler ?: 'dirtsimple\imposer\Promise::deferred_throw';
		return ( $data instanceof static  && $data->handler === $handler ) ? $data : new static($data, $handler);
	}


	function wait($unwrap=true) {
		# Synchronous inspection throws upon rejection, so consider ourselves checked
		if ($unwrap) $this->checked = true;
		return $this->promise->wait($unwrap);
	}

	function then(callable $onFulfilled=null, callable $onRejected=null) {
		$this->checked = true;
		$next = $this->promise->then($onFulfilled, $onRejected);
		return ( $this->handler ) ? new static($next, $this->handler) : $next;
	}

	# The rest of the interface is trivial delegation to this or this->promise:
	function otherwise(callable $onRejected) { return $this->then(null, $onRejected); }
	function getState() { return $this->promise->getState(); }
	function resolve($value) { $this->promise->resolve($value); }
	function reject($reason) {  $this->promise->reject($reason); }
	function cancel() { $this->promise-cancel(); }
}

<?php

/*  A Promise wrapper that invokes a hnadler for rejections of leaf promises
 *  (i.e., those with no handlers and which have not been unwrapped)
 */

namespace dirtsimple\imposer;

use GuzzleHttp\Promise;

class CheckedPromise implements Promise\PromiseInterface {

	protected $promise, $handler, $checked=false;

	function __construct($promiseOrValue, callable $handler=null) {
		$this->promise = Promise\promise_for($promiseOrValue);
		$this->handler = $handler;
		if ($handler) $this->promise->otherwise(
			function($reason) use($handler) {
				if (! $this->checked) $handler($reason);
			}
		);
	}

	/* Factory that avoids duplicating wrappers with the same handler */
	static function wrap($data, $handler='dirtsimple\imposer\CheckedPromise::deferredThrow') {
		return ( $data instanceof static && $data->handler === $handler) ? $data : new static($data, $handler);
	}

	static function deferredThrow($reason) {
		Promise\queue()->add(function() use ($reason) {
			throw Promise\exception_for($reason);
		});
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
	function resolve($value) { return $this->promise->resolve($value); }
	function reject($reason) { return $this->promise->reject($reason); }
	function cancel() { return $this->promise-cancel(); }
}

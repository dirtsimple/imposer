<?php

/*  A Promise wrapper that invokes a hnadler for rejections of leaf promises
 *  (i.e., those with no handlers and which have not been unwrapped)
 */

namespace dirtsimple\imposer;

use GuzzleHttp\Promise as GP;

class Promise implements GP\PromiseInterface {

	protected $promise, $handler, $checked=false;

	function __construct($promiseOrValue, callable $handler=null) {
		$this->promise = GP\promise_for($promiseOrValue);
		$this->handler = $handler;
		if ($handler) $this->promise->otherwise(
			function($reason) use($handler) {
				if (! $this->checked) $handler($reason);
			}
		);
	}

	/* Factory that avoids duplicating wrappers with the same handler */
	static function checked($data, $handler='dirtsimple\imposer\Promise::deferredThrow') {
		return ( $data instanceof static && $data->handler === $handler) ? $data : new static($data, $handler);
	}

	static function deferredThrow($reason) {
		GP\queue()->add(function() use ($reason) {
			throw GP\exception_for($reason);
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

<?php

namespace dirtsimple\imposer;

use GuzzleHttp\Promise as GP;
use GuzzleHttp\Promise\PromiseInterface;

class WatchedPromise implements GP\PromiseInterface {

	/*  A Promise wrapper that invokes a hnadler for rejections of leaf promises
	 *  (i.e., those with no handlers and which have not been unwrapped)
	 */

	protected $promise, $handler, $checked=false;

	function __construct($promiseOrValue=null, callable $handler=null) {
		$this->promise = func_num_args() ? GP\promise_for($promiseOrValue) : new GP\Promise();
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


	/* set promise state to the result of calling a function w/args, trapping errors and unwrapping sync results */
	function call() {
		$args = func_get_args(); $fn = array_shift($args);
		try {
			$val = call_user_func_array($fn, $args);
			if ($val instanceof \Generator) return $this->spawn($val);
			$val = Promise::interpret( $val );
			if ($val instanceof PromiseInterface) {
				if ( $val->getState() === self::REJECTED ) $this->reject( GP\inspect($val)['reason'] );
				else $val->then( array($this,'resolve'), array($this,'reject') );
			} else {
				$this->resolve($val);
				return $val;
			}
		} catch (\Exception $e) {
			$this->reject($e);
		} catch (\Throwable $t) {
			$this->reject($t);
		}
		return $this;
	}

	/* set promise state using the last yield or error of a coroutine */
	function spawn($gen) {
		$send  = array($gen, 'send');
		$throw = array($gen, 'throw');
		$run = function($fn, $in) use ($gen, $send, $throw, &$run) {
			try {
				while (true) {
					$val = Promise::interpret( $fn ? $fn($in) : $gen->current() );
					if ( ! $gen->valid() ) { $this->resolve( $fn == $throw ? null : $in); return; }
					if ( ! $val instanceof PromiseInterface ) {
						$fn = $send;  $in = $val;
					} else if ( $val->getState() === PromiseInterface::REJECTED ) {
						$fn = $throw; $in = GP\exception_for( GP\inspect($val)['reason'] );
					} else {
						$val->then(
							function($val) use($run, $send)  { $run( $send, $val ); },
							function($err) use($run, $throw) { $run( $throw, GP\exception_for($err) ); }
						);
						return;
					}
				}
			} catch (\Exception $e) {
				$this->reject($e);
			} catch (\Throwable $t) {
				$this->reject($t);
			}
		};
		if ( $gen instanceof \Generator ) {
			$run( null, null );
		} else {
			$this->resolve($gen);
		}
		if ( $this->getState() === PromiseInterface::FULFILLED )
			return $this->wait();
		return $this;
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

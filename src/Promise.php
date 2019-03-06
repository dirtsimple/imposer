<?php
namespace dirtsimple\imposer;

use dirtsimple\fn;
use GuzzleHttp\Promise as GP;
use GuzzleHttp\Promise\PromiseInterface;

class Promise {
	# Return watched promise for a value/promise or error
	static function value($val) { return WatchedPromise::wrap($val); }
	static function error($reason) { return new WatchedPromise( GP\rejection_for($reason) ); }

	# Synchronously return a value or throw an error; return default if pending
	static function now($val, $default=null) {
		if ( $val instanceof PromiseInterface ) {
			return ( $val->getState() === PromiseInterface::PENDING ) ? $default : $val->wait();
		}
		return $val;
	}

	# Interpret a yielded value from a generator or Task step
	static function interpret($data) {
		if ( \is_object($data) ) {
			if ( $data instanceof PromiseInterface )
				if ( $data->getState() === PromiseInterface::FULFILLED )
					return $data->wait();
				else return WatchedPromise::wrap($data);
			else if ( $data instanceof \Generator )
				return Promise::spawn($data);
			else if ( $data instanceof \Closure && (new \ReflectionFunction($data))->isGenerator())
				return Promise::call($data);
			else return $data;
		} else if ( \is_array($data) ) {
			$all = false;
			foreach ($data as &$v) {
				$v = Promise::interpret($v);
				if ( $v instanceof PromiseInterface ) {
					$all = true;
					if ( $v->getState() === PromiseInterface::REJECTED )
						return $v;
				}
			}
			return $all ? new WatchedPromise( GP\all($data) ) : $data;
		} else return $data;
	}

	# call a function w/args and interpret the value, trapping errors as a promise
	static function call() {
		$args = func_get_args(); $fn = array_shift($args);
		try {
			$val =  call_user_func_array($fn, $args);
			return Promise::interpret( $val );
		} catch (\Exception $e) {
			return Promise::error($e);
		} catch (\Throwable $t) {
			return Promise::error($t);
		}
	}

	static function spawn($gen) {
		$promise = new GP\Promise();
		$send  = array($gen, 'send');
		$throw = array($gen, 'throw');
		$run = function($fn, $in) use ($promise, $gen, $send, $throw, &$run) {
			try {
				while (true) {
					$val = Promise::interpret( $fn ? $fn($in) : $gen->current() );
					if ( ! $gen->valid() ) { $promise->resolve( $fn == $throw ? null : $in); return; }
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
				$promise->reject($e);
			} catch (\Throwable $t) {
				$promise->reject($t);
			}
		};
		if ( $gen instanceof \Generator ) {
			$run( null, null );
		} else {
			$promise->resolve($gen);
		}
		if ( $promise->getState() === PromiseInterface::FULFILLED )
			return $promise->wait();
		return new WatchedPromise($promise);
	}

	# Default handler for watched promises
	static function deferred_throw($reason) {
		GP\queue()->add(function() use ($reason) { throw GP\exception_for($reason); });
	}

	# Guzzle wrappers and async utils
	static function sync() {
		GP\queue()->run();
	}

	static function later($cb, ...$args) {
		if ($args) $cb = function () use ($cb, $args) { $cb(...$args); };
		GP\queue()->add($cb);
	}

}
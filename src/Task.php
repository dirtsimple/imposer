<?php
namespace dirtsimple\imposer;

use WP_CLI;
use GuzzleHttp\Promise as GP;
use dirtsimple\fn;

class __TaskBlockingException extends \Exception {}  # private!

class Task {

	// ===== Constructor -- internal use only ===== //

	protected $name, $tries=0, $scheduled=false;
	protected $dependsOn, $reads=array(), $steps, $blocker;

	function __construct($name, $scheduler) {
		$this->name = $name;
		$this->scheduler = $scheduler;
		$this->schedule();
	}


	// ===== Task Declaration API ===== //

	function produces() {
		foreach ( func_get_args() as $what ) $this->resource($what)->isProducedBy($this);
		return $this;
	}

	function reads() {
		$reads = func_get_args();
		if (!$reads) { $this->reads = null; }
		foreach ($reads as $what) $this->reads[] = $what;
		return $this;
	}

	function steps() {
		foreach ( func_get_args() as $cb ) $this->steps[] = $cb; return $this->schedule();
	}

	function resource($resource) { return $this->scheduler->resource($resource); }
	function task($task)         { return $this->scheduler->task($task); }

	// ===== Specification Management API ===== //

	function error($msg) { WP_CLI::error("$this: $msg"); }

	function blockOn($resource, $msg) {
		$this->blocker = "$resource: $msg";
		if ( $this->resource($resource)->ready() ) {
			$this->error($msg);
		} else {
			throw new __TaskBlockingException;
		}
	}

	// ===== Task Status API ===== //

	function finished() {
		return $this->tries && ( ( $this->ready() && ! $this->steps ) || ! $this->needed() );
	}

	function ready() {
		while ( $this->dependsOn ) {
			$this->blocker = $this->dependsOn[0];
			if ( ! $this->blocker->finished() ) return false;
			array_shift($this->dependsOn);
		}
		return true;
	}

	function needed() {
		if ( ! $this->reads ) return true;
		foreach ( $this->reads as $key ) if ( $this->scheduler->spec_has($key) ) return true;
		return false;
	}

	function __toString() {
		return $this->blocker ? "$this->name ($this->blocker)" : $this->name;
	}


	// ===== Scheduling Internals ===== //

	function run() {
		$this->scheduled = false; ++$this->tries; $progress = 0;
		while ( ! $this->finished() ) {
			if ( $this->ready() && $this->run_next_callback() ) {
				$progress++;
			} else {
				$this->schedule();
				return $progress;
			}
		}
		return true;
	}

	protected function schedule() {
		if ( ! $this->scheduled && ! $this->finished()) {
			$this->scheduled = true;
			$this->scheduler->enqueue($this);
		}
		return $this;
	}

	protected function run_next_callback() {
		try {
			$args = $this->reads ? array_map( array($this->scheduler, 'spec'), $this->reads ) : array();
			$this->spawn( $this->steps[0](...$args) );
			array_shift($this->steps);
			return true;
		} catch (__TaskBlockingException $e) {
			return false;
		}
	}

	protected function spawn($res) {
		if ($res instanceof \Generator) $res = GP\Coroutine(fn::val($res));
		if (\is_object($res) && \method_exists($res, 'then')) {
			Promise::checked($res);
		}
	}

}





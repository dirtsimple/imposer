<?php
namespace dirtsimple\imposer;

use dirtsimple\Imposer;
use WP_CLI;

class __TaskBlockingException extends \Exception {}  # private!


class Task {

	// ===== Static API ==== //

	protected static $state, $current, $queue=array(), $instances=array(), $restart_requested=false;

	static function task($what)     { return Task::instance($what); }
	static function resource($what) { return Resource::instance($what); }

	static function __run_all($state) {
		State::load($state);
		while ($tasks = self::$queue) {
			self::$queue = array();
			$progress = 0;
			foreach ($tasks as $task) {
				$progress += $task->run();
				if ( self::$restart_requested ) exit(75);
			}
			if ( ! $progress ) static::deadlocked($tasks);
		}
	}


	// ===== Constructor -- internal use only ===== //

	function __construct($name) { $this->name = $name; $this->schedule(); }

	protected $name, $tries=0, $scheduled=false;
	protected $dependsOn, $reads=array(), $steps, $blocker;



	// ===== Task Declaration API ===== //

	function produces() {
		foreach ( func_get_args() as $what ) $this->resource($what)->dependsOn($this);
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

	function dependsOn() {
		foreach ( func_get_args() as $what ) $this->dependsOn[] = $this->task($what);
		return $this;
	}

	// ===== State Management API ===== //

	function error($msg) { WP_CLI::error("$this: $msg"); }

	static function current() { return self::$current; }
	static function request_restart() { self::$restart_requested = true; }

	static function blockOn($resource, $msg) {
		if ( ! self::$current ) WP_CLI::error($msg);
		if ( self::resource($resource)->ready() ) {
			self::$current->error($msg);
		} else {
			self::$current->blocker = "$resource: $msg";
			throw new __TaskBlockingException;
		}
	}


	// ===== Task Status API ===== //

	function finished() {
		return $this->tries && ( ! $this->steps || ! $this->needed() );
	}

	function ready() {
		while ( $this->dependsOn ) {
			$this->blocker = $this->dependsOn[0];
			if ( ! $this->blocker->finished() ) { return false; }
			array_shift($this->dependsOn);
		}
		return true;
	}

	function needed() {
		if ( ! $this->reads ) return true;
		foreach ( $this->reads as $key ) if ( State::has($key) ) return true;
		return false;
	}

	function __toString() {
		return $this->name;
	}


	// ===== Scheduling Internals ===== //

	protected function run() {
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
			self::$queue[] = $this;
		}
		return $this;
	}

	protected function run_next_callback() {
		try {
			self::$current = $this;
			$this->steps[0](...array_map(array(State::class,'get'), $this->reads));
			array_shift($this->steps);
			self::$current = null;
			return true;
		} catch (__TaskBlockingException $e) {
			self::$current = null;
			return false;
		}
	}

	protected static function deadlocked($tasks) {
		$msg = "Remaining tasks deadlocked; cannot proceed:\n";
		foreach ($tasks as $task) $msg .= "\n\t$task\t-> $task->blocker";
		WP_CLI::error($msg);
	}

	protected static function instance($what) {
		$cls = static::class;
		if ($what instanceof $cls) return $what;
		if (!is_string($what)) WP_CLI::error("Not a string or $cls: $what");
		$res =& static::$instances[$what];
		if ( $res && ! $res instanceof $cls ) WP_CLI::error("'$what' is not a $cls");
		return $res ?: static::$instances[$what] = new $cls($what);
	}
}

Imposer::bootstrap();



<?php

namespace dirtsimple\imposer;

use dirtsimple\fn;
use GuzzleHttp\Promise;
use WP_CLI;
use WP_CLI\Entity\RecursiveDataStructureTraverser;
use WP_CLI\Entity\NonExistentKeyException;

class Scheduler {

	function task($what=null, $required=false) {
		if ( is_null($what) ) return $this->current;
		return $this->getOrCast(Task::class, $what, $this->tasks, $required);
	}

	function resource($what, $required=false) {
		return $this->getOrCast(Resource::class, $what, $this->resources, $required);
	}

	protected function getOrCast($cls, $key, $pool, $required) {
		if ( is_string($key) ) {
			if ( $required && ! $pool->has($key) )
				throw new \DomainException(
					array_slice(explode("\\", $cls), -1)[0] . " '$key' does not exist"
				);
			return $pool[$key];
		}
		if ( $key instanceof $cls ) return $key;
		throw new \UnexpectedValueException("Not a string or $cls");
	}

	function ref($resource, ...$args) {
		return $this->resource($resource, true)->lookup(...$args);
	}


	function spec_has($key) {
		try { $this->data->get($key); return true; }
		catch (NonExistentKeyException $e) { return false; }
	}

	function spec($key, $default=null) {
		try { return $this->data->get($key); }
		catch (NonExistentKeyException $e) { return $default; }
	}

	function request_restart() {
		Promise\queue()->add(function() {
			WP_CLI::debug("Restarting to apply changes", "imposer");
			WP_CLI::halt(75);
		});
	}

	function __call($name, $args) {
		# Delegate unknown methods to current task, so you can
		# e.g. `Imposer::blockOn()` to block the current task
		if ( $this->current ) {
			return call_user_func_array(array($this->current, $name), $args);
		}
		throw new \RuntimeException("Can't call $name() on a scheduler with no current task");
	}

	function run($spec=null) {
		# If we're already running: abort
		if ( $this->running ) return false;
		$this->running = true;
		try {
			Promise\queue()->run();
			$this->data->set_value($spec);
			while ( $todo = $this->todo->exchangeArray(array()) ) {
				$progress = 0;
				foreach ($todo as $task) {
					$this->current = $task;
					$progress += $task->run();
					Promise\queue()->run();
					$this->current = null;
				}
				if ( ! $progress ) {
					# We stalled, maybe due to unresolved references
					foreach ($todo as $res) {
						if ( $res instanceof Resource && $res->hasSteps() ) {
							# Try to break the deadlock by rejecting a reference
							$res->cancelPending();
							continue 2;
						}
					}
					# No pending refs, so it's a retry deadlock
					return $this->deadlocked($todo);
				}
			}
		} finally {
			$this->running = false;
			$this->current = null;
		}
		return true;
	}

	protected $current, $tasks, $resources, $data, $todo, $running=false;

	function __construct($data=null) {
		$this->tasks     = new Pool( fn::bind ( array($this, '_new'), Task::class ) );
		$this->resources = new Pool( fn::bind ( array($this, '_new'), Resource::class ) );
		$this->data      = new RecursiveDataStructureTraverser($data);
		$this->todo      = new \ArrayObject();
	}

	function enqueue($task) {
		$this->todo[] = $task;
	}

	function _new($type, $name) { return new $type($name, $this); }

	protected function deadlocked($tasks) {
		$msg = "Remaining tasks deadlocked; cannot proceed:\n";
		foreach ($tasks as $task) $msg .= "\n\t$task";
		WP_CLI::error($msg);
	}

}
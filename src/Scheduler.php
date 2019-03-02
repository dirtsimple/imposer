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
		$this->restart_requested = true;
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
		$oldQueue = Promise\queue();
		# If our queue is the global one, we're already running: abort
		if ( $oldQueue === $this->queue ) return false;

		$this->data->set_value($spec);
		$this->queue->add(array($this, 'check_progress'));
		Promise\queue($this->queue);

		try { $this->queue->run(); } finally { Promise\queue($oldQueue); }
		return true;
	}

	function check_progress() {
		if ( ! $this->tried ) return;
		if ( ! $this->progress ) return $this->deadlocked($this->tried);
		$this->progress = 0;
		$this->tried = array();
		Promise\queue()->add(array($this, 'check_progress'));
	}

	function run_task($task) {
		$this->current = $task;
		$this->progress += $task->run();
		$this->tried[] = $task;
		$this->current = null;
		if ( $this->restart_requested ) {
			WP_CLI::debug("Restarting to apply changes", "imposer");
			WP_CLI::halt(75);
		}
	}

	protected $current, $tasks, $resources, $data, $restart_requested=false;
	protected $progress=0, $tried=array(), $queue;

	function __construct($data=null) {
		$this->tasks     = new Pool( fn::bind ( array($this, '_new'), Task::class ) );
		$this->resources = new Pool( fn::bind ( array($this, '_new'), Resource::class ) );
		$this->data      = new RecursiveDataStructureTraverser($data);
		$this->queue     = new Promise\TaskQueue(false);
		$this->queue->add( array( Promise\queue(), 'run' ) );
	}

	function enqueue($task) {
		$this->queue->add( fn::bind( array($this, 'run_task'), $task) );
	}

	function _new($type, $name) { return new $type($name, $this); }

	protected function deadlocked($tasks) {
		$msg = "Remaining tasks deadlocked; cannot proceed:\n";
		foreach ($tasks as $task) $msg .= "\n\t$task";
		WP_CLI::error($msg);
	}
}
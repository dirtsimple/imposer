<?php

namespace dirtsimple\imposer;

use WP_CLI;
use WP_CLI\Entity\RecursiveDataStructureTraverser;
use WP_CLI\Entity\NonExistentKeyException;

class Scheduler {

	function task($what=null) {
		return is_null($what) ? $this->current : $this->tasks->get($what);
	}

	function resource($what) {
		return $this->resources->get($what);
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
		$this->data->set_value($spec);
		while ($tasks = $this->queue) {
			$this->queue = array();
			$progress = 0;
			foreach ($tasks as $task) {
				$this->current = $task;
				$progress += $task->run();
				$this->current = null;
				if ( $this->restart_requested ) {
					WP_CLI::debug("Restarting to apply changes", "imposer");
					WP_CLI::halt(75);
				}
			}
			if ( ! $progress ) return $this->deadlocked($tasks);
		}
		return true;
	}

	protected $current, $tasks, $resources, $data, $restart_requested=false;
	public $queue=array();

	function __construct($data=null) {
		$this->tasks     = new Pool(Task::class,     array($this, '_new') );
		$this->resources = new Pool(Resource::class, array($this, '_new') );
		$this->data      = new RecursiveDataStructureTraverser($data);
	}

	function enqueue($task) {
		$this->queue[] = $task;
	}

	function _new($type, $name, $owner) { return new $type($name, $this); }

	protected function deadlocked($tasks) {
		$msg = "Remaining tasks deadlocked; cannot proceed:\n";
		foreach ($tasks as $task) $msg .= "\n\t$task";
		WP_CLI::error($msg);
	}
}
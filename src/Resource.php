<?php
namespace dirtsimple\imposer;

use dirtsimple\fn;
use GuzzleHttp\Promise as GP;

class Resource extends Task {

	function define_using($modelClass) {
		if ( $this->modelClass = $modelClass ) {
			if ( ! is_subclass_of($modelClass, Model::class) ) throw new \DomainException(
				"$modelClass is not a Model subclass"
			);
			call_user_func("$modelClass::configure", $this);
		}
	}

	function define($key, $keyType='') {
		if ( ! $modelClass = $this->modelClass ) throw new \LogicException(
			"No class has been registered to define instances of resource type $this->name"
		);
		return new $modelClass(Promise::value($this->lookup($key, $keyType)));
	}

	function steps() {
		$this->error("Resources can't have steps");
	}

	function reads() {
		$this->error("Resources don't read specification data");
	}

	function isProducedBy() {
		foreach ( func_get_args() as $what ) $this->dependsOn[] = $this->task($what);
		return $this;
	}

	protected $cache, $lookups, $pending, $modelClass=null;

	function __construct($name, $scheduler) {
		parent::__construct($name, $scheduler);
		$this->cache = new Pool();
		$this->lookups = new Pool();
		$this->pending = new Pool(
			function() {
				return new Pool( function() { return new GP\Promise(); } );
			}
		);
	}

	# A resource's steps are actually its pending promises

	function hasSteps() {
		return $this->pending->count() > 0;
	}

	protected function run_next_step() {
		$progress = $this->updatePending();
		Promise::sync();
		return $progress;
	}

	# Lookup management

	function lookup($key, $keyType='') {
		$cache = $this->cache[$keyType];
		if ( $cache->has($key) ) return $cache[$key];
		if ( ($found = $this->runLookups($key, $keyType) ) !== null) {
			return $found;
		}
		$p = $this->pending[$keyType][$key];

		# Ensure any external resolution will also resolve internally
		$p->then(
			fn::bind( array($this, 'resolve'), $keyType, $key ),
			function($reason) use($keyType, $key, &$p) { $this->resolve($keyType, $key, $p ); }
		);

		$this->schedule();  # <-- must be *after* promise is created
		return $cache[$key] = $p = new WatchedPromise($p);
	}

	function addLookup($handler, $keyType='') {
		if ( ! $this->hasLookup($handler, $keyType) )
			$this->lookups[$keyType][] = $handler;
		return $this;
	}

	function removeLookup($handler, $keyType='') {
		if ( $this->hasLookup($handler, $keyType) )
			unset( $this->lookups[$keyType][
					array_search($handler, (array) $this->lookups[$keyType], true)
			]);
		return $this;
	}

	function hasLookup($handler, $keyType='') {
		return $this->lookups->has($keyType) &&
			in_array($handler, (array) $this->lookups[$keyType], true);
	}

	function resolve($keyType, $key, $value=null) {
		$data = (func_num_args() === 2) ? $key : array($key=>$value);
		$cache = $this->cache[$keyType];
		$pending = $this->pending[$keyType];
		foreach ($data as $k => $v) {
			$cache[$k] = $v;
			if ( $pending->has($k) ) {
				$p = $pending[$k];
				unset($pending[$k]);
				if ( ! GP\is_settled($p) ) $p->resolve($v);
			}
		}
		if ( ! $pending->count() ) unset($this->pending[$keyType]);
		return $value;
	}

	function updatePending() {
		# Return true if nothing to update
		if ( ! $this->pending->count() ) return true;
		$resolved = 0;
		foreach ( $this->pending as $keyType => $pending ) {
			foreach ( $pending as $key => $promise ) {
				$found = $this->runLookups($key, $keyType);
				if ( $found !== null ) {
					$this->resolve($keyType, $key, $found);
					$resolved++;
				}
			}
		}
		return $resolved;
	}

	function cancelPending() {
		foreach ( $this->pending as $keyType => $pending ) {
			foreach ( $pending as $key => $promise ) {
				$promise->reject("$this->name:$keyType '$key' not found");
				$this->resolve($keyType, $key, $promise);
				Promise::sync();
				return;
			}
		}
	}

	protected function runLookups($key, $keyType='') {
		foreach ( $this->lookups[$keyType] as $lookup ) {
			if ( ( $found = $lookup($key, $keyType, $this) ) !== null )
				return $this->resolve($keyType, $key, $found);
		}
	}
}

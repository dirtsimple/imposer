<?php
namespace dirtsimple\imposer;

use dirtsimple\fun;
use GuzzleHttp\Promise as GP;

class Resource extends Task {

	function set_model($model_class, $force=false) {
		if ( $model_class && ! is_subclass_of($model_class, Model::class) ) throw new \DomainException(
			"$model_class is not a Model subclass"
		);
		if ( $this->model_class !== $model_class ) {
			if ( $this->model_class ) {
				if ($force) call_user_func("$this->model_class::deconfigure", $this);
				else return $this;
			}
			$this->model_class = $model_class;
			if ( $model_class ) call_user_func("$model_class::configure", $this);
		}
		return $this;
	}

	function define($key, $keyType='') {
		if ( ! $model_class = $this->model_class ) throw new \LogicException(
			"No class has been registered to define instances of resource type $this->name"
		);
		return $this->mappers[$keyType][$key];
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

	protected $cache, $lookups, $pending, $mappers, $model_class=null;

	function __construct($name, $scheduler) {
		parent::__construct($name, $scheduler);
		$this->cache = new Pool();
		$this->lookups = new Pool();
		$this->pending = new Pool(
			function() {
				return new Pool( function() { return new GP\Promise(); } );
			}
		);
		$this->mappers = new Pool(
			function($keyType) {
				return new Pool( function($key) use ($keyType) {
					$ref = Promise::value($this->ref($key, $keyType));
					$ref->key = $key;
					$ref->keyType = $keyType;
					$ref->resource = $this;
					$cls = $this->model_class;
					return new Mapper( new $cls( $ref ) );
				});
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

	function ref($key, $keyType='') {
		if ( is_array($key) || is_object($key) )
			return array_map(
				function($key) use ($keyType) { return $this->ref($key, $keyType); },
				(array) $key
			);

		$cache = $this->cache[$keyType];
		if ( $cache->has($key) ) return $cache[$key];
		if ( ($found = $this->lookup($key, $keyType) ) !== null) {
			return $found;
		}
		$p = $this->pending[$keyType][$key];

		# Ensure any external resolution will also resolve internally
		$p->then(
			fun::bind( array($this, 'resolve'), $keyType, $key ),
			function($reason) use($keyType, $key, &$p) { $this->resolve($keyType, $key, $p ); }
		);

		$this->schedule();  # <-- must be *after* promise is created
		$p = new WatchedPromise($p);
		$p->key = $key;
		$p->keyType = $keyType;
		$p->resource = $this;
		return $cache[$key] = $p;
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
				$found = $this->lookup($key, $keyType);
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

	function lookup($key, $keyType='') {
		# XXX this can recurse infinitely if called from a lookup w/same args
		foreach ( $this->lookups[$keyType] as $lookup ) {
			if ( ( $found = $lookup($key, $keyType, $this) ) !== null )
				return $this->resolve($keyType, $key, $found);
		}
	}
}

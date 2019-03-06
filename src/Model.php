<?php

namespace dirtsimple\imposer;

use dirtsimple\fn;
use GuzzleHttp\Promise as GP;
use WP_CLI;

abstract class Model extends Bag {

	# WP Metadata type (e.g. 'post', 'user', 'term', etc.) - if empty, set_meta() is disabled
	protected const meta_type='';

	# Override in subclasses
	abstract protected function save();

	# Return the underlying database ID, or null if it doesn't exist yet
	function id() {
		return ($id = Promise::now($this->id)) ? $this->id = $id : $id;
	}

	function ref() { return $this->ref; }

	function next() { return new static($this->ref); }

	# Create or update the database object, returning a promise that resolves when
	# this and any prior apply()s, set_metas, etc. are finished.
	function apply($saver=null) {
		# Await args before save
		yield( $this->settle_args() );

		$res = yield( $saver ? $saver($this) : $this->save() );

		# XXX check that handler-based lookup resolves correctly?
		if ( $res && $this->id() === null ) $this->ref->resolve($res);

		# Await dependencies before finish
		while ($item = array_shift($this->todo)) yield $item ;

		# Return the result of the save
		yield $res;
	}

	# Blocks apply() from finishing before $do (generator, promise, array, etc.)
	# is resolved; can be called more than once to add more parallel tasks
	protected function also($do) {
		$do = ( \is_object($do) && \is_callable($do) ) ? Promise::call($do) : Promise::interpret($do);
		if ( $do instanceof GP\PromiseInterface ) $this->todo[] = $do;
		return $this;
	}

	protected function settle_args() {
		$this->exchangeArray( yield( $this->exchangeArray( array() ) ) );
	}

	# Implementation Details:

	private $todo=array(), $id, $ref;

	function __construct($ref) {
		parent::__construct();
		$this->id = $this->ref = $ref;
	}

	# Configure resource lookups
	static function configure($resource) {
		if ( method_exists(static::class, 'lookup') )
			$resource->addLookup( array(static::class, 'lookup') );
		foreach ( get_class_methods(static::class) as $method ) {
			$type = explode('lookup_by_', $method);
			if ( count($type)===2 && $type[0] === '' ) {
				$resource->addLookup( array(static::class, $method), $type[1]);
			}
		}
	}

}

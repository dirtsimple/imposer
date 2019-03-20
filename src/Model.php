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
		return ($id = Promise::now($this->_id)) ? $this->_id = $id : $id;
	}

	function ref() { return $this->_ref; }

	function next($previous) { return new static($this->_ref, $previous); }

	# Create or update the database object, returning a promise that resolves when
	# this and any prior apply()s, set_metas, etc. are finished.
	function apply() {
		# Await previous save
		yield $this->_previous;

		# Await args before save
		yield( $this->settle_args() );

		$res = yield( $this->save() );

		# XXX check that handler-based lookup resolves correctly?
		if ( $res && $this->id() === null ) $this->_ref->resolve($res);

		# Await dependencies before finish
		while ($item = array_shift($this->_todo)) yield $item ;

		# Return the result of the save
		yield $res;
	}

	# Blocks apply() from finishing before $do (generator, promise, array, etc.)
	# is resolved; can be called more than once to add more parallel tasks
	function also($do) {
		$this->_todo[] = $done = new WatchedPromise();
		$done->call(
			function () use ($do) { yield $this->_previous; yield $do; }
		);
		return $done;
	}

	protected function settle_args() {
		$this->exchangeArray( yield( $this->items() ) );
	}

	# Implementation Details:

	private $_todo=array(), $_id, $_ref, $_previous;

	function __construct($ref, $previous=null) {
		parent::__construct();
		$this->_id = $this->_ref = $ref;
		$this->_previous = $previous;
	}

	protected static function on_setup() {}
	protected static function on_teardown() {}


	private static $refcnt = array();

	# Configure resource lookups
	static function configure($resource) {
		foreach( static::lookup_methods() as $type => $cb )
			$resource->addLookup($cb, $type);
		$refcnt =& self::$refcnt[static::class];
		if ( ! $refcnt++ ) static::on_setup();
	}

	static function deconfigure($resource) {
		foreach( static::lookup_methods() as $type => $cb )
			$resource->removeLookup($cb, $type);
		$refcnt =& self::$refcnt[static::class];
		if ( ! --$refcnt ) static::on_teardown();
	}

	static function lookup_methods() {
		$methods = array();
		if ( method_exists(static::class, 'lookup') )
			$methods[''] = array(static::class, 'lookup');
		foreach ( get_class_methods(static::class) as $method ) {
			$type = explode('lookup_by_', $method);
			if ( count($type)===2 && $type[0] === '' ) {
				$methods[$type[1]] = array(static::class, $method);
			}
		}
		return $methods;
	}

	protected function check_save($cb, ...$args) {
		$res = $cb(...$args);
		if ( is_wp_error( $res ) ) WP_CLI::error($res);
		if ( ! $res ) {
			$name = is_string($cb) ? $cb : "(" . var_export($cb, true) . ")";
			$args = implode(', ', array_map('json_encode', $args));
			WP_CLI::error(
				sprintf( "Empty ID returned by %s(%s)", $name, $args )
			);
		}
		return $res;
	}

}

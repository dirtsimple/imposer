<?php
namespace dirtsimple\imposer\tests;

use dirtsimple\imposer\Pool;
use \UnexpectedValueException;

describe("Pool", function() {

	class Pooled {
		function __construct($name, $pool) {
			$this->name = $name;
			$this->pool = $pool;
		}
	}

	$this->poolClass = Pool::class;
	$this->pooledClass = Pooled::class;

	describe("factory function", function() {
		beforeEach( function() {
			$this->log = array();
			$this->pool = new $this->poolClass(
				$this->pooledClass,
				function($type, $name, $owner) {
					expect($type)->to->equal($this->pooledClass);
					expect($owner)->to->equal($this->pool);
					$this->log[] = $name;
					return "((($name)))";
				}
			);
		});

		it("is called with type, name, and pool on new item", function() {
			expect($this->log)->to->equal(array());
			expect($this->pool->get('foo'))->to->equal('(((foo)))');
			expect($this->log)->to->equal(array('foo'));
		});

		it("return value is cached for future use", function() {
			expect($this->log)->to->equal(array());
			expect($this->pool->get('bar'))->to->equal('(((bar)))');
			expect($this->log)->to->equal(array('bar'));
			expect($this->pool->get('bar'))->to->equal('(((bar)))');
			expect($this->log)->to->equal(array('bar'));
			expect($this->pool->get('foo'))->to->equal('(((foo)))');
			expect($this->log)->to->equal(array('bar', 'foo'));
		});
	});

	beforeEach( function() { $this->pool = new $this->poolClass($this->pooledClass); });
	describe("get()", function() {
		it("returns a Pooled(name,pool) when given a name", function() {
			$pooled = $this->pool->get("a name");
			expect( $pooled ) -> to -> be -> {"instanceof"}($this->pooledClass);
			expect( $pooled ) -> to -> have -> property('name', "a name");
			expect( $pooled ) -> to -> have -> property('pool', $this->pool);
		});
		it("returns the same object for the same name", function() {
			expect( $this->pool->get("a name") )
				-> to -> equal ( $this->pool->get("a name") );
		});
		it("returns a different object for different names", function() {
			expect( $this->pool->get("some name") )
				-> to -> not -> equal ( $this->pool->get("another name") );
		});
		it("returns an instance of the pool's type if given one", function() {
			$inst = new $this->pooledClass("my name", $this->pool);
			$pooled = $this->pool->get($inst);
			expect( $pooled ) -> to -> equal($inst);
		});
		it("raises UnexpectedValueException for non-string/non-instances", function() {
			$get = array($this->pool, 'get');
			expect( $get ) -> with( array() ) -> to-> throw(UnexpectedValueException::class);
		});
	});

	describe("has()", function() {
		it("returns false if a name hasn't been used yet", function() {
			expect( $this->pool->has("a name") ) -> to -> be -> false;
		});
		it("returns true if a name has been used", function() {
			$this->pool->get("a name");
			expect( $this->pool->has("a name") ) -> to -> be -> true;
		});
	});

	describe("contents()", function() {
		it("returns an array mapping names to existing items", function() {
			expect( $this->pool->contents() ) -> to -> have -> length(0);
			$thingy = $this->pool->get("thingy");
			expect( $this->pool->contents() ) -> to -> have -> length(1);
			$thingy2 = $this->pool->get("thingy2");
			expect( $this->pool->contents() ) -> to -> equal( compact('thingy', 'thingy2') );
		});
	});
});

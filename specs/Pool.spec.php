<?php
namespace dirtsimple\imposer\tests;

use dirtsimple\imposer\Pool;
use \UnexpectedValueException;

describe("Pool", function() {

	describe("factory function", function() {
		beforeEach( function() {
			$this->log = array();
			$this->pool = new Pool(
				function($name, $owner) {
					expect($owner)->to->equal($this->pool);
					$this->log[] = $name;
					return "((($name)))";
				}
			);
		});

		it("is called with name and pool on new item", function() {
			expect($this->log)->to->equal(array());
			expect($this->pool['foo'])->to->equal('(((foo)))');
			expect($this->log)->to->equal(array('foo'));
		});

		it("return value is cached for future use", function() {
			expect($this->log)->to->equal(array());
			expect($this->pool['bar'])->to->equal('(((bar)))');
			expect($this->log)->to->equal(array('bar'));
			expect($this->pool['bar'])->to->equal('(((bar)))');
			expect($this->log)->to->equal(array('bar'));
			expect($this->pool['foo'])->to->equal('(((foo)))');
			expect($this->log)->to->equal(array('bar', 'foo'));
		});
	});

	beforeEach( function() { $this->pool = new Pool(); });
	describe('["name"]', function() {
		it("without a factory, returns another Pool", function() {
			$pooled = $this->pool["a name"];
			expect( $pooled ) -> to -> be -> {"instanceof"}(Pool::class);
		});
		it("returns the same object for the same name", function() {
			expect( $this->pool["a name"] )
				-> to -> equal ( $this->pool["a name"] );
		});
		it("returns a different object for different names", function() {
			expect( $this->pool["some name"] )
				-> to -> not -> equal ( $this->pool["another name"] );
		});
	});

	describe("has()", function() {
		it("returns false if a name hasn't been used yet", function() {
			expect( $this->pool->has("a name") ) -> to -> be -> false;
		});
		it("returns true if a name has been used", function() {
			$this->pool["a name"];
			expect( $this->pool->has("a name") ) -> to -> be -> true;
		});
	});

	describe("array interface", function() {
		it("includes ArrayAccess, Countable, and IteratorAggregate", function() {
			$pool = $this->pool;
			expect( $pool ) -> to -> be -> {"instanceof"}(\ArrayAccess::class);
			expect( $pool ) -> to -> be -> {"instanceof"}(\Countable::class);
			expect( $pool ) -> to -> be -> {"instanceof"}(\IteratorAggregate::class);
		});
		it("has length, creates via []-access, and can be cast to an array", function() {
			expect( $this->pool ) -> to -> have -> length(0);
			$thingy = $this->pool["thingy"];
			expect( $this->pool ) -> to -> have -> length(1);
			$thingy2 = $this->pool["thingy2"];
			expect( (array) $this->pool ) -> to -> equal( compact('thingy', 'thingy2') );
		});
		it("allows assignment", function() {
			expect( $this->pool ) -> to -> have -> length(0);
			$this->pool["thingy"] = 42;
			expect( (array) $this->pool ) -> to -> equal( array('thingy'=>42) );
		});
	});
});

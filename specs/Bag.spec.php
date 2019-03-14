<?php
namespace dirtsimple\imposer\tests;

use dirtsimple\imposer\Bag;
use dirtsimple\fn;

describe("Bag", function() {
	it("is an ArrayObject with prop-setting", function(){
		expect( $b = new Bag() )->to->be->instanceof(\ArrayObject::class);
		expect( $b->getFlags() )->to->equal(\ArrayObject::ARRAY_AS_PROPS);
	});

	it("can be created with an initial array", function(){
		$b = new Bag(array("a", "b"=>"c", "d"));
		expect( (array) $b )->to->equal(array("a", "b"=>"c", "d"));
	});

	it("can be created without an initial array", function(){
		$b = new Bag();
		expect( (array) $b )->to->equal(array());
	});

	beforeEach(function(){
		$this->bag = new Bag( array('x'=>42) );
	});

	describe("items()", function() {
		it("casts the bag to an array", function(){
			$b = new Bag(array('a', 'b'=>'c'));
			expect($b->items())->to->equal(array('a', 'b'=>'c'));
		});
	});

	describe("has()", function() {
		it("returns true if key is present", function(){
			expect($this->bag->has('x'))->to->be->true;
		});
		it("returns false if key is not present", function(){
			expect($this->bag->has('y'))->to->be->false;
		});
	});

	describe("get()", function() {
		it("gets an existing item, if key is present", function(){
			expect($this->bag->get('x'))->to->equal(42);
		});
		it("returns the default, if key is not present", function(){
			expect($this->bag->get('y'))->to->equal(null);
			expect($this->bag->get('y', 99))->to->equal(99);
		});
	});

	describe("set()", function() {
		it("sets items from an array", function() {
			$this->bag->set(array('q'=>'r'));
			expect((array)$this->bag)->to->equal(array('x'=>42,'q'=>'r'));
		});
		it("returns the bag (for chaining)", function(){
			expect($this->bag->set(array(99)))->to->equal($this->bag);
		});
	});

	describe("setdefault()", function() {
		it("gets an existing item, if key is present", function(){
			expect($this->bag->setdefault('x'))->to->equal(42);
		});
		it("returns the default if key is not present, and adds the key", function(){
			expect($this->bag->setdefault('y', 99))->to->equal(99);
			expect($this->bag['y'])->to->equal(99);
		});
	});

	describe("select() returns an array that's", function() {
		it("empty for an empty array", function(){
			expect( $this->bag->select(array()) )->to->equal( array() );
		});
		it("empty for an array w/out overlapping keys", function(){
			expect( $this->bag->select( array('q'=>fn::expr('$_')) ) )->to->equal( array() );
		});
		it("the result of calling the given function(s) with any extra args", function(){
			expect(
				$this->bag->select( array('x'=>fn::expr('$_+1') ) )
			)->to->equal( array('x'=>43) );
			expect(
				$this->bag->select(
					array(
						'x' => function ($a1, $a2, $a3) {
							return array($a2, $a1, $a3);
						}
					), 'wazzup', 'whiz'
				)
			)->to->equal( array('x'=>array('wazzup', 42, 'whiz')) );
		});
		it("original values for non-callables", function() {
			expect(
				$this->bag->select( array('x'=>true ) )
			)->to->equal( array('x'=>42) );
		});
		it("recursively nested for associative-array callbacks", function(){
			$this->bag->q = array('foo'=>array('bar'=>'baz', 'baz'=>'bing'));
			expect(
				$this->bag->select(
					array(
						'x'=>fn::expr('$_*2'),
						'q'=>array(
							'foo'=>array(
								'baz'=>function($v, $x) { return $x; },
								'bar'=>fn::expr('"bar: $_"'))
						)
					), "arg"
				)
			)->to->equal( array('x'=>84, 'q'=>array('foo' => array('baz'=>'arg', 'bar'=> 'bar: baz'))) );
		});
		it("correct when given a string and a func/val in place of an array+args", function(){
			expect(
				$this->bag->select( 'x', fn::expr('$_*3') )
			)->to->equal( array('x'=>126) );
			expect(
				$this->bag->select(
					'x',
					function ($a1, $a2, $a3) {
						return array($a2, $a1, $a3);
					},
					'wazzup', 'whiz'
				)
			)->to->equal( array('x'=>array('wazzup', 42, 'whiz')) );
		});
	});

});

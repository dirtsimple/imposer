<?php
namespace dirtsimple\imposer\tests;

use dirtsimple\imposer\Bag;

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
});

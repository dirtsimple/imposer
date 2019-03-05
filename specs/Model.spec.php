<?php
namespace dirtsimple\imposer\tests;

use dirtsimple\imposer\Bag;
use dirtsimple\imposer\Model;
use dirtsimple\imposer\Resource;

use Brain\Monkey;
use Mockery;

describe("Model", function() {
	beforeEach( function() {
		$this->res = Mockery::spy(Resource::class);
	});

	afterEach( function() { Monkey\tearDown(); });

	it("is a Bag", function(){
		expect( new Model($this->res, 'someKey', 'someType') )->to->be->instanceof(Bag::class);
	});
});

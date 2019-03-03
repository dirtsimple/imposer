<?php
namespace dirtsimple\imposer\tests;

use dirtsimple\imposer\Bag;
use dirtsimple\imposer\Resource;
use dirtsimple\imposer\ResourceDef;

use Brain\Monkey;
use Mockery;

describe("ResourceDef", function() {
	beforeEach( function() {
		$this->res = Mockery::spy(Resource::class);
	});

	afterEach( function() { Monkey\tearDown(); });

	it("is a Bag", function(){
		expect( new ResourceDef($this->res, 'someKey', 'someType') )->to->be->instanceof(Bag::class);
	});
});

<?php
namespace dirtsimple\imposer\tests;

use dirtsimple\fn;
use dirtsimple\imposer\Promise;
use dirtsimple\imposer\WatchedPromise;

use GuzzleHttp\Promise as GP;

describe("Promise", function() {

	describe("::value(valOrPromise) returns", function() {
		it("a watched promise for a non-promise", function() {
			$v = Promise::value("foo");
			expect($v)->to->be->instanceof(WatchedPromise::class);
			expect(GP\inspect($v))->to->equal(
				array('state'=>'fulfilled', 'value'=>'foo')
			);
		});
		it("a watched rejected promise for a rejected promise", function() {
			$e = Promise::value( GP\rejection_for("foo") );
			expect($e)->to->be->instanceof(WatchedPromise::class);
			expect(GP\inspect($e))->to->equal(
				array('state'=>'rejected', 'reason'=>'foo')
			);
		});
		it("a watched pending promise for a pending promise", function() {
			$v = Promise::value( $p = new GP\Promise() );
			expect($v)->to->be->instanceof(WatchedPromise::class);
			expect(GP\is_settled($v))->to->be->false;
			$p->resolve(42);
			expect(GP\inspect($v))->to->equal(
				array('state'=>'fulfilled', 'value'=>42)
			);
		});
		it("the same promise for an already-watched promise", function() {
			$p1 = Promise::value( 42 );
			$p2 = Promise::value( $p1 );
			expect($p2)->to->equal($p1);
		});
	});
	describe("::error(reason) returns", function() {
		it("a watched, rejected promise with that reason", function() {
			$e = Promise::error("foo");
			expect($e)->to->be->instanceof(WatchedPromise::class);
			expect(GP\inspect($e))->to->equal(
				array('state'=>'rejected', 'reason'=>'foo')
			);
		});
	});
	describe("::now(valOrPromise)", function() {
		it("throws for a rejected promise", function(){
			$e = Promise::error( new \DomainException("testing") );
			expect( function() use($e) { Promise::now( $e ); } )->to->throw(
				\DomainException::class, "testing"
			);
		});
		it("returns a value for a fulfilled promise", function(){
			expect( Promise::now( Promise::value(42) ) )->to->equal(42);
		});
		it("returns the exact same value for a non-promise", function(){
			$someVal = array( 42, 'blue'=>Promise::value('shoe') );
			expect( Promise::now( $someVal ) )->to->equal($someVal);
		});
		it("returns the default for a pending promise", function(){
			$p = new GP\Promise();
			expect( Promise::now($p, 99) )->to->equal(99);
		});
	});
	describe("::interpret(yieldedData) returns", function() {
		it("a watched coroutine promise for a generator", function() {
			$this->flag = false;
			$p = new GP\Promise;
			$mygen = (function($p){ yield $p; $this->flag = true; })($p);
			$g = Promise::interpret($mygen);
			expect( $g )->to->be->instanceof(WatchedPromise::class);
			expect( Promise::now($g) )->to->be->null;
			expect($this->flag)->to->be->false;
			$p->resolve(42); Promise::sync();
			expect( Promise::now($g) )->to->equal(42);
			expect($this->flag)->to->be->true;
		});
		it("a watched coroutine promise for a generator function", function() {
			$this->flag = false;
			$p = new GP\Promise;
			$mygf = (function () use ($p){ yield $p; $this->flag = true; });
			$g = Promise::interpret($mygf);
			expect( $g )->to->be->instanceof(WatchedPromise::class);
			expect( Promise::now($g) )->to->be->null;
			expect($this->flag)->to->be->false;
			$p->resolve(42); Promise::sync();
			expect( Promise::now($g) )->to->equal(42);
			expect($this->flag)->to->be->true;
		});
		it("a value for a fulfilled promise", function(){
			expect( Promise::interpret( GP\promise_for(42) ) )->to->equal(42);
		});
		it("a watched rejected promise for a rejected promise", function() {
			$e = Promise::interpret( GP\rejection_for("foo") );
			expect($e)->to->be->instanceof(WatchedPromise::class);
			expect(GP\inspect($e))->to->equal(
				array('state'=>'rejected', 'reason'=>'foo')
			);
		});
		it("a watched pending promise for a pending promise", function() {
			$v = Promise::interpret( $p = new GP\Promise() );
			expect($v)->to->be->instanceof(WatchedPromise::class);
			expect(GP\is_settled($v))->to->be->false;
			$p->resolve(42);
			expect(GP\inspect($v))->to->equal(
				array('state'=>'fulfilled', 'value'=>42)
			);
		});
		it("a watched, pending promise for an array w/any pending promises (recursively)", function(){
			$p = new GP\Promise;
			$i = Promise::interpret( array('x'=>array('y'=>array('z'=>$p))) );
			expect($i)->to->be->instanceof(WatchedPromise::class);
			$p->resolve(55); Promise::sync();
			expect( Promise::now($i) )->to->equal( array('x'=>array('y'=>array('z'=>55))) );
		});
		it("a watched, rejected promise for an array w/any rejected promises (recursively)", function(){
			$p = GP\rejection_for("bah!");
			$i = Promise::interpret( array('x'=>array('y'=>array('z'=>$p))) );
			expect($i)->to->be->instanceof(WatchedPromise::class);
			expect( GP\inspect($i) )->to->equal( array('state'=>'rejected', 'reason'=>'bah!') );
		});
		it("a value for anything else", function(){
			$ob = new \stdClass();
			expect( Promise::interpret($ob) )->to->equal($ob);
			expect( Promise::interpret("blue") )->to->equal("blue");
			expect(
				Promise::interpret( array('x'=>array('y'=>GP\promise_for(27))) )
			)          ->to->equal( array('x'=>array('y'=>               27 )) );
		});

	});
	describe("::call(func,...args)", function() {
		it("returns a watched, rejected promise if the function throws", function(){
			$e = new \DomainException("blah");
			$f = function() use ($e){ throw $e; };
			$p = Promise::call( $f );
			expect( $p instanceof WatchedPromise )->to->be->true;
			$p->otherwise( fn::val(null) );  # don't throw this later
			expect( GP\is_rejected($p) )->to->be->true;
			expect( GP\inspect($p) )->to->equal( array('state'=>'rejected', 'reason'=>$e) );
		});
		it("returns Promise::interpret() of the function's return value", function(){
			$f = function(){ return array('blue'=>Promise::value(42)); };
			expect( Promise::call( $f ) )->to->equal( array('blue'=>42) );
		});
		it("passes additional arguments through to the function", function(){
			$f = function($a, $b) { return array($b, $a); };
			expect( Promise::call($f, 1, 2) )->to->equal( array(2,1) );
		});
	});

	describe("Primitives", function() {
		describe("::later(func, ...args)", function() {
			it("calls func(...args) during Promise::sync()", function(){
				$this->log = array();
				$f = function() { $this->log[] = func_get_args(); };
				Promise::later($f); Promise::later($f, 1, 2, 3);
				expect( $this->log )->to->equal( array() );
				Promise::sync();
				expect( $this->log )->to->equal( array( array(), array(1,2,3) ) );
			});
		});
		describe("::sync(func, ...args)", function() {
			it("updates chained promises", function() {
				$p1 = new WatchedPromise();
				$p2 = $p1->then( fn::expr('$_*2') );
				$p1->resolve(21);
				expect(Promise::now($p1))->to->equal(21);
				expect(Promise::now($p2))->to->be->null;
				Promise::sync();
				expect(Promise::now($p2))->to->equal(42);
			});
		});
		describe("::deferred_throw(reason) asynchronously throws", function() {
			it("a throwable or exception", function(){
				Promise::deferred_throw($e = new \DomainException("blah"));
				expect( array(Promise::class, 'sync') )->to->throw(\DomainException::class, "blah");
			});
			it("a rejection error for a non-throwable, non-exception", function(){
				Promise::deferred_throw("blah");
				expect( array(Promise::class, 'sync') )->to->throw(GP\RejectionException::class, "The promise was rejected with reason: blah");
			});
		});
		describe("::spawn(generator)", function() {
			it("returns the value of the last yield if no pending promises yielded", function(){
				$gen = (function(){ yield 10; yield 15; yield 42; })();
				expect(Promise::spawn($gen))->to->equal(42);
			});
			it("sends the resolved Promise::interpret() of yield back into the generator", function(){
				$g1 = (function(){ yield 10; yield 15; yield 42; })();
				$g2 = (function() use ($g1) { yield $g1; })();
				expect(Promise::spawn($g2))->to->equal(42);
			});
			it("throws the rejected Promise::interpret() of yield back into the generator", function(){
				$e = new \Exception("i am here");
				$g1 = (function($e){ yield 10; throw $e; yield 42; })($e);
				$g2 = (function() use ($g1) { yield $g1; })();
				$p = Promise::spawn($g2);
				$p->otherwise(fn::compose());
				expect(GP\inspect($p))->to->equal(array('state'=>'rejected','reason'=>$e));
			});
			it("returns a promise that rejects if the generator throws", function(){
				$e = new \DomainException("test");
				$gen = ( function() use ($e) { throw $e; yield 10; })();
				$p = Promise::spawn($gen);
				$p->otherwise( fn::val(null) );  # don't throw this later
				expect($p)->to->be->instanceof(WatchedPromise::class);
				Promise::sync();
				expect(GP\inspect($p))->to->equal(array('state'=>'rejected', 'reason'=>$e));
			});
			it("returns a promise that resolves to the last yield result", function() {
				$this->flag = false;
				$p = new GP\Promise;
				$mygen = (function($p){ yield 'x'; yield $p; $this->flag = true; })($p);
				$g = Promise::spawn($mygen);
				expect( $g )->to->be->instanceof(WatchedPromise::class);
				expect( Promise::now($g) )->to->be->null;
				expect($this->flag)->to->be->false;
				$p->resolve(42); Promise::sync();
				expect( Promise::now($g) )->to->equal(42);
				expect($this->flag)->to->be->true;
			});
			it("returns null if the last yield was a (caught) rejection", function(){
				$p = new GP\Promise;
				$gen = ( function() use ($p) { try { yield $p; } catch(\Exception $e){ } })();
				$g = Promise::spawn($gen);
				expect($g)->to->be->instanceof(WatchedPromise::class);
				$p->reject($e=new \Exception('bah'));
				Promise::sync();
				expect($g->wait())->to->be->null;
			});
		});
	});

});

describe("WatchedPromise", function() {
	it("creates a pending promise if no arguments", function(){
		$p = new WatchedPromise();
		expect( $p )->to->be->instanceof(WatchedPromise::class);
		expect( GP\is_settled($p) )->to->be->false;
	});
	beforeEach( function(){
		$this->log = array();
		$this->handler = function($v) { $this->log[] = $v; };
		$this->promise = new GP\Promise();
		$this->watched = new WatchedPromise($this->promise, $this->handler);
	});
	describe("::wrap() returns", function() {
		it("the same promise if it's watched with the same handler", function(){
			$wrapped = WatchedPromise::wrap($this->watched, $this->handler);
			expect( $wrapped )->to->equal($this->watched);
		});
		it("a new watched promise if the handler is different", function(){
			$wrapped = WatchedPromise::wrap($this->watched);
			expect( $wrapped )->to->not->equal($this->watched);
			expect( $wrapped )->to->be->instanceof(WatchedPromise::class);
		});
		it("a new watched promise for anything else", function(){
			$wrapped = WatchedPromise::wrap(42);
			expect( $wrapped )->to->be->instanceof(WatchedPromise::class);
			expect( $wrapped->wait() )->to->equal(42);
		});
	});

	describe("instances", function() {
		it("return chained WatchedPromises with the same handler from then() and otherwise()", function(){
			$p1 = $this->watched->otherwise(fn::expr('$_+3'));
			$p2 = $this->watched->then(fn::expr('$_*2'));
			expect($p1)->to->be->instanceof(WatchedPromise::class);
			expect($p2)->to->be->instanceof(WatchedPromise::class);
			$this->watched->reject(15);
			expect($p1->wait())->to->equal(18);
			# Check that p2 failed and called the same handler:
			expect( $this->log )->to->equal( array(15) );
		});
		it("asynchronously call the rejection handler when rejected", function(){
			$this->promise->reject("error");
			Promise::sync();
			expect( $this->log )->to->equal( array("error") );
		});
		describe("don't call the rejection handler", function() {
			afterEach( function(){
				Promise::sync(); expect( $this->log )->to->equal( array() );
			});
			it("if they've been chained", function() {
				$p2 = $this->watched->otherwise(fn::compose());
				$this->promise->reject("error");
			});
			it("if they're still pending", function() { }); # see afterEach above
			it("if they're resolved", function() {
				$this->promise->resolve(99);
			});
			it("if they've been waited on", function() {
				$this->promise->reject("error");
				GP\inspect($this->watched);
			});
		});
	});
});

<?php
namespace dirtsimple\imposer\tests;

use dirtsimple\fn;
use function dirtsimple\fn;
use dirtsimple\imposer\Model;
use dirtsimple\imposer\Promise;
use dirtsimple\imposer\Task;   # XXX should be mockable
use dirtsimple\imposer\Resource;
use dirtsimple\imposer\Scheduler;

use \Mockery;
use Brain\Monkey;
use GuzzleHttp\Promise as GP;


describe("Resource", function () {
	beforeEach( function() {
		$this->sched = Mockery::spy(Scheduler::class);
		$this->res = new Resource("@demo", $this->sched);
	});
	afterEach( function() { Monkey\tearDown(); });

	it("doesn't allow steps() or reads()", function() {
		global $wp_cli_logger;
		$wp_cli_logger->ob_start();
		$wp_cli_logger->stderr = '';
		expect( array($this->res, 'steps') ) -> to -> throw(\Exception::class);
		expect( array($this->res, 'reads') ) -> to -> throw(\Exception::class);
		expect( $wp_cli_logger->stderr ) -> to -> equal(
			"Error: @demo: Resources can't have steps\n" .
			"Error: @demo: Resources don't read specification data\n"
		);
		$wp_cli_logger->ob_end();
	});

	class ValidModel extends Model { function save(){} }

	describe("define()", function() {
		it("throws an error if no definer registered", function(){
			expect(function() { $this->res->define("x"); })->to->throw(
				\LogicException::class,
				"No class has been registered to define instances of resource type @demo"
			);
		});
		it("returns a new Model of the defined type", function() {
			$this->res->define_using(ValidModel::class);
			$s1 = $this->res->define("x");
			$s2 = $this->res->define("x");
			expect($s1)->to->be->instanceof(ValidModel::class);
			expect($s2)->to->be->instanceof(ValidModel::class);
			expect($s1)->to->not->equal($s2);
		});
	});

	describe("define_using()", function() {
		it("accepts only false values or Model subclasses", function(){
			$this->res->define_using(ValidModel::class);
			$this->res->define_using(false);
			expect(function() { $this->res->define_using(Task::class); })->to->throw(
				\DomainException::class,
				"dirtsimple\imposer\Task is not a Model subclass"
			);
			expect(function() { $this->res->define_using(Model::class); })->to->throw(
				\DomainException::class,
				"dirtsimple\imposer\Model is not a Model subclass"
			);
		});
		it("calls the given class's ::configure() method with the resource", function(){
			class ConfigModel extends Model {
				public static $r=42;
				static function configure($resource) { static::$r = $resource; }
				function save(){}
			}
			$this->res->define_using(ConfigModel::class);
			expect(ConfigModel::$r)->to->equal($this->res);
		});
	});

	describe("run()", function() {
		it("returns true if no pending promises", function(){
			expect($this->res->run())-> to -> be -> true;
		});
		it("flushes the promise queue", function(){
			$p1 = $this->res->lookup('x', 'a');
			$this->wasRun = false;
			Promise::later( function() { $this->wasRun = true; } );
			$this->res->run();
			expect($this->wasRun)-> to -> be -> true;
		});
		it("returns 1 if any promises were resolved", function(){
			$p1 = $this->res->lookup('x', 'a');
			$p2 = $this->res->lookup('y', 'b');
			$this->res->addLookup(fn::val(42), 'a');
			expect($this->res->run())-> to -> equal(1);
		});
		it("returns 0 and reschedules itself if no promises could be resolved", function(){
			$p1 = $this->res->lookup('x', 'a');
			$p2 = $this->res->lookup('y', 'b');
			$this->sched->shouldHaveReceived('enqueue')->with($this->res)->once();
			expect($this->res->run())-> to -> equal(0);
			$this->sched->shouldHaveReceived('enqueue')->with($this->res)->twice();
		});
		it("finishes as soon as it's ready (and has run once) if no pending promises", function() {
			$task = new Task("test", $this->sched);
			$this->sched->shouldReceive('task')->with($task, false)->andReturn($task);
			$this->res->isProducedBy($task);
			expect($this->res->finished())-> to -> be -> false;
			expect($this->res->ready())   -> to -> be -> false;

			expect($this->res->run())     -> to -> be -> false;
			expect($this->res->finished())-> to -> be -> false;
			expect($this->res->ready())   -> to -> be -> false;

			expect($task->run())          -> to -> be -> true;
			expect($this->res->ready())   -> to -> be -> true;
			expect($this->res->finished())-> to -> be -> true;

			$this->sched->shouldHaveReceived('enqueue', array($this->res))->twice();
		});
	});

	describe("ready()", function() {
		it("is true by default", function() {
			expect($this->res->ready())->to->be->true;
		});
		it("is false if any dependencies are unfinished", function() {
			$task = new Task("test", $this->sched);
			$this->sched->shouldReceive('task')->with($task, false)->andReturn($task);
			$this->res->isProducedBy($task);
			expect($this->res->ready())->to->be->false;
		});
		it("is true if all dependencies are finished", function() {
			$task1 = new Task("task", $this->sched);
			$this->sched->shouldReceive('task')->with($task1, false)->andReturn($task1);

			$task2 = new Task("task2", $this->sched);
			$this->sched->shouldReceive('task')->with($task2, false)->andReturn($task2);

			$this->res->isProducedBy($task1, $task2);
			expect($this->res->ready())->to->be->false;
			$task1->run();
			expect($this->res->ready())->to->be->false;
			$task2->run();
			expect($this->res->ready())->to->be->true;
		});
	});
	describe("lookup handlers", function() {
		it("can be added/removed/checked, distinctly by by type (default '')", function () {
			$f = fn::val(null);
			expect($this->res->hasLookup($f))->to->be->false;
			expect($this->res->hasLookup($f, 'foo'))->to->be->false;

			$this->res->addLookup($f);
			expect($this->res->hasLookup($f))->to->be->true;
			expect($this->res->hasLookup($f, 'foo'))->to->be->false;
			expect($this->res->hasLookup($f, ''))->to->be->true;

			$this->res->addLookup($f, 'foo');
			expect($this->res->hasLookup($f, 'foo'))->to->be->true;

			$this->res->removeLookup($f);
			expect($this->res->hasLookup($f))->to->be->false;
			expect($this->res->hasLookup($f, ''))->to->be->false;
			expect($this->res->hasLookup($f, 'foo'))->to->be->true;
		});
		it("are called in add order for the matching type", function(){
			$this->res->addLookup($f1 = fn::val(42));
			$this->res->addLookup($f2 = fn::val(23));
			expect( $this->res->lookup("x") )->to->equal(42);
			$this->res->removeLookup($f1);
			expect( $this->res->lookup("y") )->to->equal(23);
		});
	});
	describe("lookup()", function() {
		it("caches results", function(){
			$this->res->addLookup($f1 = fn::val(42));
			$this->res->addLookup($f2 = fn::val(23));
			expect( $this->res->lookup("x") )->to->equal(42);
			$this->res->removeLookup($f1);
			expect( $this->res->lookup("x") )->to->equal(42);
		});
		it("returns pending promises for not-found items", function(){
			$p = $this->res->lookup("x");
			expect( $p )->to->be->instanceof(GP\PromiseInterface::class);
			expect( GP\is_settled($p) )->to->be->false;
		});
		it("schedules a run if needed", function() {
			$this->sched->shouldHaveReceived('enqueue')->with($this->res)->once();
			$this->res->run();
			expect($this->res->finished())->to->be->true;
			$p = $this->res->lookup("x");
			expect($this->res->finished())->to->be->false;
			$this->sched->shouldHaveReceived('enqueue')->with($this->res)->twice();
			$p = $this->res->lookup("y");
			$this->sched->shouldHaveReceived('enqueue')->with($this->res)->twice();
		});
		describe("updates cache and pending status for externally", function(){
			it("fulfilled promises", function() {
				$p = $this->res->lookup("x");
				$p->resolve("foo");
				Promise::sync();
				expect( $this->res->lookup("x") )->to->equal("foo");
				expect( $this->res->hasSteps() )->to->be->false;
			});
			it("rejected promises", function() {
				$p = $this->res->lookup("x");
				$p->reject("foo");
				$p->otherwise(fn()); # don't throw
				Promise::sync();
				expect( $this->res->lookup("x") )->to->equal($p);
				expect( $this->res->hasSteps() )->to->be->false;
			});
		});
	});
	describe("resolve()", function() {
		it("updates the cache for one or more items", function(){
			$this->res->resolve("", "a", "b");
			expect( $this->res->lookup("a") )->to->equal("b");
			$this->res->resolve("", "a", "c");
			expect( $this->res->lookup("a") )->to->equal("c");
			$this->res->resolve("x", array('q'=>'z', 'p'=>'r'));
			expect( $this->res->lookup("p", "x") )->to->equal("r");
			expect( $this->res->lookup("q", "x") )->to->equal("z");
		});
		it("resolves the relevant outstanding promises", function(){
			$p1 = $this->res->lookup("x", "y");
			$p2 = $this->res->lookup("x", "z");
			expect( GP\is_fulfilled($p1) )->to->be->false;
			expect( GP\is_fulfilled($p2) )->to->be->false;
			$this->res->resolve("y", "x", "q");
			expect( GP\is_fulfilled($p1) )->to->be->true;
			expect( GP\is_fulfilled($p2) )->to->be->false;
			expect( $p1->wait() )->to->equal("q");
		});
		it("doesn't affect resolved promises", function(){
			$p = $this->res->lookup("x", "y");
			$this->res->resolve("y", "x", "q");
			$this->res->resolve("y", "x", "z");
			expect( $this->res->lookup("x", "y") )->to->equal("z");
			expect( $p->wait() )->to->equal("q");
		});
	});
	describe("hasSteps()", function() {
		it("returns true iff there are pending promises", function() {
			expect($this->res->hasSteps())->to->be->false;
			$this->res->lookup('x');
			expect($this->res->hasSteps())->to->be->true;
			$this->res->resolve('', 'x', 'y');
			expect($this->res->hasSteps())->to->be->false;
		});
	});
	describe("updatePending()", function() {
		it("reruns lookup+resolve on pending promises, counting successes", function(){
			$p1 = $this->res->lookup('x', 'a');
			$p2 = $this->res->lookup('y', 'b');
			expect( GP\is_fulfilled($p1) )->to->be->false;
			expect( GP\is_fulfilled($p2) )->to->be->false;
			$this->res->addLookup(fn::val(42), 'b');
			expect( $this->res->updatePending() )->to->equal(1);
			expect( GP\is_fulfilled($p1) )->to->be->false;
			expect( GP\is_fulfilled($p2) )->to->be->true;
			expect( $p2->wait() )->to->equal(42);
			expect( $this->res->updatePending() )->to->equal(0);
		});
		it("returns true if no promises are pending", function(){
			expect( $this->res->updatePending() )->to->equal(true);
		});
	});
	describe("cancelPending()", function() {
		it("rejects a pending promise that can't be resolved", function(){
			$p1 = $this->res->lookup('x', 'a'); $p1->otherwise(fn());
			$p2 = $this->res->lookup('y', 'b'); $p2->otherwise(fn());
			expect( GP\is_rejected($p1) )->to->be->false;
			expect( GP\is_rejected($p2) )->to->be->false;

			$this->res->cancelPending();
			expect( GP\is_rejected($p1) )->to->be->true;
			expect( GP\is_rejected($p2) )->to->be->false;
			expect( GP\inspect($p1)['reason'] )->to->equal("@demo:a 'x' not found");

			$this->res->cancelPending();
			expect( GP\is_rejected($p2) )->to->be->true;
			expect( GP\inspect($p2)['reason'] )->to->equal("@demo:b 'y' not found");
		});
		it("resets hasSteps() to false", function(){
			$p1 = $this->res->lookup('x', 'a'); $p1->otherwise(fn());
			$p2 = $this->res->lookup('y', 'b'); $p2->otherwise(fn());
			expect($this->res->hasSteps())->to->be->true;
			$this->res->cancelPending();
			$this->res->cancelPending();
			expect($this->res->hasSteps())->to->be->false;
		});
	});

});

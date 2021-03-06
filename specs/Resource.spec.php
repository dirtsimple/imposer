<?php
namespace dirtsimple\imposer\tests;

use dirtsimple\fun;
use function dirtsimple\fun;
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
		it("throws an error if no model class registered", function(){
			expect(function() { $this->res->define("x"); })->to->throw(
				\LogicException::class,
				"No class has been registered to define instances of resource type @demo"
			);
		});
		it("returns a Mapper wrapping an instance of the model class", function() {
			$this->res->set_model(ValidModel::class);
			$s1 = $this->res->define("x");
			$s2 = $this->res->define("x");
			expect($s1->implements(ValidModel::class))->to->be->true;
			expect($s2->implements(ValidModel::class))->to->be->true;
			expect($s1)->to->equal($s2);
		});
		it("returns a Mapper that knows its key, keyType, and resource", function(){
			$this->res->set_model(ValidModel::class);
			$this->res->resolve('q', 'y', 'z');
			$s1 = $this->res->define("x");
			$s2 = $this->res->define("y", "q");
			expect($s1->ref()->resource)->to->equal($this->res);
			expect($s2->ref()->resource)->to->equal($this->res);
			expect($s1->ref()->key)->to->equal("x");
			expect($s2->ref()->key)->to->equal("y");
			expect($s1->ref()->keyType)->to->equal("");
			expect($s2->ref()->keyType)->to->equal("q");
		});
	});

	describe("set_model()", function() {
		it("accepts only false values or Model subclasses", function(){
			$this->res->set_model(ValidModel::class);
			$this->res->set_model(false);
			expect(function() { $this->res->set_model(Task::class); })->to->throw(
				\DomainException::class,
				"dirtsimple\imposer\Task is not a Model subclass"
			);
			expect(function() { $this->res->set_model(Model::class); })->to->throw(
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
			ConfigModel::$r = 42;
			expect( $this->res->set_model(ConfigModel::class) )->to->equal($this->res);
			expect(ConfigModel::$r)->to->equal($this->res);
		});
		it("calls the previously-registered class's ::deconfigure() method with the resource", function(){
			class DeConfigModel extends Model {
				public static $r=42;
				static function deconfigure($resource=null) { static::$r = $resource; }
				function save(){}
			}
			DeconfigModel::$r = 42;
			expect( $this->res->set_model(DeConfigModel::class) )->to->equal($this->res);
			$this->res->set_model(null, true);
			expect(DeConfigModel::$r)->to->equal($this->res);
		});
		it("doesn't replace an existing model class if the force flag isn't set", function(){
			ConfigModel::$r = 42;
			DeconfigModel::$r = 42;
			expect( $this->res->set_model(DeConfigModel::class) )->to->equal($this->res);
			expect( $this->res->set_model(ConfigModel::class) )->to->equal($this->res);
			expect(ConfigModel::$r  )->to->equal(42);
			expect(DeConfigModel::$r)->to->equal(42);
		});
	});

	describe("run()", function() {
		it("returns true if no pending promises", function(){
			expect($this->res->run())-> to -> be -> true;
		});
		it("flushes the promise queue", function(){
			$p1 = $this->res->ref('x', 'a');
			$this->wasRun = false;
			Promise::later( function() { $this->wasRun = true; } );
			$this->res->run();
			expect($this->wasRun)-> to -> be -> true;
		});
		it("returns 1 if any promises were resolved", function(){
			$p1 = $this->res->ref('x', 'a');
			$p2 = $this->res->ref('y', 'b');
			$this->res->addLookup(fun::val(42), 'a');
			expect($this->res->run())-> to -> equal(1);
		});
		it("returns 0 and reschedules itself if no promises could be resolved", function(){
			$p1 = $this->res->ref('x', 'a');
			$p2 = $this->res->ref('y', 'b');
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
			$f = fun::val(null);
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
			$this->res->addLookup($f1 = fun::val(42));
			$this->res->addLookup($f2 = fun::val(23));
			expect( $this->res->ref("x") )->to->equal(42);
			$this->res->removeLookup($f1);
			expect( $this->res->ref("y") )->to->equal(23);
		});
	});
	describe("lookup()", function() {
		it("returns the uncached result of chaining lookups", function(){
			$this->res->addLookup($f1 = fun::val(42), 'q');
			$this->res->addLookup($f2 = fun::val(23), 'q');
			expect( $this->res->lookup("x", "q") )->to->equal(42);
			$this->res->removeLookup($f1, "q");
			expect( $this->res->lookup("x", "q") )->to->equal(23);
		});
	});
	describe("ref()", function() {
		it("caches results", function(){
			$this->res->addLookup($f1 = fun::val(42));
			$this->res->addLookup($f2 = fun::val(23));
			expect( $this->res->ref("x") )->to->equal(42);
			$this->res->removeLookup($f1);
			expect( $this->res->ref("x") )->to->equal(42);
		});
		it("returns pending promises for not-found items", function(){
			$p = $this->res->ref("x");
			expect( $p )->to->be->instanceof(GP\PromiseInterface::class);
			expect( GP\is_settled($p) )->to->be->false;
		});
		it("labels promises with the original key, keyType, and resource", function(){
			$p = $this->res->ref("x", "y");
			expect( $p->resource )->to->equal($this->res);
			expect( $p->key      )->to->equal('x');
			expect( $p->keyType  )->to->equal('y');
		});
		it("maps arrays", function(){
			list($p1, $p2) = $this->res->ref(array("x", "y"));
			expect( $p1->key )->to->equal("x");
			expect( $p2->key )->to->equal("y");
		});
		it("schedules a run if needed", function() {
			$this->sched->shouldHaveReceived('enqueue')->with($this->res)->once();
			$this->res->run();
			expect($this->res->finished())->to->be->true;
			$p = $this->res->ref("x");
			expect($this->res->finished())->to->be->false;
			$this->sched->shouldHaveReceived('enqueue')->with($this->res)->twice();
			$p = $this->res->ref("y");
			$this->sched->shouldHaveReceived('enqueue')->with($this->res)->twice();
		});
		describe("updates cache and pending status for externally", function(){
			it("fulfilled promises", function() {
				$p = $this->res->ref("x");
				$p->resolve("foo");
				Promise::sync();
				expect( $this->res->ref("x") )->to->equal("foo");
				expect( $this->res->hasSteps() )->to->be->false;
			});
			it("rejected promises", function() {
				$p = $this->res->ref("x");
				$p->reject("foo");
				$p->otherwise(fun()); # don't throw
				Promise::sync();
				expect( $this->res->ref("x") )->to->equal($p);
				expect( $this->res->hasSteps() )->to->be->false;
			});
		});
	});
	describe("resolve()", function() {
		it("updates the cache for one or more items", function(){
			$this->res->resolve("", "a", "b");
			expect( $this->res->ref("a") )->to->equal("b");
			$this->res->resolve("", "a", "c");
			expect( $this->res->ref("a") )->to->equal("c");
			$this->res->resolve("x", array('q'=>'z', 'p'=>'r'));
			expect( $this->res->ref("p", "x") )->to->equal("r");
			expect( $this->res->ref("q", "x") )->to->equal("z");
		});
		it("resolves the relevant outstanding promises", function(){
			$p1 = $this->res->ref("x", "y");
			$p2 = $this->res->ref("x", "z");
			expect( GP\is_fulfilled($p1) )->to->be->false;
			expect( GP\is_fulfilled($p2) )->to->be->false;
			$this->res->resolve("y", "x", "q");
			expect( GP\is_fulfilled($p1) )->to->be->true;
			expect( GP\is_fulfilled($p2) )->to->be->false;
			expect( $p1->wait() )->to->equal("q");
		});
		it("doesn't affect resolved promises", function(){
			$p = $this->res->ref("x", "y");
			$this->res->resolve("y", "x", "q");
			$this->res->resolve("y", "x", "z");
			expect( $this->res->ref("x", "y") )->to->equal("z");
			expect( $p->wait() )->to->equal("q");
		});
	});
	describe("hasSteps()", function() {
		it("returns true iff there are pending promises", function() {
			expect($this->res->hasSteps())->to->be->false;
			$this->res->ref('x');
			expect($this->res->hasSteps())->to->be->true;
			$this->res->resolve('', 'x', 'y');
			expect($this->res->hasSteps())->to->be->false;
		});
	});
	describe("updatePending()", function() {
		it("reruns lookup+resolve on pending promises, counting successes", function(){
			$p1 = $this->res->ref('x', 'a');
			$p2 = $this->res->ref('y', 'b');
			expect( GP\is_fulfilled($p1) )->to->be->false;
			expect( GP\is_fulfilled($p2) )->to->be->false;
			$this->res->addLookup(fun::val(42), 'b');
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
			$p1 = $this->res->ref('x', 'a'); $p1->otherwise(fun());
			$p2 = $this->res->ref('y', 'b'); $p2->otherwise(fun());
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
			$p1 = $this->res->ref('x', 'a'); $p1->otherwise(fun());
			$p2 = $this->res->ref('y', 'b'); $p2->otherwise(fun());
			expect($this->res->hasSteps())->to->be->true;
			$this->res->cancelPending();
			$this->res->cancelPending();
			expect($this->res->hasSteps())->to->be->false;
		});
	});

});

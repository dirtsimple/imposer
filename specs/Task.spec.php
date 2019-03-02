<?php
namespace dirtsimple\imposer\tests;

use dirtsimple\fn;
use dirtsimple\imposer\Task;
use dirtsimple\imposer\Resource;
use dirtsimple\imposer\Scheduler;

use \Mockery;
use Brain\Monkey;
use \WP_CLI\ExitException;
use GuzzleHttp\Promise;

class Thenable { function then() { } }

describe("Task", function () {

	beforeEach( function() {
		$this->sched = Mockery::spy(Scheduler::class);
		$this->task = new Task("demo", $this->sched);
	});
	afterEach( function() { Monkey\tearDown(); });

	it("is enqueued to its scheduler upon creation", function() {
		$this->sched->shouldHaveReceived('enqueue', array($this->task))->once();
	});
	it("delegates task() to its scheduler", function() {
		$this->sched->shouldReceive('task')->with("something")->once()->andReturn("blue");
		$res = $this->task->task("something");
		expect($res)->to->equal("blue");
	});
	it("delegates resource() to its scheduler", function() {
		$this->sched->shouldReceive('resource')->with("@dummy")->once()->andReturn(42);
		$res = $this->task->resource("@dummy");
		expect($res)->to->equal(42);
	});

	describe("__toString()", function() {
		it("is its name initially", function() {
			expect("$this->task")->to->equal("demo");
		});
		it("includes its blocking task/resource & message if blocked", function() {
			$res = Mockery::mock(Resource::class);
			$res->shouldReceive('ready')->once()->andReturn(false);
			$this->sched->shouldReceive('resource')->with("@resource")->once()->andReturn($res);
			$this->task->steps(function() { $this->task->blockOn("@resource","message"); });
			expect($this->task->run())->to->be->false;
			expect("$this->task")->to->equal("demo (@resource: message)");
		});

	});

	describe("produces()", function() {
		it("tells the resource to depend on it, and returns itself", function() {
			$res = Mockery::mock(Resource::class);
			$this->sched->shouldReceive('resource')->with("@foo")->once()->andReturn($res);
			$res->shouldReceive('isProducedBy')->with($this->task)->once()->andReturn($res);
			expect($this->task->produces("@foo"))->to->equal($this->task);
		});
		it("accepts multiple arguments", function() {
			$res1 = Mockery::mock(Resource::class);
			$this->sched->shouldReceive('resource')->with("@foo")->once()->andReturn($res1);
			$res1->shouldReceive('isProducedBy')->with($this->task)->once()->andReturn($res1);
			$res2 = Mockery::mock(Resource::class);
			$this->sched->shouldReceive('resource')->with("@bar")->once()->andReturn($res2);
			$res2->shouldReceive('isProducedBy')->with($this->task)->once()->andReturn($res2);
			expect($this->task->produces("@foo", "@bar"))->to->equal($this->task);
		});
	});

	describe("needed()", function() {
		it("is true if no reads() have been specified", function() {
			expect($this->task->needed())->to->be->true;
		});

		it("is true if even one reads() target is available", function() {
			$this->task->reads('foo', 'bar');
			$this->sched->shouldReceive('spec_has')->with('foo')->andReturn(false);
			$this->sched->shouldReceive('spec_has')->with('bar')->andReturn(true);
			expect($this->task->needed())->to->be->true;
		});

		it("is false if no reads() data are available", function() {
			$this->task->reads('foo');
			$this->sched->shouldReceive('spec_has')->with('foo')->andReturn(false);
			expect($this->task->needed())->to->be->false;
		});
	});

	describe("ready()", function() {
		it("is true by default", function() {
			expect($this->task->ready())->to->be->true;
		});
		it("is false while waiting on a generator", function() {
			$this->task->steps( function() { yield 23; } );
			$this->task->run();
			expect($this->task->ready())->to->be->false;
			Promise\queue()->run();
			expect($this->task->ready())->to->be->true;
		});
		it("is false while waiting on an unresolved promise", function() {
			$this->task->steps( fn::val($p = new Promise\Promise) );
			$this->task->run();
			expect($this->task->ready())->to->be->false;
			$p->resolve(23);
			Promise\queue()->run();
			expect($this->task->ready())->to->be->true;
		});
		it("is false while waiting on an arbitrary 'thenable'", function() {
			$p = new Promise\Promise;
			$m = Mockery::mock(Thenable::class);
			$m->shouldReceive('then')->once()->andReturnUsing(
				function(...$args) use ($p) { return $p->then(...$args); }
			);
			$this->task->steps( fn::val($m) );
			$this->task->run();
			expect($this->task->ready())->to->be->false;
			$p->resolve(23);
			Promise\queue()->run();
			expect($this->task->ready())->to->be->true;
		});
		it("is true after steps returning synchronous values", function() {
			$this->task->steps( fn::val(42) );
			$this->task->run();
			expect($this->task->ready())->to->be->true;
		});
		it("forces a throw of unhandled async errors from promises", function() {
			$this->task->steps(function () {
				yield 23;
				throw new \UnexpectedValueException(42);
			});
			$this->task->run();
			expect($this->task->finished())->to->be->false;
			expect( array(Promise\queue(), 'run') )->to->throw(\UnexpectedValueException::class);
		});
	});

	describe("finished()", function() {
		it("is false if a run hasn't been attempted", function() {
			expect($this->task->finished())->to->be->false;
		});
		it("is true if the task was attempted and has no steps", function() {
			$this->task->run();
			expect($this->task->finished())->to->be->true;
		});
		it("is false while the task isn't ready()", function() {
			$this->task->steps( function() { yield 23; } );
			$this->task->run();
			expect($this->task->finished())->to->be->false;
			Promise\queue()->run();
			expect($this->task->finished())->to->be->true;
		});
		it("is true if the task was attempted and is not needed()", function() {
			$this->task->steps( function() {
				expect($this->task->finished())->to->be->false;
				$this->task->reads('foo');
				$this->sched->shouldReceive('spec_has')->with('foo')->andReturn(false);
				expect($this->task->finished())->to->be->true;
			});
			$this->task->run();
		});
	});

	describe("run()", function() {
		it("returns true if no steps", function() {
			expect($this->task->run())->to->be->true;
			$this->sched->shouldHaveReceived('enqueue', array($this->task))->once();
		});

		it("returns true if all steps finish", function() {
			$this->task->steps(function() { });
			$this->task->steps(function() { });
			expect($this->task->run())->to->be->true;
			$this->sched->shouldHaveReceived('enqueue', array($this->task))->once();
		});

		it("returns 0 (and reschedules) if the first step blocks", function() {
			$this->sched->shouldHaveReceived('enqueue', array($this->task))->once();
			$res = Mockery::mock(Resource::class);
			$this->sched->shouldReceive('resource')->with("@foo")->andReturn($res);
			$res->shouldReceive('ready')->andReturn(false);
			$this->task->steps(function() { $this->task->blockOn('@foo','spam'); });
			expect($this->task->run())->to->equal(0);
			$this->sched->shouldHaveReceived('enqueue')->with($this->task)->twice();
			expect("$this->task")->to->equal("demo (@foo: spam)");

		});

		it("returns the count of steps processed before blocking", function() {
			$this->sched->shouldHaveReceived('enqueue', array($this->task))->once();
			$res = Mockery::mock(Resource::class);
			$this->sched->shouldReceive('resource')->with("@foo")->andReturn($res);
			$res->shouldReceive('ready')->andReturn(false);
			$this->task->steps(function() { });
			$this->task->steps(function() { });
			$this->task->steps(function() { $this->task->blockOn('@foo','bar'); });
			expect($this->task->run())->to->equal(2);
			$this->sched->shouldHaveReceived('enqueue')->with($this->task)->twice();
		});

		it("finishes immediately and returns true (without rescheduling) if not needed", function() {
			$this->sched->shouldHaveReceived('enqueue', array($this->task))->once();
			$this->task->reads('foo');
			$this->sched->shouldReceive('spec_has')->with('foo')->andReturn(false);
			expect($this->task->needed())->to->be->false;
			$this->task->steps(function() { throw new Exception("This should not run"); });
			expect($this->task->run())->to->be->true;
			$this->sched->shouldHaveReceived('enqueue', array($this->task))->once();
		});

		it("calls steps() in order (including multiple args to steps())", function() {
			$this->log = array();
			$this->task->steps(function() { $this->log[] = 1; });
			$this->task->steps(
				function() { $this->log[] = 2; },
				function() { $this->log[] = 3; },
				function() { $this->log[] = 4; }
			);
			expect($this->task->run())->to->be->true;
			expect($this->log)->to->equal(array(1,2,3,4));
		});

		it("calls steps() with all spec data from reads()", function() {
			$this->task->reads('foo', 'bar'); $this->task->reads('baz');
			$this->log = array();
			$this->task->steps($f = function() { $this->log[] = func_get_args(); });
			$this->task->steps($f, $f);

			$this->sched->shouldReceive('spec_has')->andReturn(true);
			$this->sched->shouldReceive('spec')->andReturnUsing(function($arg) { return "($arg)"; });

			expect($this->task->run())->to->be->true;
			expect($this->log)->to->equal(
				array(
					array('(foo)','(bar)','(baz)'),
					array('(foo)','(bar)','(baz)'),
					array('(foo)','(bar)','(baz)')
				)
			);
		});
		it("forces an exception for unhandled errors from promises", function() {
			$p = Promise\rejection_for(new \UnexpectedValueException(42));
			$this->task->steps(fn::val($p));
			Promise\queue()->add( array($this->task, 'run') );
			expect( array(Promise\queue(), 'run') )->to->throw(\UnexpectedValueException::class);
		});
	});

	describe("blockOn()", function() {
		it("throws an error when there are no tasks for the resource", function() {
			$res = Mockery::mock(Resource::class);
			$this->sched->shouldReceive('resource')->with("@foo")->once()->andReturn($res);
			$res->shouldReceive('ready')->once()->andReturn(true);
			$this->task->steps(function() {$this->task->blockOn("@foo", "bar"); });
			expect(array($this->task, 'run'))->to->throw(ExitException::class);
			expect("$this->task")->to->equal("demo (@foo: bar)");
		});
	});
});

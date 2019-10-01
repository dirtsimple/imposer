<?php
namespace dirtsimple\imposer\tests;

use dirtsimple\fun;
use dirtsimple\imposer\Promise;
use dirtsimple\imposer\Task;
use dirtsimple\imposer\Resource;
use dirtsimple\imposer\Scheduler;

use \Mockery;
use Brain\Monkey;
use \WP_CLI\ExitException;
use GuzzleHttp\Promise as GP;

class Thenable { function then() { } }

class Unready extends Task {
	function ready() { return $this->mockReady; }
	function setReady($ready) { $this->mockReady = $ready; }
}


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
		$this->sched->shouldReceive('task')->with("something", false)->twice()->andReturn("blue");
		$this->sched->shouldReceive('task')->with("something", true)->once()->andReturn("green");
		expect( $this->task->task("something") ) ->to->equal("blue");
		expect( $this->task->task("something", true) )->to->equal("green");
		expect( $this->task->task("something", false) )->to->equal("blue");
	});
	it("delegates resource() to its scheduler", function() {
		$this->sched->shouldReceive('resource')->with("@dummy", false)->twice()->andReturn(42);
		$this->sched->shouldReceive('resource')->with("@dummy", true)->once()->andReturn(23);
		expect( $this->task->resource("@dummy") ) ->to->equal(42);
		expect( $this->task->resource("@dummy", true) )->to->equal(23);
		expect( $this->task->resource("@dummy", false) )->to->equal(42);
	});

	describe("name()", function() {
		it("returns its name", function(){
			expect($this->task->name())->to->equal("demo");
		});
	});

	describe("__toString()", function() {
		it("is its name initially", function() {
			expect("$this->task")->to->equal("demo");
		});
		it("includes its blocking task/resource & message if blocked", function() {
			$res = Mockery::mock(Resource::class);
			$res->shouldReceive('ready')->once()->andReturn(false);
			$this->sched->shouldReceive('resource')->with("@resource", false)->once()->andReturn($res);
			$this->task->steps(function() { $this->task->blockOn("@resource","message"); });
			expect($this->task->run())->to->be->false;
			expect("$this->task")->to->equal("demo (@resource: message)");
		});

	});

	describe("produces()", function() {
		it("tells the resource to depend on it, and returns itself", function() {
			$res = Mockery::mock(Resource::class);
			$this->sched->shouldReceive('resource')->with("@foo", false)->once()->andReturn($res);
			$res->shouldReceive('isProducedBy')->with($this->task)->once()->andReturn($res);
			expect($this->task->produces("@foo"))->to->equal($this->task);
		});
		it("accepts multiple arguments", function() {
			$res1 = Mockery::mock(Resource::class);
			$this->sched->shouldReceive('resource')->with("@foo", false)->once()->andReturn($res1);
			$res1->shouldReceive('isProducedBy')->with($this->task)->once()->andReturn($res1);
			$res2 = Mockery::mock(Resource::class);
			$this->sched->shouldReceive('resource')->with("@bar", false)->once()->andReturn($res2);
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
			$this->task = new Unready("not ready", $this->sched);
			$this->task->setReady(false);
			$this->task->run();
			expect($this->task->finished())->to->be->false;
			$this->task->setReady(true);
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
			$this->sched->shouldReceive('resource')->with("@foo", false)->andReturn($res);
			$res->shouldReceive('ready')->andReturn(false);
			$this->task->steps(function() { $this->task->blockOn('@foo','spam'); });
			expect($this->task->run())->to->equal(0);
			$this->sched->shouldHaveReceived('enqueue')->with($this->task)->twice();
			expect("$this->task")->to->equal("demo (@foo: spam)");

		});

		it("returns the count of steps processed before blocking", function() {
			$this->sched->shouldHaveReceived('enqueue', array($this->task))->once();
			$res = Mockery::mock(Resource::class);
			$this->sched->shouldReceive('resource')->with("@foo", false)->andReturn($res);
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
	});
	describe("steps() returning promises", function() {
		it("asynchronously throw an exception for synchronous rejections", function() {
			$p = GP\rejection_for(new \UnexpectedValueException(42));
			$this->task->steps(fun::val($p));
			Promise::later( array($this->task, 'run') );
			expect( array(Promise::class, 'sync') )->to->throw(\UnexpectedValueException::class);
		});
		it("asynchronously throw an exception for asynchronous rejections", function() {
			$p = new GP\Promise;
			$this->task->steps( fun::val($p) );
			$this->task->run();
			Promise::sync();
			$p->reject(new \UnexpectedValueException(42));
			expect( array(Promise::class, 'sync') )->to->throw(\UnexpectedValueException::class);
		});
	});

	describe("steps() returning generators", function() {
		it("immediately spawn a coroutine wrapping the generator", function() {
			$this->done = false;
			$this->p = new GP\Promise;
			$this->task->steps( function() {
				yield null;     $this->done = 1;
				yield $this->p; $this->done = 2;
			});
			$this->task->run();
			expect($this->done)->to->equal(1);
			$this->p->resolve(23); Promise::sync();
			expect($this->done)->to->equal(2);
		});
		it("immediately throw an exception for unhandled errors", function() {
			$this->task->steps(function () {
				yield 42;
				throw new \UnexpectedValueException(42);
			});
			expect( array($this->task, 'run') )->to->throw(\UnexpectedValueException::class);
		});
		it("asynchronously throw an exception for unhandled async errors", function() {
			$this->p = new GP\Promise;
			$this->task->steps(function () {
				yield $this->p;
				throw new \UnexpectedValueException(42);
			});
			$this->task->run();
			$this->p->resolve(23);
			expect( array(Promise::class, 'sync') )->to->throw(\UnexpectedValueException::class);
		});
	});

	describe("blockOn()", function() {
		it("throws an error when there are no tasks for the resource", function() {
			$res = Mockery::mock(Resource::class);
			$this->sched->shouldReceive('resource')->with("@foo", false)->once()->andReturn($res);
			$res->shouldReceive('ready')->once()->andReturn(true);
			$this->task->steps(function() {$this->task->blockOn("@foo", "bar"); });
			expect(array($this->task, 'run'))->to->throw(ExitException::class);
			expect("$this->task")->to->equal("demo (@foo: bar)");
		});
	});
});

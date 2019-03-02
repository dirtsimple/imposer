<?php
namespace dirtsimple\imposer\tests;

use dirtsimple\imposer\Task;   # XXX should be mockable
use dirtsimple\imposer\Resource;
use dirtsimple\imposer\Scheduler;

use \Mockery;
use Brain\Monkey;



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

	describe("run()", function() {
		it("finishes as soon as it's ready (and run)", function() {
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

});

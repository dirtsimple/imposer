<?php
namespace dirtsimple\imposer\tests;

use dirtsimple\imposer\Task;
use dirtsimple\imposer\Resource;
use dirtsimple\imposer\Scheduler;

use \Mockery;
use Brain\Monkey;
use \WP_CLI\ExitException;
use \Exception;
use \RuntimeException;
use GuzzleHttp\Promise;

describe("Scheduler", function () {
	beforeEach( function() {
		$this->sched = new Scheduler();

		# use a fresh queue each time
		Promise\queue(new Promise\TaskQueue(false));
	});
	afterEach( function() { Monkey\tearDown(); });

	describe("task()", function() {
		it("returns Task objects", function() {
			expect( $this->sched->task("A task") ) -> to -> be -> instanceof(Task::class);
		});
		context("with no argument", function() {
			it("returns the running task, only if there is one", function() {
				$sched = $this->sched;
				$t1 = $sched->task("test")->steps(
					function() use ($sched, &$t1) {
						expect($sched->task())->to->equal($t1);
						return true;
					}
				);
				expect( $sched->task() ) -> to -> be -> null;
				expect( $sched->run()  ) -> to -> be -> true;
				expect( $sched->task() ) -> to -> be -> null;
			});
		});
	});
	describe("resource()", function() {
		it("returns Resource objects", function() {
			expect( $this->sched->resource("A task") ) -> to -> be -> instanceof(Resource::class);
		});
		it("uses a separate namespace from task()", function() {
			$resource = $this->sched->resource("thing 1");
			$task     = $this->sched->task(    "thing 1");
			expect( $resource ) -> to -> not -> equal($task);
		});
	});

	describe("run()", function() {
		it("returns true for an empty queue", function() {
			expect($this->sched->run())->to->be->true;
		});
		it("returns false (and is a no-op) when called recursively", function() {
			$sched = $this->sched;
			$flag = false;
			$sched->task('test')->steps(
				function() use ($sched, &$flag) {
					$sched->task('other')->steps(
						function () use (&$flag) { $flag = true; }
					);
					expect($sched->run()); #->to->be->false;
					expect($flag)->to->be->false;
				}
			);
			$sched->run();
			expect($flag)->to->be->true;
		});
		it("calls WP_CLI::halt(75) if request_restart() called", function() {
			$sched = $this->sched;
			$sched->enqueue($task = Mockery::mock(Task::class));
			$task->shouldReceive('run')->once()->andReturnUsing(
				function() use ($sched) {
					$sched->request_restart(); return true;
				}
			);
			try { $this->sched->run(); }
			catch ( ExitException $e ) {
				expect($e->getCode())->to->equal(75); return;
			}
			throw new Exception("run() didn't try to exit");
		});

		it("invokes each task's run() method", function() {
			$sched = $this->sched;
			$sched->enqueue( $t1 = Mockery::mock(Task::class) );
			$sched->enqueue( $t2 = Mockery::mock(Task::class) );
			$t1->shouldReceive('run')->with()->once()->andReturn(true);
			$t2->shouldReceive('run')->with()->once()->andReturn(true);
			expect($sched->run())->to->be->true;
		});

		it("runs freshly-added tasks after current tasks", function() {
			$sched = $this->sched;
			$t1 = Mockery::mock(Task::class);
			$t2 = Mockery::mock(Task::class);
			$t1->shouldReceive('run')->with()->once()->andReturnUsing(
				function() use ($sched, $t2) {
					$sched->enqueue( $t2 ); return true;
				}
			);
			$t2->shouldReceive('run')->with()->once()->andReturn(true);
			$sched->enqueue( $t1 );
			expect($sched->run())->to->be->true;
		});

		it("aborts w/task list on stderr if no task makes progress", function() {
			$t1 = Mockery::mock(Task::class);
			$t1->shouldReceive('run')->with()->once()->andReturn(false);
			$t1->shouldReceive('__toString')->with()->once()->andReturn("Task Alpha");
			$t2 = Mockery::mock(Task::class);
			$t2->shouldReceive('run')->with()->once()->andReturn(false);
			$t2->shouldReceive('__toString')->with()->once()->andReturn("Task Beta");
			$this->sched->enqueue($t1);
			$this->sched->enqueue($t2);

			global $wp_cli_logger;
			$wp_cli_logger->ob_start();
			$wp_cli_logger->stderr = '';
			expect( array($this->sched, "run") )->to->throw(\WP_CLI\ExitException::class);
			expect( $wp_cli_logger->stderr ) -> to -> equal(
				"Error: Remaining tasks deadlocked; cannot proceed:\n\n" .
				"\tTask Alpha\n" . "\tTask Beta\n"
			);
			$wp_cli_logger->ob_end();
		});
	});

	describe("specification data", function() {
		beforeEach(function() {
			$this->data = (object) array(
				'foo' => 'bar', 'baz' => array( 15, 21, array('blue'=>42) )
			);
			$this->sched->enqueue($this->task = Mockery::mock(Task::class));
		});

		describe("spec()", function() {
			it("returns sub-items of the data passed to run()", function() {
				$this->task->shouldReceive('run')->once()->andReturnUsing(
					function() {
						$s = $this->sched; $d = $this->data;
						expect($s->spec('foo'))
							-> to -> equal($d->foo);
						expect($s->spec(array('baz',0)))
							-> to -> equal($d->baz[0]);
						expect($s ->spec(array('baz',2)))
							-> to -> equal($d->baz[2]);
						expect($s->spec(array('baz',2,'blue')))
							-> to -> equal($d->baz[2]['blue']);
						return true;
					}
				);
				expect($this->sched->run($this->data))->to->be->true;
			});
			it("returns a default for missing items", function() {
				$this->task->shouldReceive('run')->once()->andReturnUsing(
					function() {
						$s = $this->sched; $d = $this->data;
						expect($s->spec('bar', 99))
							-> to -> equal(99);
						expect($s->spec(array('baz',15), "nope"))
							-> to -> equal("nope");
						return true;
					}
				);
				expect($this->sched->run($this->data))->to->be->true;
			});
		});

		describe("spec_has()", function() {
			it("checks the existence of sub-items in the data passed to run()", function() {
				$this->task->shouldReceive('run')->once()->andReturnUsing(
					function() {
						$s = $this->sched; $d = $this->data;
						expect($s->spec_has('foo'))
							-> to -> be -> true;
						expect($s->spec_has(array('baz',0)))
							-> to -> be -> true;
						expect($s->spec_has(array('baz',2,'blue')))
							-> to -> be -> true;
						expect($s->spec_has('bar'))
							-> to -> be -> false;
						expect($s->spec_has(array('baz',15)))
							-> to -> be -> false;
						return true;
					}
				);
				expect($this->sched->run($this->data))->to->be->true;
			});
		});
	});
	describe("delegation", function() {
		it("forwards arbitrary methods to the current task", function() {
			$this->sched->enqueue($this->task = Mockery::mock(Task::class));
			$this->task->shouldReceive('run')->once()->andReturnUsing(
				function() {
					$s = $this->sched; $d = $this->data;
					expect($s->arbitraryMethod(42, 99))
						-> to -> equal('blue');
					return true;
				}
			);
			$this->task->shouldReceive('arbitraryMethod')->once()->with(42, 99)->andReturn('blue');
			expect($this->sched->run())->to->be->true;
		});
		it("produces a RuntimeException if there's no current task", function() {
			expect( array($this->sched, 'arbitraryMethod') ) -> to -> throw(
				RuntimeException::class,
				"Can't call arbitraryMethod() on a scheduler with no current task"
			);
		});
	});
});

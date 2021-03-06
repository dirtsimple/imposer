<?php
namespace dirtsimple\imposer\tests;

use dirtsimple\fun;
use dirtsimple\imposer\Bag;
use dirtsimple\imposer\HasMeta;
use dirtsimple\imposer\Mapper;
use dirtsimple\imposer\Model;
use dirtsimple\imposer\Pool;
use dirtsimple\imposer\Promise;
use dirtsimple\imposer\Resource;
use dirtsimple\imposer\WatchedPromise;

use Brain\Monkey;
use Brain\Monkey\Functions as func;
use GuzzleHttp\Promise as GP;
use Mockery;

class MockModel extends Model {

	use HasMeta;
	protected const meta_type='foo';

	# For mock saves
	public $save;
	protected function save() {
		return ($this->save)($this);
	}

	# Fake chainable method
	function return_this() { return $this; }

	# To call protected methods
	public function call($method, ...$args){
		return $this->$method(...$args);
	}
	static $count = 0;
	static function _cached_mock() {
		self::$count += 1;
		return static::class;
	}
}

describe("Mapper", function() {
	beforeEach( function() {
		$this->ref = new WatchedPromise;
		$this->model = new MockModel($this->ref);
		$this->mapper = new Mapper($this->model);
		$this->model->save = function($def) { return 42; };
	});

	describe("implements()", function(){
		it("is true iff its model is an instance of the class or interface", function(){
			expect($this->mapper->implements(Model::class))->to->be->true;
			expect($this->mapper->implements(Resource::class))->to->be->false;
		});
	});
	describe("has_method()", function(){
		it("is true iff its model has the method", function(){
			expect($this->mapper->has_method('call'))->to->be->true;
			expect($this->mapper->has_method('nosuchmethod'))->to->be->false;
		});
	});
	describe("apply()", function(){
		it("creates a new model each time", function(){
			$this->mapper->foo = 'bar';
			expect ( $this->model->items()  )->to->equal( array('foo'=>'bar') );
			expect ( $this->mapper->items() )->to->equal( array('foo'=>'bar') );
			$this->mapper->apply();
			expect ( $this->mapper->items() )->to->equal( array() );
			expect ( $this->model->items()  )->to->equal( array('foo'=>'bar') );
		});
		it("calls its model's apply() after the previous one resolves", function(){
			$this->log = array(); $p1 = new GP\Promise(); $p2 = new GP\Promise();
			$this->mapper->save = function() use ($p1) { $this->log[] = 'save 1'; return $p1; };

			# First apply: save started but hung on p1, so not resolved yet
			$a1 = $this->mapper->apply();
			Promise::sync();
			expect($this->log)->to->equal(array('save 1'));
			expect( Promise::now($a1, false) )->to->be->false;

			# Second apply, save shouldn't have run because a1 isn't done
			$this->mapper->save = function() use ($p2) { $this->log[] = 'save 2'; return $p2; };
			$a2 = $this->mapper->apply();
			expect($this->log)->to->equal(array('save 1'));
			expect( Promise::now($a1, false) )->to->equal(false);
			expect( Promise::now($a2, false) )->to->equal(false);

			# Resolving p1 finishes a1's save and a1 in general, starting a2 save
			$p1->resolve(42); Promise::sync();
			expect( Promise::now($a1, false) )->to->equal(42);
			expect( Promise::now($a2, false) )->to->equal(false);
			expect($this->log)->to->equal(array('save 1', 'save 2'));

			# Resolving p2 allows a2 to finish
			$p2->resolve(99); Promise::sync();
			expect( Promise::now($a2, false) )->to->equal(99);
		});
	});
	describe("delegates to its model methods and", function(){
		class DelegateTestModel extends MockModel {
			function yieldingMethod() {
				yield 42; yield 26; yield "hut!";
			}
			function rejectingMethod() {
				return Promise::error("blue 62");
			}
		}
		beforeEach( function() {
			$this->model = new DelegateTestModel($this->ref);
			$this->mapper = new Mapper($this->model);
		});
		it("Promise::spawn()s returned generators", function(){
			expect( $this->mapper->yieldingMethod() )->to->equal('hut!');
		});
		it("throws errors for returned promise rejections", function(){
			expect( array($this->mapper, 'rejectingMethod') )->to->throw(
				"Exception", "The promise was rejected with reason: blue 62"
			);
		});
		it("returns itself for chainable methods", function(){
			expect( $this->mapper->return_this() )->to->equal($this->mapper);
		});
		it("properties, offsets, and count", function(){
			$mapper = $this->mapper; $model = $this->model;
			$mapper->x = 'y';
			$mapper['q'] = 'r';
			expect( isset($mapper->q) )->to->be->true;
			expect( isset($mapper['x']) )->to->be->true;
			expect( isset($mapper->y) )->to->be->false;
			expect( isset($mapper['z']) )->to->be->false;
			expect( $mapper->q )->to->equal('r');
			expect( $mapper['x'] )->to->equal('y');
			expect( $model->items() )->to->equal( array('x'=>'y', 'q'=>'r') );
			expect( count($mapper) )->to->equal(2);
			expect( $mapper->offsetExists('x') )->to->be->true;
			expect( $mapper->offsetExists('a') )->to->be->false;
			expect( (array) $mapper->getIterator() )->to->equal( array('x'=>'y', 'q'=>'r') );
			unset($mapper->q);
			expect( $model->items() )->to->equal( array('x'=>'y') );
			unset($mapper['x']);
			expect( $model->items() )->to->equal( array() );
		});
	});

});

describe("Model", function() {
	beforeEach( function() {
		$this->ref = new WatchedPromise;
		$this->model = new MockModel($this->ref);
	});

	it("is a Bag", function(){
		expect( $this->model )->to->be->instanceof(Bag::class);
	});

	describe("::configure()/::deconfigure()", function(){
		class Lookup1 extends MockModel {
			static function lookup() {}
		}
		class Lookup2 extends MockModel {
			static function lookup() {}
			static function lookup_by_path() {}
			static function lookup_by_guid() {}
		}

		class SetupModel extends MockModel {
			public static $setupCount = 0;
			protected static function on_setup() { static::$setupCount++; }
			protected static function on_teardown() { static::$setupCount--; }
		}

		afterEach( function(){
			Lookup1::deconfigure();
			Lookup2::deconfigure();
			SetupModel::deconfigure();
			SetupModel::$setupCount = 0;
			Monkey\tearDown();
		});

		it("call ::on_setup() on first ::configure(), ::uncache() + ::on_teardown() on last ::deconfigure()", function() {
			SetupModel::cached()['configured'] = true;
			expect(SetupModel::is_cached())->to->be->true;
			$res = Mockery::Mock(Resource::class);
			expect(SetupModel::$setupCount)->to->equal(0);
			SetupModel::configure($res);
			expect(SetupModel::$setupCount)->to->equal(1);
			SetupModel::configure($res);
			expect(SetupModel::$setupCount)->to->equal(1);
			SetupModel::deconfigure($res);
			expect(SetupModel::$setupCount)->to->equal(1);
			expect(SetupModel::is_cached())->to->be->true;
			SetupModel::deconfigure($res);
			expect(SetupModel::is_cached())->to->be->false;
			expect(SetupModel::$setupCount)->to->equal(0);
		});

		it("do a full deconfiguration upon ::deconfigure() w/no args", function() {
			SetupModel::cached()['configured'] = true;
			expect(SetupModel::is_cached())->to->be->true;
			$res = Mockery::Mock(Resource::class);
			expect(SetupModel::$setupCount)->to->equal(0);
			SetupModel::configure($res);
			expect(SetupModel::$setupCount)->to->equal(1);
			SetupModel::configure($res);
			expect(SetupModel::$setupCount)->to->equal(1);
			expect(SetupModel::is_cached())->to->be->true;
			SetupModel::deconfigure();
			expect(SetupModel::is_cached())->to->be->false;
			expect(SetupModel::$setupCount)->to->equal(0);
		});

		describe("manage subclass lookups such that", function(){
			it("lookup() method becomes a lookup handler", function() {
				$res1 = Mockery::Mock(Resource::class);
				$res1->shouldReceive('addLookup')->with(array(Lookup1::class, 'lookup'), '')->once();
				Lookup1::configure($res1);

				$res2 = Mockery::Mock(Resource::class);
				$res2->shouldReceive('removeLookup')->with(array(Lookup1::class, 'lookup'), '')->once();
				Lookup1::deconfigure($res2);
			});

			it("lookup_by_X() methods become lookup handlers for key type X", function() {
				$res1 = Mockery::Mock(Resource::class);
				$res1->shouldReceive('addLookup')->with(array(Lookup2::class, 'lookup'), '')->once();
				$res1->shouldReceive('addLookup')->with(array(Lookup2::class, 'lookup_by_path'), 'path')->once();
				$res1->shouldReceive('addLookup')->with(array(Lookup2::class, 'lookup_by_guid'), 'guid')->once();
				Lookup2::configure($res1);

				$res2 = Mockery::Mock(Resource::class);
				$res2->shouldReceive('removeLookup')->with(array(Lookup2::class, 'lookup'), '')->once();
				$res2->shouldReceive('removeLookup')->with(array(Lookup2::class, 'lookup_by_path'), 'path')->once();
				$res2->shouldReceive('removeLookup')->with(array(Lookup2::class, 'lookup_by_guid'), 'guid')->once();
				Lookup2::deconfigure($res2);
			});
		});
	});

	describe("id()", function(){
		it("returns a default if its key wasn't found", function(){
			expect($this->model->id())->to->be->null;
		});
		it("returns a value once the promise is resolved", function(){
			$this->ref->resolve(42);
			expect($this->model->id())->to->equal(42);
		});
	});

	describe("ref()", function(){
		it("returns the Reference (promise) it was created from", function(){
			expect($this->model->ref())->to->equal($this->ref);
			$this->ref->resolve(42);  # even after resolution
			expect($this->model->ref())->to->equal($this->ref);
		});
	});

	describe("apply()", function(){
		it("calls save() with all promised bag contents resolved", function(){
			$this->props=array();
			$this->model->set(array('a'=>42, 'b'=>Promise::value(99)));
			$this->model->save=function($def) { $this->props = (array) $def; };
			$res = Promise::interpret($this->model->apply());
			expect($this->props)->to->equal(array('a'=>42,'b'=>99));
		});
		it("resolves its reference promise with the result from save()", function(){
			$this->model->set(array('a'=>42, 'b'=>Promise::value(99)));
			$this->model->save=function($def) { return 42; };
			expect($this->model->id())->to->equal(null);
			$res = Promise::interpret($this->model->apply());
			expect($this->model->id())->to->equal(42);
		});
		it("waits for all promises given to also() before resolving", function(){
			$this->model->save=function($def) { return 42; };
			$this->model->also($p1=new GP\Promise);
			$this->model->also($p2=new GP\Promise);
			$res = Promise::interpret($this->model->apply());

			expect( Promise::now($res) )->to->be->null;
			$p1->resolve(1); Promise::sync();
			expect( Promise::now($res) )->to->be->null;
			$p2->resolve(2); Promise::sync();
			expect( Promise::now($res) )->to->equal(42);
		});
	});

	describe("also()", function(){
		it("returns a promise tied to task completion", function (){
			$p1 = $this->model->also(42);
			expect($p1)->to->be->instanceof(WatchedPromise::class);
			expect(Promise::now($p1))->to->equal(42);
			$p2 = $this->model->also($p3 = new GP\Promise());
			expect($p2)->to->be->instanceof(WatchedPromise::class);
			expect(Promise::now($p2))->to->equal(null);
			$p3->resolve(99); Promise::sync();
			expect(Promise::now($p2))->to->equal(99);
		});
		describe("starts coroutines", function(){
			it("after the previous model completes", function(){
				$this->flag = false;
				$new = $this->model->next($p = new GP\Promise());
				$new->also(function(){
					yield 16; $this->flag = true;
				});
				expect($this->flag)->to->be->false;
				$p->resolve(null); Promise::sync();
				expect($this->flag)->to->be->true;
			});
			it("immediately if no previous model", function(){
				$this->flag = false;
				$this->model->also(function(){
					yield 16; $this->flag = true;
				});
				expect($this->flag)->to->be->true;
			});
		});
	});

	describe("settle_args()", function(){
		it("returns a promise for resolving all internal promises", function(){
			$p = new WatchedPromise;
			$this->model->set(array('a'=>42, 'b'=>$p));
			$res = Promise::interpret($this->model->call('settle_args'));
			Promise::sync();
			expect((array)$this->model)->to->equal(array('a'=>42,'b'=>$p));
			$p->resolve(99);
			Promise::sync();
			expect((array)$this->model)->to->equal(array('a'=>42,'b'=>99));
		});
	});

	describe("next()", function(){
		it("returns a new empty instance with the same class and ref()", function (){
			$this->model->foo = 22;
			$new = $this->model->next($p=new GP\Promise());
			expect($new)->to->be->instanceof(get_class($this->model));
			expect( $new->ref() )->to->equal($this->model->ref());
			expect( (array) $this->model )->to->equal( array('foo'=>22) );
			expect( (array) $new         )->to->equal( array() );
		});
	});

	describe("check_save() calls the given func/args", function(){
		beforeEach( function() {
			func\stubs(array(
				'is_wp_error' => function($val){ return false; },
			));
			global $wp_cli_logger;
			$wp_cli_logger->ob_start();
			$wp_cli_logger->stderr = '';
			$this->logger = $wp_cli_logger;
		});
		afterEach( function(){
			$this->logger->ob_end();
			Monkey\tearDown();
		});
		it("issuing a WP_CLI::error if result is_wp_error()", function(){
			func\stubs(array(
				'is_wp_error' => function($val){ return true; },
			));
			expect( array( $this->model, 'call' ) )->with(
				'check_save', fun::expr('$_'), "msg"
			)->to->throw(\WP_CLI\ExitException::class);
			expect( $this->logger->stderr ) -> to -> equal("Error: msg\n");
		});
		it("issuing a formatted WP_CLI::error if result is empty", function(){
			expect( array( $this->model, 'call' ) )->with(
				'check_save', 'is_array', 42
			)->to->throw(\WP_CLI\ExitException::class);
			expect( $this->logger->stderr ) -> to -> equal(
				"Error: Empty ID returned by is_array(42)\n");
		});
		it("returning the result if not empty", function() {
			expect($this->model->call('check_save', fun::expr('$_'), 42))->to->equal(42);
		});
	});

	describe("memoization", function(){
		class MockModel2 extends MockModel {}
		beforeEach( function() {
			MockModel::$count = 0;
			MockModel::uncache();
			MockModel2::uncache();
		});
		it("calls ::_cached_X once for one or more static X() calls", function(){
			expect( MockModel::$count )->to->equal(0);
			expect( MockModel::mock() )->to->equal(MockModel::class);
			expect( MockModel::$count )->to->equal(1);
			expect( MockModel::mock() )->to->equal(MockModel::class);
			expect( MockModel::$count )->to->equal(1);
		});
		it("caches on a per-class basis", function(){
			expect( MockModel::$count )->to->equal(0);
			expect( MockModel::mock() )->to->equal(MockModel::class);
			expect( MockModel2::mock() )->to->equal(MockModel2::class);
			expect( MockModel::$count )->to->equal(2);
			expect( MockModel::mock() )->to->equal(MockModel::class);
			expect( MockModel2::mock() )->to->equal(MockModel2::class);
			expect( MockModel::$count )->to->equal(2);
		});
		describe("cached()", function(){
			it("returns the cached result or invokes the _cached_ version of the named method", function(){
				expect( MockModel::$count )->to->equal(0);
				expect( MockModel::cached('mock') )->to->equal(MockModel::class);
				expect( MockModel2::cached('mock') )->to->equal(MockModel2::class);
				expect( MockModel::$count )->to->equal(2);
				expect( MockModel::cached('mock') )->to->equal(MockModel::class);
				expect( MockModel2::cached('mock') )->to->equal(MockModel2::class);
				expect( MockModel::$count )->to->equal(2);
				MockModel::uncache('mock');
				expect( MockModel::cached('mock') )->to->equal(MockModel::class);
				expect( MockModel2::cached('mock') )->to->equal(MockModel2::class);
				expect( MockModel::$count )->to->equal(3);
			});
			it("with no name returns the class's method cache itself", function(){
				expect( (array) MockModel::cached() )->to->equal(array());
				expect( (array) MockModel2::cached() )->to->equal(array());
				expect( MockModel::cached('mock') )->to->equal(MockModel::class);
				expect( MockModel2::cached('mock') )->to->equal(MockModel2::class);
				expect( (array) MockModel::cached() )->to->equal(array('mock' => MockModel::class));
				expect( (array) MockModel2::cached() )->to->equal(array('mock' => MockModel2::class));
			});
		});
		describe("uncache()", function(){
			it("uncaches the named method's result", function(){
				expect( MockModel::cached('mock') )->to->equal(MockModel::class);
				expect( MockModel2::cached('mock') )->to->equal(MockModel2::class);
				expect( (array) MockModel::cached() )->to->equal(array('mock' => MockModel::class));
				expect( (array) MockModel2::cached() )->to->equal(array('mock' => MockModel2::class));
				MockModel::uncache('mock');
				expect( (array) MockModel::cached() )->to->equal(array());
				expect( (array) MockModel2::cached() )->to->equal(array('mock' => MockModel2::class));
				MockModel2::uncache('mock');
				expect( (array) MockModel::cached() )->to->equal(array());
				expect( (array) MockModel2::cached() )->to->equal(array());
			});
			it("with no name uncaches all method results", function(){
				expect( MockModel::cached('mock') )->to->equal(MockModel::class);
				expect( MockModel2::cached('mock') )->to->equal(MockModel2::class);
				MockModel::uncache();
				expect( (array) MockModel::cached() )->to->equal(array());
				expect( (array) MockModel2::cached() )->to->equal(array('mock' => MockModel2::class));
				MockModel2::uncache();
				expect( (array) MockModel::cached() )->to->equal(array());
				expect( (array) MockModel2::cached() )->to->equal(array());
			});
		});
		describe("is_cached()", function(){
			it("returns true if the named method has a cached result", function(){
				expect( MockModel::is_cached('mock') )->to->be->false;
				expect( MockModel2::is_cached('mock') )->to->be->false;
				MockModel2::mock();
				expect( MockModel::is_cached('mock') )->to->be->false;
				expect( MockModel2::is_cached('mock') )->to->be->true;
			});
			it("with no name returns true if any methods had cached results since reset", function(){
				expect( MockModel::is_cached() )->to->be->false;
				expect( MockModel2::is_cached() )->to->be->false;
				MockModel2::mock();
				expect( MockModel::is_cached() )->to->be->false;
				expect( MockModel2::is_cached() )->to->be->true;
				MockModel2::uncache('mock');
				expect( MockModel2::is_cached() )->to->be->true;
				MockModel2::uncache();
				expect( MockModel2::is_cached() )->to->be->false;
			});
		});
	});

	describe("with HasMeta", function(){
		beforeEach( function() {
			$this->log=array();
			$this->mock_meta=new Pool(function(){ return new Pool(fun::val(false)); });
			$this->expected = [];
			$this->expect = function(...$args) { $this->expected[] = $args; };
			$this->model->save = function($def) { return 42; };
			$this->check = function() {
				expect($this->log)->to->equal($this->expected);
			};
			func\stubs(array(
				'wp_slash' => function($val){ return $val; },
				'update_foo_meta' => function($id, $key, $val){
					$this->log[] = array('set', $id, $key, $val);
					$this->mock_meta[$id][$key]=$val;
				},
				'get_foo_meta' => function($id, $key, $true){
					$this->log[] = array('get', $id, $key, $true);
					return $this->mock_meta[$id][$key];
				},
				'delete_foo_meta' => function($id, $key, $val){
					$this->log[] = array('delete', $id, $key, $val);
					$this->mock_meta[$id][$key];  # force to exist before delete
					unset($this->mock_meta[$id][$key]);
				},
			));
		});

		afterEach( function(){
			$this->check();
			Monkey\tearDown();
		});

		class NoMetaModel extends Model {
			use HasMeta;
			function save(){}
		}

		it("serializes execution of set_meta()/delete_meta()", function(){
			$this->ref->resolve(42);  # run sync so we don't have to apply()
			$this->model->set_meta('x', $p1 = new GP\Promise());
			$this->model->delete_meta('x');
			$this->model->set_meta('x', 99);
			# none of the above execute until $p1 resolves
			$this->check();
			$p1->resolve('y'); Promise::sync();
			$this->expect('set', 42, 'x', 'y');
			$this->expect('delete', 42, 'x', '');
			$this->expect('set', 42, 'x',  99);
		});

		function common_hasmeta_checks() {
			it("is disabled unless a `meta_type` constant is set", function(){
				$model = new NoMetaModel($this->ref);
				expect( array($model, $this->method) )->with('x', 'y')->to->throw(
					"Exception", 'dirtsimple\imposer\tests\NoMetaModel does not support metadata'
				);
			});

			it("rejects null, empty, or non-string keys", function(){
				expect( array($this->model, $this->method) )->with(null, 'y')->to->throw(
					"Exception", "meta_key must not be empty"
				);
				expect( array($this->model, $this->method) )->with(array(), 'y')->to->throw(
					"Exception", "meta_key must not be empty"
				);
				expect( array($this->model, $this->method) )->with('', 'y')->to->throw(
					"Exception", "meta_key items must be non-empty strings"
				);
				expect( array($this->model, $this->method) )->with(2, 'y')->to->throw(
					"Exception", "meta_key items must be non-empty strings"
				);
				expect( array($this->model, $this->method) )->with(array("x", 4.6), 'y')->to->throw(
					"Exception", "meta_key items must be non-empty strings"
				);
				expect( array($this->model, $this->method) )->with(array('','x'), 'y')->to->throw(
					"Exception", "meta_key items must be non-empty strings"
				);
				expect( array($this->model, $this->method) )->with(array('x',''), 'y')->to->throw(
					"Exception", "meta_key items must be non-empty strings"
				);
			});
		}

		describe("set_meta()", function(){
			$this->method = 'set_meta';
			common_hasmeta_checks();

			it("returns a promise for its completion", function(){
				$this->ref->resolve(42);  # run sync so we don't have to apply()
				$this->expect('set', 42, 'x', 'y');
				$p = $this->model->set_meta('x', 'y');
				expect( $p )->to->be->instanceof(WatchedPromise::class);
			});

			it("calls _set_meta() if key is a string or single-element array", function(){
				$this->ref->resolve(42);  # run sync so we don't have to apply()
				$this->expect('set', 42, 'aKey', array('some','thing'));
				$this->model->set_meta(array('aKey'), array('some', Promise::value('thing')));
				$this->check();  # should synchronously apply and set meta

				$this->expect('set', 42, 'aKey', array('another','thing'));
				$this->model->set_meta(array('aKey'), array($p=new GP\Promise(), 'thing'));
				$p->resolve('another');
				Promise::sync();  # promised, so async
			});

			it("patches meta values if key is a multi-element array", function(){
				$this->ref->resolve(42);  # run sync so we don't have to apply()
				$this->expect('get', 42, 'srcKey', true);
				$this->expect('set', 42, 'srcKey', array('some'=>array('thing'=>'else')));
				$this->model->set_meta(array('srcKey', 'some', 'thing'), Promise::value('else'));
				$this->check();  # should synchronously apply and resolve

				$this->expect('get', 42, 'srcKey', true);
				$this->expect('set', 42, 'srcKey', array('some'=>array('thing'=>'else', 'and'=>'more')));
				$this->model->set_meta(array('srcKey', 'some', 'and'), 'more');
			});

			it("waits before fetching the value to patch", function(){
				$this->ref->resolve(42);  # run sync so we don't have to apply()
				$this->model->set_meta(array('srcKey', 'some', 'thing'), $p = new GP\Promise());
				$this->check();  # should not get or set until promise resolves
				$p->resolve('else'); Promise::sync();
				$this->expect('get', 42, 'srcKey', true);
				$this->expect('set', 42, 'srcKey', array('some'=>array('thing'=>'else')));
			});

			it("delays the fulfillment of apply(), if it has to wait", function(){
				$this->expect('set', 42, 'aKey', array('another','thing'));
				$done = $this->model->set_meta(array('aKey'), array($p=new GP\Promise(), 'thing'));
				expect(GP\is_settled($done))->to->be->false;
				$res = Promise::interpret( $this->model->apply() ); Promise::sync();
				expect( Promise::now($res) )->to->be->null;
				$p->resolve('another'); Promise::sync();
				expect(GP\is_settled($done))->to->be->true;
				expect( Promise::now($res) )->to->equal(42);
			});
		});

		describe("delete_meta()", function(){
			$this->method = 'delete_meta';
			common_hasmeta_checks();
			it("returns a promise for its completion", function(){
				$this->ref->resolve(42);  # run sync so we don't have to apply()
				$this->expect('delete', 42, 'x', '');
				$p = $this->model->delete_meta('x');
				expect( $p )->to->be->instanceof(WatchedPromise::class);
			});
			it("calls _delete_meta() if key is a string or single-element array", function(){
				$this->ref->resolve(42);  # run sync so we don't have to apply()
				$this->expect('delete', 42, 'aKey', '');
				$this->model->delete_meta(array('aKey'));
				$this->check();  # should synchronously apply and unset meta

				$this->expect('delete', 42, 'otherKey', '');
				$this->model->delete_meta(array('otherKey'));
			});
			it("patches meta values if key is a multi-element array", function(){
				$this->ref->resolve(42);  # run sync so we don't have to apply()
				$this->expect('get', 42, 'srcKey', true);
				$this->expect('set', 42, 'srcKey', array('some'=>array('thing'=>'else')));
				$this->model->set_meta(array('srcKey', 'some', 'thing'), 'else');
				$this->check();  # should synchronously apply and resolve
				$this->expect('get', 42, 'srcKey', true);
				$this->expect('set', 42, 'srcKey', array('some'=>array()));
				$this->model->delete_meta(array('srcKey', 'some', 'thing'));
			});
		});
	});

});

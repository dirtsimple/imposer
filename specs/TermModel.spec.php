<?php
namespace dirtsimple\imposer\tests;

use dirtsimple\imposer\Bag;
use dirtsimple\imposer\Promise;
use dirtsimple\imposer\Resource;
use dirtsimple\imposer\TermModel;
use dirtsimple\imposer\WatchedPromise;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions as fun;
use Brain\Monkey\Filters;
use Mockery;

describe("TermModel", function() {
	beforeEach(function(){
		Monkey\setUp();
		$this->res = Mockery::Mock(Resource::class);
		$this->terms = array(
				(object) array('taxonomy'=>'category', 'term_id'=>1,  'name'=>'Uncategorized', 'slug'=>'uncategorized'),
				(object) array('taxonomy'=>'category', 'term_id'=>2,  'name'=>'What, ever!',   'slug'=>'what-ever'),
				(object) array('taxonomy'=>'post_tag', 'term_id'=>42, 'name'=>'Foo',           'slug'=>'foo'),
				(object) array('taxonomy'=>'post_tag', 'term_id'=>99, 'name'=>'Bar Baz',       'slug'=>'bar-baz'),
		);
		fun\expect('get_term')->andReturnUsing(function($id, $tax) {
			foreach ($this->terms as $term)
				if ($term->term_id === $id && $term->taxonomy === $tax)
					return $term;
		});

		$this->expectFetch = function() {
			fun\expect('get_terms')->with()->andReturnUsing(function() {
				return $this->terms;
			});
		};
	});
	afterEach( function(){
		Promise::sync();
		TermModel::deconfigure();
		Monkey\tearDown();
	});

	describe("::has_changed(id, tax, data)", function(){
		it("returns true for a non-existent term+tax", function(){
			expect( TermModel::has_changed(57, 'blah', array()) )->to->be->true;
		});
		it("returns false for empty or partial-match data", function(){
			expect( TermModel::has_changed(1, 'category', array()) )->to->be->false;
			expect( TermModel::has_changed(1, 'category', array('slug'=>'uncategorized')) )->to->be->false;
		});
		it("returns true for new or changed fields", function(){
			expect( TermModel::has_changed(1, 'category', array('description'=>'test')) )->to->be->true;
			expect( TermModel::has_changed(1, 'category', array('slug'=>'not categorized')) )->to->be->true;
		});
	});

	describe("::taxonomy_for(resource)", function(){
		it("returns the X from resources named in the form '@wp-X-term'", function(){
			$this->res->allows()->name()->andReturn('@wp-this-and-that-term');
			expect(TermModel::taxonomy_for($this->res))->to->equal('this-and-that');
		});
		it("returns null for resources not named in the form '@wp-X-term'", function(){
			$this->res->allows()->name()->andReturn('@wp-sandwich');
			expect(TermModel::taxonomy_for($this->res))->to->equal(null);
		});
	});

	describe("save()", function(){
		beforeEach(function(){
			$this->model = new TermModel($this->p = new WatchedPromise());
			$this->p->resource = $this->res;
			fun\stubs(array(
				'is_wp_error' => '__return_false',
				'wp_slash' => function($val){ return "$val slashed"; },
			));
		});
		it("returns the ID for an existing, unchanged term", function(){
			$this->p->resolve($id = 99);
			$this->res->allows()->name()->andReturn('@wp-post_tag-term');
			$res = Promise::interpret( $this->model->apply() );
			expect( $res )->to->equal(99);
		});
		it("throws an error for a new term with no name", function(){
			$this->res->allows()->name()->andReturn('@wp-category-term');
			$this->model->slug = "foo";
			$res = Promise::interpret( $this->model->apply() );
			expect( function() use ($res) {
				Promise::now($res);
			})->to->throw(
				\UnexpectedValueException::class,
				'missing name for nonexistent term with args {"slug":"foo"}'
			);
		});
		it("calls wp_insert_term w/slashed args for a new term", function(){
			$this->res->allows()->name()->andReturn('@wp-category-term');
			$this->model->set(
				array( 'slug' => "new", 'name'=>'New', 'description'=>'test' )
			);
			fun\expect('wp_insert_term')->with(
				'New slashed', 'category', array( 'name'=>'New slashed', 'description'=>'test slashed', 'slug' => "new" )
			)->once()->andReturn( array( 'term_id'=> $id = 158 ) );
			$res = Promise::interpret( $this->model->apply() );
			expect( Promise::now($res) )->to->equal($id);
		});
		it("calls wp_update_term w/slashed args for a revised term", function(){
			$this->p->resolve($id = 99);
			$this->res->allows()->name()->andReturn('@wp-post_tag-term');
			$this->model->set(array('parent'=>15, 'name'=>'Changed', 'term_group'=>65));
			fun\expect('wp_update_term')->with(
				$id, 'post_tag', array( 'name'=>'Changed slashed', 'parent'=>15, 'term_group'=>65 )
			)->once()->andReturn( array( 'term_id'=> $id ) );
			$res = Promise::interpret( $this->model->apply() );
			expect( $res )->to->equal(99);
		});
		describe("replaces alias_of with a term_group", function(){
			it("allowing it to skip an update if no change", function(){
				$this->terms[2]->term_group = $this->terms[3]->term_group = 27;
				$this->p->resolve($id = 99);
				$this->res->allows()->name()->andReturn('@wp-post_tag-term');
				$this->model->alias_of = 'foo';
				$this->res->shouldReceive('ref')->with('foo', 'slug')->andReturn(42);
				$res = Promise::interpret( $this->model->apply() );
				expect( $res )->to->equal(99);
			});
			it("updating if the group changed", function(){
				$this->terms[2]->term_group = 27;
				$this->p->resolve($id = 99);
				$this->res->allows()->name()->andReturn('@wp-post_tag-term');
				$this->model->alias_of = 'foo';
				$this->res->shouldReceive('ref')->with('foo', 'slug')->andReturn(42);
				fun\expect('wp_update_term')->with(
					$id, 'post_tag', array( 'term_group'=>27 )
				)->once()->andReturn( array( 'term_id'=> $id ) );
				$res = Promise::interpret( $this->model->apply() );
				expect( $res )->to->equal(99);
			});
			it("unless there's no matching term", function(){
				$this->p->resolve($id = 99);
				$this->res->allows()->name()->andReturn('@wp-post_tag-term');
				$this->model->alias_of = 'no-such';
				$this->model->term_group = 55;  # <- this gets dropped from args
				$this->res->shouldReceive('ref')->with('no-such', 'slug')->andReturn(55);
				fun\expect('wp_update_term')->with(
					$id, 'post_tag', array( 'alias_of'=>'no-such' )
				)->once()->andReturn( array( 'term_id'=> $id ) );
				$res = Promise::interpret( $this->model->apply() );
				expect( $res )->to->equal(99);
			});
			it("unless the aliased term lacks a group", function(){
				$this->p->resolve($id = 99);
				$this->res->allows()->name()->andReturn('@wp-post_tag-term');
				$this->model->alias_of = 'foo';
				$this->model->term_group = 55;  # <- this gets dropped from args
				$this->res->shouldReceive('ref')->with('foo', 'slug')->andReturn(42);
				fun\expect('wp_update_term')->with(
					$id, 'post_tag', array( 'alias_of'=>'foo' )
				)->once()->andReturn( array( 'term_id'=> $id ) );
				$res = Promise::interpret( $this->model->apply() );
				expect( $res )->to->equal(99);
			});
		});
		it("applies term_meta", function(){
			$this->p->resolve($id = 99);
			$this->res->allows()->name()->andReturn('@wp-post_tag-term');
			$this->model->term_meta = array('set_me'=>'foo', 'delete_me'=>null);
			fun\expect('update_term_meta')->with($id, 'set_me slashed', 'foo slashed')->once()->andReturn(true);
			fun\expect('delete_term_meta')->with($id, 'delete_me slashed', ' slashed')->once()->andReturn(true);
			$res = Promise::interpret( $this->model->apply() );
			expect( $res )->to->equal(99);
		});
	});

	describe("::lookup_by_slug()", function(){
		it("returns a cached or current id, refetching if out of date", function(){
			$this->expectFetch();
			$this->res->allows()->name()->andReturn('@wp-post_tag-term');
			expect(TermModel::lookup_by_slug('foo',  'slug', $this->res))->to->equal(42);
			expect(TermModel::lookup_by_slug('blue', 'slug', $this->res))->to->equal(null);
			$this->terms[2]->slug = 'blue';
			expect(TermModel::lookup_by_slug('foo',  'slug', $this->res))->to->equal(null);
			expect(TermModel::lookup_by_slug('blue', 'slug', $this->res))->to->equal(42);
		});
	});
	describe("::lookup_by_name()", function(){
		it("returns a cached or current id, refetching if out of date", function(){
			$this->expectFetch();
			$this->res->allows()->name()->andReturn('@wp-category-term');
			expect(TermModel::lookup_by_name('Uncategorized',   'name', $this->res))->to->equal(1);
			expect(TermModel::lookup_by_name('Not Categorized', 'name', $this->res))->to->equal(null);
			$this->terms[0]->name = 'Not Categorized';
			expect(TermModel::lookup_by_name('Uncategorized',   'name', $this->res))->to->equal(null);
			expect(TermModel::lookup_by_name('Not Categorized', 'name', $this->res))->to->equal(1);
		});
	});
	describe("::lookup()", function(){
		it("tries slug, then name", function() {
			$this->res->shouldReceive('lookup')->with('foo', 'slug')->once()->andReturn(false);
			$this->res->shouldReceive('lookup')->with('foo', 'name')->once()->andReturn(99);
			expect( TermModel::lookup("foo", '', $this->res) )->to->equal(99);
		});
	});
	describe("::terms()", function(){
		it("queries the DB for the guids of non-excluded post types", function(){
			$this->expectFetch();
			expect( (array) TermModel::terms()['category']['slug'] )->to->equal(
				array('uncategorized'=>1, 'what-ever'=>2)
			);
			expect( (array) TermModel::terms()['post_tag']['name'] )->to->equal(
				array('Foo'=>42, 'Bar Baz'=>99)
			);
		});
	});
	describe("::configure()", function(){
		it("configures lookups", function(){
			$this->res->shouldReceive('addLookup')->with(array(TermModel::class, 'lookup'), '')->once();
			$this->res->shouldReceive('addLookup')->with(array(TermModel::class, 'lookup_by_slug'), 'slug')->once();
			$this->res->shouldReceive('addLookup')->with(array(TermModel::class, 'lookup_by_name'), 'name')->once();
			TermModel::configure($this->res);
		});
		it("ensures ::on_created_term is registered as a created_term action", function(){
			$this->res->shouldReceive('addLookup')->times(3);
			TermModel::configure($this->res);
			expect( \has_action('created_term', array(TermModel::class, 'on_created_term') ) )->to->be->true;
		});
	});
	describe("::on_created_term()", function(){
		it("is a no-op when terms cache is inactive", function() {
			expect( (array) TermModel::is_cached('terms') )->to->be->false;
			TermModel::on_created_term( 42, 'foo', 'bar');
			expect( (array) TermModel::is_cached('terms') )->to->be->false;
		});
		it("updates the terms cache", function() {
			$this->expectFetch();
			TermModel::terms();  // init cache
			$this->terms[] = (object) array('name'=>'Blue', 'slug'=>'blue', 'term_id'=>52, 'taxonomy'=>'bar');
			TermModel::on_created_term( 52, 77, 'bar');
			expect( (array) TermModel::terms()['bar']['slug'] )->to->equal(array('blue'=>52));
			expect( (array) TermModel::terms()['bar']['name'] )->to->equal(array('Blue'=>52));
		});
	});
});

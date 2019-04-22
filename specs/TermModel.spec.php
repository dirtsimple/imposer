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
			Monkey\setUp();
			$this->res->shouldReceive('addLookup')->with(array(TermModel::class, 'lookup'), '')->once();
			$this->res->shouldReceive('addLookup')->with(array(TermModel::class, 'lookup_by_slug'), 'slug')->once();
			$this->res->shouldReceive('addLookup')->with(array(TermModel::class, 'lookup_by_name'), 'name')->once();
			TermModel::configure($this->res);
		});
		it("ensures ::on_created_term is registered as a created_term action", function(){
			Monkey\setUp();
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

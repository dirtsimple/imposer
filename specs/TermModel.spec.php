<?php
namespace dirtsimple\imposer\tests;

use dirtsimple\imposer\Bag;
use dirtsimple\imposer\Imposer;
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
		private_var(Imposer::class, 'instance')->setValue(null);

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

		fun\stubs(array(
			'is_wp_error' => '__return_false',
			'wp_slash' => function($val){ return "$val slashed"; },
			'get_terms' => function($args=array(), $deprecated='') {
				expect($args)->to->equal(array('suppress_filter'=>true, 'hide_empty' => false));
				expect(func_num_args())->to->equal(1);
				return $this->terms;
			}
		));
	});
	afterEach( function(){
		Promise::sync();
		TermModel::deconfigure();
		private_var(Imposer::class, 'instance')->setValue(null);
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
		it("queries the DB for the terms", function(){
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
			TermModel::terms();  // init cache
			$this->terms[] = (object) array('name'=>'Blue', 'slug'=>'blue', 'term_id'=>52, 'taxonomy'=>'bar');
			TermModel::on_created_term( 52, 77, 'bar');
			expect( (array) TermModel::terms()['bar']['slug'] )->to->equal(array('blue'=>52));
			expect( (array) TermModel::terms()['bar']['name'] )->to->equal(array('Blue'=>52));
		});
	});
	describe("::impose_term()", function(){
		it("requires a string, array, or stdObj", function(){
			foreach ( array(null, 42, new Bag()) as $invalid )
				expect ( function() use ($invalid) {
					TermModel::impose_term($invalid, "some_tax");
				} )->to->throw(
					\DomainException::class,
					"Term must be string, stdClass, or array (got ". var_export($invalid, true) . ")"
				);
		});
		it("requires a name and/or slug", function(){
			expect ( function() {
				TermModel::impose_term(array('description'=>'x'), "some_tax");
			} )->to->throw(
				\UnexpectedValueException::class,
				'Term must have a name or slug ({"description":"x"})'
			);
		});
		it("doesn't require a name if there's a slug", function(){
			TermModel::impose_term(array('slug'=>"uncategorized"), "category");
		});
		it("treats a string as a name (if no key)", function(){
			fun\expect('wp_update_term')->with(
				1, "category", array('name'=>'Uncategorized slashed', 'parent'=>2)
			)->once()->andReturn(1);
			TermModel::impose_term("Uncategorized", "category", null, 2);
		});
		it("treats a string as a name (w/key as slug)", function(){
			fun\expect('wp_update_term')->with(
				1, "category",
				array('name'=>'Uncategorized slashed', 'slug'=>'uncategorized', 'parent'=>3)
			)->once()->andReturn(1);
			TermModel::impose_term("Uncategorized", "category", 'uncategorized', 3);
		});
		it("doesn't override an explicit parent", function(){
			fun\expect('wp_update_term')->with(
				1, "category", array('name'=>'Uncategorized slashed', 'parent'=>2)
			)->once()->andReturn(1);
			TermModel::impose_term(array('name'=>"Uncategorized", 'parent'=>2), "category", null, 999);
		});
		it("treats non-numeric string parents as slugs", function(){
			fun\expect('wp_update_term')->with(
				1, "category", array('name'=>'Uncategorized slashed', 'parent'=>2)
			)->once()->andReturn(1);
			TermModel::impose_term(array('name'=>"Uncategorized", 'parent'=>'what-ever'), "category");
		});

		it("runs the imposer_term and imposer_term_%taxonomy actions", function (){
			$is_model = Mockery::on(function ($mdl) {
				return $mdl->implements(TermModel::class) && $mdl->items() === array(
					'name'=>'Uncategorized', 'slug'=>'uncategorized',
				);
			});
			Actions\expectDone('imposer_term'         )->with($is_model, 27)->once();
			Actions\expectDone('imposer_term_category')->with($is_model, 27)->once();
			TermModel::impose_term(
				array('name'=>'Uncategorized', 'slug'=> 'uncategorized'), 'category', 27
			);
		});
		it("converts nested objects to arrays", function(){
			$data = (object) array ('x'=>42, 'a'=> (object) array('b'));
			$term = (object) array ('random' => $data );
			$is_arrayified = Mockery::on(function($mdl) use ($data) {
				return $mdl->items() === array(
					'random' => json_decode(json_encode($data), true), 'name'=>'Uncategorized'
				);
			});
			Actions\expectDone('imposer_term')->with($is_arrayified, null)->once();
			TermModel::impose_term( $term, 'category', 'Uncategorized' );
		});
		it("defaults the name or slug from the key",function(){
			$is_named = Mockery::on(function($mdl) {
				return $mdl->items() === array( 'parent'=>22, 'name'=>'Uncategorized' );
			});
			$is_slugged = Mockery::on(function($mdl) {
				return $mdl->items() === array( 'name'=>'Uncategorized', 'slug'=>'uncategorized' );
			});
			Actions\expectDone('imposer_term')->with($is_named, null)->once();
			Actions\expectDone('imposer_term')->with($is_slugged, null)->once();
			$this->terms[0]->parent = 22;  # avoid update
			TermModel::impose_term( array(), 'category', 'Uncategorized', 22);
			TermModel::impose_term( 'Uncategorized', 'category', 'uncategorized');
		});
		it("calls ::impose_terms() on children w/tax and parent", function(){
			class ChildTermModel extends TermModel {
				public static $log;
				static function impose_terms($terms, $tax, $parent=null) {
					static::$log[] = array($terms, $tax, $parent);
				}
			}
			ChildTermModel::$log = array();
			$children = (object) array('Child1', 'Child2');
			$this->terms[0]->parent = 42;  # avoid update
			ChildTermModel::impose_term(array('children'=>$children), 'category', 'Uncategorized', 42);
			expect( ChildTermModel::$log )->to->equal( array(
				array( (array) $children, 'category', 1 )
			) );
		});
	});
	class MockTermModel extends TermModel {
		public static $log;
		static function impose_term($term, $tax, $key=null, $parent=null) {
			static::$log[] = array($term, $tax, $key, $parent);
		}
	}
	describe("::impose_terms()", function(){
		it("requires a string, array, or stdObj", function(){
			foreach ( array(null, 42, new Bag()) as $invalid )
				expect ( function() use ($invalid) {
					TermModel::impose_terms($invalid, "some_tax");
				} )->to->throw(
					\DomainException::class,
					"Terms must be string, stdClass, or array (got ". var_export($invalid, true) . ")"
				);
		});
		it("passes the parent and taxonomy to ::impose_term()", function(){
			MockTermModel::$log = array();
			MockTermModel::impose_terms( array( 'aSlug'=>'aName' ), 'tax1', 42 );
			MockTermModel::impose_terms( array( 'Name1', 'Name2' ), 'tax2' );
			expect( MockTermModel::$log )->to->equal( array(
				array( 'aName', 'tax1', 'aSlug', 42),
				array( 'Name1', 'tax2', 0, null),
				array( 'Name2', 'tax2', 1, null),
			) );
		});
	});
	describe("::impose_taxonomy_terms()", function(){
		it("requires an array or stdObj", function(){
			foreach ( array(null, 42, "x", new Bag()) as $invalid )
				expect ( function() use ($invalid) {
					TermModel::impose_taxonomy_terms($invalid);
				} )->to->throw(
					\DomainException::class,
					"Taxonomy term sets must be stdClass, or array (got ". var_export($invalid, true) . ")"
				);
		});
		it("passes the taxonomies to ::impose_terms()", function(){
			MockTermModel::$log = array();
			MockTermModel::impose_taxonomy_terms( array(
				'tax1' => array( 'aSlug'=>'aName' ),
				'tax2' => array( 'Name1', 'Name2' ),
			) );
			expect( MockTermModel::$log )->to->equal( array(
				array( 'aName', 'tax1', 'aSlug', null),
				array( 'Name1', 'tax2', 0, null),
				array( 'Name2', 'tax2', 1, null),
			) );
		});
	});
});

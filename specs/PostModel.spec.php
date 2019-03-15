<?php
namespace dirtsimple\imposer\tests;

use dirtsimple\imposer\PostModel;
use dirtsimple\imposer\Promise;
use dirtsimple\imposer\Resource;
use dirtsimple\imposer\WatchedPromise;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions as fun;
use Brain\Monkey\Filters;
use Mockery;

describe("PostModel", function() {
	beforeEach(function(){
		PostModel::_test_set_guid_cache(null);
		PostModel::_test_set_excludes(null);
		$this->res = Mockery::Mock(Resource::class);
		global $wpdb;
		$wpdb = $this->wpdb = Mockery::Mock();
		$this->expectedFilter = 'post_type NOT IN ("foo", "bar")';
		$this->expectFilter = function() {
			PostModel::_test_set_excludes( $fubar = array('foo'=>1, 'bar'=>1) );
			$this->wpdb->shouldReceive('prepare')->once()
			->with('post_type NOT IN (%s, %s)', array_keys($fubar))
			->andReturn( $this->expectedFilter );
		};
		$this->expectFetch = function() {
			$this->expectFilter();
			$this->wpdb->posts = $posts_table = 'test_prefix_wp_posts';
			$this->wpdb->shouldReceive('get_results')->once()
			->with("SELECT ID, guid FROM $posts_table WHERE $this->expectedFilter", 'ARRAY_N')
			->andReturn( array(array(27,'fee'), array(42,'fi')) );
		};
	});
	afterEach( function(){
		Monkey\tearDown();
	});

	describe("save()", function(){
		beforeEach(function(){
			$this->model = new PostModel($this->p = new WatchedPromise());
			$this->model->post_title = "Just another thing";
		});
		it("filters revision count to 0 for the post", function(){
			Monkey\setUp();
			$this->p->resolve($id = 99);
			$items = $this->model->items();
			fun\expect('wp_slash')->with($items)->once()->andReturn($items);
			$items['ID'] = $id;

			fun\expect('wp_update_post')->with($items, true)->once()->andReturnUsing(
				function() use ($id) {
					expect(
						has_filter('wp_revisions_to_keep', 'function ($num, $post)')
					)->to->be->true;
					return $id;
				}
			);
			fun\expect('is_wp_error')->with($id)->once()->andReturn(false);
			expect( has_filter('wp_revisions_to_keep') )->to->be->false;
			$res = Promise::interpret( $this->model->apply() );
			expect( has_filter('wp_revisions_to_keep') )->to->be->false;
			expect( $res )->to->equal($id);
		});
		it("calls wp_insert_post w/slashed args if there's no ID", function(){
			Monkey\setUp();
			$items = $this->model->items();
			fun\expect('wp_slash')->with($items)->once()->andReturn($items);
			fun\expect('wp_insert_post')->with($items, true)->once()->andReturn(27);
			fun\expect('is_wp_error')->with(27)->once()->andReturn(false);
			$res = Promise::interpret( $this->model->apply() );
			expect( $res )->to->equal(27);
		});
		it("calls wp_update_post w/slashed args if there's an ID", function(){
			Monkey\setUp();
			$this->p->resolve($id = 99);
			$items = $this->model->items();
			fun\expect('wp_slash')->with($items)->once()->andReturn($items);
			$items['ID'] = $id;
			fun\expect('wp_update_post')->with($items, true)->once()->andReturn($id);
			fun\expect('is_wp_error')->with($id)->once()->andReturn(false);
			$res = Promise::interpret( $this->model->apply() );
			expect( $res )->to->equal($id);
		});
	});
	describe("::lookup_by_path()", function(){
		it("calls url_to_postid", function() {
			fun\expect('url_to_postid')->with('/foo')->once()->andReturn(42);
			expect(PostModel::lookup_by_path('/foo'))->to->equal(42);
		});
		it("returns null if url_to_postid failed", function() {
			fun\expect('url_to_postid')->with('/bar')->once()->andReturn(false);
			expect(PostModel::lookup_by_path('/bar'))->to->equal(null);
		});
	});
	describe("::lookup_by_guid()", function(){
		beforeEach(function(){
			PostModel::_test_set_guid_cache(array('urn:x-foo:bar'=>27));
		});
		it("returns a cached id", function(){
			expect(PostModel::lookup_by_guid('urn:x-foo:bar'))->to->equal(27);
		});
		it("returns null if not in cache", function(){
			expect(PostModel::lookup_by_guid('urn:x-foo:baz'))->to->equal(null);
		});
		it("fetches guids on demand", function(){
			PostModel::_test_set_guid_cache(null);
			$this->expectFetch();
			expect(PostModel::lookup_by_guid('fi'))->to->equal(42);
		});
	});
	describe("::lookup()", function(){
		it("tries guid, then path", function() {
			$this->res->shouldReceive('lookup')->with('foo', 'guid')->once()->andReturn(false);
			$this->res->shouldReceive('lookup')->with('foo', 'path')->once()->andReturn(99);
			expect( PostModel::lookup("foo", '', $this->res) )->to->equal(99);
		});
	});
	describe("::nonguid_post_types()", function(){
		it("includes some known types (revision, EDD/woo orders & payments)", function() {
			Monkey\setUp();
			expect( PostModel::nonguid_post_types() )->to->equal(array(
				'edd_payment' => 1,
				'revision' => 1,
				'shop_order' => 1,
				'shop_subscription' => 1,
			));
		});
		it("filters via imposer_nonguid_post_types", function() {
			Monkey\setUp();
			Filters\expectApplied('imposer_nonguid_post_types')->once()->with(
				array('revision','edd_payment','shop_order','shop_subscription')
			);
			PostModel::nonguid_post_types();
		});
		it("returns the cached map if set", function() {
			PostModel::_test_set_excludes( $fubar = array('foo'=>1, 'bar'=>1) );
			expect( PostModel::nonguid_post_types() )->to->equal($fubar);
		});
	});
	describe("::posttype_exclusion_filter()", function(){
		it("returns an SQL filter for post type exclusion", function(){
			$this->expectFilter();
			expect( PostModel::posttype_exclusion_filter() )->to->equal( $this->expectedFilter );
		});
	});
	describe("::fetch_guids()", function(){
		it("queries the DB for the guids of non-excluded post types", function(){
			$this->expectFetch();
			expect( PostModel::fetch_guids() )->to->equal( array('fee'=>27, 'fi'=>42) );
		});
	});
	describe("::configure()", function(){
		it("configures lookups", function(){
			Monkey\setUp();
			$this->res->shouldReceive('addLookup')->with(array(PostModel::class, 'lookup'))->once();
			$this->res->shouldReceive('addLookup')->with(array(PostModel::class, 'lookup_by_path'), 'path')->once();
			$this->res->shouldReceive('addLookup')->with(array(PostModel::class, 'lookup_by_guid'), 'guid')->once();
			PostModel::configure($this->res);
		});
		it("ensures ::on_save_post is registered as a save_post action", function(){
			Monkey\setUp();
			$this->res->shouldReceive('addLookup')->times(3);
			PostModel::configure($this->res);
			expect( \has_action('save_post', array(PostModel::class, 'on_save_post') ) )->to->be->true;
		});
	});
	describe("::on_save_post()", function(){
		it("is a no-op when guid cache is inactive", function() {
			expect( PostModel::_test_get_guid_cache() )->to->equal(null);
			PostModel::on_save_post( 42, (object) array('type'=>'post', 'guid'=>'urn:x-test-guid:foo') );
			expect( PostModel::_test_get_guid_cache() )->to->equal(null);
		});
		it("is a no-op when post->type is exlcuded", function() {
			PostModel::_test_set_excludes(array('post'=>1));
			PostModel::_test_set_guid_cache(array());
			expect( PostModel::_test_get_guid_cache() )->to->equal(array());
			PostModel::on_save_post( 42, (object) array('type'=>'post', 'guid'=>'urn:x-test-guid:foo') );
			expect( PostModel::_test_get_guid_cache() )->to->equal(array());
		});
		it("updates the guid cache", function() {
			PostModel::_test_set_guid_cache(array());
			PostModel::_test_set_excludes(array());
			expect( PostModel::_test_get_guid_cache() )->to->equal(array());
			PostModel::on_save_post( 42, (object) array('type'=>'post', 'guid'=>'urn:x-test-guid:foo') );
			expect( PostModel::_test_get_guid_cache() )->to->equal(array('urn:x-test-guid:foo'=>42));
		});
	});
});

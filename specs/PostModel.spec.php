<?php
namespace dirtsimple\imposer\tests;

use dirtsimple\imposer\Bag;
use dirtsimple\imposer\PostModel;
use dirtsimple\imposer\Promise;
use dirtsimple\imposer\Resource;
use dirtsimple\imposer\WatchedPromise;

use Brain\Monkey;
use Brain\Monkey\Functions as func;
use Brain\Monkey\Filters;
use Mockery;

describe("PostModel", function() {
	beforeEach(function(){
		$this->res = Mockery::Mock(Resource::class);
		global $wpdb;
		$wpdb = $this->wpdb = Mockery::Mock();
		$this->expectedFilter = 'post_type NOT IN ("foo", "bar")';
		$this->expectFilter = function() {
			PostModel::cached()['nonguid_post_types'] = $fubar = array('foo'=>1, 'bar'=>1);
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
		PostModel::deconfigure();
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
			func\expect('wp_slash')->with($items)->once()->andReturn($items);
			$items['ID'] = $id;

			func\expect('wp_update_post')->with($items, true)->once()->andReturnUsing(
				function() use ($id) {
					expect(
						has_filter('wp_revisions_to_keep', 'function ($num, $post)')
					)->to->be->true;
					return $id;
				}
			);
			func\expect('is_wp_error')->with($id)->once()->andReturn(false);
			expect( has_filter('wp_revisions_to_keep') )->to->be->false;
			$res = Promise::interpret( $this->model->apply() );
			expect( has_filter('wp_revisions_to_keep') )->to->be->false;
			expect( $res )->to->equal($id);
		});
		it("calls wp_insert_post w/slashed args if there's no ID", function(){
			Monkey\setUp();
			$items = $this->model->items();
			func\expect('wp_slash')->with($items)->once()->andReturn($items);
			func\expect('wp_insert_post')->with($items, true)->once()->andReturn(27);
			func\expect('is_wp_error')->with(27)->once()->andReturn(false);
			$res = Promise::interpret( $this->model->apply() );
			expect( $res )->to->equal(27);
		});
		it("calls wp_update_post w/slashed args if there's an ID", function(){
			Monkey\setUp();
			$this->p->resolve($id = 99);
			$items = $this->model->items();
			func\expect('wp_slash')->with($items)->once()->andReturn($items);
			$items['ID'] = $id;
			func\expect('wp_update_post')->with($items, true)->once()->andReturn($id);
			func\expect('is_wp_error')->with($id)->once()->andReturn(false);
			$res = Promise::interpret( $this->model->apply() );
			expect( $res )->to->equal($id);
		});
		describe("forces the post GUID to match the given one", function(){
			beforeEach(function(){
				Monkey\setUp();
				$this->model->guid = $this->originalGUID = "http://example.com/foo?bar=baz&bing=bang";
				PostModel::cached()['guids'] = new Bag;
				$this->mangledGUID  = "http://example.com/foo?bar=baz&amp;bing=bang";
				func\expect('get_post_field')->with('guid', 99, 'raw')->once()->andReturn($this->mangledGUID);
				$this->wpdb->posts = $posts_table = 'test_prefix_wp_posts';
				$this->wpdb->shouldReceive('update')->once()->with(
					$this->wpdb->posts, array('guid' => $this->originalGUID), array('ID'=>99)
				);
				func\expect('get_post_field')->with('post_type', 99, 'raw')->once()->andReturn("post");
				# XXX expect on_save_post
				func\expect('clean_post_cache')->with(99)->once();
			});
			it("on insert", function(){
				$items = $this->model->items();
				func\expect('wp_slash')->with($items)->once()->andReturn($items);
				func\expect('wp_insert_post')->with($items, true)->once()->andReturn(99);
				func\expect('is_wp_error')->with(99)->once()->andReturn(false);
				$res = Promise::interpret( $this->model->apply() );
				expect( $res )->to->equal(99);
				expect(Postmodel::guids()->get($this->originalGUID))->to->equal(99);
			});
			it("on update", function(){
				$this->p->resolve($id = 99);
				$items = $this->model->items();
				func\expect('wp_slash')->with($items)->once()->andReturn($items);
				$items['ID'] = $id;
				func\expect('wp_update_post')->with($items, true)->once()->andReturn($id);
				func\expect('is_wp_error')->with($id)->once()->andReturn(false);
				$res = Promise::interpret( $this->model->apply() );
				expect( $res )->to->equal($id);
				expect(Postmodel::guids()->get($this->originalGUID))->to->equal($id);
			});
		});
	});
	describe("::lookup_by_path()", function(){
		it("calls url_to_postid", function() {
			func\expect('url_to_postid')->with('/foo')->once()->andReturn(42);
			expect(PostModel::lookup_by_path('/foo'))->to->equal(42);
		});
		it("returns null if url_to_postid failed", function() {
			func\expect('url_to_postid')->with('/bar')->once()->andReturn(false);
			expect(PostModel::lookup_by_path('/bar'))->to->equal(null);
		});
	});
	describe("::lookup_by_guid()", function(){
		beforeEach(function(){
			PostModel::cached()['guids'] = new Bag(array('urn:x-foo:bar'=>27));
		});
		it("returns a cached id", function(){
			expect(PostModel::lookup_by_guid('urn:x-foo:bar'))->to->equal(27);
		});
		it("returns null if not in cache", function(){
			expect(PostModel::lookup_by_guid('urn:x-foo:baz'))->to->equal(null);
		});
		it("fetches guids on demand", function(){
			Postmodel::uncache('guids');
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
			expect( (array) PostModel::nonguid_post_types() )->to->equal(array(
				'edd_log' => 1,
				'edd_payment' => 1,
				'revision' => 1,
				'shop_order' => 1,
				'shop_subscription' => 1,
			));
		});
		it("filters via imposer_nonguid_post_types", function() {
			Monkey\setUp();
			Filters\expectApplied('imposer_nonguid_post_types')->once()->with(
				array('revision','edd_log','edd_payment','shop_order','shop_subscription')
			);
			PostModel::nonguid_post_types();
		});
		it("returns the cached map if set", function() {
			PostModel::cached()['nonguid_post_types'] = $fubar = array('foo'=>1, 'bar'=>1);
			expect( PostModel::nonguid_post_types() )->to->equal($fubar);
		});
	});
	describe("::posttype_exclusion_filter()", function(){
		it("returns an SQL filter for post type exclusion", function(){
			$this->expectFilter();
			expect( PostModel::posttype_exclusion_filter() )->to->equal( $this->expectedFilter );
		});
	});
	describe("::guids()", function(){
		it("queries the DB for the guids of non-excluded post types", function(){
			$this->expectFetch();
			expect( (array) PostModel::guids() )->to->equal( array('fee'=>27, 'fi'=>42) );
		});
	});
	describe("::configure()", function(){
		afterEach(function() { PostModel::deconfigure(); });
		it("configures lookups", function(){
			Monkey\setUp();
			$this->res->shouldReceive('addLookup')->with(array(PostModel::class, 'lookup'), '')->once();
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
			expect( (array) PostModel::is_cached('guids') )->to->be->false;
			PostModel::on_save_post( 42, (object) array('post_type'=>'post', 'guid'=>'urn:x-test-guid:foo') );
			expect( (array) PostModel::is_cached('guids') )->to->be->false;
		});
		it("is a no-op when post->type is exlcuded", function() {
			PostModel::cached()['nonguid_post_types'] = array('post'=>1);
			PostModel::cached()['guids'] = new Bag;
			expect( (array) PostModel::guids() )->to->equal(array());
			PostModel::on_save_post( 42, (object) array('post_type'=>'post', 'guid'=>'urn:x-test-guid:foo') );
			expect( (array) PostModel::guids() )->to->equal(array());
		});
		it("updates the guid cache", function() {
			PostModel::cached()['guids'] = new Bag(array());
			expect( PostModel::is_cached('guids') )->to->be->true;
			PostModel::on_save_post( 42, (object) array('post_type'=>'post', 'guid'=>'urn:x-test-guid:foo') );
			expect( (array) PostModel::guids() )->to->equal(array('urn:x-test-guid:foo'=>42));
		});
	});
});

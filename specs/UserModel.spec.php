<?php
namespace dirtsimple\imposer\tests;

use dirtsimple\imposer\Promise;
use dirtsimple\imposer\Resource;
use dirtsimple\imposer\UserModel;
use dirtsimple\imposer\WatchedPromise;

use Brain\Monkey;
use Brain\Monkey\Functions as func;
use Mockery;

describe("UserModel", function(){
	beforeEach( function(){
		Monkey\setUp();
		func\stubs(array(
			'is_wp_error' => '__return_false',
			'wp_slash' => function($val){ return "$val slashed"; },
		));
		$this->res = Mockery::Mock(Resource::class);
	});
	afterEach( function(){
		Monkey\tearDown();
	});
	describe("lookup()", function() {
		it("tries email, then login", function(){
			$this->res->shouldReceive('lookup')->with('foo', 'email')->once()->andReturn(null);
			$this->res->shouldReceive('lookup')->with('foo', 'login')->once()->andReturn(99);
			expect( UserModel::lookup("foo", '', $this->res) )->to->equal(99);
		});
	});
	describe("lookup_by_email()", function() {
		it("gets an ID from get_user_by('email', key)", function(){
			func\expect('get_user_by')->once()->with('email', 'me@u')->andReturn((object) array('ID'=>21));
			func\expect('get_user_by')->once()->with('email', 'u@me')->andReturn(false);
			expect( UserModel::lookup_by_email("me@u") )->to->equal(21);
			expect( UserModel::lookup_by_email("u@me") )->to->equal(null);
		});
	});
	describe("lookup_by_login()", function() {
		it("gets an ID from get_user_by('login', key)", function(){
			func\expect('get_user_by')->once()->with('login', 'me')->andReturn((object) array('ID'=>17));
			func\expect('get_user_by')->once()->with('login', 'you')->andReturn(false);
			expect( UserModel::lookup_by_login("me") )->to->equal(17);
			expect( UserModel::lookup_by_login("you") )->to->equal(null);
		});
	});
	describe("save()", function() {
		beforeEach(function(){
			$this->model = new UserModel($this->p = new WatchedPromise());
			$slashables = array(
				"user_email" => "foo@bar.com",
				"user_url" => "http://example.com",
				"user_nicename" => "Nice",
				"display_name" => "Display",
				"user_registered" => "Y-m-d H:i:s",
			);
			$unslashed = array(
				"user_pass" => "sekret!",
				"description" => "test"
			);
			$this->expected = array_map('wp_slash', $slashables) + $unslashed;
			$this->model->set($slashables)->set($unslashed);
		});
		it("calls wp_update_user if id is known", function(){
			$this->p->resolve(16); Promise::sync();
			$this->expected = $this->expected + array('ID'=>16);
			func\expect('wp_update_user')->once()->with( $this->expected )->andReturn(16);
			expect( Promise::interpret($this->model->apply()) )->to->equal(16);
		});
		it("calls wp_insert_user if new", function(){
			func\expect('wp_insert_user')->once()->with( $this->expected )->andReturn(99);
			expect( Promise::interpret($this->model->apply()) )->to->equal(99);
		});
	});
});

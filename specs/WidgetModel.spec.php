<?php
namespace dirtsimple\imposer\tests;

use dirtsimple\imposer\Bag;
use dirtsimple\imposer\Imposer;
use dirtsimple\imposer\Promise;
use dirtsimple\imposer\WatchedPromise;
use dirtsimple\imposer\WidgetModel;

use Brain\Monkey;
use Brain\Monkey\Functions as func;
use Brain\Monkey\Filters;
use Mockery;

describe("WidgetModel", function(){
	beforeEach(function(){
		Monkey\setUp();
		$this->opts = new Bag();
		private_var(Imposer::class, 'instance')->setValue(null);
		class_exists(WidgetModel::class);
		WidgetModel::deconfigure();
		$this->get_option = function($key, $dflt=false) { return $this->opts->get($key, $dflt); };
		$this->set_option = function($key, $val, $autoload=null){ $this->opts[$key] = $val; return true; };
		$this->make_model = function($key, $p=null) {
			$p = isset($p) ? Promise::value($p) : new WatchedPromise();
			$p->key = $key;
			return new WidgetModel($this->p = $p);
		};
		global $wp_widget_factory;
		$wp_widget_factory = $this->widgets = (object) array(
			'widgets' => array(
				'WP_Widget_Calendar' => (object)array('id_base'=>'calendar'),
				'WP_Widget_Archives' => (object)array('id_base'=>'archives'),
			)
		);
		func\stubs(array(
			'sanitize_option'=> function($key, $val) { return $val; },
		));
		$this->stub_options = function(){
			func\stubs(array(
				'get_option'=>$this->get_option,
				'update_option'=>$this->set_option,
			));
		};
	});
	afterEach(function(){
		Monkey\tearDown();
		private_var(Imposer::class, 'instance')->setValue(null);
	});
	describe("lookup()", function(){
		it("uses from the `imposer_widget_ids` option", function(){
			$this->set_option('imposer_widget_ids', array('foo'=>'bar-1'));
			func\expect('get_option')->once()->with('imposer_widget_ids', array())->andReturnUsing($this->get_option);
			expect( WidgetModel::lookup('foobar') )->to->be->null;
			expect( WidgetModel::lookup('foo') )->to->equal('bar-1');
		});
		it("caches the option until a new widget is created", function(){
			$this->set_option('imposer_widget_ids', array('foo'=>'bar-1'));
			func\expect('get_option')->once()->with('imposer_widget_ids', array())->andReturnUsing($this->get_option);
			expect( WidgetModel::lookup('foo') )->to->equal('bar-1');
			expect( WidgetModel::lookup('foo') )->to->equal('bar-1');
		});
	});
	describe("save()", function(){
		describe("new widget", function(){
			it("requires a registered widget_type", function(){
				$m = $this->make_model('foo');
				expect( array($m, 'save') )->to->throw(
					\UnexpectedValueException::class, "Widget 'foo' has no widget_type"
				);
				$m = $this->make_model('foo')->set( array('widget_type'=>'xyz') );
				expect( array($m, 'save') )->to->throw(
					\UnexpectedValueException::class, "Widget foo: 'xyz' is not a registered widget type"
				);
			});
			it("returns an ID > any existing ID for that widget type", function(){
				$this->stub_options();
				$m = $this->make_model('foo');
				$m->set( array('widget_type'=>'calendar', 'blah'=>42) );
				expect($m->save())->to->equal('calendar-1');
				$base = array(
					'imposer_widget_ids' => array('foo'=>'calendar-1'),
					'widget_calendar' => array('1'=>array('blah'=>42), '_multiwidget'=>1),
				);
				expect($this->opts->items())->to->equal($base);
				expect(WidgetModel::lookup('foo'))->to->equal('calendar-1');
				$m = $this->make_model('bar');
				$m->set( array('widget_type'=>'calendar', 'bing'=>99) );
				expect($m->save())->to->equal('calendar-2');
				$base['widget_calendar']['2'] = array('bing'=>99);
				$base['imposer_widget_ids']['bar'] = 'calendar-2';
				expect($this->opts->items())->to->equal($base);
				
			});
		});
		describe("existing widget", function(){
			it("must keep the same widget_type", function(){
				$m = $this->make_model('foo', 'bar-1')->set( array('widget_type'=>'xyz') );
				expect( array($m, 'save') )->to->throw(
					\UnexpectedValueException::class,
					"Widget 'foo' already exists; can't change type from 'bar' to 'xyz'"
				);
			});
			it('patches the widget_$type option', function() {
				func\stubs(array(
					'get_option'=>$this->get_option,
					'update_option'=>$this->set_option,
				));
				$m = $this->make_model('foo', 'bar-1')->set( array('foo'=>42) );
				expect($m->save())->to->equal('bar-1');
				$base = array(
					'widget_bar' => array('1'=>array('foo'=>42), '_multiwidget'=>1)
				);
				expect($this->opts->items())->to->equal($base);
				$m = $this->make_model('foo', 'bar-1')->set( array('bar'=>99) )->save();
				$base['widget_bar']['1']['bar'] = 99;
				expect($this->opts->items())->to->equal($base);
			});
		});
	});
	describe("impose_widgets()", function(){
		it("saves each key/value pair as a @wp-widget", function(){
			$this->stub_options();
			WidgetModel::impose_widgets(
				array( 'cal-tech' => array('widget_type'=>'calendar', 'data'=>42) )
			);
			$base = array(
				'imposer_widget_ids' => array('cal-tech'=>'calendar-1'),
				'widget_calendar' => array('1'=>array('data'=>42), '_multiwidget'=>1),
			);
			expect($this->opts->items())->to->equal($base);
		});
		it("filters widget data generically and by type", function(){
			$this->stub_options();
			Filters\expectApplied('imposer_widget')->once()->with( array('widget_type'=>'calendar', 'data'=>42), 'cal-tech' );
			Filters\expectApplied('imposer_widget_calendar')->once()->with( array('widget_type'=>'calendar', 'data'=>42), 'cal-tech' );
			WidgetModel::impose_widgets(
				array( 'cal-tech' => array('widget_type'=>'calendar', 'data'=>42) )
			);
			Filters\expectApplied('imposer_widget')->once()->with( array('data'=>99), 'cal-tech' );
			WidgetModel::impose_widgets(
				array( 'cal-tech' => array('data'=>99) )
			);
		});
	});
	describe("impose_sidebars()", function(){
		it("maps each array to the named widgets and patches 'sidebars_widgets'", function(){
			$this->stub_options();
			$this->set_option('imposer_widget_ids', array(
				'foo'=>'bar-1', 'baz'=>'bing-27', 'calrissian'=>'calendar-19'
			));
			$base = $this->opts->items();
			Promise::interpret(
				WidgetModel::impose_sidebars(
					array(
						'sb1' => array('baz', 'foo'),
						'footer_bar' => array('calrissian', 'cal-tech'),
					)
				)
			);
			Imposer::define('@wp-widget', 'calrissian')->apply();
			Imposer::define('@wp-widget', 'cal-tech')->set(array('widget_type'=>'calendar'))->apply();
			unset($this->opts->widget_calendar);
			Promise::sync();
			$base['imposer_widget_ids']['cal-tech'] = 'calendar-20';
			$base['sidebars_widgets'] = array(
				'sb1'=>array('bing-27', 'bar-1'),
				'footer_bar'=>array('calendar-19', 'calendar-20')
			);
			expect( $this->opts->items() )->to->equal($base);
		});
	});
});

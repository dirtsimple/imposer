<?php

namespace dirtsimple\imposer;

class WidgetModel extends Model {

	const INDEX = 'imposer_widget_ids';

	static function lookup($key) {
		return self::index()->get($key);
	}

	static function _cached_index() {
		return new Bag( get_option(self::INDEX, array()) );
	}

	static function on_setup() {
		add_action(
			'update_option_' . self::INDEX,
			function($old, $val) { self::cached()['index'] = new Bag( $val ?: array() ); },
			10, 2
		);
	}

	function save() {
		$opts = array();
		$key = $this->ref()->key;

		if ( $id = $this->id() ) {
			$type = explode('-', $id);
			$numb = array_pop($type);
			$type = implode('-', $type);

			// must NOT have type that's different from existing
			if ( $this->get('widget_type', $type) !== $type ) throw new \UnexpectedValueException(
				"Widget '$key' already exists; can't change type from '$type' to '$this->widget_type'"
			);
		} else {
			// must have type, or else error
			if ( ! $type = $this->get('widget_type') ) throw new \UnexpectedValueException(
				"Widget '$key' has no widget_type"
			);
			global $wp_widget_factory;
			if ( false === array_search($type, array_column($wp_widget_factory->widgets, 'id_base')) ) {
				throw new \UnexpectedValueException("Widget $key: '$type' is not a registered widget type");
			}
			$data = get_option("widget_$type", array()); unset( $data['_multiwidget'] );
			$numb = count($data) ? 1 + max( array_map('intval', array_keys($data)) ) : 1;
			$id = "$type-$numb";
			$index = get_option(self::INDEX, array()); $index[$key] = $id;
			update_option(self::INDEX, $index, false);
		}

		$args = $this->items(); unset($args['widget_type']);
		$opts["widget_$type"] = (object) array( $numb => json_decode(json_encode($args)), '_multiwidget'=>1 );
		Imposer::impose_options( $opts, false );
		return $id;
	}

	static function impose_widgets($widgets) {
		// Convert to array form
		$widgets = json_decode( json_encode($widgets), true);
		foreach ($widgets as $key => $data) {
			$data = apply_filters('imposer_widget', $data, $key);
			if ( array_key_exists('widget_type', $data ) )
				$data = apply_filters('imposer_widget_' . $data['widget_type'], $data, $key);
			$m = Imposer::define('@wp-widget', $key)->set($data)->apply();
		}
	}

	static function impose_sidebars($sidebars) {
		// Convert to array form
		$sidebars = json_decode( json_encode($sidebars), true);
		foreach ($sidebars as $bar => &$widgets) {
			$widgets = yield Imposer::ref('@wp-widget', $widgets);
		}
		Imposer::impose_options( array('sidebars_widgets' => (object) $sidebars), false );
	}

}

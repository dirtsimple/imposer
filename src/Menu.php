<?php
namespace dirtsimple\imposer;
use WP_CLI;

class Menu {

	static function build_menus($menus) {
		// Get all exising menus, indexed by name
		$oldMenus = array_column( wp_get_nav_menus(), null, 'name' );

		foreach ($menus as $name => $data) {
			$old = aget($oldMenus, $name, null);
			// If the menu is just a list, treat it as items w/no desc or loc
			if ( is_array($data) ) $data = (object) array('items'=>$data);

			$menu = new Menu(
				get($old->term_id, 0), $name,
				get($data->description, get($old->description, '')),
				get($data->items, null)
			);
			$menu->sync($old, get($data->location, null));
		}
	}

	function __construct($id, $name, $description, $items) {
		$this->term_id     = $id;
		$this->name        = $name;
		$this->description = $description;
		$this->items       = (array) $items;
	}

	function sync($old, $locations) {
		if ( $this->term_id === 0 || $this->description != $old->description ) {
			$id = wp_update_nav_menu_object(
				$this->term_id, wp_slash(
					array('menu-name'=>$this->name, 'description'=>$this->description)
				)
			);
			if ( is_wp_error($id) ) WP_CLI::error($id);
			$this->term_id = $id;
		}
		$this->sync_items();
		$this->sync_locations($locations);
	}

	function sync_locations($locations) {
		$this->location_map = array();
		$this->parse_locations($locations, $current = get_option('stylesheet'));
		$registered_menus = get_registered_nav_menus();
		foreach( $this->location_map as $theme => $locations ) {
			if ( $theme === $current ) {
				foreach (array_keys($locations) as $slot) {
					if ( count($registered_menus) && ! array_key_exists( $slot, $registered_menus ) ) {
						WP_CLI::error( "Theme '$theme' has no menu location '$slot'." );
					}
				}
				$locations += array_map( array($this, 'remove_me'), $slots = get_nav_menu_locations() );
				if ( $slots !== $locations ) set_theme_mod( 'nav_menu_locations', $locations);
			} else {
				$mods = $old = get_option( "theme_mods_$theme" );
				$slots = aget($mods, 'nav_menu_locations');
				$locations += array_map( array($this, 'remove_me'), $slots = is_array($slots) ? $slots : array() );
				if ( $slots !== $locations ) {
					$mods['nav_menu_locations'] = $locations;
					update_option( "theme_mods_$theme", $mods );
				}
			}
		}
	}

	function remove_me($menu_id) { return $menu_id == $this->term_id ? 0 : $menu_id; }

	function parse_locations($locations, $default_theme) {
		if ( is_array($locations) || is_object($locations) ) {
			foreach ( $locations as $theme => $location) {
				# if sequential, set location for default theme, otherwise use named theme
				$this->parse_locations($location, is_numeric($theme) ? $default_theme : $theme);
			}
		} elseif ( !empty($locations) ) {
			$this->location_map[$default_theme][$locations] = $this->term_id;
		} else {
			$this->location_map[$default_theme] = aget(
				$this->location_map, $default_theme, array()
			);
		}
	}

	function sync_items() {
		# Process items recursively w/in-order position assignment
		$this->item_count = 0;
		$this->old_items = (object) array_column(wp_get_nav_menu_items($this->name), null, 'guid');
		$this->sync_children($this);

		# Delete any previously-existing items not reused by the new list
		foreach ( (array) $this->old_items as $old_item ) {
			! $old_item
			|| wp_delete_post( $old_item->db_id, true )
			|| WP_CLI::error("Failed to delete post {$old_item->db_id}");
		}
	}

	function sync_children($ob, $parent_id=0) {
		foreach (get($ob->items, array()) as $itemdata) {
			$item = new MenuItem($this->term_id, (object) $itemdata, $parent_id, ++$this->item_count);
			$db_id = $item->sync($old = get($this->old_items->{$item->guid}, null));
			if ( is_wp_error($db_id) ) WP_CLI::error($db_id);
			if ( $old ) unset($this->old_items->{$item->guid});
			$this->sync_children($item, $db_id);
		}
	}
}



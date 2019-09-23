<?php
namespace dirtsimple\imposer;
use WP_CLI;

class MenuItem {

	function __construct($menu, $item, $parent=0, $position=0) {
		$this->menu        = $menu;
		$this->parent_id   = $parent;
		$this->position    = $position;
		$this->title       = get($item->title, '');
		$this->description = get($item->description, '');
		$this->attr_title  = get($item->attr_title, '');
		$this->target      = get($item->target, '');
		$this->classes     = get($item->classes, '');
		$this->xfn         = get($item->xfn, '');
	}

	protected function guid($item) {
		if ( ($url = get($item->url)) !== false ) {
			$name = $this->custom($url, $item);
		} elseif ( ($page = get($item->page)) !== false ) {
			$name = yield $this->page($page, $item);
		} elseif ( ($archive_type = get($item->archive)) !== false ) {
			$name = $this->archive($archive_type, $item);
		} elseif ( ($term = get($item->term)) !== false ) {
			$name = yield $this->term($term, $item);
		} elseif ( ($tag = get($item->tag)) !== false ) {
			$name = $this->term( array('post_tag'=>$tag), $item );
		} elseif ( ($category = get($item->category)) !== false ) {
			$name = $this->term( array('category'=>$category), $item );
		} else {
			WP_CLI::error("Menu items must have one of: url, page, archive, tag, category, or term");
		}
		yield "urn:x-wp-menu-item:" . urlencode($name) . "@" . $this->menu;
	}

	protected function custom($url, $item) {
		$this->type = 'custom';
		$this->url = $url;
		return get($item->id, "custom:$url");
	}

	protected function page($page, $item) {
		$post = yield Imposer::ref('@wp-post', $page);
		$this->object = $post_type = get_post($post)->post_type;
		$this->object_id = $post;
		$this->type = 'post_type';
		yield get($item->id, "page:$post_type:$post");
	}

	protected function archive($archive_type, $item) {
		if ( ! isset(get_post_types()[$archive_type]) ) {
			WP_CLI::error("Invalid archive post type '$archive_type'");
		}
		$this->type = 'post_type_archive';
		$this->object = $archive_type;
		return get($item->id, "archive:$archive_type");
	}

	protected function term($terminfo, $item) {
		if ( ! is_object($terminfo) || count((array)$terminfo) != 1 ) {
			WP_CLI::error("Menu item's `term` property must be a single-property object mapping a taxonomy to a term");
		}
		foreach ($terminfo as $tax => $term) {
			$this->type = 'taxonomy';
			$this->object = $tax; $this->object_id = yield Imposer::ref("@wp-$tax-term", $term);
		}
		yield get($item->id, "term:$tax:$term");
	}

	function sync($itemdata, $old_items) {
		$this->guid = yield $this->guid($itemdata);
		$old_item = get($old_items->{$this->guid}, null);
		$db_id = $old_item ? $old_item->db_id : 0;
		if ( empty($db_id) || $this->changed_from($old_item) ) {
			add_filter( 'wp_insert_post_data', array($this, '_sync_guid'), 999999, 2 );
			$db_id = wp_update_nav_menu_item($this->menu, $db_id, $this->sync_args());
			remove_filter( 'wp_insert_post_data', array($this, '_sync_guid'), 999999, 2 );
		}
		if ( is_wp_error($db_id) ) WP_CLI::error($db_id);
		if ( $old ) unset($old_items->{$this->guid});
		yield $db_id;
	}

	function _sync_guid($data, $postarr) { return array('guid' => wp_slash($this->guid)) + $data; }

	protected function changed_from($old) {
		return (
			$this->parent_id   !== (int) $old->menu_item_parent ||
			$this->position    !== $old->menu_order             ||
			$this->type        !== $old->type                   ||
			$this->description !== $old->description            ||
			$this->attr_title  !== $old->attr_title             ||
			$this->target      !== $old->target                 ||
			$this->xfn         !== $old->xfn                    ||

			( $this->type == 'custom' && $this->url       !== $old->url )             ||
			( isset($this->object)    && $this->object    !== $old->object )          ||
			( isset($this->object_id) && $this->object_id !== (int) $old->object_id ) ||
			( $this->title !== ''     && $this->title     !== $old->title )           ||

			$old->classes !== array_map( 'sanitize_html_class', explode( ' ', $this->classes ) ) ||
			$old->status  !== 'publish'
		);
	}

	protected function sync_args() {
		return array(
			'menu-item-object-id'   => get($this->object_id, 0),
			'menu-item-object'      => get($this->object, ''),
			'menu-item-parent-id'   => $this->parent_id,
			'menu-item-position'    => $this->position,
			'menu-item-type'        => $this->type,
			'menu-item-title'       => wp_slash($this->title),
			'menu-item-url'         => get($this->url, ''),
			'menu-item-description' => wp_slash($this->description),
			'menu-item-attr-title'  => wp_slash($this->attr_title),
			'menu-item-target'      => get($this->target, ''),
			'menu-item-classes'     => get($this->classes, ''),
			'menu-item-xfn'         => get($this->xfn, ''),
			'menu-item-status'      => 'publish',
		);
	}
}


<?php
/**
 * Elementor Agend Community Popular Posts widget for Agend Elementor Widgets.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-agend-elementor-community-widget.php';

/**
 * Renders the posts members are joining in from the association's Agend Loop community, as a compact list: featured posts first, then the most replies. Only channels an administrator has allowed under Agend Apps settings are ever shown.
 */
class Agend_Elementor_Community_Feed extends Agend_Elementor_Community_Widget {

	/**
	 * The core surface this widget adapts.
	 *
	 * @return string Surface id.
	 */
	protected function surface(): string {
		return 'community-feed';
	}

	/**
	 * Returns the widget name (unique identifier).
	 *
	 * @return string Widget name.
	 */
	public function get_name(): string {
		return 'agend-community-feed';
	}

	/**
	 * Returns the widget display title.
	 *
	 * @return string Widget title.
	 */
	public function get_title(): string {
		return __( 'Agend Community Popular Posts', 'agend-elementor' );
	}

	/**
	 * Returns the Elementor icon class for the widget.
	 *
	 * @return string Icon class.
	 */
	public function get_icon(): string {
		return 'eicon-comments';
	}

	/**
	 * Returns search keywords for the widget panel.
	 *
	 * @return array Keywords.
	 */
	public function get_keywords(): array {
		return array( 'agend', 'community', 'loop', 'popular', 'posts', 'discussions' );
	}
}

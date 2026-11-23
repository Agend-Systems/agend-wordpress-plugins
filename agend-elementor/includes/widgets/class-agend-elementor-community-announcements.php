<?php
/**
 * Elementor Agend Community Announcements widget for Agend Elementor Widgets.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-agend-elementor-community-widget.php';

/**
 * Renders announcements from the association's Agend Loop community as a notice list, pinned first. Only channels an administrator has allowed under Agend Apps settings are ever shown.
 */
class Agend_Elementor_Community_Announcements extends Agend_Elementor_Community_Widget {

	/**
	 * The core surface this widget adapts.
	 *
	 * @return string Surface id.
	 */
	protected function surface(): string {
		return 'community-announcements';
	}

	/**
	 * Returns the widget name (unique identifier).
	 *
	 * @return string Widget name.
	 */
	public function get_name(): string {
		return 'agend-community-announcements';
	}

	/**
	 * Returns the widget display title.
	 *
	 * @return string Widget title.
	 */
	public function get_title(): string {
		return __( 'Agend Community Announcements', 'agend-elementor' );
	}

	/**
	 * Returns the Elementor icon class for the widget.
	 *
	 * @return string Icon class.
	 */
	public function get_icon(): string {
		return 'eicon-alert';
	}

	/**
	 * Returns search keywords for the widget panel.
	 *
	 * @return array Keywords.
	 */
	public function get_keywords(): array {
		return array( 'agend', 'community', 'loop', 'announcements', 'notices', 'news' );
	}
}

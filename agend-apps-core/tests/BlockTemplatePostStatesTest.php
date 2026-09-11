<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Test;
use WP_Post;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/block-template-post-states.php';

/**
 * `agend_apps_block_template_post_states()`: mirrors
 * agend-elementor/includes/adapters/template-post-states.php for `wp_block`
 * templates, so the detail-template-in-use label shows up regardless of
 * which builder produced the configured template.
 */
#[CoversFunction( 'agend_apps_block_template_post_states' )]
final class BlockTemplatePostStatesTest extends TestCase {

	#[Test]
	public function should_label_a_wp_block_post_configured_as_the_event_detail_template(): void {
		update_option( AGEND_APPS_RECORDS_EVENT_DETAIL_TEMPLATE_OPTION, 7 );
		$post = new WP_Post( array( 'ID' => 7, 'post_type' => 'wp_block' ) );

		$states = agend_apps_block_template_post_states( array(), $post );

		$this->assertSame( 'Agend Event detail template', $states['agend_event_detail'] );
	}

	#[Test]
	public function should_leave_states_untouched_for_a_wp_block_post_not_configured_as_any_detail_template(): void {
		$post = new WP_Post( array( 'ID' => 8, 'post_type' => 'wp_block' ) );

		$this->assertSame( array(), agend_apps_block_template_post_states( array(), $post ) );
	}

	#[Test]
	public function should_ignore_a_post_of_a_different_post_type_even_when_its_id_matches(): void {
		update_option( AGEND_APPS_RECORDS_EVENT_DETAIL_TEMPLATE_OPTION, 9 );
		$post = new WP_Post( array( 'ID' => 9, 'post_type' => 'elementor_library' ) );

		$this->assertSame( array(), agend_apps_block_template_post_states( array(), $post ) );
	}
}

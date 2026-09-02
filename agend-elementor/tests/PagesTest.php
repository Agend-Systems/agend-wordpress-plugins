<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Elementor_Pages;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-settings.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-pages.php';

/**
 * `Agend_Elementor_Pages`: the dedicated Events/Courses page resolution that
 * fixes the host-page hijack (a catalogue widget used away from its
 * configured page must never take over the page it happens to sit on).
 */
#[CoversClass( Agend_Elementor_Pages::class )]
final class PagesTest extends TestCase {

	private function seedPage( int $id, string $post_type = 'page', string $post_status = 'publish' ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array(
			'ID'          => $id,
			'post_type'   => $post_type,
			'post_status' => $post_status,
		);
	}

	#[Test]
	public function should_build_pretty_detail_url_when_permalinks_enabled_and_page_configured(): void {
		$this->seedPage( 5 );
		Agend_Test_WP::$options['agend_elementor_events_page_id'] = 5;
		Agend_Test_WP::$options['permalink_structure']            = '/%postname%/';

		$url = Agend_Elementor_Pages::detail_url( 'event', 'my-slug' );

		$this->assertSame( 'https://example.test/page-5/event/my-slug/', $url );
	}

	#[Test]
	public function should_build_legacy_query_param_url_when_permalinks_disabled(): void {
		$this->seedPage( 5 );
		Agend_Test_WP::$options['agend_elementor_events_page_id'] = 5;

		$url = Agend_Elementor_Pages::detail_url( 'event', 'my-slug' );

		$this->assertSame( 'https://example.test/page-5/?agend_event=my-slug', $url );
	}

	#[Test]
	public function should_fall_back_to_host_page_when_no_dedicated_page_configured(): void {
		$this->seedPage( 9 );
		Agend_Test_WP::$options['permalink_structure'] = '/%postname%/';

		$url = Agend_Elementor_Pages::detail_url( 'event', 'my-slug', 9 );

		$this->assertSame( 'https://example.test/page-9/event/my-slug/', $url );
	}

	#[Test]
	public function should_treat_trashed_configured_page_as_unset(): void {
		$this->seedPage( 5, 'page', 'trash' );
		Agend_Test_WP::$options['agend_elementor_events_page_id'] = 5;

		$this->assertSame( 0, Agend_Elementor_Pages::page_id( 'event' ) );
		$this->assertSame( '', Agend_Elementor_Pages::page_url( 'event' ) );
	}

	#[Test]
	public function should_treat_configured_non_page_post_as_unset(): void {
		$this->seedPage( 5, 'post', 'publish' );
		Agend_Test_WP::$options['agend_elementor_courses_page_id'] = 5;

		$this->assertSame( 0, Agend_Elementor_Pages::page_id( 'course' ) );
	}

	#[Test]
	public function should_report_dedicated_page_true_when_id_matches_configured_page(): void {
		$this->seedPage( 5 );
		Agend_Test_WP::$options['agend_elementor_events_page_id'] = 5;

		$this->assertTrue( Agend_Elementor_Pages::is_dedicated_page( 'event', 5 ) );
	}

	#[Test]
	public function should_report_dedicated_page_false_when_id_differs_from_configured_page(): void {
		$this->seedPage( 5 );
		Agend_Test_WP::$options['agend_elementor_events_page_id'] = 5;

		$this->assertFalse( Agend_Elementor_Pages::is_dedicated_page( 'event', 9 ) );
	}

	#[Test]
	public function should_report_dedicated_page_false_when_type_is_unconfigured(): void {
		$this->assertFalse( Agend_Elementor_Pages::is_dedicated_page( 'course', 5 ) );
	}

	#[Test]
	public function should_return_empty_page_url_when_type_is_unconfigured(): void {
		$this->assertSame( '', Agend_Elementor_Pages::page_url( 'course' ) );
	}

	#[Test]
	public function should_honour_the_detail_url_filter(): void {
		$this->seedPage( 5 );
		Agend_Test_WP::$options['agend_elementor_events_page_id'] = 5;
		Agend_Test_WP::set_filter( 'agend_elementor_detail_url', 'https://example.test/custom-override/' );

		$url = Agend_Elementor_Pages::detail_url( 'event', 'my-slug' );

		$this->assertSame( 'https://example.test/custom-override/', $url );
	}

	#[Test]
	public function should_read_detail_template_option_and_return_zero_for_unknown_type(): void {
		Agend_Test_WP::$options['agend_elementor_event_detail_template'] = '77';

		$this->assertSame( 77, Agend_Elementor_Pages::detail_template_id( 'event' ) );
		$this->assertSame( 0, Agend_Elementor_Pages::detail_template_id( 'course' ) );
		$this->assertSame( 0, Agend_Elementor_Pages::detail_template_id( 'listing' ) );
	}
}

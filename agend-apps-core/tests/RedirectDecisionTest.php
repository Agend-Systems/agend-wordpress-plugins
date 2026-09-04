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

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/class-agend-elementor-settings.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/class-agend-elementor-pages.php';

/**
 * `Agend_Elementor_Pages::redirect_target()`: the pure decision behind the
 * wp:4 redirect (`agend_elementor_maybe_redirect_to_dedicated_page()`). The
 * hook function itself is not exercised here: it ends in `wp_safe_redirect()`
 * + `exit`, which would terminate the test process, so only the side-effect-
 * free decision it delegates to is covered.
 */
#[CoversClass( Agend_Elementor_Pages::class )]
final class RedirectDecisionTest extends TestCase {

	private function seedPage( int $id ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array(
			'ID'          => $id,
			'post_type'   => 'page',
			'post_status' => 'publish',
		);
	}

	#[Test]
	public function should_redirect_to_the_dedicated_page_when_on_another_page(): void {
		$this->seedPage( 5 );
		Agend_Test_WP::$options['agend_elementor_events_page_id'] = 5;
		Agend_Test_WP::$options['permalink_structure']            = '/%postname%/';

		$target = Agend_Elementor_Pages::redirect_target( 'event', 'my-slug', 9 );

		$this->assertSame( 'https://example.test/page-5/event/my-slug/', $target );
	}

	#[Test]
	public function should_return_empty_when_already_on_the_dedicated_page(): void {
		$this->seedPage( 5 );
		Agend_Test_WP::$options['agend_elementor_events_page_id'] = 5;

		$target = Agend_Elementor_Pages::redirect_target( 'event', 'my-slug', 5 );

		$this->assertSame( '', $target );
	}

	#[Test]
	public function should_return_empty_when_the_type_is_unconfigured(): void {
		$target = Agend_Elementor_Pages::redirect_target( 'course', 'my-slug', 9 );

		$this->assertSame( '', $target );
	}

	#[Test]
	public function should_return_empty_for_an_empty_slug(): void {
		$this->seedPage( 5 );
		Agend_Test_WP::$options['agend_elementor_events_page_id'] = 5;

		$target = Agend_Elementor_Pages::redirect_target( 'event', '', 9 );

		$this->assertSame( '', $target );
	}
}

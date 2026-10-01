<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/pages.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/directory-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/speculation.php';

/**
 * Opening a directory profile: cards navigate to the server-rendered
 * template rather than drawing the built-in detail in place, and the page is
 * prefetched while the visitor hovers its link.
 */
final class ProfileSpeedTest extends TestCase {

	#[Test]
	public function should_open_listings_in_place_when_nothing_server_renders_them(): void {
		$this->assertFalse( agend_apps_records_directory_catalogue_ssr_detail() );
	}

	#[Test]
	public function should_navigate_to_the_listing_detail_template_when_one_is_set(): void {
		update_option( AGEND_APPS_RECORDS_LISTING_DETAIL_TEMPLATE_OPTION, 42 );

		$this->assertTrue( agend_apps_records_directory_catalogue_ssr_detail() );
	}

	#[Test]
	public function should_navigate_when_server_rendered_detail_pages_are_on(): void {
		update_option( AGEND_APPS_RECORDS_SSR_DETAIL_OPTION, '1' );

		$this->assertTrue( agend_apps_records_directory_catalogue_ssr_detail() );
	}

	#[Test]
	public function should_prefetch_agend_detail_links_on_hover(): void {
		$rules = new class() {
			/** @var array<int, array{0: string, 1: string, 2: array}> */
			public array $added = array();
			public function add_rule( string $mode, string $id, array $rule ): bool {
				$this->added[] = array( $mode, $id, $rule );
				return true;
			}
		};

		agend_apps_records_add_detail_speculation_rule( $rules );

		$this->assertCount( 1, $rules->added );
		list( $mode, $id, $rule ) = $rules->added[0];
		$this->assertSame( 'prefetch', $mode );
		$this->assertMatchesRegularExpression( '/^[a-z][a-z0-9_-]+$/', $id, 'WordPress rejects any other rule id' );
		$this->assertSame( 'moderate', $rule['eagerness'] );
		$this->assertStringContainsString( 'a.agend-card-link', $rule['where']['selector_matches'] );
		$this->assertStringContainsString( 'a.agend-map-popup__link', $rule['where']['selector_matches'] );
		$this->assertArrayNotHasKey( 'urls', $rule, 'a rule has either where or urls, never both' );
	}

	#[Test]
	public function should_let_a_site_turn_hover_prefetching_off(): void {
		\Agend_Test_WP::set_filter( 'agend_apps_records_detail_link_selector', '' );
		$rules = new class() {
			public int $calls = 0;
			public function add_rule(): bool {
				++$this->calls;
				return true;
			}
		};

		agend_apps_records_add_detail_speculation_rule( $rules );

		$this->assertSame( 0, $rules->calls );
	}
}

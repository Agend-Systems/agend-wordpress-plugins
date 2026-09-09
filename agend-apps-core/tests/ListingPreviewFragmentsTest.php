<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/preview-records.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/ssr-detail.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fragments.php';

/**
 * Every Directory content block, rendered against the listing preview record.
 *
 * The Agend Content Block widget shows "This record has nothing to show for
 * this block" when its fragment returns nothing, so a preview record missing
 * a key leaves a template author looking at a wall of notices instead of the
 * panel they are laying out. This pins the preview record to the keys the
 * blocks actually read.
 */
final class ListingPreviewFragmentsTest extends TestCase {

	/**
	 * Block key => fragment function, as the Content Block widget maps them.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function listingBlocks(): array {
		$blocks = array(
			'listing_about',
			'listing_contact',
			'listing_categories',
			'listing_tags',
			'listing_locations',
			'listing_hours',
			'listing_custom_fields',
			'listing_achievements',
			'listing_reviews',
		);

		return array_combine(
			$blocks,
			array_map( static fn( string $block ): array => array( $block ), $blocks )
		);
	}

	#[Test]
	#[DataProvider( 'listingBlocks' )]
	public function should_render_a_populated_panel_for_every_directory_block( string $block ): void {
		$record   = \agend_apps_records_preview_placeholder_record( 'listing' );
		$extra    = \agend_apps_records_preview_extra( 'listing', $record );
		$fragment = 'agend_apps_records_fragment_' . $block;

		$html = 'listing_reviews' === $block
			? $fragment( $record, (string) $extra['slug'], $extra )
			: $fragment( $record );

		$this->assertNotSame( '', trim( (string) $html ), $block . ' previews empty' );
	}

	#[Test]
	public function should_preview_the_reviews_block_without_a_gateway_call(): void {
		$record = \agend_apps_records_preview_placeholder_record( 'listing' );
		$extra  = \agend_apps_records_preview_extra( 'listing', $record );

		// agend_apps_directory_get_listing_reviews() is undefined in this
		// suite, so a fragment that fell through to fetching would fatal
		// rather than quietly return an empty list.
		$this->assertFalse( function_exists( 'agend_apps_directory_get_listing_reviews' ) );

		$html = \agend_apps_records_fragment_listing_reviews( $record, (string) $extra['slug'], $extra );

		$this->assertStringContainsString( 'Sample Reviewer', $html );
	}
}

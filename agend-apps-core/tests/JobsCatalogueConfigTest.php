<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The jobs catalogue's client config, built from the surface settings in
 * either editor's shape. One surface is both the full board and a featured
 * strip, so the settings that switch between the two are pinned here.
 */
final class JobsCatalogueConfigTest extends TestCase {

	/**
	 * Loads only the config builder; it needs no gateway (see
	 * MembershipsCatalogueUrlShapeTest for why the render doubles stay out).
	 */
	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/jobs-catalogue.php';
	}

	#[Test]
	public function should_default_to_a_full_board_in_a_single_column_list(): void {
		$config = agend_apps_records_jobs_catalogue_build_config( array() );

		self::assertFalse( $config['listing']['featuredOnly'] );
		self::assertSame( '', $config['listing']['category'] );
		self::assertSame( 'published_at', $config['listing']['sortBy'] );
		self::assertSame( 'desc', $config['listing']['sortOrder'] );
		self::assertSame( 'list', $config['layout']['style'] );
		self::assertSame( 1, $config['layout']['desktop'] );
		self::assertTrue( $config['filters']['search'] );
		self::assertTrue( $config['filters']['category'] );
		self::assertTrue( $config['filters']['type'] );
		self::assertTrue( $config['filters']['location'] );
		self::assertSame( 10, $config['pagination']['perPage'] );
		self::assertSame( 'Apply now', $config['detail']['applyLabel'] );
		self::assertTrue( $config['detail']['emailApply'] );
	}

	#[Test]
	public function should_show_only_featured_jobs_when_the_featured_toggle_is_on(): void {
		$config = agend_apps_records_jobs_catalogue_build_config( array( 'featured_only' => 'yes' ) );

		self::assertTrue( $config['listing']['featuredOnly'] );
	}

	#[Test]
	public function should_hide_the_visitor_category_filter_when_the_board_is_pinned_to_one_category(): void {
		$config = agend_apps_records_jobs_catalogue_build_config(
			array(
				'category'             => 'Financial Planning',
				'show_category_filter' => 'yes',
			)
		);

		self::assertSame( 'financial-planning', $config['listing']['category'] );
		self::assertFalse( $config['filters']['category'] );
	}

	#[Test]
	public function should_map_each_offered_order_to_a_gateway_sort(): void {
		$config = agend_apps_records_jobs_catalogue_build_config( array( 'sort' => 'expires_at:asc' ) );

		self::assertSame( 'expires_at', $config['listing']['sortBy'] );
		self::assertSame( 'asc', $config['listing']['sortOrder'] );
	}

	#[Test]
	public function should_fall_back_to_newest_first_for_an_order_it_does_not_offer(): void {
		$config = agend_apps_records_jobs_catalogue_build_config( array( 'sort' => 'view_count:desc' ) );

		self::assertSame( 'published_at', $config['listing']['sortBy'] );
		self::assertSame( 'desc', $config['listing']['sortOrder'] );
	}

	#[Test]
	public function should_use_the_column_settings_only_in_the_grid_layout(): void {
		$grid = agend_apps_records_jobs_catalogue_build_config(
			array(
				'layout_style'    => 'grid',
				'columns_desktop' => '4',
				'columns_tablet'  => '2',
			)
		);
		$list = agend_apps_records_jobs_catalogue_build_config(
			array(
				'layout_style'    => 'list',
				'columns_desktop' => '4',
			)
		);

		self::assertSame( 4, $grid['layout']['desktop'] );
		self::assertSame( 2, $grid['layout']['tablet'] );
		self::assertSame( 1, $list['layout']['desktop'] );
	}

	#[Test]
	public function should_keep_the_page_size_within_what_the_gateway_accepts(): void {
		self::assertSame( 100, agend_apps_records_jobs_catalogue_build_config( array( 'per_page' => 500 ) )['pagination']['perPage'] );
		self::assertSame( 1, agend_apps_records_jobs_catalogue_build_config( array( 'per_page' => 0 ) )['pagination']['perPage'] );
	}

	#[Test]
	public function should_fall_back_to_the_default_apply_label_when_it_is_blank(): void {
		$config = agend_apps_records_jobs_catalogue_build_config( array( 'apply_label' => '   ' ) );

		self::assertSame( 'Apply now', $config['detail']['applyLabel'] );
	}

	#[Test]
	public function should_read_block_toggles_stored_as_yes_or_empty(): void {
		$config = agend_apps_records_jobs_catalogue_build_config(
			array(
				'show_salary'       => '',
				'allow_email_apply' => '',
			)
		);

		self::assertFalse( $config['card']['salary'] );
		self::assertFalse( $config['detail']['emailApply'] );
	}
}

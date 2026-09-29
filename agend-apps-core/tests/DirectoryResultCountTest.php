<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Test;

#[CoversFunction( 'agend_apps_records_directory_catalogue_count_text' )]
#[CoversFunction( 'agend_apps_records_directory_catalogue_build_config' )]
final class DirectoryResultCountTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/directory-catalogue.php';
	}

	#[Test]
	public function should_count_the_listings_on_the_current_page(): void {
		self::assertSame(
			'Showing 13–24 of 56 members',
			agend_apps_records_directory_catalogue_count_text( 'Showing {from}–{to} of {total} members', array( 'page' => 2, 'total_pages' => 5, 'total' => 56 ), 12 )
		);
	}

	#[Test]
	public function should_stop_at_the_total_on_the_last_page(): void {
		self::assertSame(
			'49–56 of 56',
			agend_apps_records_directory_catalogue_count_text( '{from}–{to} of {total}', array( 'page' => 5, 'total_pages' => 5, 'total' => 56 ), 12 )
		);
	}

	#[Test]
	public function should_say_nothing_when_nothing_matches(): void {
		self::assertSame( '', agend_apps_records_directory_catalogue_count_text( '{total}', array( 'page' => 1, 'total_pages' => 0, 'total' => 0 ), 12 ) );
		self::assertSame( '', agend_apps_records_directory_catalogue_count_text( '{total}', null, 12 ) );
	}

	#[Test]
	public function should_prefer_the_limit_the_gateway_reports_when_it_sends_one(): void {
		self::assertSame(
			'11–20 of 56',
			agend_apps_records_directory_catalogue_count_text( '{from}–{to} of {total}', array( 'page' => 2, 'limit' => 10, 'total_pages' => 6, 'total' => 56 ), 12 )
		);
	}

	#[Test]
	public function should_add_the_count_to_the_config_only_when_switched_on(): void {
		self::assertArrayNotHasKey( 'resultCount', agend_apps_records_directory_catalogue_build_config( array() ) );

		$config = agend_apps_records_directory_catalogue_build_config( array( 'show_result_count' => 'yes', 'result_count_text' => '{total} members' ) );
		self::assertSame( array( 'text' => '{total} members' ), $config['resultCount'] );
	}
}

<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Block_Template_Source;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

/**
 * Which record type a templated surface's editor preview renders against is
 * resolved on the server, by `agend_apps_records_surface_preview_type()`.
 *
 * It lives in PHP deliberately: step one is
 * `agend_apps_records_type_from_key()`'s rule, and an editor-side copy of it
 * would be the same rule in two languages. These tests pin the resolution
 * ORDER, which is the part a reader cannot infer from either step alone.
 */
#[RunTestsInSeparateProcesses]
final class SurfacePreviewTypeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/interface-agend-apps-template-source.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/class-agend-apps-block-template-source.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/blocks.php';
		$GLOBALS['agend_test_post_meta'] = array();
	}

	/** @return array<string, array{string, string}> */
	public static function fieldKeys(): array {
		return array(
			'listing key'      => array( 'listing:name', 'listing' ),
			'event key'        => array( 'event:name', 'event' ),
			'course key'       => array( 'course:name', 'course' ),
			'underscored key'  => array( 'event_tickets', 'event' ),
			'common names none' => array( 'common:title', '' ),
			'unknown prefix'   => array( 'nonsense:thing', '' ),
		);
	}

	#[Test]
	#[DataProvider( 'fieldKeys' )]
	public function should_take_the_type_the_field_key_names( string $key, string $expected ): void {
		self::assertSame( $expected, agend_apps_records_surface_preview_type( array( 'field' => $key ) ) );
	}

	#[Test]
	public function should_read_the_panel_key_when_the_surface_has_one_instead_of_a_field(): void {
		self::assertSame( 'event', agend_apps_records_surface_preview_type( array( 'block' => 'event_tickets' ) ) );
	}

	#[Test]
	public function should_fall_back_to_the_template_type_when_the_key_names_none(): void {
		update_post_meta( 7, Agend_Apps_Block_Template_Source::TYPE_META_KEY, 'listing' );

		self::assertSame(
			'listing',
			agend_apps_records_surface_preview_type( array( 'field' => 'common:title' ), 7 ),
			'a common: field in a directory template should preview a listing'
		);
	}

	#[Test]
	public function should_prefer_the_field_key_over_the_template_type(): void {
		update_post_meta( 7, Agend_Apps_Block_Template_Source::TYPE_META_KEY, 'listing' );

		self::assertSame(
			'course',
			agend_apps_records_surface_preview_type( array( 'field' => 'course:name' ), 7 ),
			'the surface names its own type, so the template does not override it'
		);
	}

	#[Test]
	public function should_ignore_a_template_type_that_is_not_a_known_record_type(): void {
		update_post_meta( 7, Agend_Apps_Block_Template_Source::TYPE_META_KEY, 'directory' );

		self::assertSame( '', agend_apps_records_surface_preview_type( array(), 7 ) );
	}

	#[Test]
	public function should_resolve_to_nothing_when_no_post_is_open(): void {
		self::assertSame(
			'',
			agend_apps_records_surface_preview_type( array( 'field' => 'common:title' ), 0 ),
			'0 means no post-editing context, so there is no template to ask'
		);
	}
}

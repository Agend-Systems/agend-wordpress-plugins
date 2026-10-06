<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Key_Scopes;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

/**
 * The block editor side of the surface gate: a block whose key lacks what its
 * surface needs stays in the editor with a notice and a disabled preview, and
 * draws nothing, and queues nothing, for a visitor.
 */
#[RunTestsInSeparateProcesses]
final class BlockSurfaceGateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/tests/render-doubles.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/events-catalogue.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/blocks.php';
		agend_render_test_reset();
	}

	private function know( array $scopes ): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => $scopes ) ) );
	}

	#[Test]
	public function should_render_nothing_and_drop_the_view_script_when_gated(): void {
		register_block_type(
			'agend-apps/events-catalogue',
			array(
				'viewScript' => array( 'agend-apps-records-events-catalogue' ),
				'style'      => array( 'agend-apps-records-events-catalogue' ),
			)
		);
		wp_enqueue_script( 'agend-apps-records-events-catalogue' );
		wp_enqueue_style( 'agend-apps-records-events-catalogue' );

		self::assertNotSame( '', agend_apps_records_render_block( 'events-catalogue', array() ), 'unknown scopes: renders' );

		$this->know( array( 'crm.contacts.browse' ) );
		Agend_Test_WP::$requests = array();

		self::assertSame( '', agend_apps_records_render_block( 'events-catalogue', array() ) );
		self::assertSame( array(), Agend_Test_WP::$enqueued_scripts );
		self::assertSame( array(), Agend_Test_WP::$enqueued_styles );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_tell_the_editor_why_in_the_block_notices(): void {
		self::assertSame( array(), agend_apps_records_block_surface_notices( 'events-catalogue' ) );

		$this->know( array( 'crm.contacts.browse' ) );

		$notices = agend_apps_records_block_surface_notices( 'events-catalogue' );

		self::assertCount( 1, $notices );
		self::assertSame( 'warning', $notices[0]['status'] );
		self::assertStringContainsString( 'events.events.browse', $notices[0]['text'] );
		self::assertStringContainsString( 'renders nothing for visitors', $notices[0]['text'] );
		self::assertSame( array(), agend_apps_records_block_surface_notices( 'filter' ), 'surfaces off the map are unaffected' );
	}
}

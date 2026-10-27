<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Blocks;
use Agend_Content_Access_Frontend;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/BlockEnforcementTestCase.php';

/**
 * Elementor's preview flag, and what it does to blocks.
 *
 * `?elementor-preview=<id>` is a query-string value anyone can append to any URL,
 * and Elementor only checks that the user may edit the id it carries. The block
 * driver stands down for an author, so a flag it honoured on any page would let a
 * contributor read every restricted block of a page by appending the id of their
 * own draft. It is honoured on the page it names, and for the blocks of that page.
 */
#[CoversClass( Agend_Content_Access_Frontend::class )]
#[CoversClass( Agend_Content_Access_Blocks::class )]
final class BlockPreviewCarveOutTest extends BlockEnforcementTestCase {

	private const OWN        = 42;
	private const RESTRICTED = 300;

	/** @var object Preview double the suite installed, put back after each test. */
	private $original_preview;

	protected function setUp(): void {
		parent::setUp();

		$this->original_preview = \Elementor\Plugin::$instance->preview;

		Agend_Test_WP::$did_action['elementor/loaded'] = 1;

		// Elementor, which has checked the edit right on the id it was handed and
		// compared it with the query string, as the real one does.
		\Elementor\Plugin::$instance->preview = new class() {
			public bool $is_preview_mode = false;

			public function is_preview_mode( $post_id = 0 ): bool {
				return isset( $_GET['elementor-preview'] )
					&& 42 === (int) $post_id
					&& 42 === (int) $_GET['elementor-preview'];
			}
		};

		$this->registerPost( self::OWN, $this->secret() );
		$this->registerPost( self::RESTRICTED, $this->secret() );

		$this->asContributor();
	}

	protected function tearDown(): void {
		unset( $_GET['elementor-preview'] );

		\Elementor\Plugin::$instance->preview = $this->original_preview;

		parent::tearDown();
	}

	private function secret(): string {
		return $this->paragraph( 'SECRET', array( 'mode' => 'active_member' ) );
	}

	/** The request is for this page, singular, the way `template_redirect` sees it. */
	private function requestPage( int $id ): void {
		Agend_Test_WP::$queried_object_id     = $id;
		$GLOBALS['agend_test_is_singular']    = true;
		$GLOBALS['agend_test_queried_object'] = get_post( $id );
	}

	// -----------------------------------------------------------------------
	// The flag on a page it does not name
	// -----------------------------------------------------------------------

	#[Test]
	public function a_preview_id_for_another_post_does_not_stand_the_block_driver_down(): void {
		$this->requestPage( self::RESTRICTED );
		$_GET['elementor-preview'] = (string) self::OWN;

		$this->assertSame( '', $this->renderPost( self::RESTRICTED ) );
	}

	#[Test]
	public function the_same_flag_on_the_page_it_names_still_shows_the_author_their_blocks(): void {
		$this->requestPage( self::OWN );
		$_GET['elementor-preview'] = (string) self::OWN;

		$this->assertSame( '<p>SECRET</p>', $this->renderPost( self::OWN ) );
	}

	#[Test]
	public function without_the_flag_the_authors_own_page_is_filtered_like_any_other(): void {
		$this->requestPage( self::OWN );

		$this->assertSame( '', $this->renderPost( self::OWN ) );
	}

	/** `absint()` turns any non-empty array into 1, so an array would read as the id of post 1. */
	#[Test]
	public function an_array_is_not_a_preview_id(): void {
		\Elementor\Plugin::$instance->preview = new class() {
			public bool $is_preview_mode = false;

			public function is_preview_mode( $post_id = 0 ): bool {
				return true;
			}
		};
		$this->registerPost( 1, $this->secret() );
		$this->requestPage( 1 );
		$_GET['elementor-preview'] = array( 'x' );

		$this->assertSame( '', $this->renderPost( 1 ) );
	}
}

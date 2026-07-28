<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Elementor as ElementorControl;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-elementor.php';

/**
 * Replacement content (SPEC-CMS-20260727 US-6.1, ESAC parity).
 *
 * A fallback renders to visitors the conditions just EXCLUDED, which makes the
 * fallback itself a disclosure surface. The reference implementation renders
 * whatever post was chosen with no check at all, so picking a members-only page
 * as the fallback for a members-only section shows it to precisely the people
 * who were refused. These tests exist to keep that closed.
 */
#[CoversClass( ElementorControl::class )]
final class FallbackContentTest extends TestCase {

	private const FALLBACK_ID = 4242;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['agend_test_current_user_id'] = 0;
		$GLOBALS['agend_test_posts']           = array();
		$GLOBALS['agend_test_post_meta']       = array();
	}

	/** Registers a published post the fallback can point at. */
	private function publishFallback( ?array $policy ): void {
		$GLOBALS['agend_test_posts'][ self::FALLBACK_ID ] = (object) array(
			'ID'           => self::FALLBACK_ID,
			'post_status'  => 'publish',
			'post_content' => 'SIGN-IN-PROMPT-CONTENT',
			'post_title'   => 'Sign in prompt',
			'post_type'    => 'page',
		);

		if ( null !== $policy ) {
			$GLOBALS['agend_test_post_meta'][ self::FALLBACK_ID ] = array(
				'_agend_access_policy' => $policy,
			);
		}
	}

	#[Test]
	public function a_public_fallback_renders(): void {
		$this->publishFallback( null );

		$this->assertStringContainsString(
			'SIGN-IN-PROMPT-CONTENT',
			ElementorControl::fallback_html( self::FALLBACK_ID )
		);
	}

	/**
	 * The disclosure the reference walks into. A restricted fallback shown to a
	 * visitor who just failed the conditions is the exact audience it was meant
	 * to be kept from, and it would look like a thoughtful "here is what you
	 * are missing" until somebody noticed.
	 */
	#[Test]
	public function a_members_only_fallback_is_never_shown_to_a_visitor_who_cannot_access_it(): void {
		$this->publishFallback( array( 'mode' => 'active_member' ) );

		$this->assertSame( '', ElementorControl::fallback_html( self::FALLBACK_ID ) );
	}

	#[Test]
	public function a_plan_restricted_fallback_is_withheld_from_a_non_member(): void {
		$this->publishFallback(
			array(
				'mode'     => 'selected_tiers',
				'tier_ids' => array( '11111111-1111-1111-1111-111111111111' ),
			)
		);

		$this->assertSame( '', ElementorControl::fallback_html( self::FALLBACK_ID ) );
	}

	#[Test]
	public function an_unpublished_fallback_renders_nothing(): void {
		$this->publishFallback( null );
		$GLOBALS['agend_test_posts'][ self::FALLBACK_ID ]->post_status = 'draft';

		$this->assertSame( '', ElementorControl::fallback_html( self::FALLBACK_ID ) );
	}

	#[Test]
	public function a_missing_fallback_renders_nothing(): void {
		$this->assertSame( '', ElementorControl::fallback_html( 999999 ) );
	}

	/**
	 * The picker offers public content only, but a page can be restricted AFTER
	 * being chosen. The render-time check is what covers that, and this is the
	 * assertion that the two are genuinely independent.
	 */
	#[Test]
	public function a_fallback_restricted_after_being_chosen_stops_rendering(): void {
		$this->publishFallback( null );

		$this->assertStringContainsString(
			'SIGN-IN-PROMPT-CONTENT',
			ElementorControl::fallback_html( self::FALLBACK_ID )
		);

		// An editor restricts it later.
		$GLOBALS['agend_test_post_meta'][ self::FALLBACK_ID ] = array(
			'_agend_access_policy' => array( 'mode' => 'active_member' ),
		);

		$this->assertSame( '', ElementorControl::fallback_html( self::FALLBACK_ID ) );
	}
}

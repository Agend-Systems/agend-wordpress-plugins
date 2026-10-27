<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Condition_Sets;
use Agend_Content_Access_Elementor as ElementorControl;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-sets.php';
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
#[CoversClass( Agend_Content_Access_Condition_Sets::class )]
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

	/**
	 * A fallback is rendered straight from `post_content`, by a route that never
	 * asks for the password, so a protected one would be handed to every visitor
	 * the conditions refused.
	 */
	#[Test]
	public function a_password_protected_fallback_renders_nothing(): void {
		$this->publishFallback( null );
		$GLOBALS['agend_test_posts'][ self::FALLBACK_ID ]->post_password = 'hunter2';

		$this->assertSame( '', ElementorControl::fallback_html( self::FALLBACK_ID ) );
	}

	#[Test]
	public function a_fallback_that_gains_a_password_after_being_chosen_stops_rendering(): void {
		$this->publishFallback( null );

		$this->assertStringContainsString( 'SIGN-IN-PROMPT-CONTENT', ElementorControl::fallback_html( self::FALLBACK_ID ) );

		$GLOBALS['agend_test_posts'][ self::FALLBACK_ID ]->post_password = 'hunter2';

		$this->assertSame( '', ElementorControl::fallback_html( self::FALLBACK_ID ) );
	}

	#[Test]
	public function an_empty_password_is_no_password(): void {
		$this->publishFallback( null );
		$GLOBALS['agend_test_posts'][ self::FALLBACK_ID ]->post_password = '';

		$this->assertStringContainsString( 'SIGN-IN-PROMPT-CONTENT', ElementorControl::fallback_html( self::FALLBACK_ID ) );
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

	// -----------------------------------------------------------------------
	// A fallback whose stored policy cannot be read
	// -----------------------------------------------------------------------

	/**
	 * Stored values this version cannot read. 0.1.0 read each as "no policy", so
	 * the page rendered to the very visitors it was meant to be kept from.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function unreadableValues(): array {
		return array(
			'a gateway mode this plugin lacks' => array( array( 'mode' => 'authenticated' ) ),
			'segments'                         => array( array( 'mode' => 'segments', 'segment_ids' => array() ) ),
			'selected entitlements'            => array( array( 'mode' => 'selected_entitlements', 'entitlement_ids' => array() ) ),
			'a plan id that is not a uuid'     => array( array( 'mode' => 'selected_tiers', 'tier_ids' => array( 'not-a-uuid' ) ) ),
			'an extra top level key'           => array( array( 'mode' => 'public', 'note' => 'smuggled' ) ),
		);
	}

	#[Test]
	#[DataProvider( 'unreadableValues' )]
	public function a_fallback_with_an_unreadable_stored_policy_is_refused( $value ): void {
		$this->publishFallback( null );
		$GLOBALS['agend_test_post_meta'][ self::FALLBACK_ID ] = array( '_agend_access_policy' => $value );

		$this->assertSame( '', ElementorControl::fallback_html( self::FALLBACK_ID ) );
	}

	#[Test]
	public function the_picker_does_not_offer_a_password_protected_page(): void {
		$register = static function ( int $id, string $title, string $password ): void {
			$GLOBALS['agend_test_posts'][ $id ] = (object) array(
				'ID'            => $id,
				'post_status'   => 'publish',
				'post_title'    => $title,
				'post_type'     => 'page',
				'post_password' => $password,
			);
		};

		$register( 1, 'Open page', '' );
		$register( 2, 'Protected page', 'hunter2' );

		$options = Agend_Content_Access_Condition_Sets::fallback_options();

		$this->assertContains( 'Open page', $options );
		$this->assertNotContains( 'Protected page', $options );
	}

	#[Test]
	public function the_picker_offers_public_and_unset_pages_but_not_a_page_with_an_unreadable_policy(): void {
		$register = static function ( int $id, string $title, $policy ): void {
			$GLOBALS['agend_test_posts'][ $id ] = (object) array(
				'ID'          => $id,
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_type'   => 'page',
			);

			if ( null !== $policy ) {
				$GLOBALS['agend_test_post_meta'][ $id ] = array( '_agend_access_policy' => $policy );
			}
		};

		$register( 1, 'Unset page', null );
		$register( 2, 'Public page', array( 'mode' => 'public' ) );
		$register( 3, 'Members page', array( 'mode' => 'active_member' ) );
		$register( 4, 'Unreadable page', array( 'mode' => 'segments', 'segment_ids' => array() ) );
		$register( 5, 'Cleared page', '' );

		$options = Agend_Content_Access_Condition_Sets::fallback_options();

		// Sorted by title: Cleared, Public, Unset.
		$this->assertSame(
			array( '', '5', '2', '1' ),
			array_map( 'strval', array_keys( $options ) )
		);
		$this->assertNotContains( 'Unreadable page', $options );
		$this->assertNotContains( 'Members page', $options );
	}
}

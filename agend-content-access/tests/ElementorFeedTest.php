<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Decision;
use Agend_Content_Access_Elementor as ElementorControl;
use Agend_Content_Access_Policy;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-elementor.php';

/**
 * An Elementor section inside a feed item is judged as an anonymous reader.
 *
 * The feed filters evaluate a restricted item as nobody in particular, because a
 * feed is fetched without a session and cached for everybody after. The sections
 * of an Elementor document rendered into that item were judged for whoever
 * fetched it, so a member's fetch, or any fetch that carried a login cookie,
 * rendered the members-only sections into a response the next reader is served.
 */
#[CoversClass( ElementorControl::class )]
#[CoversClass( Agend_Content_Access_Decision::class )]
#[CoversClass( \Agend_Content_Access_Condition_Runtime::class )]
final class ElementorFeedTest extends TestCase {

	private const TIER = '11111111-1111-1111-1111-111111111111';

	private const PUBLIC_FALLBACK  = 88;
	private const MEMBERS_FALLBACK = 89;

	/** @var int Identity lookups made while judging a section. */
	private int $identity_lookups = 0;

	protected function setUp(): void {
		parent::setUp();

		Agend_Test_WP::$did_action['elementor/loaded'] = 1;

		$this->identity_lookups = 0;

		$this->fallback( self::PUBLIC_FALLBACK, 'PUBLIC-FALLBACK-CONTENT', array( 'mode' => 'public' ) );
		$this->fallback( self::MEMBERS_FALLBACK, 'MEMBERS-FALLBACK-CONTENT', array( 'mode' => 'active_member' ) );

		$this->signedInMember();
	}

	// -----------------------------------------------------------------------
	// Fixtures
	// -----------------------------------------------------------------------

	private function fallback( int $id, string $body, array $policy ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array(
			'ID'           => $id,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_title'   => 'Fallback ' . $id,
			'post_content' => $body,
		);

		$GLOBALS['agend_test_post_meta'][ $id ][ Agend_Content_Access_Policy::META_KEY ] = $policy;
	}

	/** A signed-in editor-role user who holds a plan, and the lookups made on their behalf. */
	private function signedInMember(): void {
		$GLOBALS['agend_test_current_user_id']    = 5;
		$GLOBALS['agend_test_current_user_roles'] = array( 'editor' );

		Agend_Test_WP::$filters['agend_apps_bearer_token'] = function (): string {
			++$this->identity_lookups;

			return 'member-token';
		};

		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array(
				'data' => array(
					'is_member' => true,
					'tier_ids'  => array( self::TIER ),
				),
			)
		);
	}

	private function feed(): void {
		$GLOBALS['agend_test_is_feed'] = true;
	}

	private function element( array $settings ): object {
		return new class( $settings ) {
			/** @var array<string, mixed> */
			private array $settings;

			public function __construct( array $settings ) {
				$this->settings = $settings;
			}

			public function get_settings_for_display(): array {
				return $this->settings;
			}
		};
	}

	private function members(): object {
		return $this->element( array( ElementorControl::MODE_KEY => 'active_member' ) );
	}

	/** Shown only to a signed-in editor, with a fallback for everybody else. */
	private function conditioned( int $fallback_id, string $condition = 'agend_wordpress:role_editor' ): object {
		return $this->element(
			array(
				ElementorControl::CONDITIONS_ENABLED_KEY  => 'yes',
				ElementorControl::CONDITIONS_KEY          => array( $condition ),
				ElementorControl::CONDITIONS_MATCH_KEY    => 'any',
				ElementorControl::CONDITIONS_FALLBACK_KEY => (string) $fallback_id,
			)
		);
	}

	private function fallbackOutput( object $element ): string {
		ob_start();
		( new ElementorControl() )->maybe_render_fallback( $element );

		return (string) ob_get_clean();
	}

	// -----------------------------------------------------------------------
	// The policy
	// -----------------------------------------------------------------------

	/** The control: the same member on an ordinary page is shown the section. */
	#[Test]
	public function a_member_is_shown_a_members_section_on_an_ordinary_page(): void {
		$this->assertTrue( ( new ElementorControl() )->maybe_suppress( true, $this->members() ) );
	}

	#[Test]
	public function a_members_section_is_suppressed_in_a_feed_whoever_fetched_it(): void {
		$this->feed();

		$this->assertFalse( ( new ElementorControl() )->maybe_suppress( true, $this->members() ) );
	}

	#[Test]
	public function a_feed_judges_a_section_without_looking_the_reader_up(): void {
		$this->feed();

		( new ElementorControl() )->maybe_suppress( true, $this->members() );

		$this->assertSame( 0, $this->identity_lookups, 'a feed response is shared, so nothing about its fetcher may shape it' );
	}

	#[Test]
	public function a_section_that_inherits_the_page_and_one_with_no_rules_are_untouched_in_a_feed(): void {
		$this->feed();

		$inherits = $this->element( array( ElementorControl::MODE_KEY => Agend_Content_Access_Policy::MODE_INHERIT ) );

		$this->assertTrue( ( new ElementorControl() )->maybe_suppress( true, $inherits ) );
		$this->assertTrue( ( new ElementorControl() )->maybe_suppress( true, $this->element( array() ) ) );
	}

	// -----------------------------------------------------------------------
	// The display conditions
	// -----------------------------------------------------------------------

	#[Test]
	public function a_section_for_signed_in_editors_is_shown_to_one_on_an_ordinary_page(): void {
		$this->assertTrue( ( new ElementorControl() )->maybe_suppress( true, $this->conditioned( self::PUBLIC_FALLBACK ) ) );
	}

	#[Test]
	public function a_section_for_signed_in_editors_is_suppressed_in_a_feed(): void {
		$this->feed();

		$this->assertFalse( ( new ElementorControl() )->maybe_suppress( true, $this->conditioned( self::PUBLIC_FALLBACK ) ) );
	}

	#[Test]
	public function a_section_for_signed_out_visitors_is_shown_in_a_feed_to_a_signed_in_fetcher(): void {
		$this->feed();

		$this->assertTrue( ( new ElementorControl() )->maybe_suppress( true, $this->conditioned( self::PUBLIC_FALLBACK, 'agend_wordpress:logged_out' ) ) );
	}

	#[Test]
	public function a_membership_condition_fails_in_a_feed_for_a_member(): void {
		$this->feed();
		$membership = $this->conditioned( self::PUBLIC_FALLBACK, 'agend_membership:is_member' );

		$this->assertFalse( ( new ElementorControl() )->maybe_suppress( true, $membership ) );
		$this->assertSame( 0, $this->identity_lookups );
	}

	#[Test]
	public function conditions_are_judged_as_the_current_visitor_unless_asked_otherwise(): void {
		$settings = $this->conditioned( self::PUBLIC_FALLBACK )->get_settings_for_display();

		$this->assertTrue( ElementorControl::conditions_pass( $settings ) );
		$this->assertFalse( ElementorControl::conditions_pass( $settings, true ) );
	}

	// -----------------------------------------------------------------------
	// The replacement content
	// -----------------------------------------------------------------------

	#[Test]
	public function no_replacement_is_rendered_on_an_ordinary_page_for_a_visitor_who_passes(): void {
		$this->assertSame( '', $this->fallbackOutput( $this->conditioned( self::PUBLIC_FALLBACK ) ) );
	}

	#[Test]
	public function a_feed_renders_the_replacement_for_a_section_its_anonymous_reader_fails(): void {
		$this->feed();

		$this->assertStringContainsString( 'PUBLIC-FALLBACK-CONTENT', $this->fallbackOutput( $this->conditioned( self::PUBLIC_FALLBACK ) ) );
	}

	/**
	 * The fallback is itself access-checked, and for the same reader the section was.
	 * Neither a member nor an anonymous reader is an administrator, so the
	 * conditions refuse both and only the fallback's own policy tells them apart.
	 */
	#[Test]
	public function a_members_only_replacement_is_shown_to_a_member_on_a_page_but_not_in_a_feed(): void {
		$section = $this->conditioned( self::MEMBERS_FALLBACK, 'agend_wordpress:role_administrator' );

		$this->assertStringContainsString( 'MEMBERS-FALLBACK-CONTENT', $this->fallbackOutput( $section ) );
		$this->assertSame( '', ElementorControl::fallback_html( self::MEMBERS_FALLBACK, true ) );

		$this->feed();

		$this->assertSame( '', $this->fallbackOutput( $section ), 'the feed reader is anonymous, who may not read it' );
	}

	// -----------------------------------------------------------------------
	// The shared viewer
	// -----------------------------------------------------------------------

	#[Test]
	public function the_anonymous_viewer_holds_nothing_and_is_not_identified(): void {
		$this->assertSame(
			array(
				'identified' => false,
				'tier_ids'   => array(),
				'degraded'   => false,
			),
			Agend_Content_Access_Decision::anonymous_viewer()
		);
	}
}

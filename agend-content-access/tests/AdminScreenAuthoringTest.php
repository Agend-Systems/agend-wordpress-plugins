<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Elementor as ElementorControl;
use Agend_Content_Access_Fragment_Rules;
use Agend_Content_Access_Frontend;
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
 * A wp-admin screen is authoring only for the posts its user may edit.
 *
 * Every non-ajax `is_admin()` request used to count as authoring for every post,
 * so any admin screen that printed an excerpt or a body, the Posts list in its
 * extended view above all, handed a contributor the members-only text of posts
 * they cannot edit. The carve-out is now the right to edit the post being
 * rendered, and with no post named, the right to edit other people's.
 */
#[CoversClass( Agend_Content_Access_Frontend::class )]
#[CoversClass( ElementorControl::class )]
#[CoversClass( Agend_Content_Access_Fragment_Rules::class )]
final class AdminScreenAuthoringTest extends TestCase {

	private const OWN     = 10;
	private const OTHER   = 11;
	private const SECTION = 12;

	private const OWN_BODY   = 'MY OWN MEMBERS BODY';
	private const OTHER_BODY = 'SOMEBODY ELSES MEMBERS BODY';
	private const TEASER     = 'THE PUBLIC TEASER';

	private Agend_Content_Access_Frontend $frontend;

	protected function setUp(): void {
		parent::setUp();

		Agend_Test_WP::$did_action['elementor/loaded'] = 1;
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', '' );

		// The one thing a visitor may be shown of a restricted post, supplied by the
		// site so the teaser is told apart from the body an editor derives it from.
		Agend_Test_WP::set_filter( 'agend_content_access_teaser', self::TEASER );

		$this->post( self::OWN, self::OWN_BODY, '', array( 'mode' => 'active_member' ) );
		$this->post( self::OTHER, self::OTHER_BODY, '', array( 'mode' => 'active_member' ) );
		$this->post( self::SECTION, 'OPENING AND SECTION SECRET', '', null );

		$GLOBALS['agend_test_post_meta'][ self::SECTION ]['_elementor_data'] = (string) wp_json_encode(
			array(
				array(
					'id'       => 'a',
					'elType'   => 'section',
					'settings' => array( 'agend_access_mode' => 'active_member' ),
					'elements' => array(),
				),
			)
		);

		$this->frontend = new Agend_Content_Access_Frontend();
		Agend_Content_Access_Fragment_Rules::init();

		// `wp_trim_excerpt()` at priority 10: derive an excerpt from the body.
		add_filter(
			'get_the_excerpt',
			static function ( $excerpt, $post = null ) {
				return '' !== trim( (string) $excerpt ) || ! $post instanceof \WP_Post ? $excerpt : $post->post_content;
			},
			10,
			2
		);

		$GLOBALS['agend_test_is_admin'] = true;
	}

	// -----------------------------------------------------------------------
	// Fixtures
	// -----------------------------------------------------------------------

	private function post( int $id, string $body, string $excerpt, ?array $policy ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array(
			'ID'           => $id,
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_title'   => 'Post ' . $id,
			'post_content' => $body,
			'post_excerpt' => $excerpt,
		);

		if ( null !== $policy ) {
			$GLOBALS['agend_test_post_meta'][ $id ][ Agend_Content_Access_Policy::META_KEY ] = $policy;
		}
	}

	/** A contributor: edits their own post, and nobody else's. */
	private function asContributor(): void {
		$GLOBALS['agend_test_current_user_can']['edit_post']                 = false;
		$GLOBALS['agend_test_current_user_can']['edit_others_posts']         = false;
		$GLOBALS['agend_test_current_user_can_for']['edit_post'][ self::OWN ] = true;
	}

	/** An editor: edits everything. */
	private function asEditor(): void {
		$GLOBALS['agend_test_current_user_can']['edit_post']         = true;
		$GLOBALS['agend_test_current_user_can']['edit_others_posts'] = true;
	}

	/** What `get_the_excerpt()` returns for a post: the whole registered chain. */
	private function excerptOf( int $id ): string {
		$post = get_post( $id );

		return (string) Agend_Test_WP::apply_registered( 'get_the_excerpt', (string) $post->post_excerpt, $post );
	}

	/** Runs `the_content` for a post, as an admin screen printing it would. */
	private function contentOf( int $id ): string {
		$GLOBALS['post'] = get_post( $id );

		return (string) Agend_Test_WP::apply_registered( 'the_content', (string) get_post( $id )->post_content );
	}

	/** An Elementor element and the document the manager says is rendering it. */
	private function elementIn( int $document_id ): object {
		\Elementor\Plugin::$instance->documents = new class( $document_id ) {
			private int $id;

			public function __construct( int $id ) {
				$this->id = $id;
			}

			public function get_current(): object {
				return new class( $this->id ) {
					private int $id;

					public function __construct( int $id ) {
						$this->id = $id;
					}

					public function get_main_id(): int {
						return $this->id;
					}
				};
			}
		};

		return new class() {
			public function get_settings_for_display(): array {
				return array( ElementorControl::MODE_KEY => 'active_member' );
			}
		};
	}

	// -----------------------------------------------------------------------
	// A contributor on a wp-admin screen
	// -----------------------------------------------------------------------

	#[Test]
	public function a_contributor_gets_the_teaser_not_the_body_of_a_post_they_cannot_edit(): void {
		$this->asContributor();

		$this->assertSame( self::TEASER, $this->excerptOf( self::OTHER ) );
	}

	#[Test]
	public function a_contributor_gets_nothing_of_a_post_they_cannot_edit_when_no_teaser_is_set(): void {
		$this->asContributor();
		Agend_Test_WP::set_filter( 'agend_content_access_teaser', '' );

		$this->assertSame( '', $this->excerptOf( self::OTHER ) );
	}

	#[Test]
	public function a_contributor_gets_the_content_of_their_own_post(): void {
		$this->asContributor();

		$this->assertSame( self::OWN_BODY, $this->excerptOf( self::OWN ) );
		$this->assertSame( self::OWN_BODY, $this->contentOf( self::OWN ) );
	}

	#[Test]
	public function an_editor_gets_everything(): void {
		$this->asEditor();

		$this->assertSame( self::OTHER_BODY, $this->excerptOf( self::OTHER ), 'the excerpt core derived from the body, not the teaser' );
		$this->assertSame( self::OWN_BODY, $this->excerptOf( self::OWN ) );
		$this->assertSame( self::OTHER_BODY, $this->contentOf( self::OTHER ) );
		$this->assertSame( self::OWN_BODY, $this->contentOf( self::OWN ) );
	}

	#[Test]
	public function a_contributor_does_not_get_the_body_of_a_post_they_cannot_edit_from_the_content_filter(): void {
		$this->asContributor();

		$html = $this->contentOf( self::OTHER );

		$this->assertStringContainsString( 'agend-access-gate', $html );
		$this->assertStringNotContainsString( self::OTHER_BODY, $html );
	}

	#[Test]
	public function a_contributor_cannot_read_a_builder_post_excerpt_that_carries_section_rules(): void {
		$this->asContributor();

		$this->assertSame( '', Agend_Content_Access_Fragment_Rules::filter_excerpt( 'OPENING AND SECTION SECRET', get_post( self::SECTION ) ) );
	}

	#[Test]
	public function an_editor_gets_the_excerpt_of_a_builder_post_that_carries_section_rules(): void {
		$this->asEditor();

		$this->assertSame( 'OPENING AND SECTION SECRET', Agend_Content_Access_Fragment_Rules::filter_excerpt( 'OPENING AND SECTION SECRET', get_post( self::SECTION ) ) );
	}

	// -----------------------------------------------------------------------
	// The Latest Posts block names no post
	// -----------------------------------------------------------------------

	private function latestPostsMode(): string {
		$block = array(
			'blockName' => 'core/latest-posts',
			'attrs'     => array( 'displayPostContent' => true, 'displayPostContentRadio' => 'full_post' ),
		);

		$filtered = Agend_Test_WP::apply_registered( 'render_block_data', $block, $block, null );

		return (string) $filtered['attrs']['displayPostContentRadio'];
	}

	#[Test]
	public function a_contributor_has_the_latest_posts_block_switched_to_excerpts_in_wp_admin(): void {
		$this->asContributor();

		$this->assertSame( 'excerpt', $this->latestPostsMode() );
	}

	#[Test]
	public function an_editor_keeps_the_full_posts_of_a_latest_posts_block_in_wp_admin(): void {
		$this->asEditor();

		$this->assertSame( 'full_post', $this->latestPostsMode() );
	}

	// -----------------------------------------------------------------------
	// Elementor suppression
	// -----------------------------------------------------------------------

	#[Test]
	public function a_contributor_has_a_section_of_a_document_they_cannot_edit_suppressed_in_wp_admin(): void {
		$this->asContributor();

		$this->assertFalse( ( new ElementorControl() )->maybe_suppress( true, $this->elementIn( self::OTHER ) ) );
	}

	#[Test]
	public function a_contributor_keeps_the_sections_of_their_own_document_in_wp_admin(): void {
		$this->asContributor();

		$this->assertTrue( ( new ElementorControl() )->maybe_suppress( true, $this->elementIn( self::OWN ) ) );
	}

	#[Test]
	public function an_editor_keeps_every_section_in_wp_admin(): void {
		$this->asEditor();

		$this->assertTrue( ( new ElementorControl() )->maybe_suppress( true, $this->elementIn( self::OTHER ) ) );
	}

	// -----------------------------------------------------------------------
	// The question itself
	// -----------------------------------------------------------------------

	#[Test]
	public function a_wp_admin_screen_with_no_post_named_asks_for_the_right_to_edit_other_peoples_posts(): void {
		$this->asContributor();

		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_context() );
		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_for_post( 0 ) );
		$this->assertTrue( Agend_Content_Access_Frontend::renders_for_visitors() );

		$this->asEditor();

		$this->assertTrue( Agend_Content_Access_Frontend::is_authoring_context() );
		$this->assertTrue( Agend_Content_Access_Frontend::is_authoring_for_post( 0 ) );
		$this->assertFalse( Agend_Content_Access_Frontend::renders_for_visitors() );
	}

	#[Test]
	public function a_wp_admin_screen_is_authoring_for_one_post_and_not_another(): void {
		$this->asContributor();

		$this->assertTrue( Agend_Content_Access_Frontend::is_authoring_for_post( self::OWN ) );
		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_for_post( self::OTHER ) );
		$this->assertFalse( Agend_Content_Access_Frontend::renders_for_visitors( self::OWN ) );
		$this->assertTrue( Agend_Content_Access_Frontend::renders_for_visitors( self::OTHER ) );
	}

	/** The ajax rule is untouched: a visitor reaches admin-ajax.php, and it is never a screen. */
	#[Test]
	public function admin_ajax_is_still_a_visitor_render_whoever_asks(): void {
		$GLOBALS['agend_test_doing_ajax'] = true;

		foreach ( array( 'asContributor', 'asEditor' ) as $role ) {
			$this->$role();

			$this->assertTrue( Agend_Content_Access_Frontend::renders_for_visitors( self::OWN ), $role );
			$this->assertTrue( Agend_Content_Access_Frontend::renders_for_visitors(), $role );
		}
	}

	/** Not a wp-admin screen at all: the right to edit says nothing on the front end. */
	#[Test]
	public function the_front_end_is_unchanged_for_an_editor(): void {
		$this->asEditor();
		$GLOBALS['agend_test_is_admin'] = false;

		$this->assertFalse( Agend_Content_Access_Frontend::is_authoring_context() );
		$this->assertTrue( Agend_Content_Access_Frontend::renders_for_visitors( self::OTHER ) );
	}
}

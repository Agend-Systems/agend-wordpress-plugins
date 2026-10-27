<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Blocks;
use Agend_Content_Access_Policy;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use WP_Post;

require_once __DIR__ . '/has-elementor-double.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/interface-agend-content-access-document-parser.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-elementor-parser.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-elementor.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-attributes.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-blocks.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-access.php';

/**
 * What the block enforcement tests share: a visitor, a member, posts, and block
 * markup that goes through the same `do_blocks()` and `pre_render_block` chain
 * WordPress runs.
 *
 * The enforcement is exercised THROUGH `do_blocks()` / `render_block()`, not by
 * calling the callback, because what matters is what reaches output after the
 * chain has had its turn, and that a short-circuit is honoured.
 *
 * Not a test class itself: the suite discovers files ending `Test.php`.
 */
abstract class BlockEnforcementTestCase extends TestCase {

	protected const TIER_A = '11111111-1111-1111-1111-111111111111';
	protected const TIER_B = '22222222-2222-2222-2222-222222222222';
	protected const SEG_A  = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
	protected const SEG_B  = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2';
	protected const ENT_A  = 'directory.access';
	protected const ENT_B  = 'journal';

	protected const PUBLIC_PAGE = 100;
	protected const TIER_B_POST = 101;

	protected Agend_Content_Access_Blocks $blocks;

	/** @var int Calls to the bearer lookup: the identity lookup a plain block must never cause. */
	protected int $identity_lookups = 0;

	/** @var int[] Post ids passed to `agend_content_access_uncacheable_render`. */
	protected array $uncacheable = array();

	protected function setUp(): void {
		parent::setUp();

		$this->identity_lookups = 0;
		$this->uncacheable      = array();

		$this->registerPost( self::PUBLIC_PAGE, '' );
		$this->registerPost( self::TIER_B_POST, '', array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_B ) ) );

		Agend_Test_WP::$queried_object_id = self::PUBLIC_PAGE;

		$this->asVisitor();
		$this->anonymous();

		add_action(
			'agend_content_access_uncacheable_render',
			function ( int $post_id ): void {
				$this->uncacheable[] = $post_id;
			}
		);

		$this->blocks = new Agend_Content_Access_Blocks();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['agend_test_is_singular'], $GLOBALS['agend_test_queried_object'] );

		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Who is looking
	// -----------------------------------------------------------------------

	/** Cannot edit anything, which is what makes them a visitor and not an author. */
	protected function asVisitor(): void {
		$GLOBALS['agend_test_current_user_can']['edit_posts']        = false;
		$GLOBALS['agend_test_current_user_can']['edit_others_posts'] = false;
		$GLOBALS['agend_test_current_user_can']['edit_post']         = false;
	}

	protected function asEditor(): void {
		$GLOBALS['agend_test_current_user_can']['edit_posts']        = true;
		$GLOBALS['agend_test_current_user_can']['edit_others_posts'] = true;
		$GLOBALS['agend_test_current_user_can']['edit_post']         = true;
	}

	/** Can write posts of their own and nobody else's: a contributor or an author. */
	protected function asContributor(): void {
		$GLOBALS['agend_test_current_user_can']['edit_posts']        = true;
		$GLOBALS['agend_test_current_user_can']['edit_others_posts'] = false;
		$GLOBALS['agend_test_current_user_can']['edit_post']         = false;
	}

	/** Counts every identity lookup and resolves to no bearer. */
	protected function anonymous(): void {
		Agend_Test_WP::$filters['agend_apps_bearer_token'] = function (): string {
			++$this->identity_lookups;

			return '';
		};
	}

	protected function asMember( string ...$tiers ): void {
		$GLOBALS['agend_test_current_user_id'] = 7;

		Agend_Test_WP::$filters['agend_apps_bearer_token'] = function (): string {
			++$this->identity_lookups;

			return 'member-token';
		};

		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'is_member' => true, 'tier_ids' => $tiers ) )
		);
	}

	/**
	 * A signed-in member holding the given segments and entitlement keys, on top
	 * of plan A.
	 *
	 * @param string[] $segment_ids      Segment ids the gateway reports.
	 * @param string[] $entitlement_keys Entitlement gate keys the gateway reports.
	 */
	protected function asMemberOf( array $segment_ids, array $entitlement_keys = array() ): void {
		$this->asMember( self::TIER_A );

		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array(
				'data' => array(
					'is_member'    => true,
					'tier_ids'     => array( self::TIER_A ),
					'capabilities' => array_map(
						static fn( string $key ): array => array( 'gate_key' => $key ),
						$entitlement_keys
					),
				),
			)
		);
		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_segments_response',
			array(
				'data' => array_map(
					static fn( string $id ): array => array( 'id' => $id, 'slug' => 'slug-' . substr( $id, 0, 4 ) ),
					$segment_ids
				),
			)
		);
	}

	/** Gateway calls recorded for one endpoint, so cost is assertable. */
	protected function gatewayCalls( string $endpoint ): int {
		return count(
			array_filter(
				Agend_Test_WP::$requests,
				static fn( array $request ): bool => $endpoint === $request['url']
			)
		);
	}

	// -----------------------------------------------------------------------
	// Posts and markup
	// -----------------------------------------------------------------------

	protected function registerPost( int $id, string $content, ?array $policy = null, string $type = 'post' ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array(
			'ID'           => $id,
			'post_status'  => 'publish',
			'post_type'    => $type,
			'post_title'   => 'Post ' . $id,
			'post_content' => $content,
		);

		if ( null !== $policy ) {
			$GLOBALS['agend_test_post_meta'][ $id ] = array( Agend_Content_Access_Policy::META_KEY => $policy );
		}
	}

	/** A synced pattern: a `wp_block` post, with the `core/block` render a real site has. */
	protected function registerPattern( int $id, string $content ): void {
		$this->registerPost( $id, $content, null, 'wp_block' );

		register_block_type(
			'core/block',
			array(
				'render_callback' => static function ( array $attributes ): string {
					$pattern = get_post( (int) ( $attributes['ref'] ?? 0 ) );

					return $pattern instanceof WP_Post ? do_blocks( (string) $pattern->post_content ) : '';
				},
			)
		);
	}

	/** `the_content` runs `do_blocks`, as it does in WordPress, so a fallback's own blocks are enforced. */
	protected function theContentRunsBlocks(): void {
		Agend_Test_WP::$filters['the_content'] = static fn( $content ) => do_blocks( (string) $content );
	}

	/** Makes `$id` the post WordPress is rendering, as `the_post()` does in a loop. */
	protected function inTheLoopOf( int $id ): void {
		$GLOBALS['post'] = get_post( $id );
	}

	/** Renders a post's body the way `the_content` does: through `do_blocks()`. */
	protected function renderPost( int $id ): string {
		$this->inTheLoopOf( $id );

		return do_blocks( (string) get_post( $id )->post_content );
	}

	/**
	 * Markup for one paragraph, optionally carrying the `agendAccess` attribute.
	 *
	 * @param string     $text   Paragraph text.
	 * @param array|null $access The attribute value, or null for a plain block.
	 */
	protected function paragraph( string $text, ?array $access = null ): string {
		return $this->raw( 'paragraph', null === $access ? '' : (string) wp_json_encode( array( 'agendAccess' => $access ) ), '<p>' . $text . '</p>' );
	}

	/** A paragraph whose attribute JSON is written verbatim, for values the encoder would not produce. */
	protected function rawAttribute( string $attributes_json, string $text ): string {
		return $this->raw( 'paragraph', $attributes_json, '<p>' . $text . '</p>' );
	}

	/** A group holding the given markup, optionally carrying the attribute. */
	protected function group( ?array $access, string ...$children ): string {
		return '<!-- wp:group' . $this->attributes( null === $access ? '' : (string) wp_json_encode( array( 'agendAccess' => $access ) ) ) . ' -->'
			. '<div class="wp-block-group">' . implode( '', $children ) . '</div>'
			. '<!-- /wp:group -->';
	}

	private function raw( string $name, string $attributes_json, string $html ): string {
		return '<!-- wp:' . $name . $this->attributes( $attributes_json ) . ' -->' . $html . '<!-- /wp:' . $name . ' -->';
	}

	private function attributes( string $json ): string {
		return '' === $json ? '' : ' ' . $json;
	}

	/** A synced pattern reference. */
	protected function reference( int $ref ): string {
		return '<!-- wp:block {"ref":' . $ref . '} /-->';
	}
}

<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Decision;
use Agend_Content_Access_Frontend;
use Agend_Content_Access_Policy;
use Agend_Test_WP;
use Agend_Test_WP_Die_Exception;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';

/**
 * An SEO plugin does not print a denied page's body text in its `<head>`.
 *
 * A denied page with a manual excerpt is served through the theme, so the theme's
 * `<head>` is rendered, and Yoast SEO, Rank Math, All in One SEO and Jetpack build
 * the meta description and the Open Graph and Twitter descriptions from the post,
 * and from the raw `post_content` when none was written. Each plugin's description
 * hooks are pointed at the teaser. The plugins are stubbed by running their hooks
 * with what they would have built from the body.
 */
#[CoversClass( Agend_Content_Access_Frontend::class )]
final class SeoDescriptionTest extends TestCase {

	private const TIER_A = '11111111-1111-1111-1111-111111111111';

	/** Plan A only, with a written excerpt: a denied visitor gets a teaser page. */
	private const A_ONLY = 71;
	/** Members only with no excerpt: a denied visitor gets a 403, and no head at all. */
	private const NO_TEASER = 72;
	private const PUBLIC_POST = 73;

	private const BODY    = 'The restricted opening paragraph of the members only article.';
	private const TEASER  = 'A public summary.';

	/** @var string[] Hooks that take the description as a string. */
	private const STRING_HOOKS = array(
		'wpseo_metadesc',
		'wpseo_opengraph_desc',
		'wpseo_twitter_description',
		'rank_math/frontend/description',
		'rank_math/opengraph/facebook/og_description',
		'rank_math/opengraph/twitter/twitter_description',
		'aioseo_description',
	);

	/** @var string[] Hooks that take an array of social tags. */
	private const TAG_HOOKS = array( 'aioseo_facebook_tags', 'aioseo_twitter_tags', 'jetpack_open_graph_tags' );

	protected function setUp(): void {
		parent::setUp();

		$this->registerPost( self::A_ONLY, array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ), self::TEASER );
		$this->registerPost( self::NO_TEASER, array( 'mode' => 'active_member' ), '' );
		$this->registerPost( self::PUBLIC_POST, array( 'mode' => 'public' ), '' );

		$this->become( 'anonymous' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['agend_test_is_singular'], $GLOBALS['agend_test_queried_object'] );

		parent::tearDown();
	}

	private function registerPost( int $id, array $policy, string $excerpt ): void {
		$GLOBALS['agend_test_posts'][ $id ]     = array(
			'ID'           => $id,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_title'   => 'Post ' . $id,
			'post_content' => self::BODY,
			'post_excerpt' => $excerpt,
		);
		$GLOBALS['agend_test_post_meta'][ $id ] = array( Agend_Content_Access_Policy::META_KEY => $policy );
	}

	private function become( string $viewer ): void {
		$GLOBALS['agend_test_current_user_can']['edit_post'] = ( 'editor' === $viewer );

		Agend_Test_WP::$filters['agend_apps_bearer_token'] = static function () use ( $viewer ): string {
			return 'right_plan' === $viewer ? 'member-token' : '';
		};

		Agend_Test_WP::set_filter(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'tier_ids' => 'right_plan' === $viewer ? array( self::TIER_A ) : array() ) )
		);

		Agend_Content_Access_Decision::reset_cache();
	}

	/** Requests the page for a post, the way `template_redirect` does. */
	private function requestPage( int $id ): void {
		$GLOBALS['agend_test_is_singular']    = true;
		$GLOBALS['agend_test_queried_object'] = get_post( $id );
		Agend_Test_WP::$queried_object_id     = $id;

		( new Agend_Content_Access_Frontend() )->gate_request();
	}

	/** @return array<string, array{0: string}> */
	public static function stringHooks(): array {
		return array_combine( self::STRING_HOOKS, array_map( static fn( string $hook ): array => array( $hook ), self::STRING_HOOKS ) );
	}

	/** @return array<string, array{0: string}> */
	public static function tagHooks(): array {
		return array_combine( self::TAG_HOOKS, array_map( static fn( string $hook ): array => array( $hook ), self::TAG_HOOKS ) );
	}

	// -----------------------------------------------------------------------
	// A denied page with a teaser
	// -----------------------------------------------------------------------

	#[Test]
	#[DataProvider( 'stringHooks' )]
	public function a_description_built_from_the_body_becomes_the_teaser( string $hook ): void {
		$this->requestPage( self::A_ONLY );

		$this->assertSame( self::TEASER, Agend_Test_WP::apply_registered( $hook, self::BODY, new \stdClass() ), $hook );
	}

	#[Test]
	#[DataProvider( 'stringHooks' )]
	public function a_description_another_filter_wrote_from_the_body_is_replaced_too( string $hook ): void {
		add_filter( $hook, static fn( $description ) => $description . ' ' . self::BODY, 10 );

		$this->requestPage( self::A_ONLY );

		$this->assertSame( self::TEASER, Agend_Test_WP::apply_registered( $hook, self::BODY ), $hook );
	}

	#[Test]
	#[DataProvider( 'tagHooks' )]
	public function the_description_entries_of_a_social_tag_array_become_the_teaser( string $hook ): void {
		$this->requestPage( self::A_ONLY );

		$tags = array(
			'og:title'            => 'Post 71',
			'og:description'      => self::BODY,
			'twitter:description' => self::BODY,
			'og:url'              => 'https://example.test/page-71/',
		);

		$this->assertSame(
			array(
				'og:title'            => 'Post 71',
				'og:description'      => self::TEASER,
				'twitter:description' => self::TEASER,
				'og:url'              => 'https://example.test/page-71/',
			),
			Agend_Test_WP::apply_registered( $hook, $tags, array() ),
			$hook
		);
	}

	#[Test]
	#[DataProvider( 'tagHooks' )]
	public function a_description_tag_the_plugin_did_not_set_is_not_added( string $hook ): void {
		$this->requestPage( self::A_ONLY );

		$this->assertSame(
			array( 'og:title' => 'Post 71' ),
			Agend_Test_WP::apply_registered( $hook, array( 'og:title' => 'Post 71' ) ),
			$hook
		);
	}

	#[Test]
	#[DataProvider( 'tagHooks' )]
	public function a_value_that_is_not_an_array_is_left_alone( string $hook ): void {
		$this->requestPage( self::A_ONLY );

		$this->assertFalse( Agend_Test_WP::apply_registered( $hook, false ), $hook );
	}

	#[Test]
	public function the_teaser_is_plain_text(): void {
		$GLOBALS['agend_test_posts'][ self::A_ONLY ]['post_excerpt'] = '<p>Members <b>only</b> summary.</p>';

		$this->requestPage( self::A_ONLY );

		$this->assertSame( 'Members only summary.', Agend_Test_WP::apply_registered( 'wpseo_metadesc', self::BODY ) );
	}

	#[Test]
	public function the_post_is_never_changed(): void {
		$this->requestPage( self::A_ONLY );
		$post = $GLOBALS['agend_test_queried_object'];

		Agend_Test_WP::apply_registered( 'wpseo_metadesc', self::BODY );
		Agend_Test_WP::apply_registered( 'jetpack_open_graph_tags', array( 'og:description' => self::BODY ) );

		$this->assertSame( self::BODY, $post->post_content );
		$this->assertSame( self::TEASER, $post->post_excerpt );
	}

	// -----------------------------------------------------------------------
	// Everywhere else costs nothing
	// -----------------------------------------------------------------------

	/** @return array<string, array{0: string, 1: int}> */
	public static function pagesThatAreNotTeasers(): array {
		return array(
			'a member the post admits' => array( 'right_plan', self::A_ONLY ),
			'an editor of the post'    => array( 'editor', self::A_ONLY ),
			'a public post'            => array( 'anonymous', self::PUBLIC_POST ),
		);
	}

	#[Test]
	#[DataProvider( 'pagesThatAreNotTeasers' )]
	public function a_page_that_is_not_a_teaser_registers_none_of_the_hooks( string $viewer, int $post_id ): void {
		$this->become( $viewer );
		$this->requestPage( $post_id );

		foreach ( array_merge( self::STRING_HOOKS, self::TAG_HOOKS ) as $hook ) {
			$this->assertSame( array(), Agend_Test_WP::$filter_registry[ $hook ] ?? array(), $hook );
		}

		$this->assertSame( self::BODY, Agend_Test_WP::apply_registered( 'wpseo_metadesc', self::BODY ) );
	}

	#[Test]
	public function a_denied_page_with_no_teaser_is_a_403_and_registers_none_of_the_hooks(): void {
		try {
			$this->requestPage( self::NO_TEASER );
			$this->fail( 'A page with no teaser is refused.' );
		} catch ( Agend_Test_WP_Die_Exception $e ) {
			foreach ( array_merge( self::STRING_HOOKS, self::TAG_HOOKS ) as $hook ) {
				$this->assertSame( array(), Agend_Test_WP::$filter_registry[ $hook ] ?? array(), $hook );
			}
		}
	}

	#[Test]
	public function the_hooks_run_last(): void {
		$this->requestPage( self::A_ONLY );

		foreach ( array_merge( self::STRING_HOOKS, self::TAG_HOOKS ) as $hook ) {
			$entries = Agend_Test_WP::$filter_registry[ $hook ] ?? array();

			$this->assertCount( 1, $entries, $hook );
			$this->assertSame( PHP_INT_MAX, $entries[0]['priority'], $hook );
		}
	}
}

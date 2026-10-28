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
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
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
 *
 * A page the reader may read is the same problem when its Elementor document
 * carries section rules: the saved body quotes the restricted sections.
 */
#[CoversClass( Agend_Content_Access_Frontend::class )]
final class SeoDescriptionTest extends TestCase {

	private const TIER_A = '11111111-1111-1111-1111-111111111111';

	/** Plan A only, with a written excerpt: a denied visitor gets a teaser page. */
	private const A_ONLY = 71;
	/** Members only with no excerpt: a denied visitor gets a 403, and no head at all. */
	private const NO_TEASER = 72;
	private const PUBLIC_POST = 73;

	/** Public, and its Elementor document restricts one section; no excerpt. */
	private const RULED = 74;
	/** The same, with an excerpt an editor wrote. */
	private const RULED_WITH_EXCERPT = 75;
	/** Public, no section rules, no excerpt. */
	private const PLAIN = 76;

	/** What a page builder saved into `post_content`, restricted section included. */
	private const SECTION = 'The restricted section text only members were meant to read.';

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

		$this->registerElementorPage( self::RULED, '', true );
		$this->registerElementorPage( self::RULED_WITH_EXCERPT, 'An editor wrote this.', true );
		$this->registerElementorPage( self::PLAIN, '', false );

		$this->become( 'anonymous' );
	}

	/** A public page whose saved body is `SECTION`, with or without a restricted section in its document. */
	private function registerElementorPage( int $id, string $excerpt, bool $restricted_section ): void {
		$this->registerPost( $id, array( 'mode' => 'public' ), $excerpt );

		$GLOBALS['agend_test_posts'][ $id ]['post_content'] = self::SECTION;

		$settings = $restricted_section ? array( 'agend_access_mode' => 'active_member' ) : array();

		$GLOBALS['agend_test_post_meta'][ $id ]['_elementor_data'] = (string) wp_json_encode(
			array( array( 'id' => 'a1', 'elType' => 'section', 'settings' => $settings, 'elements' => array() ) )
		);
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
	public function a_page_that_is_not_a_teaser_is_given_no_teaser_and_keeps_its_descriptions( string $viewer, int $post_id ): void {
		$this->become( $viewer );
		$this->requestPage( $post_id );

		foreach ( array_merge( self::STRING_HOOKS, self::TAG_HOOKS ) as $hook ) {
			foreach ( Agend_Test_WP::$filter_registry[ $hook ] ?? array() as $entry ) {
				$this->assertNotSame( 'replace_seo_description', $entry['callback'][1], $hook );
				$this->assertNotSame( 'replace_seo_description_tags', $entry['callback'][1], $hook );
			}
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

	// -----------------------------------------------------------------------
	// A readable page whose document restricts a section
	// -----------------------------------------------------------------------

	/** @return array<string, array{0: string}> Hooks of plugins whose written description is not read. */
	public static function alwaysEmptiedStringHooks(): array {
		return array( 'aioseo_description' => array( 'aioseo_description' ) );
	}

	/** @return array<string, array{0: string, 1: string}> Hook, and the post meta key holding that plugin's written description. */
	public static function descriptionsWithAWrittenKey(): array {
		$cases = array();

		foreach ( array( 'wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description' ) as $hook ) {
			$cases[ $hook ] = array( $hook, '_yoast_wpseo_metadesc' );
		}

		foreach ( array( 'rank_math/frontend/description', 'rank_math/opengraph/facebook/og_description', 'rank_math/opengraph/twitter/twitter_description' ) as $hook ) {
			$cases[ $hook ] = array( $hook, 'rank_math_description' );
		}

		return $cases;
	}

	#[Test]
	#[DataProvider( 'descriptionsWithAWrittenKey' )]
	public function a_description_built_from_a_page_with_a_restricted_section_is_emptied( string $hook ): void {
		$this->requestPage( self::RULED );

		$this->assertSame( '', Agend_Test_WP::apply_registered( $hook, self::SECTION ), $hook );
	}

	#[Test]
	#[DataProvider( 'alwaysEmptiedStringHooks' )]
	public function aioseos_description_is_emptied_on_such_a_page( string $hook ): void {
		$this->requestPage( self::RULED );

		$this->assertSame( '', Agend_Test_WP::apply_registered( $hook, self::SECTION ) );
	}

	#[Test]
	#[DataProvider( 'tagHooks' )]
	public function the_social_tags_of_such_a_page_have_empty_descriptions( string $hook ): void {
		$this->requestPage( self::RULED );

		$this->assertSame(
			array( 'og:title' => 'Post 74', 'og:description' => '', 'twitter:description' => '' ),
			Agend_Test_WP::apply_registered(
				$hook,
				array( 'og:title' => 'Post 74', 'og:description' => self::SECTION, 'twitter:description' => self::SECTION )
			),
			$hook
		);
	}

	#[Test]
	#[DataProvider( 'descriptionsWithAWrittenKey' )]
	public function a_description_the_author_wrote_is_kept( string $hook, string $meta_key ): void {
		$GLOBALS['agend_test_post_meta'][ self::RULED ][ $meta_key ] = 'Written by the author.';

		$this->requestPage( self::RULED );

		$this->assertSame( 'Written by the author.', Agend_Test_WP::apply_registered( $hook, 'Written by the author.' ), $hook );
	}

	#[Test]
	public function one_plugins_written_description_does_not_vouch_for_another_plugins_output(): void {
		$GLOBALS['agend_test_post_meta'][ self::RULED ]['rank_math_description'] = 'Written for Rank Math.';

		$this->requestPage( self::RULED );

		$this->assertSame( '', Agend_Test_WP::apply_registered( 'wpseo_metadesc', self::SECTION ) );
		$this->assertSame( '', Agend_Test_WP::apply_registered( 'aioseo_description', self::SECTION ) );
		$this->assertSame( 'x', Agend_Test_WP::apply_registered( 'rank_math/frontend/description', 'x' ) );
	}

	#[Test]
	public function a_written_description_that_prints_the_excerpt_variable_is_still_emptied(): void {
		$GLOBALS['agend_test_post_meta'][ self::RULED ]['_yoast_wpseo_metadesc'] = 'News: %%excerpt%%';

		$this->requestPage( self::RULED );

		$this->assertSame( '', Agend_Test_WP::apply_registered( 'wpseo_metadesc', 'News: ' . self::SECTION ) );
	}

	#[Test]
	public function a_blank_written_description_is_no_description(): void {
		$GLOBALS['agend_test_post_meta'][ self::RULED ]['_yoast_wpseo_metadesc'] = '   ';

		$this->requestPage( self::RULED );

		$this->assertSame( '', Agend_Test_WP::apply_registered( 'wpseo_metadesc', self::SECTION ) );
	}

	#[Test]
	#[DataProvider( 'stringHooks' )]
	public function a_page_with_a_written_excerpt_keeps_its_descriptions( string $hook ): void {
		$this->requestPage( self::RULED_WITH_EXCERPT );

		$this->assertSame( 'plugin value', Agend_Test_WP::apply_registered( $hook, 'plugin value' ), $hook );
	}

	#[Test]
	#[DataProvider( 'stringHooks' )]
	public function a_page_without_section_rules_keeps_its_descriptions( string $hook ): void {
		$this->requestPage( self::PLAIN );

		$this->assertSame( 'plugin value', Agend_Test_WP::apply_registered( $hook, 'plugin value' ), $hook );
	}

	#[Test]
	#[DataProvider( 'tagHooks' )]
	public function a_page_without_section_rules_keeps_its_social_tags( string $hook ): void {
		$this->requestPage( self::PLAIN );

		$tags = array( 'og:description' => 'plugin value', 'twitter:description' => 'plugin value' );

		$this->assertSame( $tags, Agend_Test_WP::apply_registered( $hook, $tags ), $hook );
	}

	#[Test]
	public function the_document_is_not_read_until_a_plugin_asks_and_then_once(): void {
		$reads = 0;

		Agend_Test_WP::$filters['agend_content_access_post_carries_fragment_rules'] = static function ( $carries ) use ( &$reads ) {
			++$reads;

			return $carries;
		};

		$this->requestPage( self::RULED );
		$this->assertSame( 0, $reads, 'a request no SEO plugin describes never reads it' );

		Agend_Test_WP::apply_registered( 'wpseo_metadesc', self::SECTION );
		Agend_Test_WP::apply_registered( 'wpseo_opengraph_desc', self::SECTION );
		Agend_Test_WP::apply_registered( 'aioseo_facebook_tags', array( 'og:description' => self::SECTION ) );

		$this->assertSame( 1, $reads );
	}

	#[Test]
	public function the_post_is_not_changed_by_emptying_its_descriptions(): void {
		$this->requestPage( self::RULED );
		$post = $GLOBALS['agend_test_queried_object'];

		Agend_Test_WP::apply_registered( 'wpseo_metadesc', self::SECTION );
		Agend_Test_WP::apply_registered( 'jetpack_open_graph_tags', array( 'og:description' => self::SECTION ) );

		$this->assertSame( self::SECTION, $post->post_content );
		$this->assertSame( '', $post->post_excerpt );
	}
}

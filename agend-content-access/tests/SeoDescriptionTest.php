<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Frontend;
use Agend_Content_Access_Rules as Rules;
use Agend_Content_Access_Seo as Seo;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

require_once __DIR__ . '/has-elementor-double.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-seo.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-placeholder.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-destination.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-attributes.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-access.php';

/** A page gate that throws where it would `exit`. */
final class SeoGateFrontend extends Agend_Content_Access_Frontend {

	protected function terminate(): void {
		throw new RuntimeException( 'ended' );
	}

	protected static function send_header( string $line ): void {}
}

/** The part of Yoast's indexable presentation a description hook is given. */
final class SeoPresentation {

	/** @var mixed */
	public $source;

	/** @var object|null */
	public $model;

	public function __construct( $source, $model = null ) {
		$this->source = $source;
		$this->model  = $model;
	}
}

/**
 * What an SEO plugin prints in the head of a page the reader is denied.
 *
 * The plugins build a description from the post's own text, so a denied page's opening
 * words went out in the head of its placeholder. Every hook of every covered plugin is
 * stubbed here, and what each must hand back is the teaser, never the body.
 */
#[CoversClass( Seo::class )]
final class SeoDescriptionTest extends TestCase {

	private const BODY = 'OPENING OF THE PROTECTED BODY';

	private const MEMBERS   = 100;
	private const PUBLIC    = 101;
	private const FRAGMENTS = 102;

	private const RESTRICTED_TEXT = 'MEMBERS ONLY BLOCK TEXT';

	protected function setUp(): void {
		parent::setUp();

		$this->page( self::MEMBERS, 'Members', 'A public teaser' );
		$this->page( self::PUBLIC, 'Public', '' );
		$this->page( self::FRAGMENTS, 'Fragments', '' );

		// A public page whose first block is for members, and whose body is that block's text first.
		$GLOBALS['agend_test_posts'][ self::FRAGMENTS ]['post_content']                                   = self::RESTRICTED_TEXT . ' then public words';
		$GLOBALS['agend_test_post_meta'][ self::FRAGMENTS ][ Agend_Content_Access_Frontend::FRAGMENT_RULES_META_KEY ] = '1';

		// What the block driver rebuilds from the tree without the ruled blocks.
		Agend_Test_WP::set_filter( 'agend_content_access_safe_generated_excerpt', 'Public <b>intro</b> only' );

		Seo::reset();

		$GLOBALS['agend_test_post_meta'][ self::MEMBERS ][ Rules::META_KEY ] = array( 'rules' => array( 'agend_membership:is_member' ), 'match' => 'any', 'invert' => false );
		$GLOBALS['agend_test_current_user_can']['edit_post']                   = false;

		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', '' );

		$_SERVER['REQUEST_URI'] = '/members/';

		new Seo();
	}

	protected function tearDown(): void {
		unset(
			$_SERVER['REQUEST_URI'],
			$GLOBALS['agend_test_is_singular'],
			$GLOBALS['agend_test_queried_object']
		);

		parent::tearDown();
	}

	private function page( int $id, string $title, string $excerpt ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array(
			'ID'           => $id,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_title'   => $title,
			'post_content' => self::BODY,
			'post_excerpt' => $excerpt,
		);
	}

	/** The request is for a page: its template_redirect has run and the gate has decided. */
	private function visit( int $id ): void {
		Agend_Test_WP::$queried_object_id     = $id;
		$GLOBALS['agend_test_is_singular']    = true;
		$GLOBALS['agend_test_queried_object'] = get_post( $id );

		( new SeoGateFrontend() )->gate_request();
	}

	private function describe( string $hook, $value = self::BODY, ...$args ) {
		return Agend_Test_WP::apply_registered( $hook, $value, ...$args );
	}

	/** @return array<string, array{0: string}> */
	public static function textHooks(): array {
		return array(
			'Yoast meta description'  => array( 'wpseo_metadesc' ),
			'Yoast og:description'    => array( 'wpseo_opengraph_desc' ),
			'Yoast twitter'           => array( 'wpseo_twitter_description' ),
			'Rank Math description'   => array( 'rank_math/frontend/description' ),
			'Rank Math og:description' => array( 'rank_math/opengraph/facebook/og_description' ),
			'Rank Math twitter'       => array( 'rank_math/opengraph/twitter/twitter_description' ),
			'AIOSEO description'      => array( 'aioseo_description' ),
		);
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function tagHooks(): array {
		return array(
			'AIOSEO og'     => array( 'aioseo_facebook_tags', 'og:description' ),
			'AIOSEO twitter' => array( 'aioseo_twitter_tags', 'twitter:description' ),
			'Jetpack og'    => array( 'jetpack_open_graph_tags', 'og:description' ),
			'Jetpack twitter' => array( 'jetpack_open_graph_tags', 'twitter:description' ),
		);
	}

	#[Test]
	#[DataProvider( 'textHooks' )]
	public function a_denied_page_s_description_is_its_manual_excerpt_and_not_its_body( string $hook ): void {
		$this->visit( self::MEMBERS );

		$this->assertSame( 'A public teaser', $this->describe( $hook ) );
	}

	#[Test]
	#[DataProvider( 'textHooks' )]
	public function a_denied_page_with_no_manual_excerpt_has_an_empty_description( string $hook ): void {
		$GLOBALS['agend_test_posts'][ self::MEMBERS ]['post_excerpt'] = '';
		$this->visit( self::MEMBERS );

		$this->assertSame( '', $this->describe( $hook ) );
	}

	#[Test]
	#[DataProvider( 'tagHooks' )]
	public function a_denied_page_s_description_tags_are_the_teaser_or_are_dropped( string $hook, string $key ): void {
		$this->visit( self::MEMBERS );

		$tags = $this->describe( $hook, array( $key => self::BODY, 'og:title' => 'Members' ) );

		$this->assertSame( 'A public teaser', $tags[ $key ] );
		$this->assertSame( 'Members', $tags['og:title'], 'other tags are left alone' );

		$GLOBALS['agend_test_posts'][ self::MEMBERS ]['post_excerpt'] = '';
		\Agend_Content_Access_Frontend::reset_gate();
		$this->visit( self::MEMBERS );

		$this->assertArrayNotHasKey( $key, $this->describe( $hook, array( $key => self::BODY, 'og:title' => 'Members' ) ) );
	}

	#[Test]
	public function the_teaser_is_plain_text_on_one_line(): void {
		$GLOBALS['agend_test_posts'][ self::MEMBERS ]['post_excerpt'] = "<b>Join</b>\n  us <script>alert(1)</script>today";
		$this->visit( self::MEMBERS );

		$this->assertSame( 'Join us today', $this->describe( 'wpseo_metadesc' ) );
	}

	#[Test]
	#[DataProvider( 'textHooks' )]
	public function a_public_page_keeps_the_plugin_s_own_description( string $hook ): void {
		$this->visit( self::PUBLIC );

		$this->assertSame( 'Own text', $this->describe( $hook, 'Own text' ) );
	}

	#[Test]
	public function a_member_the_rules_admit_keeps_the_plugin_s_own_description(): void {
		$GLOBALS['agend_test_current_user_id'] = 7;
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_my_entitlements_response', array( 'data' => array( 'is_member' => true, 'tier_ids' => array( '11111111-1111-1111-1111-111111111111' ) ) ) );
		$this->visit( self::MEMBERS );

		$this->assertSame( 'Own text', $this->describe( 'wpseo_metadesc', 'Own text' ) );
	}

	/** On the live site an editor who fails the rules is a reader like any other. */
	#[Test]
	public function an_editor_who_fails_the_rules_gets_the_teaser_on_the_live_site(): void {
		$GLOBALS['agend_test_current_user_id']               = 3;
		$GLOBALS['agend_test_current_user_can']['edit_post'] = true;
		$this->visit( self::MEMBERS );

		$this->assertSame( 'A public teaser', $this->describe( 'wpseo_metadesc', 'Own text' ) );
	}

	#[Test]
	public function an_editor_in_an_editing_session_keeps_the_plugin_s_own_description(): void {
		$GLOBALS['agend_test_current_user_id']               = 3;
		$GLOBALS['agend_test_current_user_can']['edit_post'] = true;
		$GLOBALS['agend_test_is_admin']                      = true;
		$this->visit( self::MEMBERS );

		$this->assertSame( 'Own text', $this->describe( 'wpseo_metadesc', 'Own text' ) );
	}

	#[Test]
	public function a_request_that_is_not_about_one_post_is_left_alone(): void {
		$this->assertSame( 'Own text', $this->describe( 'wpseo_metadesc', 'Own text' ) );
		$this->assertSame( array( 'og:description' => 'Own text' ), $this->describe( 'jetpack_open_graph_tags', array( 'og:description' => 'Own text' ) ) );
	}

	#[Test]
	public function tags_that_carry_no_description_and_values_that_are_not_tags_are_left_alone(): void {
		$this->visit( self::MEMBERS );

		$this->assertSame( array( 'og:title' => 'x' ), $this->describe( 'jetpack_open_graph_tags', array( 'og:title' => 'x' ) ) );
		$this->assertSame( 'text', $this->describe( 'jetpack_open_graph_tags', 'text' ) );
	}

	/** Yoast says which post it is describing, which need not be the page being served. */
	#[Test]
	public function a_post_the_plugin_names_is_judged_even_when_it_is_not_the_queried_page(): void {
		$this->visit( self::PUBLIC );

		$source = new SeoPresentation( get_post( self::MEMBERS ) );

		$this->assertSame( 'A public teaser', $this->describe( 'wpseo_metadesc', self::BODY, $source ) );

		$model = new SeoPresentation( null, (object) array( 'object_type' => 'post', 'object_id' => self::MEMBERS ) );

		$this->assertSame( 'A public teaser', $this->describe( 'wpseo_opengraph_desc', self::BODY, $model ) );

		$public = new SeoPresentation( get_post( self::PUBLIC ) );

		$this->assertSame( 'Own text', $this->describe( 'wpseo_metadesc', 'Own text', $public ) );
	}

	#[Test]
	public function the_post_is_read_and_never_changed(): void {
		$this->visit( self::MEMBERS );

		$post = get_post( self::MEMBERS );

		$this->describe( 'wpseo_metadesc' );
		$this->describe( 'jetpack_open_graph_tags', array( 'og:description' => self::BODY ) );

		$this->assertSame( self::BODY, $post->post_content );
		$this->assertSame( 'A public teaser', $post->post_excerpt );
	}

	// -----------------------------------------------------------------------
	// A page the reader may read, with a block or section the reader may not
	// -----------------------------------------------------------------------

	/** @return array<string, array{0: string}> Every hook, text and tag lists alike. */
	public static function allHooks(): array {
		return self::textHooks() + array(
			'AIOSEO og'       => array( 'aioseo_facebook_tags' ),
			'AIOSEO twitter'  => array( 'aioseo_twitter_tags' ),
			'Jetpack'         => array( 'jetpack_open_graph_tags' ),
		);
	}

	/** The description a hook hands back for the page, whether it is a string or a list of tags. */
	private function descriptionFor( string $hook, string $value = self::RESTRICTED_TEXT . ' then public words' ): ?string {
		if ( in_array( $hook, array( 'aioseo_facebook_tags', 'aioseo_twitter_tags', 'jetpack_open_graph_tags' ), true ) ) {
			$key  = 'aioseo_twitter_tags' === $hook ? 'twitter:description' : 'og:description';
			$tags = $this->describe( $hook, array( $key => $value ) );

			return $tags[ $key ] ?? null;
		}

		return $this->describe( $hook, $value );
	}

	#[Test]
	#[DataProvider( 'allHooks' )]
	public function a_public_page_with_a_restricted_block_first_is_described_without_its_text( string $hook ): void {
		$this->visit( self::FRAGMENTS );

		$this->assertSame( 'Public intro only', $this->descriptionFor( $hook ) );
	}

	/** An Elementor document, or anything the driver cannot rebuild, has nothing public to say. */
	#[Test]
	#[DataProvider( 'allHooks' )]
	public function a_page_the_driver_cannot_rebuild_has_no_description( string $hook ): void {
		Agend_Test_WP::set_filter( 'agend_content_access_safe_generated_excerpt', '' );
		$this->visit( self::FRAGMENTS );

		$this->assertTrue( in_array( $this->descriptionFor( $hook ), array( '', null ), true ), $hook );
	}

	#[Test]
	public function the_safe_description_is_cut_to_about_a_description_long_at_a_word(): void {
		Agend_Test_WP::set_filter( 'agend_content_access_safe_generated_excerpt', str_repeat( 'public words ', 30 ) );
		$this->visit( self::FRAGMENTS );

		$text = (string) $this->describe( 'aioseo_description', self::RESTRICTED_TEXT );

		$this->assertLessThanOrEqual( 161, mb_strlen( $text ) );
		$this->assertStringEndsWith( "words\u{2026}", $text );
	}

	/** The author wrote it on purpose, and Yoast and Rank Math store it where it can be seen cheaply. */
	#[Test]
	public function a_description_the_author_wrote_in_yoast_or_rank_math_is_kept(): void {
		$GLOBALS['agend_test_post_meta'][ self::FRAGMENTS ]['_yoast_wpseo_metadesc'] = 'Written by the author';
		$GLOBALS['agend_test_post_meta'][ self::FRAGMENTS ]['rank_math_description'] = 'Written for Rank Math';
		$this->visit( self::FRAGMENTS );

		foreach ( array( 'wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description' ) as $hook ) {
			$this->assertSame( 'Written by the author', $this->describe( $hook, 'Written by the author' ), $hook );
		}

		foreach ( array( 'rank_math/frontend/description', 'rank_math/opengraph/facebook/og_description', 'rank_math/opengraph/twitter/twitter_description' ) as $hook ) {
			$this->assertSame( 'Written for Rank Math', $this->describe( $hook, 'Written for Rank Math' ), $hook );
		}

		// All in One SEO and Jetpack always get the safe excerpt on such a page.
		$this->assertSame( 'Public intro only', $this->describe( 'aioseo_description', self::RESTRICTED_TEXT ) );
		$this->assertSame( 'Public intro only', $this->descriptionFor( 'jetpack_open_graph_tags' ) );
	}

	/** An empty custom description is no description: the plugin built its text from the body. */
	#[Test]
	public function a_blank_custom_description_does_not_keep_the_plugin_s_text(): void {
		$GLOBALS['agend_test_post_meta'][ self::FRAGMENTS ]['_yoast_wpseo_metadesc'] = '  ';
		$GLOBALS['agend_test_post_meta'][ self::FRAGMENTS ]['rank_math_description'] = '';
		$this->visit( self::FRAGMENTS );

		$this->assertSame( 'Public intro only', $this->describe( 'wpseo_metadesc', self::RESTRICTED_TEXT ) );
		$this->assertSame( 'Public intro only', $this->describe( 'rank_math/frontend/description', self::RESTRICTED_TEXT ) );
	}

	/** A page with no block or section rules is not touched, whatever the safe excerpt filter would say. */
	#[Test]
	#[DataProvider( 'allHooks' )]
	public function a_public_page_with_no_fragment_rules_is_untouched( string $hook ): void {
		$this->visit( self::PUBLIC );

		$this->assertSame( 'Own text', $this->descriptionFor( $hook, 'Own text' ) );
	}

	/** A manual excerpt is the editor's own public text, so the plugin's description of it stands. */
	#[Test]
	public function a_page_with_a_manual_excerpt_is_untouched(): void {
		$GLOBALS['agend_test_posts'][ self::FRAGMENTS ]['post_excerpt'] = 'Written excerpt';
		Seo::reset();
		$this->visit( self::FRAGMENTS );

		$this->assertSame( 'Written excerpt', $this->describe( 'wpseo_metadesc', 'Written excerpt' ) );
	}

	#[Test]
	public function the_post_of_a_page_with_fragment_rules_is_read_and_never_changed(): void {
		$this->visit( self::FRAGMENTS );

		$post = get_post( self::FRAGMENTS );

		$this->describe( 'wpseo_metadesc', self::RESTRICTED_TEXT );
		$this->describe( 'jetpack_open_graph_tags', array( 'og:description' => self::RESTRICTED_TEXT ) );

		$this->assertSame( self::RESTRICTED_TEXT . ' then public words', $post->post_content );
		$this->assertSame( '', $post->post_excerpt );
	}

	/** Last on each hook, so nothing that runs after can put the body back. */
	#[Test]
	public function every_hook_is_the_last_word(): void {
		$hooks = array_merge( array_column( self::textHooks(), 0 ), array( 'aioseo_facebook_tags', 'aioseo_twitter_tags', 'jetpack_open_graph_tags' ) );

		foreach ( $hooks as $hook ) {
			$entries = Agend_Test_WP::$filter_registry[ $hook ] ?? array();

			$this->assertCount( 1, $entries, $hook );
			$this->assertSame( PHP_INT_MAX, $entries[0]['priority'], $hook );
		}
	}
}

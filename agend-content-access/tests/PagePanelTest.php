<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Meta_Box as MetaBox;
use Agend_Content_Access_Policy as Policy;
use Agend_Content_Access_Rules as Rules;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-attributes.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-access.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-destination.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-page-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/interface-agend-content-access-document-parser.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-elementor-parser.php';

// The assets are located relative to these, which the plugin's main file defines.
if ( ! defined( 'AGEND_CONTENT_ACCESS_DIR' ) ) {
	define( 'AGEND_CONTENT_ACCESS_DIR', AGEND_TESTS_ROOT . '/agend-content-access/' );
}

if ( ! defined( 'AGEND_CONTENT_ACCESS_URL' ) ) {
	define( 'AGEND_CONTENT_ACCESS_URL', 'https://example.test/wp-content/plugins/agend-content-access/' );
}

/**
 * The page panel's two hosts, as the server sets them up.
 *
 * In the block editor the panel is a native document settings panel and the meta
 * box is hidden, so an author sees one. Everywhere else the meta box is the panel:
 * a mount point for the same React components and one hidden input they write to.
 * What the page sends to the script is its own setting and nothing else.
 */
#[CoversClass( MetaBox::class )]
final class PagePanelTest extends TestCase {

	private const POST   = 77;
	private const MEMBER = 'agend_membership:is_member';
	private const SEG    = '22222222-2222-2222-2222-222222222222';

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['agend_test_current_user_can']['edit_post'] = true;
		$GLOBALS['agend_test_posts'][ self::POST ]            = array(
			'ID'          => self::POST,
			'post_status' => 'publish',
			'post_type'   => 'page',
			'post_title'  => 'A page',
		);
	}

	private function render(): string {
		ob_start();
		( new MetaBox() )->render( get_post( self::POST ) );

		return (string) ob_get_clean();
	}

	/** @return array The setting the mount point carries. */
	private function config( string $html ): array {
		$this->assertSame( 1, preg_match( '/data-agend-access-page="([^"]*)"/', $html, $found ), 'a mount point carrying the setting' );

		$decoded = json_decode( html_entity_decode( $found[1], ENT_QUOTES ), true );

		$this->assertIsArray( $decoded );

		return $decoded;
	}

	private function screen( bool $block_editor, string $type = 'page' ): void {
		$GLOBALS['agend_test_current_screen'] = new class( $block_editor, $type ) {
			public string $post_type;
			private bool $block_editor;

			public function __construct( bool $block_editor, string $type ) {
				$this->block_editor = $block_editor;
				$this->post_type    = $type;
			}

			public function is_block_editor(): bool {
				return $this->block_editor;
			}
		};
	}

	// -----------------------------------------------------------------
	// The meta box is the classic editor's host, and is hidden in the block editor
	// -----------------------------------------------------------------

	#[Test]
	public function the_meta_box_is_hidden_in_the_block_editor_so_an_author_sees_one_panel(): void {
		( new MetaBox() )->add_meta_box();

		$this->assertCount( 1, Agend_Test_WP::$meta_boxes );
		$this->assertSame( array( '__back_compat_meta_box' => true ), Agend_Test_WP::$meta_boxes[0]['callback_args'] );
		$this->assertSame( 'agend-content-access', Agend_Test_WP::$meta_boxes[0]['id'] );
		$this->assertSame( 'side', Agend_Test_WP::$meta_boxes[0]['context'] );
	}

	#[Test]
	public function the_meta_box_no_longer_claims_to_be_a_block_editor_meta_box(): void {
		( new MetaBox() )->add_meta_box();

		$this->assertArrayNotHasKey( '__block_editor_compatible_meta_box', Agend_Test_WP::$meta_boxes[0]['callback_args'] );
	}

	#[Test]
	public function the_meta_box_is_registered_on_every_supported_post_type(): void {
		$GLOBALS['agend_test_post_types'] = array( 'post' => 'post', 'page' => 'page', 'attachment' => 'attachment' );

		( new MetaBox() )->add_meta_box();

		$this->assertSame( array( 'post', 'page' ), Agend_Test_WP::$meta_boxes[0]['screen'] );
	}

	// -----------------------------------------------------------------
	// What it renders
	// -----------------------------------------------------------------

	#[Test]
	public function it_renders_a_mount_point_the_nonce_and_an_empty_hidden_input_and_no_form(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'id="agend-access-page-root"', $html );
		$this->assertStringContainsString( 'name="agend_content_access_nonce"', $html );
		$this->assertMatchesRegularExpression( '/<input type="hidden" id="agend-access-page-input" name="agend_access_page_rules" value="" \/>/', $html, 'empty until an author changes something' );
		$this->assertStringNotContainsString( 'type="radio"', $html, 'the form of radio buttons is gone' );
		$this->assertStringNotContainsString( 'type="checkbox"', $html );
	}

	#[Test]
	public function the_input_is_named_as_the_save_handler_reads_it(): void {
		$this->assertStringContainsString( 'name="' . MetaBox::FIELD_RULES . '"', $this->render() );
		$this->assertStringContainsString( 'id="' . MetaBox::ROOT_ID . '"', $this->render() );
	}

	#[Test]
	public function the_mount_point_says_what_a_page_that_never_starts_it_is_keeping(): void {
		$this->assertStringContainsString( 'the setting this page already has is kept', $this->render() );
	}

	#[Test]
	public function a_page_with_nothing_stored_is_sent_as_a_page_with_no_rules(): void {
		$config = $this->config( $this->render() );

		$this->assertSame( array( 'state' ), array_keys( $config ), 'the setting and nothing else' );
		$this->assertSame( array(), $config['state']['rules'] );
		$this->assertFalse( $config['state']['unreadable'] );
		$this->assertFalse( $config['state']['legacy'] );
	}

	#[Test]
	public function stored_rules_are_sent_with_their_denial_setting(): void {
		$GLOBALS['agend_test_post_meta'][ self::POST ][ Rules::META_KEY ] = array(
			'rules'  => array( self::MEMBER, 'agend_segments:' . self::SEG ),
			'match'  => 'all',
			'invert' => false,
			'denied' => array( 'action' => 'back', 'target_id' => 0, 'fallback_id' => 5 ),
		);

		$state = $this->config( $this->render() )['state'];

		$this->assertSame( array( self::MEMBER, 'agend_segments:' . self::SEG ), $state['rules'] );
		$this->assertSame( 'all', $state['match'] );
		$this->assertSame( array( 'action' => 'back', 'target_id' => 0, 'fallback_id' => 5 ), $state['denied'] );
	}

	#[Test]
	public function a_page_with_only_a_0_1_policy_is_sent_as_its_converted_rules_and_flagged(): void {
		$GLOBALS['agend_test_post_meta'][ self::POST ][ Policy::META_KEY ] = array( 'mode' => 'active_member' );

		$state = $this->config( $this->render() )['state'];

		$this->assertSame( array( self::MEMBER ), $state['rules'] );
		$this->assertTrue( $state['legacy'] );
	}

	#[Test]
	public function an_unreadable_setting_is_sent_as_unreadable_and_empty(): void {
		$GLOBALS['agend_test_post_meta'][ self::POST ][ Policy::META_KEY ] = array( 'mode' => 'segments', 'segment_ids' => array() );

		$state = $this->config( $this->render() )['state'];

		$this->assertTrue( $state['unreadable'] );
		$this->assertSame( array(), $state['rules'] );
	}

	#[Test]
	public function rendering_never_rewrites_a_setting_it_shows(): void {
		$GLOBALS['agend_test_post_meta'][ self::POST ][ Policy::META_KEY ] = array( 'mode' => 'active_member' );
		$before = $GLOBALS['agend_test_post_meta'];

		$this->render();

		$this->assertSame( $before, $GLOBALS['agend_test_post_meta'] );
	}

	/** What crosses into the page is the page's own setting: no key, gateway address or payload is in scope. */
	#[Test]
	public function nothing_but_the_pages_own_setting_crosses_into_the_page(): void {
		update_option( 'agend_apps_api_key', 'SECRET-API-KEY' );
		update_option( 'agend_apps_api_base_url', 'https://gateway.example.test' );

		$html = $this->render();

		$this->assertStringNotContainsString( 'SECRET-API-KEY', $html );
		$this->assertStringNotContainsString( 'gateway.example.test', $html );
		$this->assertSame( array( 'rules', 'match', 'invert', 'denied', 'legacy', 'unreadable' ), array_keys( $this->config( $html )['state'] ) );
	}

	#[Test]
	public function a_page_built_with_elementors_new_editor_is_told_the_setting_covers_the_whole_page(): void {
		$GLOBALS['agend_test_post_meta'][ self::POST ]['_elementor_data'] = (string) wp_json_encode(
			array( array( 'id' => 'a', 'elType' => 'e-div-block', 'settings' => array(), 'elements' => array() ) )
		);

		$html = $this->render();

		$this->assertStringContainsString( 'new editor', $html );
		$this->assertStringContainsString( 'WHOLE page', $html );
		$this->assertStringNotContainsString( 'WHOLE page', ( function (): string {
			unset( $GLOBALS['agend_test_post_meta'][ self::POST ]['_elementor_data'] );

			return $this->render();
		} )() );
	}

	// -----------------------------------------------------------------
	// The classic editor's script
	// -----------------------------------------------------------------

	#[Test]
	public function the_script_and_its_styles_are_queued_on_a_classic_edit_screen_with_the_dependencies_the_build_names(): void {
		$this->screen( false );

		( new MetaBox() )->enqueue_assets( 'post.php' );

		$this->assertContains( 'agend-content-access-classic', Agend_Test_WP::$enqueued_scripts );
		$args = Agend_Test_WP::$enqueued_script_args['agend-content-access-classic'];

		$this->assertStringEndsWith( 'build/classic.js', $args['src'] );
		$this->assertContains( 'wp-element', $args['deps'] );
		$this->assertContains( 'wp-api-fetch', $args['deps'], 'the picker reads its lists through the REST API' );
		$this->assertContains( 'wp-components', Agend_Test_WP::$enqueued_styles, 'the components are WordPress\'s own, and their styles are not loaded on a classic screen unless asked for' );
		$this->assertContains( 'agend-content-access-classic', Agend_Test_WP::$enqueued_styles );
		$this->assertArrayHasKey( 'agend-content-access-classic', Agend_Test_WP::$script_translations );
	}

	#[Test]
	public function a_new_post_screen_gets_it_too(): void {
		$this->screen( false );

		( new MetaBox() )->enqueue_assets( 'post-new.php' );

		$this->assertContains( 'agend-content-access-classic', Agend_Test_WP::$enqueued_scripts );
	}

	/** The block editor has its own panel, in the script that is already there. */
	#[Test]
	public function the_block_editor_does_not_load_the_classic_script(): void {
		$this->screen( true );

		( new MetaBox() )->enqueue_assets( 'post.php' );

		$this->assertSame( array(), Agend_Test_WP::$enqueued_scripts );
		$this->assertSame( array(), Agend_Test_WP::$enqueued_styles );
	}

	#[Test]
	public function nothing_is_queued_off_the_post_screens_or_for_a_type_the_panel_is_not_offered_on(): void {
		$this->screen( false );
		( new MetaBox() )->enqueue_assets( 'edit.php' );
		( new MetaBox() )->enqueue_assets( 'options-general.php' );

		$this->assertSame( array(), Agend_Test_WP::$enqueued_scripts );

		$this->screen( false, 'nav_menu_item' );
		( new MetaBox() )->enqueue_assets( 'post.php' );

		$this->assertSame( array(), Agend_Test_WP::$enqueued_scripts );
	}

	#[Test]
	public function the_build_the_script_comes_from_is_committed(): void {
		foreach ( array( 'classic.js', 'classic.asset.php', 'classic.css', 'index.js', 'index.asset.php' ) as $file ) {
			$this->assertFileExists( AGEND_CONTENT_ACCESS_DIR . 'build/' . $file );
		}
	}

	// -----------------------------------------------------------------
	// The block editor's script carries the panel
	// -----------------------------------------------------------------

	#[Test]
	public function the_block_editors_script_depends_on_what_a_document_panel_needs(): void {
		$asset = include AGEND_CONTENT_ACCESS_DIR . 'build/index.asset.php';

		foreach ( array( 'wp-plugins', 'wp-editor', 'wp-data' ) as $handle ) {
			$this->assertContains( $handle, $asset['dependencies'], $handle );
		}
	}

	#[Test]
	public function the_classic_script_needs_none_of_the_block_editor(): void {
		$asset = include AGEND_CONTENT_ACCESS_DIR . 'build/classic.asset.php';

		foreach ( array( 'wp-block-editor', 'wp-blocks', 'wp-editor', 'wp-plugins', 'wp-data' ) as $handle ) {
			$this->assertNotContains( $handle, $asset['dependencies'], $handle );
		}
	}
}

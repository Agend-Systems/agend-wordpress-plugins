<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Records_Pages;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/pages.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/detail-login.php';

/**
 * The page flag behind "Require sign-in to view details", how a block save
 * mirrors into it, its backfill, and the wp:4 redirect that reads it.
 *
 * The redirect function itself ends in `wp_redirect()` + `exit`, which would
 * end the test process, so the decision it delegates to
 * (`agend_apps_records_directory_login_redirect_target()`) is what is covered.
 */
#[CoversFunction( 'agend_apps_records_directory_detail_requires_login' )]
#[CoversFunction( 'agend_apps_records_directory_set_login_source' )]
#[CoversFunction( 'agend_apps_records_blocks_require_login' )]
#[CoversFunction( 'agend_apps_records_directory_mirror_blocks_flag' )]
#[CoversFunction( 'agend_apps_records_directory_backfill_login_flag' )]
#[CoversFunction( 'agend_apps_records_directory_maybe_backfill_login_flag' )]
#[CoversFunction( 'agend_apps_records_directory_login_redirect_target' )]
final class DirectoryDetailLoginTest extends TestCase {

	private const META = '_agend_apps_directory_require_login';

	private const ON  = '<!-- wp:agend-apps/directory-catalogue {"require_login_for_detail":true} /-->';
	private const OFF = '<!-- wp:agend-apps/directory-catalogue /-->';

	protected function setUp(): void {
		parent::setUp();
		$_SERVER['REQUEST_METHOD']                     = 'GET';
		Agend_Test_WP::$options['permalink_structure'] = '/%postname%/';
	}

	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_METHOD'] );
		parent::tearDown();
	}

	private function seedPage( int $id, string $content = '', string $type = 'page', string $status = 'publish' ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array(
			'ID'           => $id,
			'post_type'    => $type,
			'post_status'  => $status,
			'post_content' => $content,
		);
	}

	private function flagOf( int $id ) {
		return $GLOBALS['agend_test_post_meta'][ $id ][ self::META ] ?? null;
	}

	private function setFlag( int $id, $value = array( 'blocks' ) ): void {
		$GLOBALS['agend_test_post_meta'][ $id ][ self::META ] = $value;
	}

	private function dedicate( int $id ): void {
		Agend_Test_WP::$options[ AGEND_APPS_RECORDS_DIRECTORY_PAGE_OPTION ] = $id;
	}

	// -- reading the flag ----------------------------------------------------

	#[Test]
	public function should_not_require_sign_in_for_a_page_with_no_flag(): void {
		$this->seedPage( 42 );

		self::assertFalse( agend_apps_records_directory_detail_requires_login( 42 ) );
	}

	/** @return array<string, array{mixed}> */
	public static function non_empty_flags(): array {
		return array(
			'one source'   => array( array( 'blocks' ) ),
			'two sources'  => array( array( 'blocks', 'elementor' ) ),
			'plain string' => array( '1' ),
		);
	}

	#[Test]
	#[DataProvider( 'non_empty_flags' )]
	public function should_require_sign_in_for_any_non_empty_flag( $value ): void {
		$this->setFlag( 42, $value );

		self::assertTrue( agend_apps_records_directory_detail_requires_login( 42 ) );
	}

	/** @return array<string, array{mixed}> */
	public static function empty_flags(): array {
		return array(
			'empty list'   => array( array() ),
			'empty string' => array( '' ),
			'zero'         => array( '0' ),
		);
	}

	#[Test]
	#[DataProvider( 'empty_flags' )]
	public function should_not_require_sign_in_for_an_empty_flag( $value ): void {
		$this->setFlag( 42, $value );

		self::assertFalse( agend_apps_records_directory_detail_requires_login( 42 ) );
	}

	#[Test]
	public function should_not_require_sign_in_without_a_page(): void {
		$this->setFlag( 0 );
		$this->setFlag( -1 );

		self::assertFalse( agend_apps_records_directory_detail_requires_login( 0 ) );
		self::assertFalse( agend_apps_records_directory_detail_requires_login( -1 ) );
	}

	// -- recording a builder's source ---------------------------------------

	#[Test]
	public function should_record_a_source_as_a_list(): void {
		agend_apps_records_directory_set_login_source( 42, 'blocks', true );

		self::assertSame( array( 'blocks' ), $this->flagOf( 42 ) );
		self::assertTrue( agend_apps_records_directory_detail_requires_login( 42 ) );
	}

	#[Test]
	public function should_keep_each_builders_source_apart(): void {
		agend_apps_records_directory_set_login_source( 42, 'blocks', true );
		agend_apps_records_directory_set_login_source( 42, 'elementor', true );

		self::assertSame( array( 'blocks', 'elementor' ), $this->flagOf( 42 ) );

		agend_apps_records_directory_set_login_source( 42, 'blocks', false );

		self::assertSame( array( 'elementor' ), $this->flagOf( 42 ) );
		self::assertTrue( agend_apps_records_directory_detail_requires_login( 42 ) );
	}

	#[Test]
	public function should_delete_the_flag_when_the_last_source_turns_off(): void {
		agend_apps_records_directory_set_login_source( 42, 'elementor', true );
		agend_apps_records_directory_set_login_source( 42, 'elementor', false );

		self::assertNull( $this->flagOf( 42 ) );
		self::assertFalse( agend_apps_records_directory_detail_requires_login( 42 ) );
	}

	#[Test]
	public function should_not_duplicate_a_source_or_write_when_nothing_changes(): void {
		agend_apps_records_directory_set_login_source( 42, 'blocks', true );
		agend_apps_records_directory_set_login_source( 42, 'blocks', true );
		agend_apps_records_directory_set_login_source( 43, 'blocks', false );

		self::assertSame( array( 'blocks' ), $this->flagOf( 42 ) );
		self::assertNull( $this->flagOf( 43 ) );
	}

	#[Test]
	public function should_ignore_a_missing_page_or_source(): void {
		agend_apps_records_directory_set_login_source( 0, 'blocks', true );
		agend_apps_records_directory_set_login_source( 42, '', true );

		self::assertSame( array(), $GLOBALS['agend_test_post_meta'] );
	}

	// -- reading a block tree -----------------------------------------------

	/** @return array<string, array{string, bool}> */
	public static function block_content(): array {
		return array(
			'switch on'                       => array( self::ON, true ),
			'switch off (default, no attr)'   => array( self::OFF, false ),
			'switch explicitly false'         => array( '<!-- wp:agend-apps/directory-catalogue {"require_login_for_detail":false} /-->', false ),
			'nested in a group'               => array( '<!-- wp:group --><div class="wp-block-group">' . self::ON . '</div><!-- /wp:group -->', true ),
			'two catalogues, second on'       => array( self::OFF . self::ON, true ),
			'two catalogues, first on'        => array( self::ON . self::OFF, true ),
			'two catalogues, both off'        => array( self::OFF . self::OFF, false ),
			'same attribute on another block' => array( '<!-- wp:agend-apps/events-catalogue {"require_login_for_detail":true} /-->', false ),
			'no catalogue'                    => array( '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->', false ),
			'empty'                           => array( '', false ),
		);
	}

	#[Test]
	#[DataProvider( 'block_content' )]
	public function should_find_a_catalogue_with_the_switch_on_at_any_depth( string $content, bool $expected ): void {
		self::assertSame( $expected, agend_apps_records_blocks_require_login( parse_blocks( $content ) ) );
	}

	// -- mirroring a block save ---------------------------------------------

	#[Test]
	public function should_flag_a_page_saved_with_the_switch_on(): void {
		$this->seedPage( 42, self::ON );

		agend_apps_records_directory_mirror_blocks_flag( 42 );

		self::assertSame( array( 'blocks' ), $this->flagOf( 42 ) );
	}

	#[Test]
	public function should_flag_a_page_when_any_one_of_several_catalogues_is_on(): void {
		$this->seedPage( 42, self::OFF . self::ON . self::OFF );

		agend_apps_records_directory_mirror_blocks_flag( 42, get_post( 42 ) );

		self::assertSame( array( 'blocks' ), $this->flagOf( 42 ) );
	}

	#[Test]
	public function should_remove_the_flag_when_the_switch_is_turned_off_and_the_page_saved(): void {
		$this->seedPage( 42, self::ON );
		agend_apps_records_directory_mirror_blocks_flag( 42 );

		$this->seedPage( 42, self::OFF );
		agend_apps_records_directory_mirror_blocks_flag( 42 );

		self::assertNull( $this->flagOf( 42 ) );
		self::assertFalse( agend_apps_records_directory_detail_requires_login( 42 ) );
	}

	#[Test]
	public function should_remove_the_flag_when_the_catalogue_is_removed_from_the_page(): void {
		$this->seedPage( 42, self::ON );
		agend_apps_records_directory_mirror_blocks_flag( 42 );

		$this->seedPage( 42, '<!-- wp:paragraph --><p>Gone</p><!-- /wp:paragraph -->' );
		agend_apps_records_directory_mirror_blocks_flag( 42 );

		self::assertNull( $this->flagOf( 42 ) );
	}

	#[Test]
	public function should_not_clear_what_another_builder_recorded_when_the_page_is_saved_without_blocks(): void {
		// A Quick Edit of an Elementor page runs the block mirror too.
		$this->seedPage( 42, '' );
		agend_apps_records_directory_set_login_source( 42, 'elementor', true );

		agend_apps_records_directory_mirror_blocks_flag( 42 );

		self::assertSame( array( 'elementor' ), $this->flagOf( 42 ) );
	}

	#[Test]
	public function should_ignore_a_post_that_is_not_a_page(): void {
		$this->seedPage( 42, self::ON, 'post' );

		agend_apps_records_directory_mirror_blocks_flag( 42 );

		self::assertNull( $this->flagOf( 42 ) );
	}

	#[Test]
	public function should_ignore_a_post_that_does_not_exist(): void {
		agend_apps_records_directory_mirror_blocks_flag( 99 );

		self::assertNull( $this->flagOf( 99 ) );
	}

	// -- backfill -----------------------------------------------------------

	#[Test]
	public function should_backfill_the_blocks_source_and_ask_other_builders_to_record_theirs(): void {
		$this->seedPage( 42, self::ON );
		$asked = array();
		add_action(
			'agend_apps_records_directory_backfill_login_flag',
			static function ( $page_id ) use ( &$asked ) {
				$asked[] = $page_id;
			},
			10,
			2
		);

		agend_apps_records_directory_backfill_login_flag( 42 );

		self::assertSame( array( 'blocks' ), $this->flagOf( 42 ) );
		self::assertSame( array( 42 ), $asked );
	}

	#[Test]
	public function should_not_backfill_a_missing_page_or_a_post(): void {
		$this->seedPage( 7, self::ON, 'post' );
		$asked = 0;
		add_action(
			'agend_apps_records_directory_backfill_login_flag',
			static function () use ( &$asked ) {
				++$asked;
			}
		);

		agend_apps_records_directory_backfill_login_flag( 0 );
		agend_apps_records_directory_backfill_login_flag( 99 );
		agend_apps_records_directory_backfill_login_flag( 7 );

		self::assertSame( 0, $asked );
		self::assertNull( $this->flagOf( 7 ) );
	}

	#[Test]
	public function should_backfill_the_dedicated_page_once_per_version_and_stamp_it(): void {
		$this->seedPage( 42, self::ON );
		$this->dedicate( 42 );

		agend_apps_records_directory_maybe_backfill_login_flag();

		self::assertSame( array( 'blocks' ), $this->flagOf( 42 ) );
		self::assertSame( AGEND_APPS_RECORDS_LOGIN_BACKFILL_VERSION, get_option( AGEND_APPS_RECORDS_LOGIN_BACKFILL_OPTION ) );

		// A later request does nothing, even though the page now differs.
		$this->seedPage( 42, self::OFF );
		agend_apps_records_directory_maybe_backfill_login_flag();

		self::assertSame( array( 'blocks' ), $this->flagOf( 42 ) );
	}

	#[Test]
	public function should_backfill_again_when_the_backfill_version_changes(): void {
		$this->seedPage( 42, self::ON );
		$this->dedicate( 42 );
		Agend_Test_WP::$options[ AGEND_APPS_RECORDS_LOGIN_BACKFILL_OPTION ] = 'an-older-version';

		agend_apps_records_directory_maybe_backfill_login_flag();

		self::assertSame( array( 'blocks' ), $this->flagOf( 42 ) );
		self::assertSame( AGEND_APPS_RECORDS_LOGIN_BACKFILL_VERSION, get_option( AGEND_APPS_RECORDS_LOGIN_BACKFILL_OPTION ) );
	}

	#[Test]
	public function should_stamp_the_backfill_without_a_dedicated_page(): void {
		$this->seedPage( 50, self::ON );

		agend_apps_records_directory_maybe_backfill_login_flag();

		self::assertNull( $this->flagOf( 50 ) );
		self::assertSame( AGEND_APPS_RECORDS_LOGIN_BACKFILL_VERSION, get_option( AGEND_APPS_RECORDS_LOGIN_BACKFILL_OPTION ) );
	}

	#[Test]
	public function should_backfill_a_page_when_it_becomes_the_dedicated_directory_page(): void {
		$this->seedPage( 60, self::ON );
		$this->seedPage( 61, self::ON );

		agend_apps_records_directory_backfill_on_page_updated( 0, 60 );
		agend_apps_records_directory_backfill_on_page_added( AGEND_APPS_RECORDS_DIRECTORY_PAGE_OPTION, '61' );

		self::assertSame( array( 'blocks' ), $this->flagOf( 60 ) );
		self::assertSame( array( 'blocks' ), $this->flagOf( 61 ) );
	}

	#[Test]
	public function should_run_the_redirect_ahead_of_the_server_rendered_detail_swap(): void {
		$login = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/detail-login.php' );
		$ssr   = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/ssr-detail.php' );

		self::assertSame( 1, preg_match( "/add_action\( 'wp', 'agend_apps_records_maybe_redirect_directory_detail_to_login', (\d+) \);/", $login, $redirect ) );
		self::assertSame( 1, preg_match( "/add_action\( 'wp', 'agend_apps_records_ssr_maybe_render_detail', (\d+) \);/", $ssr, $swap ) );
		self::assertLessThan( (int) $swap[1], (int) $redirect[1] );
		self::assertSame( 4, (int) $redirect[1] );
	}

	#[Test]
	public function should_mirror_on_the_page_save_hook_and_backfill_on_init(): void {
		$login = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/detail-login.php' );

		self::assertStringContainsString( "add_action( 'save_post_page', 'agend_apps_records_directory_mirror_blocks_flag', 10, 2 );", $login );
		self::assertStringContainsString( "add_action( 'init', 'agend_apps_records_directory_maybe_backfill_login_flag', 12 );", $login );
	}

	// -- the redirect decision ----------------------------------------------

	/** The usual gated request: a signed-out GET for a listing on the dedicated page. */
	private function gatedRequest(): void {
		$this->seedPage( 42 );
		$this->setFlag( 42 );
		$this->dedicate( 42 );
		Agend_Test_WP::$queried_object_id                = 42;
		Agend_Test_WP::$query_vars['agend_dir_listing']  = 'jane-smith';
	}

	private function expectedTarget( int $page_id = 42 ): string {
		return wp_login_url( 'https://example.test/page-' . $page_id . '/listing/jane-smith/' );
	}

	#[Test]
	public function should_send_a_signed_out_visitor_to_sign_in_with_the_detail_as_the_return_address(): void {
		$this->gatedRequest();

		self::assertSame( $this->expectedTarget(), agend_apps_records_directory_login_redirect_target() );
		self::assertNotSame( '', agend_apps_records_directory_login_redirect_target() );
	}

	#[Test]
	public function should_not_redirect_a_signed_in_visitor(): void {
		$this->gatedRequest();
		$GLOBALS['agend_test_current_user_id'] = 7;

		self::assertSame( '', agend_apps_records_directory_login_redirect_target() );
	}

	#[Test]
	public function should_not_redirect_when_the_switch_is_off(): void {
		$this->gatedRequest();
		unset( $GLOBALS['agend_test_post_meta'][42][ self::META ] );

		self::assertSame( '', agend_apps_records_directory_login_redirect_target() );
	}

	#[Test]
	public function should_not_redirect_a_request_with_no_listing(): void {
		$this->gatedRequest();
		Agend_Test_WP::$query_vars = array();

		self::assertSame( '', agend_apps_records_directory_login_redirect_target() );
	}

	#[Test]
	public function should_read_the_legacy_query_var_and_build_the_matching_detail_url(): void {
		$this->gatedRequest();
		Agend_Test_WP::$query_vars = array( 'agend_listing' => 'jane-smith' );
		unset( Agend_Test_WP::$options['permalink_structure'] );

		self::assertSame(
			wp_login_url( 'https://example.test/page-42/?agend_listing=jane-smith' ),
			agend_apps_records_directory_login_redirect_target()
		);
	}

	#[Test]
	public function should_prefer_the_pretty_query_var_when_both_are_present(): void {
		$this->gatedRequest();
		Agend_Test_WP::$query_vars['agend_listing'] = 'someone-else';

		self::assertSame( $this->expectedTarget(), agend_apps_records_directory_login_redirect_target() );
	}

	#[Test]
	public function should_sanitise_the_slug_before_building_the_return_address(): void {
		$this->gatedRequest();
		Agend_Test_WP::$query_vars['agend_dir_listing'] = 'Jane Smith!';

		self::assertSame( $this->expectedTarget(), agend_apps_records_directory_login_redirect_target() );
	}

	#[Test]
	public function should_read_the_flag_from_the_dedicated_page_not_the_page_the_request_landed_on(): void {
		$this->gatedRequest();
		$this->seedPage( 50 );
		$this->setFlag( 50 );
		unset( $GLOBALS['agend_test_post_meta'][42][ self::META ] );
		Agend_Test_WP::$queried_object_id = 50;

		self::assertSame( '', agend_apps_records_directory_login_redirect_target() );
	}

	#[Test]
	public function should_read_the_flag_from_the_queried_page_when_no_dedicated_page_is_set(): void {
		$this->gatedRequest();
		$this->seedPage( 50 );
		$this->setFlag( 50 );
		unset( Agend_Test_WP::$options[ AGEND_APPS_RECORDS_DIRECTORY_PAGE_OPTION ] );
		Agend_Test_WP::$queried_object_id = 50;

		self::assertSame( $this->expectedTarget( 50 ), agend_apps_records_directory_login_redirect_target() );
	}

	#[Test]
	public function should_fall_back_to_the_queried_page_when_the_dedicated_page_is_not_published(): void {
		$this->gatedRequest();
		$this->seedPage( 42, '', 'page', 'trash' );
		$this->seedPage( 50 );
		$this->setFlag( 50 );
		Agend_Test_WP::$queried_object_id = 50;

		self::assertSame( $this->expectedTarget( 50 ), agend_apps_records_directory_login_redirect_target() );
	}

	/** @return array<string, array{string}> */
	public static function requests_that_are_not_a_front_end_page_view(): array {
		return array(
			'wp-admin'      => array( 'agend_test_is_admin' ),
			'ajax'          => array( 'agend_test_doing_ajax' ),
			'cron'          => array( 'agend_test_doing_cron' ),
			'feed'          => array( 'agend_test_is_feed' ),
			'embed'         => array( 'agend_test_is_embed' ),
			'secondary query' => array( 'agend_test_not_main_query' ),
		);
	}

	#[Test]
	#[DataProvider( 'requests_that_are_not_a_front_end_page_view' )]
	public function should_not_redirect_a_request_that_is_not_a_front_end_page_view( string $flag ): void {
		$this->gatedRequest();
		$GLOBALS[ $flag ] = true;

		self::assertSame( '', agend_apps_records_directory_login_redirect_target() );
	}

	/** @return array<string, array{string}> */
	public static function methods_other_than_get(): array {
		return array(
			'POST'    => array( 'POST' ),
			'HEAD'    => array( 'HEAD' ),
			'PUT'     => array( 'PUT' ),
			'OPTIONS' => array( 'OPTIONS' ),
		);
	}

	#[Test]
	#[DataProvider( 'methods_other_than_get' )]
	public function should_only_redirect_a_get_request( string $method ): void {
		$this->gatedRequest();
		$_SERVER['REQUEST_METHOD'] = $method;

		self::assertSame( '', agend_apps_records_directory_login_redirect_target() );
	}

	#[Test]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function should_not_redirect_a_rest_request(): void {
		define( 'REST_REQUEST', true );
		$this->gatedRequest();

		self::assertSame( '', agend_apps_records_directory_login_redirect_target() );
	}

	#[Test]
	public function should_make_no_gateway_call_to_decide(): void {
		$this->gatedRequest();

		agend_apps_records_directory_login_redirect_target();

		self::assertSame( array(), Agend_Test_WP::$requests );
		self::assertTrue( class_exists( Agend_Apps_Records_Pages::class ) );
	}
}

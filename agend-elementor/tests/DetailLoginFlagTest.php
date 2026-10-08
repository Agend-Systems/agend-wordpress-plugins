<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Elementor_Detail_Login_Flag;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/pages.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/detail-login.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-detail-login-flag.php';

/**
 * Minimal Elementor document: only the one method the save hook reads.
 */
final class Detail_Login_Flag_Test_Document {

	private int $id;

	public function __construct( int $id ) {
		$this->id = $id;
	}

	public function get_main_id(): int {
		return $this->id;
	}
}

/**
 * {@see Agend_Elementor_Detail_Login_Flag}: the Elementor side of the page flag
 * behind "Require sign-in to view details". The widget's switch is mirrored to
 * post meta on save, so a detail request can read it without Elementor.
 */
#[CoversClass( Agend_Elementor_Detail_Login_Flag::class )]
final class DetailLoginFlagTest extends TestCase {

	private const META = '_agend_apps_directory_require_login';

	/**
	 * A widget element, in `_elementor_data` shape.
	 *
	 * @param string $widget_type Elementor widget type.
	 * @param array  $settings    Widget settings.
	 * @return array<string, mixed>
	 */
	private function widget( string $widget_type, array $settings ): array {
		return array(
			'id'         => 'w' . substr( md5( $widget_type . wp_json_encode( $settings ) ), 0, 6 ),
			'elType'     => 'widget',
			'widgetType' => $widget_type,
			'settings'   => $settings,
		);
	}

	private function catalogue( bool $on ): array {
		return $this->widget( 'agend-directory-catalogue', $on ? array( 'require_login_for_detail' => 'yes' ) : array() );
	}

	/**
	 * @param array $elements Child elements.
	 * @return array<string, mixed>
	 */
	private function container( array $elements ): array {
		return array(
			'id'       => 'c1',
			'elType'   => 'container',
			'settings' => array(),
			'elements' => $elements,
		);
	}

	private function seedPage( int $id, ?array $tree, string $type = 'page', bool $as_json = true ): void {
		$GLOBALS['agend_test_posts'][ $id ] = array(
			'ID'          => $id,
			'post_type'   => $type,
			'post_status' => 'publish',
		);

		if ( null !== $tree ) {
			$GLOBALS['agend_test_post_meta'][ $id ]['_elementor_data'] = $as_json ? wp_json_encode( $tree ) : $tree;
		}
	}

	private function flagOf( int $id ) {
		return $GLOBALS['agend_test_post_meta'][ $id ][ self::META ] ?? null;
	}

	// -- reading an element tree --------------------------------------------

	/** @return array<string, array{array, bool}> */
	public static function trees(): array {
		$widget    = static fn( string $type, array $settings ): array => array(
			'elType'     => 'widget',
			'widgetType' => $type,
			'settings'   => $settings,
		);
		$on        = $widget( 'agend-directory-catalogue', array( 'require_login_for_detail' => 'yes' ) );
		$off       = $widget( 'agend-directory-catalogue', array() );
		$wrap      = static fn( array $children ): array => array(
			'elType'   => 'container',
			'settings' => array(),
			'elements' => $children,
		);

		return array(
			'switch on at the top level'        => array( array( $on ), true ),
			'switch off (never saved)'          => array( array( $off ), false ),
			'switch off (saved empty)'          => array( array( $widget( 'agend-directory-catalogue', array( 'require_login_for_detail' => '' ) ) ), false ),
			'nested in section, column, widget' => array( array( $wrap( array( $wrap( array( $wrap( array( $on ) ) ) ) ) ) ), true ),
			'two catalogues, second on'         => array( array( $off, $wrap( array( $on ) ) ), true ),
			'two catalogues, first on'          => array( array( $on, $off ), true ),
			'two catalogues, both off'          => array( array( $off, $wrap( array( $off ) ) ), false ),
			'same setting on another widget'    => array( array( $widget( 'agend-events-catalogue', array( 'require_login_for_detail' => 'yes' ) ) ), false ),
			'same setting on a container'       => array( array( array( 'elType' => 'container', 'settings' => array( 'require_login_for_detail' => 'yes' ), 'elements' => array() ) ), false ),
			'no widgets'                        => array( array(), false ),
		);
	}

	#[Test]
	#[DataProvider( 'trees' )]
	public function should_find_a_catalogue_with_the_switch_on_at_any_depth( array $tree, bool $expected ): void {
		self::assertSame( $expected, Agend_Elementor_Detail_Login_Flag::from_elements( $tree ) );
	}

	#[Test]
	public function should_read_a_saved_document_from_its_json_or_decoded_meta(): void {
		$this->seedPage( 42, array( $this->container( array( $this->catalogue( true ) ) ) ) );
		$this->seedPage( 43, array( $this->container( array( $this->catalogue( true ) ) ) ), 'page', false );
		$this->seedPage( 44, array( $this->catalogue( false ) ) );
		$this->seedPage( 45, null );

		self::assertTrue( Agend_Elementor_Detail_Login_Flag::for_post( 42 ) );
		self::assertTrue( Agend_Elementor_Detail_Login_Flag::for_post( 43 ) );
		self::assertFalse( Agend_Elementor_Detail_Login_Flag::for_post( 44 ) );
		self::assertFalse( Agend_Elementor_Detail_Login_Flag::for_post( 45 ) );
	}

	#[Test]
	public function should_treat_unreadable_document_data_as_switch_off(): void {
		$this->seedPage( 42, null );
		$GLOBALS['agend_test_post_meta'][42]['_elementor_data'] = '{not json';

		self::assertFalse( Agend_Elementor_Detail_Login_Flag::for_post( 42 ) );
	}

	// -- mirroring on save --------------------------------------------------

	#[Test]
	public function should_flag_a_page_when_it_is_saved_with_the_switch_on(): void {
		$this->seedPage( 42, array( $this->catalogue( true ) ) );

		Agend_Elementor_Detail_Login_Flag::on_after_save( new Detail_Login_Flag_Test_Document( 42 ) );

		self::assertSame( array( 'elementor' ), $this->flagOf( 42 ) );
		self::assertTrue( agend_apps_records_directory_detail_requires_login( 42 ) );
	}

	#[Test]
	public function should_flag_a_page_when_any_one_of_several_catalogues_is_on(): void {
		$this->seedPage( 42, array( $this->catalogue( false ), $this->container( array( $this->catalogue( true ), $this->catalogue( false ) ) ) ) );

		Agend_Elementor_Detail_Login_Flag::on_after_save( new Detail_Login_Flag_Test_Document( 42 ) );

		self::assertSame( array( 'elementor' ), $this->flagOf( 42 ) );
	}

	#[Test]
	public function should_delete_the_flag_when_the_switch_is_turned_off_and_the_page_saved(): void {
		$this->seedPage( 42, array( $this->catalogue( true ) ) );
		Agend_Elementor_Detail_Login_Flag::on_after_save( new Detail_Login_Flag_Test_Document( 42 ) );

		$this->seedPage( 42, array( $this->catalogue( false ) ) );
		Agend_Elementor_Detail_Login_Flag::on_after_save( new Detail_Login_Flag_Test_Document( 42 ) );

		self::assertNull( $this->flagOf( 42 ) );
		self::assertFalse( agend_apps_records_directory_detail_requires_login( 42 ) );
	}

	#[Test]
	public function should_delete_the_flag_when_the_catalogue_is_removed(): void {
		$this->seedPage( 42, array( $this->catalogue( true ) ) );
		Agend_Elementor_Detail_Login_Flag::on_after_save( new Detail_Login_Flag_Test_Document( 42 ) );

		$this->seedPage( 42, array( $this->widget( 'heading', array( 'title' => 'Hello' ) ) ) );
		Agend_Elementor_Detail_Login_Flag::on_after_save( new Detail_Login_Flag_Test_Document( 42 ) );

		self::assertNull( $this->flagOf( 42 ) );
	}

	#[Test]
	public function should_leave_the_block_editors_source_alone(): void {
		$this->seedPage( 42, array( $this->catalogue( false ) ) );
		agend_apps_records_directory_set_login_source( 42, 'blocks', true );

		Agend_Elementor_Detail_Login_Flag::on_after_save( new Detail_Login_Flag_Test_Document( 42 ) );

		self::assertSame( array( 'blocks' ), $this->flagOf( 42 ) );
	}

	#[Test]
	public function should_ignore_a_saved_template_or_other_post_type(): void {
		$this->seedPage( 42, array( $this->catalogue( true ) ), 'elementor_library' );

		Agend_Elementor_Detail_Login_Flag::on_after_save( new Detail_Login_Flag_Test_Document( 42 ) );

		self::assertNull( $this->flagOf( 42 ) );
	}

	#[Test]
	public function should_ignore_a_save_that_is_not_an_elementor_document(): void {
		Agend_Elementor_Detail_Login_Flag::on_after_save( new \stdClass() );
		Agend_Elementor_Detail_Login_Flag::on_after_save( new Detail_Login_Flag_Test_Document( 0 ) );

		self::assertSame( array(), $GLOBALS['agend_test_post_meta'] );
	}

	// -- hooks and backfill -------------------------------------------------

	#[Test]
	public function should_register_the_save_hook_the_core_backfill_listener_and_the_init_backfill(): void {
		Agend_Elementor_Detail_Login_Flag::init();

		self::assertContains( array( Agend_Elementor_Detail_Login_Flag::class, 'on_after_save' ), Agend_Test_WP::$actions['elementor/document/after_save'] );
		self::assertContains( array( Agend_Elementor_Detail_Login_Flag::class, 'on_core_backfill' ), Agend_Test_WP::$actions['agend_apps_records_directory_backfill_login_flag'] );

		$index = array_search( array( Agend_Elementor_Detail_Login_Flag::class, 'maybe_backfill' ), Agend_Test_WP::$actions['init'], true );
		self::assertNotFalse( $index );
		// After core's own backfill, which runs at 12.
		self::assertSame( 13, Agend_Test_WP::$action_priorities['init'][ $index ] );
	}

	#[Test]
	public function should_record_its_source_when_core_asks_every_builder_to_backfill_a_page(): void {
		$this->seedPage( 42, array( $this->catalogue( true ) ) );
		Agend_Elementor_Detail_Login_Flag::init();

		agend_apps_records_directory_backfill_login_flag( 42 );

		self::assertSame( array( 'elementor' ), $this->flagOf( 42 ) );
	}

	#[Test]
	public function should_backfill_the_dedicated_page_once_per_version_and_stamp_it(): void {
		$this->seedPage( 42, array( $this->catalogue( true ) ) );
		Agend_Test_WP::$options[ AGEND_APPS_RECORDS_DIRECTORY_PAGE_OPTION ] = 42;

		Agend_Elementor_Detail_Login_Flag::maybe_backfill();

		self::assertSame( array( 'elementor' ), $this->flagOf( 42 ) );
		self::assertSame( Agend_Elementor_Detail_Login_Flag::BACKFILL_VERSION, get_option( Agend_Elementor_Detail_Login_Flag::BACKFILL_OPTION ) );

		// A later request does nothing, even though the page now differs.
		$this->seedPage( 42, array( $this->catalogue( false ) ) );
		Agend_Elementor_Detail_Login_Flag::maybe_backfill();

		self::assertSame( array( 'elementor' ), $this->flagOf( 42 ) );
	}

	#[Test]
	public function should_backfill_again_when_its_backfill_version_changes(): void {
		$this->seedPage( 42, array( $this->catalogue( true ) ) );
		Agend_Test_WP::$options[ AGEND_APPS_RECORDS_DIRECTORY_PAGE_OPTION ]            = 42;
		Agend_Test_WP::$options[ Agend_Elementor_Detail_Login_Flag::BACKFILL_OPTION ] = 'an-older-version';

		Agend_Elementor_Detail_Login_Flag::maybe_backfill();

		self::assertSame( array( 'elementor' ), $this->flagOf( 42 ) );
	}

	#[Test]
	public function should_stamp_the_backfill_without_a_dedicated_page(): void {
		$this->seedPage( 50, array( $this->catalogue( true ) ) );

		Agend_Elementor_Detail_Login_Flag::maybe_backfill();

		self::assertNull( $this->flagOf( 50 ) );
		self::assertSame( Agend_Elementor_Detail_Login_Flag::BACKFILL_VERSION, get_option( Agend_Elementor_Detail_Login_Flag::BACKFILL_OPTION ) );
	}
}

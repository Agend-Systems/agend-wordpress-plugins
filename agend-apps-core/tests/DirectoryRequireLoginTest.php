<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Records_Pages;
use Agend_Apps_Records_Record_Context;
use Agend_Apps_Template_Renderer;
use Agend_Apps_Templates;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/interface-agend-apps-template-renderer.php';

/**
 * A card template renderer that draws the three widgets which can link to a
 * record's detail, the way the real renderers do: push the record context,
 * render, pop.
 */
final class Require_Login_Test_Renderer implements Agend_Apps_Template_Renderer {

	public function is_valid_template( int $template_id ): bool {
		return true;
	}

	public function ensure_styles( int $template_id ): void {}

	public function render( int $template_id, string $type, array $record, array $extra = array(), bool $with_css = false ): string {
		Agend_Apps_Records_Record_Context::push( $type, $record, $extra );
		try {
			return agend_apps_records_render_record_link( array() )
				. agend_apps_records_render_record_field(
					array(
						'field'          => 'listing:name',
						'link_to_detail' => 'yes',
					)
				)
				. agend_apps_records_render_record_image( array( 'link_to_detail' => 'yes' ) );
		} finally {
			Agend_Apps_Records_Record_Context::pop();
		}
	}

	public function render_plain( int $template_id, bool $with_css = false ): string {
		return '';
	}
}

/**
 * "Require sign-in to view details" on the directory catalogue: the decision
 * (`Agend_Apps_Records_Pages::login_target()`, shared by the rendered links and
 * any request-time redirect), the schema control, the client config, and the
 * links the server renders for a card.
 *
 * Each case runs in its own process: the render doubles define the gateway
 * list functions, and other tests in this suite assert those do NOT exist.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass( Agend_Apps_Records_Pages::class )]
#[CoversFunction( 'agend_apps_records_render_cards' )]
#[CoversFunction( 'agend_apps_records_directory_catalogue_login_gate' )]
#[CoversFunction( 'agend_apps_records_directory_catalogue_build_config' )]
final class DirectoryRequireLoginTest extends TestCase {

	private const LISTING_URL = 'https://example.test/page-42/listing/jane-smith/';

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/tests/render-doubles.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/preview-records.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/record-link.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/record-field.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/record-image.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/directory-catalogue.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/blocks.php';
		agend_render_test_reset();
		Agend_Apps_Records_Record_Context::reset();

		// The host page the catalogue sits on, so a detail URL can be built.
		$GLOBALS['agend_test_posts'][42] = array(
			'ID'          => 42,
			'post_type'   => 'page',
			'post_status' => 'publish',
		);
	}

	private function signIn(): void {
		$GLOBALS['agend_test_current_user_id'] = 7;
	}

	private function loginUrlFor( string $detail_url ): string {
		return wp_login_url( $detail_url );
	}

	/**
	 * A listing record, as the list endpoint returns it.
	 *
	 * @return array<string, string>
	 */
	private function listing(): array {
		return array(
			'slug'           => 'jane-smith',
			'name'           => 'Jane Smith',
			'hero_image_url' => 'https://cdn.test/jane.jpg',
		);
	}

	/**
	 * The client config a catalogue render embeds.
	 *
	 * @param string $html Rendered catalogue markup.
	 * @return array<string, mixed>
	 */
	private function configFrom( string $html ): array {
		self::assertSame( 1, preg_match( '/data-agend-directory-config="([^"]*)"/', $html, $m ) );

		return (array) json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
	}

	// -- the shared decision -------------------------------------------------

	#[Test]
	public function should_send_a_signed_out_visitor_to_sign_in_with_the_detail_as_the_return_address(): void {
		self::assertSame(
			$this->loginUrlFor( self::LISTING_URL ),
			Agend_Apps_Records_Pages::login_target( self::LISTING_URL, true )
		);
		self::assertStringContainsString( 'redirect_to=', Agend_Apps_Records_Pages::login_target( self::LISTING_URL, true ) );
	}

	#[Test]
	public function should_keep_the_detail_url_when_sign_in_is_not_required(): void {
		self::assertSame( '', Agend_Apps_Records_Pages::login_target( self::LISTING_URL, false ) );
	}

	#[Test]
	public function should_keep_the_detail_url_for_a_signed_in_visitor(): void {
		$this->signIn();

		self::assertSame( '', Agend_Apps_Records_Pages::login_target( self::LISTING_URL, true ) );
	}

	#[Test]
	public function should_have_nothing_to_return_to_without_a_detail_url(): void {
		self::assertSame( '', Agend_Apps_Records_Pages::login_target( '', true ) );
	}

	// -- who the setting applies to -----------------------------------------

	/** @return array<string, array{array<string, mixed>, bool, bool}> */
	public static function gate_cases(): array {
		return array(
			'on, signed out'       => array( array( 'require_login_for_detail' => 'yes' ), false, true ),
			'on, signed in'        => array( array( 'require_login_for_detail' => 'yes' ), true, false ),
			'off (empty string)'   => array( array( 'require_login_for_detail' => '' ), false, false ),
			'off (no)'             => array( array( 'require_login_for_detail' => 'no' ), false, false ),
			'not set'              => array( array(), false, false ),
		);
	}

	/**
	 * @param array<string, mixed> $settings  Surface settings.
	 * @param bool                 $signed_in Whether the visitor is signed in.
	 * @param bool                 $expected  Whether the sign-in requirement applies.
	 */
	#[Test]
	#[DataProvider( 'gate_cases' )]
	public function should_apply_the_setting_only_to_a_signed_out_visitor( array $settings, bool $signed_in, bool $expected ): void {
		if ( $signed_in ) {
			$this->signIn();
		}

		self::assertSame( $expected, agend_apps_records_directory_catalogue_login_gate( $settings ) );
	}

	// -- the schema control --------------------------------------------------

	#[Test]
	public function should_declare_the_toggle_in_the_detail_view_section_defaulting_off(): void {
		$fields = array();
		foreach ( agend_apps_records_surface_schema( 'directory-catalogue' )['sections'] as $section ) {
			if ( 'section_detail' === $section['id'] ) {
				$fields = array_column( $section['fields'], null, 'name' );
			}
		}

		self::assertArrayHasKey( 'require_login_for_detail', $fields );
		self::assertSame( 'toggle', $fields['require_login_for_detail']['type'] );
		self::assertFalse( $fields['require_login_for_detail']['default'] );
		self::assertSame( 'Require sign-in to view details', $fields['require_login_for_detail']['label'] );
		self::assertNotSame( '', $fields['require_login_for_detail']['description'] );
		self::assertArrayNotHasKey( 'condition', $fields['require_login_for_detail'] );
	}

	#[Test]
	public function should_reach_the_block_as_an_attribute_and_come_back_as_a_setting(): void {
		$schema     = agend_apps_records_surface_schema( 'directory-catalogue' );
		$attributes = agend_apps_records_block_attributes( $schema );

		self::assertSame( 'boolean', $attributes['require_login_for_detail']['type'] );
		self::assertFalse( $attributes['require_login_for_detail']['default'] );

		$settings = agend_apps_records_settings_from_attributes( $schema, array( 'require_login_for_detail' => true ) );

		self::assertSame( 'yes', $settings['require_login_for_detail'] );
	}

	// -- the client config ---------------------------------------------------

	#[Test]
	public function should_write_the_requirement_and_sign_in_page_to_the_config_for_a_signed_out_visitor(): void {
		$config = agend_apps_records_directory_catalogue_build_config( array( 'require_login_for_detail' => 'yes' ) );

		self::assertTrue( $config['requireLogin'] );
		self::assertSame( wp_login_url(), $config['loginUrl'] );
	}

	/** @return array<string, array{array<string, mixed>, bool}> */
	public static function configs_without_the_requirement(): array {
		return array(
			'setting off'       => array( array( 'require_login_for_detail' => '' ), false ),
			'setting not set'   => array( array(), false ),
			'signed in, on'     => array( array( 'require_login_for_detail' => 'yes' ), true ),
		);
	}

	/**
	 * @param array<string, mixed> $settings  Surface settings.
	 * @param bool                 $signed_in Whether the visitor is signed in.
	 */
	#[Test]
	#[DataProvider( 'configs_without_the_requirement' )]
	public function should_leave_the_config_exactly_as_before_when_the_requirement_does_not_apply( array $settings, bool $signed_in ): void {
		if ( $signed_in ) {
			$this->signIn();
		}

		$config = agend_apps_records_directory_catalogue_build_config( $settings );

		self::assertArrayNotHasKey( 'requireLogin', $config );
		self::assertArrayNotHasKey( 'loginUrl', $config );
		self::assertSame( agend_apps_records_directory_catalogue_build_config( array() ), $config );
	}

	// -- the links a card renders -------------------------------------------

	#[Test]
	public function should_point_the_card_anchor_at_sign_in_for_a_signed_out_visitor(): void {
		$cards = agend_apps_records_render_cards( 'listing', 7, array( $this->listing() ), array( 'require_login_for_detail' => true, 'host_page_id' => 42 ) );

		self::assertSame( $this->loginUrlFor( self::LISTING_URL ), $cards[0]['url'] );
		self::assertStringContainsString( 'href="' . esc_url( $this->loginUrlFor( self::LISTING_URL ) ) . '"', $cards[0]['html'] );
		self::assertStringNotContainsString( 'href="' . esc_url( self::LISTING_URL ) . '"', $cards[0]['html'] );
	}

	#[Test]
	public function should_point_the_non_anchor_card_shell_at_sign_in_too(): void {
		$cards = agend_apps_records_render_cards( 'listing', 7, array( $this->listing() ), array( 'require_login_for_detail' => true, 'host_page_id' => 42, 'card_link_whole' => false ) );

		self::assertStringContainsString( 'data-agend-href="' . esc_url( $this->loginUrlFor( self::LISTING_URL ) ) . '"', $cards[0]['html'] );
	}

	#[Test]
	public function should_point_every_field_built_from_the_detail_url_at_sign_in(): void {
		Agend_Apps_Templates::reset();
		Agend_Apps_Templates::register_renderer( new Require_Login_Test_Renderer() );

		$cards = agend_apps_records_render_cards(
			'listing',
			7,
			array( $this->listing() ),
			array(
				'require_login_for_detail' => true,
				'host_page_id'             => 42,
				'card_link_whole'          => false,
			)
		);

		// The leading space keeps the card shell's own data-agend-href out of the count.
		$href  = ' href="' . esc_url( $this->loginUrlFor( self::LISTING_URL ) ) . '"';
		$count = substr_count( $cards[0]['html'], $href );

		// Agend Link, Agend Field and Agend Image each carry the sign-in URL,
		// and none of them still carries the bare detail URL.
		self::assertSame( 3, $count, $cards[0]['html'] );
		self::assertStringNotContainsString( 'href="' . esc_url( self::LISTING_URL ) . '"', $cards[0]['html'] );
	}

	#[Test]
	public function should_keep_every_detail_link_when_the_setting_is_off(): void {
		Agend_Apps_Templates::reset();
		Agend_Apps_Templates::register_renderer( new Require_Login_Test_Renderer() );

		$cards = agend_apps_records_render_cards( 'listing', 7, array( $this->listing() ), array( 'host_page_id' => 42, 'card_link_whole' => false ) );

		self::assertSame( self::LISTING_URL, $cards[0]['url'] );
		self::assertSame( 3, substr_count( $cards[0]['html'], ' href="' . esc_url( self::LISTING_URL ) . '"' ) );
		self::assertStringNotContainsString( 'wp-login.php', $cards[0]['html'] );
	}

	#[Test]
	public function should_keep_every_detail_link_for_a_signed_in_visitor(): void {
		$this->signIn();

		$cards = agend_apps_records_render_cards( 'listing', 7, array( $this->listing() ), array( 'require_login_for_detail' => true, 'host_page_id' => 42 ) );

		self::assertSame( self::LISTING_URL, $cards[0]['url'] );
		self::assertStringNotContainsString( 'wp-login.php', $cards[0]['html'] );
	}

	#[Test]
	public function should_never_rewrite_an_events_or_courses_card_link(): void {
		$GLOBALS['agend_test_posts'][43] = array(
			'ID'          => 43,
			'post_type'   => 'page',
			'post_status' => 'publish',
		);

		foreach ( array( 'event', 'course' ) as $type ) {
			$cards = agend_apps_records_render_cards( $type, 7, array( array( 'slug' => 'one' ) ), array( 'require_login_for_detail' => true, 'host_page_id' => 43 ) );

			self::assertSame( 'https://example.test/page-43/' . $type . '/one/', $cards[0]['url'] );
		}
	}

	// -- the whole catalogue -------------------------------------------------

	#[Test]
	public function should_render_a_templated_catalogue_with_sign_in_cards_and_config_for_a_signed_out_visitor(): void {
		$html   = agend_apps_records_render_directory_catalogue( array( 'card_template' => '7', 'require_login_for_detail' => 'yes' ) );
		$config = $this->configFrom( $html );

		self::assertTrue( $config['requireLogin'] );
		self::assertSame( wp_login_url(), $config['loginUrl'] );
		self::assertStringContainsString(
			'href="' . esc_url( $this->loginUrlFor( 'https://example.test/page-42/listing/listing-one/' ) ) . '"',
			$html
		);
		self::assertStringNotContainsString( 'href="https://example.test/page-42/listing/listing-one/"', $html );
	}

	#[Test]
	public function should_render_exactly_the_same_catalogue_for_a_signed_in_visitor_as_with_the_setting_off(): void {
		$off = agend_apps_records_render_directory_catalogue( array( 'card_template' => '7' ) );

		$this->signIn();
		$on = agend_apps_records_render_directory_catalogue( array( 'card_template' => '7', 'require_login_for_detail' => 'yes' ) );

		self::assertSame( $off, $on );
		self::assertStringNotContainsString( 'requireLogin', $on );
	}
}

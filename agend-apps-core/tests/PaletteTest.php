<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The colour source resolution order (Decision 2.2): the site palette, then
 * the plugin defaults, then the instance's own colour settings when
 * inheritance is off (US-1.3).
 */
final class PaletteTest extends TestCase {

	private const ROLES = array(
		'heading'    => '#1E2A4A',
		'body'       => '#26304D',
		'accent'     => '#FF6B55',
		'button'     => '#FF6B55',
		'buttonText' => '#FFFFFF',
	);

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
	}

	// -- agend_apps_records_site_palette() -----------------------------------

	#[Test]
	public function should_return_every_role_null_when_the_theme_has_no_theme_json(): void {
		Agend_Test_WP::$theme_has_theme_json = false;
		Agend_Test_WP::$global_styles        = array( 'color' => array( 'text' => '#123456' ) );

		self::assertSame(
			array(
				'heading'    => null,
				'body'       => null,
				'accent'     => null,
				'button'     => null,
				'buttonText' => null,
			),
			agend_apps_records_site_palette()
		);
	}

	#[Test]
	public function should_convert_a_preset_reference_to_a_css_variable_when_reading_a_role_path(): void {
		Agend_Test_WP::$theme_has_theme_json = true;
		Agend_Test_WP::$global_styles        = array(
			'elements' => array(
				'button' => array( 'color' => array( 'background' => 'var:preset|color|primary' ) ),
			),
		);

		self::assertSame( 'var(--wp--preset--color--primary)', agend_apps_records_site_palette()['button'] );
	}

	#[Test]
	public function should_fall_back_to_the_next_path_when_the_first_role_path_is_absent(): void {
		Agend_Test_WP::$theme_has_theme_json = true;
		Agend_Test_WP::$global_styles        = array( 'color' => array( 'text' => '#0a0a0a' ) );

		// 'heading' has no elements.heading.color.text in the tree above, so
		// it falls back to the second path, color.text (Decision 2.3).
		self::assertSame( '#0a0a0a', agend_apps_records_site_palette()['heading'] );
		self::assertSame( '#0a0a0a', agend_apps_records_site_palette()['body'] );
	}

	#[Test]
	public function should_prefer_the_filtered_palette_when_a_classic_theme_registers_one(): void {
		Agend_Test_WP::$theme_has_theme_json = false;
		Agend_Test_WP::set_filter(
			'agend_apps_records_site_palette',
			array(
				'heading'    => '#654321',
				'body'       => null,
				'accent'     => null,
				'button'     => null,
				'buttonText' => null,
			)
		);

		self::assertSame( '#654321', agend_apps_records_site_palette()['heading'] );
		self::assertNull( agend_apps_records_site_palette()['body'] );
	}

	#[Test]
	public function should_reject_an_unsafe_value_from_the_filter_and_return_null(): void {
		Agend_Test_WP::$theme_has_theme_json = false;
		Agend_Test_WP::set_filter(
			'agend_apps_records_site_palette',
			array(
				'heading'    => 'red;background:url(https://evil.test)',
				'body'       => null,
				'accent'     => null,
				'button'     => null,
				'buttonText' => null,
			)
		);

		self::assertNull( agend_apps_records_site_palette()['heading'] );
	}

	// -- agend_apps_records_resolve_colours() --------------------------------

	#[Test]
	public function should_yield_wordpress_source_when_inherit_is_on_and_the_palette_has_a_value(): void {
		Agend_Test_WP::$theme_has_theme_json = true;
		Agend_Test_WP::$global_styles        = array(
			'elements' => array(
				'button' => array( 'color' => array( 'background' => '#00ff00' ) ),
			),
		);

		$result = agend_apps_records_resolve_colours( array( 'inherit_colours' => 'yes' ), self::ROLES );

		self::assertSame( 'wordpress', $result['source'] );
		self::assertSame( '#00ff00', $result['colours']['button'] );
		// Roles the palette left null still take their default.
		self::assertSame( '#1E2A4A', $result['colours']['heading'] );
	}

	#[Test]
	public function should_yield_agend_source_when_inherit_is_on_and_the_palette_is_empty(): void {
		Agend_Test_WP::$theme_has_theme_json = false;

		$result = agend_apps_records_resolve_colours( array( 'inherit_colours' => 'yes' ), self::ROLES );

		self::assertSame( 'agend', $result['source'] );
		self::assertSame( self::ROLES, $result['colours'] );
	}

	#[Test]
	public function should_yield_custom_source_and_the_settings_values_when_inherit_is_off(): void {
		$result = agend_apps_records_resolve_colours(
			array(
				'inherit_colours' => '',
				'heading_colour'  => '#111111',
				'accent_colour'   => '#222222',
			),
			self::ROLES
		);

		self::assertSame( 'custom', $result['source'] );
		self::assertSame( '#111111', $result['colours']['heading'] );
		self::assertSame( '#222222', $result['colours']['accent'] );
		// button_colour was not set, so the role default applies.
		self::assertSame( '#FF6B55', $result['colours']['button'] );
	}

	#[Test]
	public function should_fall_back_to_the_role_default_when_a_manual_colour_is_unsafe(): void {
		$result = agend_apps_records_resolve_colours(
			array(
				'inherit_colours' => '',
				'heading_colour'  => 'red;background:url(https://evil.test)',
			),
			self::ROLES
		);

		self::assertSame( '#1E2A4A', $result['colours']['heading'] );
	}

	#[Test]
	public function should_yield_custom_source_when_inherit_colours_is_missing(): void {
		// Memberships has no inherit_colours control at all, so a settings
		// array without the key must resolve to 'custom', never 'wordpress'
		// or 'agend'.
		$result = agend_apps_records_resolve_colours( array(), self::ROLES );

		self::assertSame( 'custom', $result['source'] );
	}

	// -- agend_apps_records_colour_is_safe() ---------------------------------

	/** @return array<string, array{string, bool}> */
	public static function colours(): array {
		return array(
			'hex3'                  => array( '#f00', true ),
			'hex4'                  => array( '#f00a', true ),
			'hex6'                  => array( '#FF6B55', true ),
			'hex8'                  => array( '#FF6B55AA', true ),
			'hex uppercase letters' => array( '#ABCDEF', true ),
			'rgb'                   => array( 'rgb(255, 0, 0)', true ),
			'rgba with decimal'     => array( 'rgba(255, 0, 0, 0.5)', true ),
			'rgb space separated'   => array( 'rgb(255 0 0 / 50%)', true ),
			'hsl'                   => array( 'hsl(200deg, 50%, 50%)', true ),
			'hsla'                  => array( 'hsla(200, 50%, 50%, 0.5)', true ),
			'preset var'            => array( 'var(--wp--preset--color--primary)', true ),
			'empty string'          => array( '', false ),
			'css injection'         => array( 'red;background:url(https://evil.test)', false ),
			'javascript url'        => array( 'expression(alert(1))', false ),
			'plain colour name'     => array( 'red', false ),
			'malformed hex'         => array( '#12345', false ),
			'unmatched preset var'  => array( 'var(--my-custom-colour)', false ),
		);
	}

	#[Test]
	#[DataProvider( 'colours' )]
	public function should_allow_list_colour_values_per_decision_2_4( string $value, bool $expected ): void {
		self::assertSame( $expected, agend_apps_records_colour_is_safe( $value ) );
	}
}

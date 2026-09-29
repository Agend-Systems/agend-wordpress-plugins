<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Elementor_Global_Colours;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass( Agend_Elementor_Global_Colours::class )]
final class GlobalColoursTest extends TestCase {

	private const KIT = array(
		'primary'  => '#6699CC',
		'a1b2c3d4' => '#224488',
	);

	#[Test]
	public function should_replace_a_global_colour_reference_with_the_kit_colour(): void {
		$settings = array(
			'accent_colour' => '',
			'__globals__'   => array( 'accent_colour' => 'globals/colors?id=a1b2c3d4' ),
		);

		$resolved = Agend_Elementor_Global_Colours::resolve( $settings, self::KIT );

		$this->assertSame( '#224488', $resolved['accent_colour'] );
	}

	#[Test]
	public function should_override_a_stale_manual_value_when_a_global_is_chosen(): void {
		$settings = array(
			'accent_colour' => '#FF6B55',
			'__globals__'   => array( 'accent_colour' => 'globals/colors?id=primary' ),
		);

		$this->assertSame( '#6699CC', Agend_Elementor_Global_Colours::resolve( $settings, self::KIT )['accent_colour'] );
	}

	#[Test]
	public function should_leave_the_setting_alone_when_the_kit_no_longer_holds_the_colour(): void {
		$settings = array(
			'accent_colour' => '',
			'__globals__'   => array( 'accent_colour' => 'globals/colors?id=deleted1' ),
		);

		$this->assertSame( '', Agend_Elementor_Global_Colours::resolve( $settings, self::KIT )['accent_colour'] );
	}

	#[Test]
	public function should_ignore_global_typography_references(): void {
		$settings = array(
			'title_typography_typography' => 'custom',
			'__globals__'                 => array( 'title_typography_typography' => 'globals/typography?id=primary' ),
		);

		$this->assertSame( $settings, Agend_Elementor_Global_Colours::resolve( $settings, self::KIT ) );
	}

	#[Test]
	public function should_return_settings_unchanged_without_globals(): void {
		$settings = array( 'accent_colour' => '#123456' );

		$this->assertSame( $settings, Agend_Elementor_Global_Colours::resolve( $settings, self::KIT ) );
	}

	#[Test]
	public function should_resolve_global_colours_inside_repeater_rows(): void {
		$settings = array(
			'colour_rules' => array(
				array(
					'rule_match'      => 'Gold',
					'rule_background' => '',
					'__globals__'     => array( 'rule_background' => 'globals/colors?id=a1b2c3d4' ),
				),
				array( 'rule_match' => 'Silver', 'rule_background' => '#AA3300' ),
			),
		);

		$resolved = Agend_Elementor_Global_Colours::resolve( $settings, self::KIT );

		$this->assertSame( '#224488', $resolved['colour_rules'][0]['rule_background'] );
		$this->assertSame( '#AA3300', $resolved['colour_rules'][1]['rule_background'] );
	}

	#[Test]
	public function should_read_only_colour_references(): void {
		$this->assertSame( 'abc_1-2', Agend_Elementor_Global_Colours::colour_id( 'globals/colors?id=abc_1-2' ) );
		$this->assertSame( '', Agend_Elementor_Global_Colours::colour_id( 'globals/typography?id=primary' ) );
		$this->assertSame( '', Agend_Elementor_Global_Colours::colour_id( 'globals/colors?id=a"b' ) );
	}
}

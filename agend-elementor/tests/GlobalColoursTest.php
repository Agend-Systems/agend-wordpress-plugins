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
		'primary'  => '#6EC1E4',
		'292aa7d8' => '#0C5998',
	);

	#[Test]
	public function should_replace_a_global_colour_reference_with_the_kit_colour(): void {
		$settings = array(
			'accent_colour' => '',
			'__globals__'   => array( 'accent_colour' => 'globals/colors?id=292aa7d8' ),
		);

		$resolved = Agend_Elementor_Global_Colours::resolve( $settings, self::KIT );

		$this->assertSame( '#0C5998', $resolved['accent_colour'] );
	}

	#[Test]
	public function should_override_a_stale_manual_value_when_a_global_is_chosen(): void {
		$settings = array(
			'accent_colour' => '#FF6B55',
			'__globals__'   => array( 'accent_colour' => 'globals/colors?id=primary' ),
		);

		$this->assertSame( '#6EC1E4', Agend_Elementor_Global_Colours::resolve( $settings, self::KIT )['accent_colour'] );
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
	public function should_read_only_colour_references(): void {
		$this->assertSame( 'abc_1-2', Agend_Elementor_Global_Colours::colour_id( 'globals/colors?id=abc_1-2' ) );
		$this->assertSame( '', Agend_Elementor_Global_Colours::colour_id( 'globals/typography?id=primary' ) );
		$this->assertSame( '', Agend_Elementor_Global_Colours::colour_id( 'globals/colors?id=a"b' ) );
	}
}

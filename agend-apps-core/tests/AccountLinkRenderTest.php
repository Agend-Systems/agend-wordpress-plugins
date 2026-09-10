<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Elementor_Account_Link;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The account link markup moved out of the Elementor widget into core. The
 * fixture was recorded from the widget BEFORE the move, so equality here is
 * the proof that neither the core renderer nor the delegating widget changed
 * a byte of what a site receives (SPEC-INFRA-20260907-gutenberg-block-colours-
 * and-surfaces US-4.1).
 */
final class AccountLinkRenderTest extends TestCase {

	private const CUSTOM = array(
		'show_heading'       => '',
		'heading_text'       => 'Custom <Heading>',
		'linked_message'     => 'You are linked & good to go',
		'unlinked_message'   => 'Please link up',
		'button_label'       => 'Link now',
		'portal_link_label'  => 'Go to portal',
		'logged_out_message' => 'Log in first',
		'heading_colour'     => '#101010',
		'body_colour'        => '#202020',
		'accent_colour'      => '#303030',
		'button_colour'      => '#404040',
		'button_text_colour' => '#EFEFEF',
		'inherit_fonts'      => '',
		'inherit_colours'    => '',
	);

	/** @return array<string, array{string}> */
	public static function scenarios(): array {
		return array(
			'defaults' => array( 'defaults' ),
			'custom'   => array( 'custom' ),
		);
	}

	#[Test]
	#[DataProvider( 'scenarios' )]
	public function should_render_the_recorded_markup_when_core_renders_the_surface_directly( string $scenario ): void {
		self::assertSame(
			$this->expected( $scenario ),
			agend_apps_records_render_account_link( $this->settings( $scenario ) )
		);
	}

	#[Test]
	#[DataProvider( 'scenarios' )]
	public function should_render_the_recorded_markup_when_the_elementor_widget_delegates_to_core( string $scenario ): void {
		$widget = new Agend_Elementor_Account_Link( array(), null, $this->settings( $scenario ) );

		ob_start();
		( function () {
			$this->render();
		} )->call( $widget );

		self::assertSame( $this->expected( $scenario ), (string) ob_get_clean() );
	}

	#[Test]
	public function should_pass_through_the_manual_colours_unresolved_when_inheritance_is_off(): void {
		// Unlike member-login and the catalogues, this surface has no server-side
		// palette resolution: the manual colour settings and the inherit toggles
		// both pass straight through to the client, which does its own
		// inheritance (US-1.2 spec-vs-code finding, kept as-is by the extraction).
		$config = agend_apps_records_account_link_build_config(
			array(
				'heading_colour'  => '#123456',
				'inherit_colours' => 'yes',
			)
		);

		self::assertSame( '#123456', $config['colours']['heading'] );
		self::assertTrue( $config['theme']['inheritColours'] );
	}

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/account-link.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-account-link.php';
	}

	private function settings( string $scenario ): array {
		return 'custom' === $scenario ? self::CUSTOM : array();
	}

	private function expected( string $scenario ): string {
		$fixtures = json_decode(
			(string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/account-link-render.json' ),
			true
		);

		return $fixtures[ $scenario ];
	}
}

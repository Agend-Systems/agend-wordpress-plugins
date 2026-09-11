<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Elementor_Header_Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The header auth-link markup moved out of the Elementor widget into core.
 * The fixture was recorded from the widget BEFORE the move, so equality here
 * is the proof that neither the core renderer nor the delegating widget
 * changed a byte of what a site receives (SPEC-INFRA-20260907-gutenberg-
 * block-colours-and-surfaces US-4.1).
 *
 * `custom_url_array` and `custom_url_string` render identically on purpose:
 * `login_url` is a `url`-shaped `adapter` field, and the two scenarios drive
 * it with Elementor's URL-control shape and a Gutenberg block's plain-string
 * shape respectively, proving `agend_apps_records_normalise_url_setting()`
 * (format.php) treats them the same.
 */
final class HeaderAuthRenderTest extends TestCase {

	private const CUSTOM_URL_ARRAY = array(
		'logged_out_label' => 'Sign In',
		'logged_in_label'  => 'Portal',
		'sign_out_label'   => 'Log out',
		'login_url'        => array(
			'url'         => 'https://example.test/custom-login/',
			'is_external' => '',
			'nofollow'    => '',
		),
	);

	private const CUSTOM_URL_STRING = array(
		'logged_out_label' => 'Sign In',
		'logged_in_label'  => 'Portal',
		'sign_out_label'   => 'Log out',
		'login_url'        => 'https://example.test/custom-login/',
	);

	private const EMPTY_SIGN_OUT = array(
		'sign_out_label' => '',
	);

	/** @return array<string, array{string}> */
	public static function scenarios(): array {
		return array(
			'defaults'          => array( 'defaults' ),
			'custom_url_array'  => array( 'custom_url_array' ),
			'custom_url_string' => array( 'custom_url_string' ),
			'empty_sign_out'    => array( 'empty_sign_out' ),
		);
	}

	#[Test]
	#[DataProvider( 'scenarios' )]
	public function should_render_the_recorded_markup_when_core_renders_the_surface_directly( string $scenario ): void {
		self::assertSame(
			$this->expected( $scenario ),
			agend_apps_records_render_header_auth( $this->settings( $scenario ) )
		);
	}

	#[Test]
	#[DataProvider( 'scenarios' )]
	public function should_render_the_recorded_markup_when_the_elementor_widget_delegates_to_core( string $scenario ): void {
		$widget = new Agend_Elementor_Header_Auth( array(), null, $this->settings( $scenario ) );

		ob_start();
		( function () {
			$this->render();
		} )->call( $widget );

		self::assertSame( $this->expected( $scenario ), (string) ob_get_clean() );
	}

	#[Test]
	public function should_render_nothing_when_member_sign_in_is_set_to_sso(): void {
		// SPEC-CORE-20260907 US-4.1 AC7: the credential login surface does not
		// exist at all in `sso` member sign-in mode. The guard lives in the core
		// renderer, not the widget, so a Gutenberg block gets the same '' with
		// no editor-only notice baked in (that stays in the Elementor widget).
		update_option( 'agend_apps_member_auth_mode', 'sso' );

		self::assertSame( '', agend_apps_records_render_header_auth( array() ) );
	}

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/header-auth.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-header-auth.php';
	}

	private function settings( string $scenario ): array {
		switch ( $scenario ) {
			case 'custom_url_array':
				return self::CUSTOM_URL_ARRAY;
			case 'custom_url_string':
				return self::CUSTOM_URL_STRING;
			case 'empty_sign_out':
				return self::EMPTY_SIGN_OUT;
			default:
				return array();
		}
	}

	private function expected( string $scenario ): string {
		$fixtures = json_decode(
			(string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/header-auth-render.json' ),
			true
		);

		return $fixtures[ $scenario ];
	}
}

<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Elementor_Member_Login;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The member login markup moved out of the Elementor widget into core. The
 * fixture was recorded from the widget BEFORE the move, so equality here is
 * the proof that neither the core renderer nor the delegating widget changed
 * a byte of what a site receives (SPEC-INFRA-20260907-gutenberg-block-colours-
 * and-surfaces US-4.1).
 */
final class MemberLoginRenderTest extends TestCase {

	private const CUSTOM = array(
		'heading_text'          => 'Custom <Heading>',
		'intro_text'            => 'Sign in & continue',
		'submit_label'          => 'Log me in',
		'forgot_label'          => '',
		'back_to_sign_in_label' => 'Return',
		'signed_in_message'     => 'You are in',
		'portal_button_label'   => 'My account',
		'sign_out_label'        => 'Log out',
		'register_label'        => 'Create an account',
		'heading_colour'        => '#101010',
		'body_colour'           => '#202020',
		'button_colour'         => '#303030',
		'button_text_colour'    => '#EFEFEF',
		// Set alongside the manual colour values above (US-1.3): with
		// inherit_colours left at its schema default of 'yes', the resolver
		// would supersede these values with the resolved site/Agend colours.
		// A settings map with manual colours and inheritance still on is
		// precisely the state a real UI cannot produce (US-1.2 hides the
		// manual fields while inherit is on), so 'no' here matches what an
		// editor who set these actually did, on the CatalogueRenderTest
		// pattern (539c15b).
		'inherit_colours'       => '',
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
			agend_apps_records_render_member_login( $this->settings( $scenario ) )
		);
	}

	#[Test]
	#[DataProvider( 'scenarios' )]
	public function should_render_the_recorded_markup_when_the_elementor_widget_delegates_to_core( string $scenario ): void {
		$widget = new Agend_Elementor_Member_Login( array(), null, $this->settings( $scenario ) );

		ob_start();
		( function () {
			$this->render();
		} )->call( $widget );

		self::assertSame( $this->expected( $scenario ), (string) ob_get_clean() );
	}

	// -----------------------------------------------------------------
	// docs/PLAN-wordpress-idp-option-b.md section 4.5: `wordpress` mode
	// server-renders instead of standing down (previously indistinguishable
	// from `sso` mode) or delegating to the client-side credential flow
	// (`credentials` mode), since it has neither a credential form nor an
	// `/auth/*` proxy route to probe a session through.
	// -----------------------------------------------------------------

	#[Test]
	public function should_render_a_wordpress_login_prompt_when_signed_out_in_wordpress_mode(): void {
		update_option( 'agend_apps_member_auth_mode', 'wordpress' );

		$markup = agend_apps_records_render_member_login( self::CUSTOM );

		self::assertStringContainsString( 'Custom &lt;Heading&gt;', $markup );
		self::assertStringContainsString( 'Sign in with your WordPress account to continue.', $markup );
		self::assertStringContainsString( 'wp-login.php', $markup );
		self::assertStringNotContainsString( 'is-signed-in', $markup );
		// No client-side session probe in this mode: the config attribute the
		// script keys off is deliberately absent.
		self::assertStringNotContainsString( 'data-agend-member-login-config', $markup );
	}

	#[Test]
	public function should_render_the_signed_in_view_when_signed_in_in_wordpress_mode(): void {
		update_option( 'agend_apps_member_auth_mode', 'wordpress' );
		$GLOBALS['agend_test_current_user_id'] = 5;

		$markup = agend_apps_records_render_member_login( self::CUSTOM );

		self::assertStringContainsString( 'is-signed-in', $markup );
		self::assertStringContainsString( 'You are in', $markup );
		self::assertStringContainsString( 'Log out', $markup );
		self::assertStringContainsString( 'wp-login.php?action=logout', $markup );
		self::assertStringNotContainsString( 'Sign in with your WordPress account', $markup );
		self::assertStringNotContainsString( 'data-agend-member-login-config', $markup );
	}

	#[Test]
	public function should_still_render_nothing_in_sso_mode(): void {
		update_option( 'agend_apps_member_auth_mode', 'sso' );

		self::assertSame( '', agend_apps_records_render_member_login( self::CUSTOM ) );

		$GLOBALS['agend_test_current_user_id'] = 5;

		self::assertSame( '', agend_apps_records_render_member_login( self::CUSTOM ) );
	}

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/member-login.php';
		require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-member-login.php';
	}

	private function settings( string $scenario ): array {
		return 'custom' === $scenario ? self::CUSTOM : array();
	}

	private function expected( string $scenario ): string {
		$fixtures = json_decode(
			(string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/member-login-render.json' ),
			true
		);

		return $fixtures[ $scenario ];
	}
}

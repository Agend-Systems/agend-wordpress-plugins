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
	public function should_render_an_inline_sign_in_form_without_naming_wordpress_when_signed_out_in_wordpress_mode(): void {
		update_option( 'agend_apps_member_auth_mode', 'wordpress' );

		$markup = agend_apps_records_render_member_login( self::CUSTOM );

		self::assertStringContainsString( 'Custom &lt;Heading&gt;', $markup );
		self::assertStringContainsString( '<form class="agend-ml-form" method="post" action="https://example.test/wp-login.php"', $markup );
		self::assertStringContainsString( 'name="log"', $markup );
		self::assertStringContainsString( 'name="pwd"', $markup );
		self::assertStringContainsString( 'name="rememberme"', $markup );
		self::assertStringContainsString( 'Email address', $markup );
		self::assertStringContainsString( 'Log me in', $markup );
		self::assertStringContainsString( 'action=lostpassword', agend_apps_records_render_member_login( array() ) );
		self::assertStringNotContainsStringIgnoringCase( 'wordpress', $markup );
		self::assertStringNotContainsStringIgnoringCase( 'agend', str_replace( 'agend-', '', str_replace( 'agend_', '', $markup ) ) );
		self::assertStringNotContainsString( 'is-signed-in', $markup );
		self::assertStringNotContainsString( 'data-agend-member-login-config', $markup );
	}

	#[Test]
	public function should_use_the_site_name_for_the_intro_when_the_intro_is_left_at_its_default_in_wordpress_mode(): void {
		update_option( 'agend_apps_member_auth_mode', 'wordpress' );
		$GLOBALS['agend_test_blogname'] = 'ATEM';

		$markup = agend_apps_records_render_member_login( array( 'intro_text' => 'Sign in with your Agend member account.' ) );

		self::assertStringContainsString( 'Sign in with your ATEM email and password.', $markup );
	}

	#[Test]
	public function should_render_the_custom_intro_text_when_one_is_set_in_wordpress_mode(): void {
		update_option( 'agend_apps_member_auth_mode', 'wordpress' );

		$markup = agend_apps_records_render_member_login( self::CUSTOM );

		self::assertStringContainsString( 'Sign in &amp; continue', $markup );
		self::assertStringNotContainsString( 'email and password.', $markup );
	}

	#[Test]
	public function should_hide_the_forgot_link_when_its_label_is_empty_in_wordpress_mode(): void {
		update_option( 'agend_apps_member_auth_mode', 'wordpress' );

		self::assertStringNotContainsString( 'lostpassword', agend_apps_records_render_member_login( self::CUSTOM ) );
	}

	#[Test]
	public function should_return_to_the_current_page_after_sign_in_when_no_redirect_was_requested(): void {
		update_option( 'agend_apps_member_auth_mode', 'wordpress' );
		$_SERVER['REQUEST_URI'] = '/members/sign-in/';

		$markup = agend_apps_records_render_member_login( self::CUSTOM );

		self::assertStringContainsString( 'name="redirect_to" value="https://example.test/members/sign-in/"', $markup );
	}

	#[Test]
	public function should_preserve_a_local_redirect_to_the_page_was_reached_with(): void {
		update_option( 'agend_apps_member_auth_mode', 'wordpress' );
		$_SERVER['REQUEST_URI'] = '/members/sign-in/?redirect_to=https%3A%2F%2Fexample.test%2Fsaml%2Fcontinue%3Fa%3D1';
		$_GET['redirect_to']    = 'https://example.test/saml/continue?a=1';

		$markup = agend_apps_records_render_member_login( self::CUSTOM );

		self::assertStringContainsString( 'name="redirect_to" value="https://example.test/saml/continue?a=1"', $markup );
	}

	#[Test]
	public function should_ignore_a_redirect_to_that_leaves_the_site(): void {
		update_option( 'agend_apps_member_auth_mode', 'wordpress' );
		$_SERVER['REQUEST_URI'] = '/sign-in/';
		$_GET['redirect_to']    = 'https://evil.test/phish';

		$markup = agend_apps_records_render_member_login( self::CUSTOM );

		self::assertStringNotContainsString( 'evil.test', $markup );
		self::assertStringContainsString( 'name="redirect_to" value="https://example.test/sign-in/"', $markup );
	}

	#[Test]
	public function should_show_an_inline_error_when_the_page_is_reached_after_a_failed_sign_in(): void {
		update_option( 'agend_apps_member_auth_mode', 'wordpress' );
		$_GET['agend_login'] = 'failed';

		$markup = agend_apps_records_render_member_login( self::CUSTOM );

		self::assertStringContainsString( 'role="alert"', $markup );
		self::assertStringContainsString( 'The email or password you entered is incorrect.', $markup );
	}

	#[Test]
	public function should_show_no_error_for_an_unknown_failure_flag(): void {
		update_option( 'agend_apps_member_auth_mode', 'wordpress' );
		$_GET['agend_login'] = '<script>';

		self::assertStringNotContainsString( 'role="alert"', agend_apps_records_render_member_login( self::CUSTOM ) );
	}

	#[Test]
	public function should_return_to_the_form_page_with_a_flag_when_an_inline_sign_in_fails(): void {
		$post = array( 'agend_ml_return' => 'https://example.test/sign-in/?x=1&agend_login=failed' );

		self::assertSame(
			'https://example.test/sign-in/?x=1&agend_login=failed',
			agend_apps_wp_login_inline_failure_redirect( new \WP_Error( 'incorrect_password' ), $post )
		);
		self::assertSame(
			'https://example.test/sign-in/?agend_login=verify',
			agend_apps_wp_login_inline_failure_redirect( new \WP_Error( 'agend_apps_verification_required' ), array( 'agend_ml_return' => 'https://example.test/sign-in/' ) )
		);
	}

	#[Test]
	public function should_leave_the_failure_to_wp_login_when_any_error_code_is_one_the_form_cannot_explain(): void {
		$error = new \WP_Error( 'incorrect_password' );
		$error->add( 'jetpack_login_blocked', 'Locked out' );

		self::assertSame( '', agend_apps_wp_login_inline_failure_redirect( $error, array( 'agend_ml_return' => 'https://example.test/sign-in/' ) ) );
	}

	#[Test]
	public function should_escape_the_site_name_in_the_wp_login_header_text(): void {
		$GLOBALS['agend_test_blogname'] = '&lt;img src=x onerror=alert(1)&gt;';
		agend_apps_wp_login_inline_register();

		$text = apply_filters( 'login_headertext', 'WordPress' );

		self::assertStringNotContainsString( '<img', $text );
		self::assertStringContainsString( '&lt;img', $text );
	}

	#[Test]
	public function should_run_the_failure_redirect_after_other_login_failed_callbacks(): void {
		agend_apps_wp_login_inline_register();

		$index = array_search( 'agend_apps_wp_login_inline_on_failure', \Agend_Test_WP::$actions['wp_login_failed'], true );

		self::assertSame( PHP_INT_MAX, \Agend_Test_WP::$action_priorities['wp_login_failed'][ $index ] );
	}

	#[Test]
	public function should_leave_the_failure_to_wp_login_when_it_did_not_come_from_the_inline_form_or_needs_its_screen(): void {
		self::assertSame( '', agend_apps_wp_login_inline_failure_redirect( new \WP_Error( 'incorrect_password' ), array() ) );
		self::assertSame( '', agend_apps_wp_login_inline_failure_redirect( new \WP_Error( 'agend_apps_mfa_required' ), array( 'agend_ml_return' => 'https://example.test/sign-in/' ) ) );
		self::assertSame( '', agend_apps_wp_login_inline_failure_redirect( new \WP_Error( 'incorrect_password' ), array( 'agend_ml_return' => 'https://evil.test/' ) ) );
	}

	#[Test]
	public function should_render_the_form_through_the_elementor_widget_in_wordpress_mode(): void {
		update_option( 'agend_apps_member_auth_mode', 'wordpress' );
		$widget = new Agend_Elementor_Member_Login( array(), null, self::CUSTOM );

		ob_start();
		( function () {
			$this->render();
		} )->call( $widget );

		self::assertStringContainsString( 'name="pwd"', (string) ob_get_clean() );
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
		self::assertStringNotContainsString( '<form', $markup );
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
		unset( $_GET['redirect_to'], $_GET['agend_login'], $GLOBALS['agend_test_blogname'] );
		$_SERVER['REQUEST_URI'] = '';
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

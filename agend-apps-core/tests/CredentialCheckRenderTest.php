<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Captcha;
use Agend_Apps_Key_Scopes;
use Agend_Apps_Records_Record_Context;
use Agend_Apps_Secret_Store;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

/**
 * The credential check surface's form: its settings, what it draws, when it
 * draws nothing (no captcha, a key without the scope, inside a template), and
 * how the pieces the script depends on (wording, config, assets) line up.
 *
 * The form holds no result and no credential data: the script gets those from
 * the route (see CredentialCheckRouteTest).
 *
 * Each case runs in its own process: the renderer's collaborators are declared
 * here for real, which other tests in this suite declare as doubles.
 */
#[RunTestsInSeparateProcesses]
final class CredentialCheckRenderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/tests/render-doubles.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-secret-store.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-captcha.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/blocks.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/public-throttle.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/credential-guard.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/credential-check.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/credential-check.php';
		agend_render_test_reset();
		Agend_Apps_Records_Record_Context::reset();
		Agend_Test_WP::set_filter( 'agend_apps_captcha_allow_none', true );
		$this->know( array( 'lms.certificates.verify', 'lms.badges.verify' ) );
	}

	protected function tearDown(): void {
		Agend_Apps_Secret_Store::delete( Agend_Apps_Captcha::SECRET_STORE_KEY );
		Agend_Apps_Records_Record_Context::reset();
		parent::tearDown();
	}

	private function know( array $scopes ): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => $scopes, 'app_ids' => array() ) ) );
	}

	private function use_provider( string $provider ): void {
		Agend_Test_WP::set_filter( 'agend_apps_captcha_allow_none', false );
		update_option( Agend_Apps_Captcha::OPTION_PROVIDER, $provider );
		update_option( Agend_Apps_Captcha::OPTION_SITE_KEY, 'site-key' );
		Agend_Apps_Secret_Store::set( Agend_Apps_Captcha::SECRET_STORE_KEY, 'captcha-secret' );
	}

	/**
	 * The settings an editor hands the renderer: the schema's defaults, in the
	 * Elementor shape the renderer takes, with the test's changes over them.
	 */
	private function settings( array $overrides = array() ): array {
		$schema   = agend_apps_records_surface_schema( 'credential-check' );
		$defaults = array_map( static fn( array $a ) => $a['default'], agend_apps_records_block_attributes( $schema ) );

		return $overrides + agend_apps_records_settings_from_attributes( $schema, $defaults );
	}

	private function render( array $overrides = array(), array $opts = array() ): string {
		return agend_apps_records_render_credential_check( $this->settings( $overrides ), $opts );
	}

	/**
	 * The script's config as the page prints it.
	 */
	private function client_config( string $html ): array {
		self::assertSame( 1, preg_match( '/data-agend-credential-config="([^"]*)"/', $html, $match ), 'the form carries its config' );

		return json_decode( html_entity_decode( $match[1], ENT_QUOTES ), true );
	}

	private function field( string $name ): ?array {
		foreach ( agend_apps_records_surface_schema( 'credential-check' )['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				if ( $name === $field['name'] ) {
					return $field;
				}
			}
		}

		return null;
	}

	// -----------------------------------------------------------------
	// Settings
	// -----------------------------------------------------------------

	#[Test]
	public function should_default_a_result_to_every_row_the_record_can_honestly_show_and_no_earner_name(): void {
		$config = agend_apps_records_credential_check_build_config( $this->settings() );

		self::assertSame(
			array(
				'number'     => true,
				'issued'     => true,
				'course'     => true,
				'cpd'        => true,
				'instructor' => true,
				'topics'     => true,
				'image'      => true,
				'checkedAt'  => true,
				'holder'     => false,
			),
			$config['show']
		);
		self::assertFalse( $this->field( 'show_holder' )['default'], 'the earner name is off by default' );
	}

	#[Test]
	public function should_say_in_plain_words_why_the_earner_name_is_off(): void {
		$help = $this->field( 'show_holder' )['description'];

		self::assertStringContainsString( 'Off by default', $help );
		self::assertStringContainsString( 'association decides which badges are public', $help );
		self::assertStringContainsString( 'Certificates never show a name', $help );
	}

	#[Test]
	public function should_offer_no_row_for_a_certificate_holder_an_issuer_or_an_expiry(): void {
		$names = array();

		foreach ( agend_apps_records_surface_schema( 'credential-check' )['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$names[] = $field['name'];
			}
		}

		foreach ( $names as $name ) {
			self::assertDoesNotMatchRegularExpression( '/issuer|expiry|expires|show_certificate_holder/', $name );
		}

		self::assertContains( 'show_holder', $names, 'the one name row is for badge earners' );
	}

	#[Test]
	public function should_turn_the_earner_name_on_only_for_an_explicit_yes(): void {
		self::assertFalse( agend_apps_records_credential_check_build_config( array() )['show']['holder'] );
		self::assertFalse( agend_apps_records_credential_check_build_config( array( 'show_holder' => '' ) )['show']['holder'] );
		self::assertTrue( agend_apps_records_credential_check_build_config( array( 'show_holder' => 'yes' ) )['show']['holder'] );
	}

	#[Test]
	public function should_fall_back_to_the_default_wording_when_a_setting_is_blank_except_where_blank_is_allowed(): void {
		$config = agend_apps_records_credential_check_build_config(
			$this->settings(
				array(
					'valid_title'    => '   ',
					'not_found_text' => '',
					'checked_text'   => '',
					'holder_note'    => '',
					'help_text'      => '',
					'intro_text'     => '',
				)
			)
		);

		self::assertSame( 'Credential verified', $config['text']['validTitle'] );
		self::assertSame( 'No credential found with that code', $config['text']['notFound'] );
		self::assertSame( 'Checked against the Agend learning record', $config['text']['checkedText'] );
		self::assertSame( '', $config['text']['holderNote'], 'blank hides the reminder' );
		self::assertSame( '', $config['form']['hint'] );
		self::assertSame( '', $config['heading']['intro'] );
	}

	#[Test]
	public function should_use_the_default_wording_for_a_setting_a_saved_page_never_had(): void {
		$config = agend_apps_records_credential_check_build_config( array() );

		self::assertSame( 'Check a credential', $config['heading']['title'] );
		self::assertNotSame( '', $config['text']['holderNote'] );
		self::assertSame( 'Check', $config['form']['button'] );
	}

	/**
	 * @return array<string, array{mixed, string}>
	 */
	public static function link_names(): array {
		return array(
			'the default'        => array( 'code', 'code' ),
			'a custom name'      => array( 'cert', 'cert' ),
			'the platform name'  => array( 'agend_code', 'agend_code' ),
			'blank'              => array( '', 'code' ),
			'spaces only'        => array( '   ', 'code' ),
			'a leading question mark' => array( '?code', 'code' ),
			'symbols stripped'   => array( 'co de=<x>', 'codex' ),
			'only symbols'       => array( '=?&', 'code' ),
			'too long'           => array( str_repeat( 'a', 100 ), str_repeat( 'a', 40 ) ),
		);
	}

	#[Test]
	#[DataProvider( 'link_names' )]
	public function should_keep_the_link_code_name_to_plain_characters( $given, string $expected ): void {
		self::assertSame( $expected, agend_apps_records_credential_check_build_config( array( 'prefill_param' => $given ) )['form']['param'] );
	}

	#[Test]
	public function should_check_straight_away_by_default_and_say_what_that_does(): void {
		self::assertTrue( agend_apps_records_credential_check_build_config( $this->settings() )['form']['autoCheck'] );
		self::assertFalse( agend_apps_records_credential_check_build_config( $this->settings( array( 'auto_check' => '' ) ) )['form']['autoCheck'] );
		self::assertStringContainsString( 'security check', $this->field( 'auto_check' )['description'] );
	}

	#[Test]
	public function should_only_link_to_help_from_a_web_or_site_address(): void {
		$url = static fn( $value ): string => agend_apps_records_credential_check_build_config( array( 'help_url' => $value ) )['help']['url'];

		self::assertSame( 'https://assoc.example.test/contact/', $url( 'https://assoc.example.test/contact/' ) );
		self::assertSame( 'https://assoc.example.test/contact/', $url( array( 'url' => 'https://assoc.example.test/contact/' ) ) );
		self::assertSame( '/contact/', $url( '/contact/' ) );
		self::assertSame( '', $url( 'javascript:alert(1)' ) );
		self::assertSame( '', $url( 'data:text/html,x' ) );
		self::assertSame( '', $url( '' ) );
	}

	#[Test]
	public function should_word_every_setting_for_an_association_editor(): void {
		$words = array();

		foreach ( agend_apps_records_surface_schema( 'credential-check' )['sections'] as $section ) {
			$words[] = (string) $section['label'];

			foreach ( $section['fields'] as $field ) {
				$words[] = (string) ( $field['label'] ?? '' );
				$words[] = (string) ( $field['description'] ?? '' );
				$words[] = (string) ( $field['content'] ?? '' );
				$words[] = is_string( $field['default'] ?? null ) ? $field['default'] : '';

				if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
					$words = array_merge( $words, array_map( 'strval', array_values( $field['options'] ) ) );
				}
			}
		}

		$text = implode( "\n", $words );

		self::assertDoesNotMatchRegularExpression( '/\b(requests?|cach(e|ed|ing)|api|slugs?|ids?|endpoints?|gateway|tokens?|nonces?|scopes?|throttl\w*|rate.?limit\w*)\b/i', $text, 'no developer-speak in what an editor reads' );
		self::assertStringNotContainsString( "\u{2014}", $text, 'no em dashes' );
		self::assertStringContainsString( 'Agend learning record', $text );
		self::assertStringContainsString( 'Checked against the Agend learning record', $text );
	}

	// -----------------------------------------------------------------
	// The form
	// -----------------------------------------------------------------

	#[Test]
	public function should_draw_a_labelled_code_box_that_a_phone_keyboard_leaves_alone(): void {
		$html = $this->render();

		self::assertStringContainsString( 'class="agend-credential-check__label" for="agend-credential-1-code"', $html );
		self::assertStringContainsString( 'Certificate or badge code', $html );
		self::assertMatchesRegularExpression( '/<input[^>]*id="agend-credential-1-code"[^>]*autocapitalize="off"[^>]*autocorrect="off"[^>]*spellcheck="false"/s', $html );
		self::assertStringContainsString( 'autocomplete="off"', $html );
		self::assertStringContainsString( 'aria-describedby="agend-credential-1-hint"', $html );
		self::assertStringContainsString( 'Codes must match exactly', $html, 'the hint does not promise case-insensitive codes' );
		self::assertStringContainsString( 'type="submit"', $html );
		self::assertStringContainsString( 'exclude-agend-theme-link', $html );
		self::assertStringContainsString( '<noscript>', $html );
	}

	#[Test]
	public function should_hold_no_result_and_no_credential_data_in_the_page(): void {
		$html = $this->render();

		self::assertStringContainsString( 'data-agend-credential-result></div>', $html, 'the result container starts empty' );
		self::assertStringNotContainsString( 'Credential verified</', $html );
		self::assertDoesNotMatchRegularExpression( '/VER-[0-9a-f]{20}/', preg_replace( '/For example VER-[0-9a-f]{20}/', '', $html ) );
	}

	#[Test]
	public function should_announce_results_in_a_live_region_of_their_own(): void {
		$html = $this->render();

		self::assertStringContainsString( 'role="status" aria-live="polite" data-agend-credential-live', $html );
		self::assertStringNotContainsString( 'role="status" aria-live="polite" tabindex', $html, 'the card is not itself the live region' );
	}

	#[Test]
	public function should_draw_the_heading_and_introduction_only_when_asked(): void {
		self::assertStringContainsString( '<h2 class="agend-credential-check__title">Check a credential</h2>', $this->render() );
		self::assertStringNotContainsString( 'agend-credential-check__heading', $this->render( array( 'show_heading' => '' ) ) );
	}

	#[Test]
	public function should_escape_everything_an_editor_typed(): void {
		$html = $this->render(
			array(
				'heading_text'      => '<script>alert(1)</script>',
				'input_label'       => '"><img src=x onerror=alert(1)>',
				'input_placeholder' => '" onfocus="alert(1)',
				'help_text'         => '<b>hint</b>',
				'button_label'      => '</button><script>',
			)
		);

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( '<img src=x', $html );
		self::assertStringNotContainsString( '" onfocus=', $html );
		self::assertStringNotContainsString( '<b>hint</b>', $html );
	}

	#[Test]
	public function should_give_two_forms_on_one_page_their_own_element_ids(): void {
		$first  = $this->render();
		$second = $this->render();
		$named  = $this->render( array(), array( 'id' => 'ab12cd' ) );

		self::assertStringContainsString( 'id="agend-credential-1"', $first );
		self::assertStringContainsString( 'id="agend-credential-2"', $second );
		self::assertStringContainsString( 'id="agend-credential-ab12cd"', $named );
	}

	#[Test]
	public function should_set_the_colour_variables_from_the_palette_and_inherit_nothing_else(): void {
		$html = $this->render();

		self::assertStringContainsString( '--agend-cc-heading:#1E2A4A;--agend-cc-body:#26304D;--agend-cc-accent:#FF6B55;', $html );

		$manual = $this->render( array( 'inherit_colours' => '', 'accent_colour' => '#00AA88' ) );

		self::assertStringContainsString( '--agend-cc-accent:#00AA88;', $manual );
	}

	// -----------------------------------------------------------------
	// The script's config
	// -----------------------------------------------------------------

	#[Test]
	public function should_hand_the_script_the_route_its_toggles_and_its_wording(): void {
		$config = $this->client_config( $this->render() );

		self::assertSame( 'https://example.test/wp-json/agend-apps/v1/lms/credentials/verify', $config['endpoint'] );
		self::assertSame( 'code', $config['param'] );
		self::assertTrue( $config['autoCheck'] );
		self::assertTrue( $config['show']['number'] );
		self::assertFalse( $config['show']['holder'] );
		self::assertSame( '', $config['holderKey'], 'a page that does not show the name cannot ask for it' );
		self::assertSame( 'Credential verified', $config['text']['validTitle'] );
		self::assertSame( 'Checked against the Agend learning record', $config['text']['checkedText'] );
		self::assertNull( $config['captcha'], 'no provider with the development opt-out' );
	}

	#[Test]
	public function should_carry_the_earner_name_key_only_on_a_page_set_to_show_the_name(): void {
		$config = $this->client_config( $this->render( array( 'show_holder' => 'yes' ) ) );

		self::assertSame( agend_apps_credentials_holder_key(), $config['holderKey'] );
		self::assertTrue( $config['show']['holder'] );
		self::assertSame( 64, strlen( $config['holderKey'] ) );
	}

	#[Test]
	public function should_never_put_the_captcha_secret_in_the_page(): void {
		$this->use_provider( Agend_Apps_Captcha::PROVIDER_TURNSTILE );

		$html = $this->render();

		self::assertStringNotContainsString( 'captcha-secret', $html );
		self::assertSame( 'site-key', $this->client_config( $html )['captcha']['siteKey'] );
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function providers(): array {
		return array(
			'turnstile draws a box'    => array( Agend_Apps_Captcha::PROVIDER_TURNSTILE, true ),
			'reCAPTCHA v2 draws a box' => array( Agend_Apps_Captcha::PROVIDER_RECAPTCHA_V2, true ),
			'reCAPTCHA v3 is invisible' => array( Agend_Apps_Captcha::PROVIDER_RECAPTCHA, false ),
		);
	}

	#[Test]
	#[DataProvider( 'providers' )]
	public function should_make_room_for_a_captcha_box_only_when_the_provider_draws_one( string $provider, bool $box ): void {
		$this->use_provider( $provider );

		$html = $this->render();

		self::assertSame( $box, false !== strpos( $html, 'data-agend-credential-captcha' ) );
		self::assertSame( $provider, $this->client_config( $html )['captcha']['provider'] );
	}

	/**
	 * The words the script reads must all exist: a missing one would show a
	 * blank message at the worst moment.
	 */
	#[Test]
	public function should_supply_every_word_and_setting_the_script_reads(): void {
		$js     = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/assets/js/credential-check.js' );
		$config = $this->client_config( $this->render() );

		preg_match_all( '/\bt\.([A-Za-z]+)/', $js, $words );
		preg_match_all( '/\bshow\.([A-Za-z]+)/', $js, $shows );
		preg_match_all( '/\bcfg\.([A-Za-z]+)/', $js, $cfgs );

		foreach ( array_unique( $words[1] ) as $word ) {
			self::assertArrayHasKey( $word, $config['text'], 'the script reads text.' . $word );
		}

		foreach ( array_unique( $shows[1] ) as $show ) {
			self::assertArrayHasKey( $show, $config['show'], 'the script reads show.' . $show );
		}

		foreach ( array_unique( $cfgs[1] ) as $key ) {
			self::assertArrayHasKey( $key, $config, 'the script reads cfg.' . $key );
		}
	}

	// -----------------------------------------------------------------
	// Fails closed
	// -----------------------------------------------------------------

	#[Test]
	public function should_draw_nothing_for_a_visitor_when_no_captcha_is_set_up_and_the_opt_out_is_off(): void {
		Agend_Test_WP::set_filter( 'agend_apps_captcha_allow_none', false );

		self::assertSame( '', $this->render() );
		self::assertSame( 'no_captcha', agend_apps_records_credential_check_state()['status'] );
	}

	#[Test]
	public function should_draw_nothing_for_a_visitor_when_a_provider_is_selected_but_incomplete(): void {
		Agend_Test_WP::set_filter( 'agend_apps_captcha_allow_none', false );
		update_option( Agend_Apps_Captcha::OPTION_PROVIDER, Agend_Apps_Captcha::PROVIDER_TURNSTILE );

		self::assertSame( '', $this->render() );
	}

	#[Test]
	public function should_still_draw_the_form_for_an_editor_so_it_can_be_styled_and_say_why_visitors_will_not_see_it(): void {
		Agend_Test_WP::set_filter( 'agend_apps_captcha_allow_none', false );

		$html = $this->render( array(), array( 'preview' => true ) );

		self::assertStringContainsString( 'agend-credential-check__form', $html );
		self::assertStringContainsString( 'data-agend-credential-preview="1"', $html, 'the script never submits a preview' );

		$notices = agend_apps_records_credential_check_notices( agend_apps_records_credential_check_state() );

		self::assertCount( 1, $notices );
		self::assertStringContainsString( 'visitors will not see this form', $notices[0] );
		self::assertStringContainsString( 'Settings > Agend Apps > Form Captcha', $notices[0] );
	}

	#[Test]
	public function should_tell_the_block_editor_the_same_things(): void {
		Agend_Test_WP::set_filter( 'agend_apps_captcha_allow_none', false );
		$this->know( array( 'lms.certificates.verify' ) );

		$notices = agend_apps_records_credential_check_block_notices( array(), 'credential-check' );

		self::assertCount( 2, $notices );
		self::assertSame( 'warning', $notices[0]['status'] );
		self::assertStringContainsString( 'lms.badges.verify', $notices[1]['text'], 'a certificate-only key says what badges would need' );
		self::assertSame( array(), agend_apps_records_credential_check_block_notices( array(), 'learning-paths' ), 'other surfaces are untouched' );
	}

	#[Test]
	public function should_say_nothing_to_the_editor_when_everything_is_in_order(): void {
		self::assertSame( array(), agend_apps_records_credential_check_notices( agend_apps_records_credential_check_state() ) );
		self::assertSame( array(), agend_apps_records_credential_check_block_notices( array(), 'credential-check' ) );
	}

	#[Test]
	public function should_draw_nothing_inside_a_card_or_detail_template(): void {
		Agend_Apps_Records_Record_Context::push( 'course', array( 'slug' => 'a-course' ) );

		self::assertSame( '', $this->render() );
	}

	// -----------------------------------------------------------------
	// Gate
	// -----------------------------------------------------------------

	#[Test]
	public function should_require_the_certificate_scope_and_nothing_else_of_the_key(): void {
		$needs = agend_apps_records_surface_requirements( 'credential-check' );

		self::assertSame( array( 'lms.certificates.verify' ), $needs['scopes'] );
		self::assertSame( array( 'lms' ), $needs['apps'] );
		self::assertSame( 'Agend Credential Check', $needs['label'] );
	}

	#[Test]
	public function should_draw_nothing_for_a_visitor_and_a_disabled_box_naming_the_scope_for_an_editor_when_the_key_lacks_it(): void {
		$this->know( array( 'lms.paths.browse' ) );

		self::assertSame( '', $this->render() );

		$box = $this->render( array(), array( 'preview' => true ) );

		self::assertStringContainsString( 'agend-surface-gate', $box );
		self::assertStringContainsString( 'lms.certificates.verify', $box );
		self::assertStringNotContainsString( 'agend-credential-check__form', $box );
	}

	#[Test]
	public function should_stay_open_when_the_key_holds_only_the_certificate_scope(): void {
		$this->know( array( 'lms.certificates.verify' ) );

		self::assertStringContainsString( 'agend-credential-check__form', $this->render() );
		self::assertFalse( agend_apps_credentials_badges_available() );
	}

	#[Test]
	public function should_offer_badges_only_when_the_key_also_holds_the_badge_scope(): void {
		self::assertTrue( agend_apps_credentials_badges_available() );

		$features = agend_apps_records_optional_features();

		self::assertSame( array( 'lms.badges.verify' ), $features['lms_credential_badges']['scopes'] );

		$this->know( array( 'lms.certificates.verify' ) );
		self::assertFalse( agend_apps_credentials_badges_available() );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function routes(): array {
		return array(
			'the form route'            => array( '/agend-apps/v1/lms/credentials/verify', 'credential-check' ),
			'the certificate GET proxy' => array( '/agend-apps/v1/lms/certificates/verify/VER-3fa91c0b7d2e48a1b6c5', 'credential-check' ),
			'the badge GET proxy'       => array( '/agend-apps/v1/lms/badges/verify/0123456789abcdef0123456789abcdef', 'credential-check' ),
			'the courses list'          => array( '/agend-apps/v1/lms/courses', 'courses-catalogue' ),
			'a learning pathway'        => array( '/agend-apps/v1/lms/paths/abc-123', 'learning-paths' ),
			'enrolments are not ours'   => array( '/agend-apps/v1/lms/me/enrollments', '' ),
		);
	}

	#[Test]
	#[DataProvider( 'routes' )]
	public function should_map_the_routes_that_belong_to_the_surface_and_leave_the_rest_alone( string $route, string $surface ): void {
		self::assertSame( $surface, agend_apps_records_rest_route_surface( $route ) );
	}

	#[Test]
	public function should_refuse_the_routes_while_the_key_lacks_the_scope_and_pass_them_when_it_holds_it(): void {
		$request = new \WP_REST_Request( 'POST', '/agend-apps/v1/lms/credentials/verify' );

		self::assertNull( agend_apps_records_surface_gate_rest( null, null, $request ) );

		$this->know( array( 'lms.paths.browse' ) );

		$refused = agend_apps_records_surface_gate_rest( null, null, $request );

		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'agend_apps_feature_unavailable', $refused->get_error_code() );
		self::assertSame( 403, $refused->get_error_data()['status'] );
	}

	// -----------------------------------------------------------------
	// Block
	// -----------------------------------------------------------------

	#[Test]
	public function should_derive_the_block_attributes_from_the_schema(): void {
		$attributes = agend_apps_records_block_attributes( agend_apps_records_surface_schema( 'credential-check' ) );

		self::assertSame( array( 'type' => 'boolean', 'default' => false ), $attributes['show_holder'] );
		self::assertSame( array( 'type' => 'boolean', 'default' => true ), $attributes['auto_check'] );
		self::assertSame( array( 'type' => 'string', 'default' => 'code' ), $attributes['prefill_param'] );
		self::assertSame( array( 'type' => 'string', 'default' => 'Check' ), $attributes['button_label'] );
		self::assertSame( array( 'type' => 'string', 'default' => '#1E2A4A' ), $attributes['heading_colour'] );
		self::assertArrayNotHasKey( 'security_note', $attributes, 'a note stores no value' );
	}

	#[Test]
	public function should_render_the_block_the_same_as_the_core_renderer_at_its_defaults(): void {
		$schema   = agend_apps_records_surface_schema( 'credential-check' );
		$defaults = array_map( static fn( array $a ) => $a['default'], agend_apps_records_block_attributes( $schema ) );

		$html = agend_apps_records_render_block( 'credential-check', $defaults );

		// The element ids count the forms on the page, so the second render's differ.
		$same = static fn( string $markup ): string => (string) preg_replace( '/agend-credential-\d+/', 'agend-credential-N', $markup );

		self::assertSame( $same( agend_apps_records_render_credential_check( agend_apps_records_settings_from_attributes( $schema, $defaults ) ) ), $same( $html ) );
		self::assertStringContainsString( 'Check a credential', $html );
	}

	#[Test]
	public function should_draw_nothing_from_a_gated_block(): void {
		$this->know( array( 'lms.paths.browse' ) );

		self::assertSame( '', agend_apps_records_render_block( 'credential-check', array() ) );
	}

	#[Test]
	public function should_list_the_surface_as_a_block_in_the_inserter(): void {
		self::assertContains( 'credential-check', AGEND_APPS_RECORDS_BLOCK_SURFACES );
	}

	#[Test]
	public function should_ship_a_built_block_that_matches_its_source_and_names_its_script_and_style(): void {
		$src   = json_decode( (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/src/blocks/credential-check/block.json' ), true );
		$build = json_decode( (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/build/blocks/credential-check/block.json' ), true );

		self::assertSame( $src, $build );
		self::assertSame( 'agend-apps/credential-check', $build['name'] );
		self::assertSame( 'Agend Credential Check', $build['title'] );
		self::assertSame( array( 'agend-apps-records-credential-check' ), $build['viewScript'] );
		self::assertSame( array( 'agend-apps-records-credential-check' ), $build['style'] );
		self::assertFileExists( AGEND_TESTS_ROOT . '/agend-apps-core/build/blocks/credential-check/index.js' );
		self::assertFileExists( AGEND_TESTS_ROOT . '/agend-apps-core/build/blocks/credential-check/render.php' );
		self::assertStringContainsString( 'Agend Credential Check', (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/build/blocks/credential-check/index.js' ) );
	}

	#[Test]
	public function should_register_the_script_style_and_shared_captcha_client_it_names(): void {
		$assets = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/assets.php' );

		self::assertMatchesRegularExpression( "#wp_register_style\(\s*'agend-apps-records-credential-check',\s*agend_apps_records_asset_url\( 'css/credential-check.css' \)#", $assets );
		self::assertMatchesRegularExpression( "#wp_register_script\(\s*'agend-apps-records-credential-check',\s*agend_apps_records_asset_url\( 'js/credential-check.js' \),\s*array\( 'agend-apps-captcha-client' \)#", $assets );
		self::assertMatchesRegularExpression( "#wp_register_script\(\s*'agend-apps-captcha-client',\s*agend_apps_records_asset_url\( 'js/captcha-client.js' \)#", $assets );

		foreach ( array( 'css/credential-check.css', 'js/credential-check.js', 'js/captcha-client.js' ) as $file ) {
			self::assertFileExists( AGEND_TESTS_ROOT . '/agend-apps-core/assets/' . $file );
		}
	}

	// -----------------------------------------------------------------
	// Assets: guards that need no browser
	// -----------------------------------------------------------------

	#[Test]
	public function should_draw_a_result_without_ever_building_markup_from_a_string(): void {
		$js = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/assets/js/credential-check.js' );

		foreach ( array( 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'dangerouslySetInnerHTML' ) as $banned ) {
			self::assertStringNotContainsString( $banned, $js, $banned );
		}

		self::assertStringContainsString( 'textContent', $js );
	}

	#[Test]
	public function should_use_no_shadow_and_respect_reduced_motion_and_keyboard_focus_in_the_stylesheet(): void {
		$css = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/assets/css/credential-check.css' );

		// Declarations only: the header comment talks about the theme's own rule.
		preg_match_all( '/box-shadow:\s*([^;]+);/', (string) preg_replace( '#/\*.*?\*/#s', '', $css ), $shadows );

		foreach ( $shadows[1] as $value ) {
			self::assertSame( 'none', trim( $value ), 'the Agend theme forces no shadows: depth is borders' );
		}

		self::assertStringContainsString( 'prefers-reduced-motion: no-preference', $css );
		self::assertStringContainsString( ':focus-visible', $css );
		self::assertStringContainsString( 'container-type: inline-size', $css );
		self::assertStringContainsString( 'margin-top: 0 !important', $css, 'the theme li + li margin is cancelled' );
		self::assertStringNotContainsString( "\u{2014}", $css );
	}
}

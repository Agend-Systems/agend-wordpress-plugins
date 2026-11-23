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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/enquiry-stubs.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-secret-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-captcha.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/record-context.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/preview-records.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/listing-enquiry.php';

/**
 * When the listing enquiry form renders and what it renders: nothing unless a
 * listing that accepts enquiries is in scope, the editable copy, the terms
 * checkbox, the captcha hand-off, and the markup the script and screen readers
 * rely on.
 */
final class ListingEnquiryRenderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		agend_apps_records_listing_enquiry_claim_anchor( true );
		Agend_Apps_Records_Record_Context::reset();
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => array( 'directory.enquiries.send' ) ) ) );
		Agend_Test_WP::set_filter( 'agend_apps_captcha_allow_none', true );
	}

	protected function tearDown(): void {
		Agend_Apps_Records_Record_Context::reset();
		Agend_Apps_Secret_Store::delete( Agend_Apps_Captcha::SECRET_STORE_KEY );
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $record Listing fields to replace.
	 */
	private function pushListing( array $record = array() ): void {
		Agend_Apps_Records_Record_Context::push(
			'listing',
			$record + array(
				'slug'              => 'jane-smith',
				'name'              => 'Jane Smith Advisory',
				'accepts_enquiries' => true,
			),
			array( 'detail_url' => 'https://example.test/listing/jane-smith/' )
		);
	}

	private function render( array $settings = array() ): string {
		return agend_apps_records_render_listing_enquiry( $settings, array( 'id' => 'abc123' ) );
	}

	// -----------------------------------------------------------------
	// Render conditions
	// -----------------------------------------------------------------

	#[Test]
	public function should_render_nothing_when_no_record_is_in_scope(): void {
		$this->assertSame( '', $this->render() );
	}

	#[Test]
	public function should_render_nothing_for_a_record_that_is_not_a_listing(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array( 'slug' => 'gala', 'name' => 'Gala', 'accepts_enquiries' => true ) );

		$this->assertSame( '', $this->render() );
		$this->assertSame( 'wrong_type', agend_apps_records_listing_enquiry_state()['status'] );
	}

	#[Test]
	public function should_render_the_form_when_the_listing_accepts_enquiries(): void {
		$this->pushListing();

		$html = $this->render();

		$this->assertStringContainsString( '<form class="agend-enquiry__form" id="agend-enquiry-abc123" novalidate>', $html );
		$this->assertSame( 'ready', agend_apps_records_listing_enquiry_state()['status'] );
	}

	#[Test]
	public function should_render_nothing_when_the_listing_does_not_accept_enquiries(): void {
		$this->pushListing( array( 'accepts_enquiries' => false ) );

		$this->assertSame( '', $this->render() );
		$this->assertSame( 'closed', agend_apps_records_listing_enquiry_state()['status'] );
	}

	#[Test]
	public function should_render_nothing_when_the_api_key_lacks_the_enquiry_scope(): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => array( 'directory.listings.browse' ) ) ) );
		$this->pushListing();

		$this->assertSame( '', $this->render() );
		$this->assertSame( 'unavailable', agend_apps_records_listing_enquiry_state()['status'] );
	}

	#[Test]
	public function should_render_nothing_when_the_scopes_are_not_yet_known(): void {
		delete_option( Agend_Apps_Key_Scopes::OPTION );
		Agend_Test_WP::queue_response( 500, array() );
		$this->pushListing();

		$this->assertSame( '', $this->render() );
	}

	#[Test]
	public function should_not_look_the_listing_up_again_when_the_record_already_says_whether_it_accepts_enquiries(): void {
		$this->pushListing( array( 'accepts_enquiries' => false ) );

		agend_apps_records_listing_enquiry_state();

		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	#[RunInSeparateProcess]
	public function should_ask_the_gateway_for_the_listing_when_the_record_does_not_carry_the_flag(): void {
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/directory-listing-stub.php';
		$GLOBALS['agend_test_directory_listing'] = array( 'data' => array( 'slug' => 'jane-smith', 'accepts_enquiries' => true, 'enquiry_terms_url' => 'https://example.test/terms' ) );
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => array( 'directory.enquiries.send' ) ) ) );
		Agend_Apps_Records_Record_Context::push( 'listing', array( 'slug' => 'jane-smith', 'name' => 'Jane Smith Advisory' ) );

		$state = agend_apps_records_listing_enquiry_state();

		$this->assertSame( 'ready', $state['status'] );
		$this->assertSame( 'https://example.test/terms', $state['terms_url'] );
		$this->assertSame( array( array( 'listing', 'jane-smith' ) ), $GLOBALS['agend_test_directory_requests'] );
	}

	#[Test]
	#[RunInSeparateProcess]
	public function should_stay_hidden_when_the_gateway_cannot_say_whether_the_listing_accepts_enquiries(): void {
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/directory-listing-stub.php';
		$GLOBALS['agend_test_directory_listing'] = new WP_Error( 'agend_api_error', 'down', array( 'status_code' => 500 ) );
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => array( 'directory.enquiries.send' ) ) ) );
		Agend_Apps_Records_Record_Context::push( 'listing', array( 'slug' => 'jane-smith', 'name' => 'Jane Smith Advisory' ) );

		$this->assertSame( 'closed', agend_apps_records_listing_enquiry_state()['status'] );
		$this->assertSame( '', agend_apps_records_render_listing_enquiry( array() ) );
	}

	#[Test]
	public function should_hide_the_form_when_no_captcha_is_configured_and_the_opt_out_is_not_set(): void {
		Agend_Test_WP::set_filter( 'agend_apps_captcha_allow_none', false );
		$this->pushListing();

		$this->assertSame( '', $this->render() );
		$this->assertSame( 'no_captcha', agend_apps_records_listing_enquiry_state()['status'] );
	}

	#[Test]
	public function should_hide_the_form_when_a_provider_is_selected_without_its_site_key_or_secret(): void {
		Agend_Test_WP::set_filter( 'agend_apps_captcha_allow_none', false );
		update_option( Agend_Apps_Captcha::OPTION_PROVIDER, Agend_Apps_Captcha::PROVIDER_RECAPTCHA );
		update_option( Agend_Apps_Captcha::OPTION_SITE_KEY, 'site-key' );
		$this->pushListing();

		$this->assertSame( '', $this->render() );

		Agend_Apps_Secret_Store::set( Agend_Apps_Captcha::SECRET_STORE_KEY, 'secret' );

		$this->assertNotSame( '', $this->render() );
	}

	#[Test]
	public function should_render_the_heading_as_an_h3_unless_the_author_chooses_another_level(): void {
		$this->pushListing();

		$this->assertStringContainsString( '<h3 class="agend-enquiry__heading">', $this->render() );
		$this->assertStringContainsString( '<h2 class="agend-enquiry__heading">Get in touch', $this->render( array( 'heading_tag' => 'h2' ) ) );
		$this->assertStringContainsString( '<h3 class="agend-enquiry__heading">', $this->render( array( 'heading_tag' => 'script' ) ) );
	}

	#[Test]
	public function should_never_enqueue_or_print_a_captcha_script_while_rendering(): void {
		update_option( Agend_Apps_Captcha::OPTION_PROVIDER, Agend_Apps_Captcha::PROVIDER_RECAPTCHA );
		update_option( Agend_Apps_Captcha::OPTION_SITE_KEY, 'site-key' );
		Agend_Apps_Secret_Store::set( Agend_Apps_Captcha::SECRET_STORE_KEY, 'secret' );
		$this->pushListing();

		$html = $this->render();

		$this->assertStringContainsString( 'agend-enquiry__form', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'recaptcha/api.js', $html );
		$this->assertStringNotContainsString( 'turnstile/v0/api.js', $html );
		$this->assertSame( array(), Agend_Test_WP::$enqueued_scripts );
	}

	// -----------------------------------------------------------------
	// Layout and copy
	// -----------------------------------------------------------------

	#[Test]
	public function should_lay_the_fields_out_as_name_pair_email_pair_message_then_submit(): void {
		$this->pushListing();

		$html = $this->render();

		$positions = array();
		foreach ( array( 'name="first_name"', 'name="last_name"', 'name="email"', 'name="phone"', 'name="message"', 'type="submit"' ) as $needle ) {
			$positions[ $needle ] = strpos( $html, $needle );
			$this->assertNotFalse( $positions[ $needle ], $needle );
		}
		$sorted = $positions;
		asort( $sorted );
		$this->assertSame( array_keys( $positions ), array_keys( $sorted ) );

		$this->assertSame( 3, substr_count( $html, 'class="agend-enquiry__row' ), 'two paired rows and one full-width row' );
		$this->assertStringContainsString( 'agend-enquiry__row agend-enquiry__row--full', $html );
		$this->assertStringContainsString( 'agend-enquiry__actions', $html );
	}

	#[Test]
	public function should_use_the_old_smsf_labels_and_the_default_heading_success_message_and_button(): void {
		$this->pushListing();

		$html = $this->render();

		$this->assertStringContainsString( '<h3 class="agend-enquiry__heading">Get in touch with Jane Smith Advisory</h3>', $html );
		foreach ( array( 'First', 'Last', 'Email', 'Mobile', 'Message' ) as $label ) {
			$this->assertMatchesRegularExpression( '/<label class="agend-enquiry__label" for="[^"]+">' . $label . '/', $html );
		}
		$this->assertStringContainsString( '>Submit</button>', $html );
		$this->assertStringContainsString( 'Thanks for contacting us! We will get in touch with you shortly.', $html );
	}

	#[Test]
	public function should_use_the_editable_labels_placeholders_button_heading_and_success_message(): void {
		$this->pushListing();

		$html = $this->render(
			array(
				'heading'             => 'Contact {listing_name} today',
				'first_label'         => 'Given name',
				'first_placeholder'   => 'Ada',
				'message_placeholder' => 'How can we help?',
				'button_text'         => 'Send enquiry',
				'success_message'     => 'Got it, cheers.',
			)
		);

		$this->assertStringContainsString( 'Contact Jane Smith Advisory today', $html );
		$this->assertStringContainsString( '>Given name', $html );
		$this->assertStringContainsString( 'placeholder="Ada"', $html );
		$this->assertStringContainsString( 'placeholder="How can we help?"', $html );
		$this->assertStringContainsString( '>Send enquiry</button>', $html );
		$this->assertStringContainsString( 'Got it, cheers.', $html );
	}

	#[Test]
	public function should_put_the_listing_name_in_the_success_message_button_and_folded_copy(): void {
		$this->pushListing();

		$html = $this->render(
			array(
				'start_folded'        => 'yes',
				'heading'             => '',
				'folded_label'        => 'Ask {listing_name}',
				'folded_intro'        => '{listing_name} replies by email.',
				'message_placeholder' => 'Your question for {listing_name}',
				'button_text'         => 'Message {listing_name}',
				'success_message'     => 'Sent. {listing_name} will reply to the email address you gave.',
			)
		);

		$this->assertStringNotContainsString( '{listing_name}', $html );
		$this->assertStringContainsString( '>Ask Jane Smith Advisory</span>', $html );
		$this->assertStringContainsString( '>Jane Smith Advisory replies by email.</span>', $html );
		$this->assertStringContainsString( 'placeholder="Your question for Jane Smith Advisory"', $html );
		$this->assertStringContainsString( '>Message Jane Smith Advisory</button>', $html );
		preg_match( '/data-agend-enquiry-config="([^"]+)"/', $html, $m );
		$config = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
		$this->assertSame( 'Sent. Jane Smith Advisory will reply to the email address you gave.', $config['successMessage'] );
	}

	#[Test]
	public function should_escape_the_listing_name_when_it_fills_the_button(): void {
		$this->pushListing( array( 'name' => '<b>Jane</b>' ) );

		$html = $this->render( array( 'button_text' => 'Message {listing_name}' ) );

		$this->assertStringNotContainsString( '<b>Jane</b>', $html );
		$this->assertStringContainsString( '>Message &lt;b&gt;Jane&lt;/b&gt;</button>', $html );
	}

	#[Test]
	public function should_fall_back_to_the_default_when_a_label_or_button_is_left_blank(): void {
		$this->pushListing();

		$html = $this->render( array( 'first_label' => '  ', 'button_text' => '' ) );

		$this->assertStringContainsString( '>First', $html );
		$this->assertStringContainsString( '>Submit</button>', $html );
	}

	#[Test]
	public function should_hide_the_heading_when_the_author_clears_it(): void {
		$this->pushListing();

		$this->assertStringNotContainsString( 'agend-enquiry__heading', $this->render( array( 'heading' => '' ) ) );
	}

	#[Test]
	public function should_escape_the_listing_name_and_every_label(): void {
		$this->pushListing( array( 'name' => '<script>alert(1)</script> Advisory' ) );

		$html = $this->render( array( 'first_label' => '"><img src=x onerror=alert(1)>', 'button_text' => '<b>Go</b>' ) );

		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringNotContainsString( '<b>Go</b>', $html );
	}

	// -----------------------------------------------------------------
	// Accessibility
	// -----------------------------------------------------------------

	#[Test]
	public function should_label_every_control_and_expose_a_polite_status_region(): void {
		$this->pushListing();

		$html = $this->render();

		foreach ( array( 'first', 'last', 'email', 'phone', 'message' ) as $key ) {
			$this->assertStringContainsString( 'for="agend-enquiry-abc123-' . $key . '"', $html );
			$this->assertStringContainsString( 'id="agend-enquiry-abc123-' . $key . '"', $html );
			$this->assertStringContainsString( 'id="agend-enquiry-abc123-' . $key . '-error"', $html );
		}
		$this->assertStringContainsString( 'role="status" aria-live="polite" tabindex="-1"', $html );
		$this->assertStringContainsString( 'novalidate', $html );
	}

	#[Test]
	public function should_mark_only_the_required_fields_required(): void {
		$this->pushListing();

		$html = $this->render();

		foreach ( array( 'first_name', 'last_name', 'email', 'message' ) as $name ) {
			$this->assertMatchesRegularExpression( '/name="' . $name . '"[^>]* required aria-required="true"/', $html, $name );
		}
		$this->assertDoesNotMatchRegularExpression( '/name="phone"[^>]* required/', $html );
	}

	// -----------------------------------------------------------------
	// Terms
	// -----------------------------------------------------------------

	#[Test]
	public function should_show_no_terms_checkbox_when_the_listing_has_no_terms_url(): void {
		$this->pushListing( array( 'enquiry_terms_url' => null ) );

		$html = $this->render();

		$this->assertStringNotContainsString( 'terms_accepted', $html );
		$this->assertStringContainsString( '&quot;hasTerms&quot;:false', $html );
	}

	#[Test]
	public function should_show_a_required_terms_checkbox_linking_to_the_terms_url(): void {
		$this->pushListing( array( 'enquiry_terms_url' => 'https://example.test/terms?a=1&b=2' ) );

		$html = $this->render();

		$this->assertStringContainsString( 'name="terms_accepted"', $html );
		$this->assertMatchesRegularExpression( '/name="terms_accepted"[^>]* required/', $html );
		$this->assertStringContainsString( 'I agree to the <a href="https://example.test/terms?a=1&amp;b=2" target="_blank" rel="noopener">terms and conditions</a>', $html );
		$this->assertStringContainsString( '&quot;hasTerms&quot;:true', $html );
	}

	#[Test]
	public function should_append_the_terms_link_when_the_label_has_no_token(): void {
		$this->pushListing( array( 'enquiry_terms_url' => 'https://example.test/terms' ) );

		$html = $this->render( array( 'terms_label' => 'I accept', 'terms_link_text' => 'the rules' ) );

		$this->assertStringContainsString( 'I accept <a href="https://example.test/terms"', $html );
		$this->assertStringContainsString( '>the rules</a>', $html );
	}

	#[Test]
	public function should_not_link_a_terms_url_with_an_unsafe_scheme(): void {
		$this->pushListing( array( 'enquiry_terms_url' => 'javascript:alert(1)' ) );

		$this->assertStringNotContainsString( 'javascript:', $this->render() );
	}

	// -----------------------------------------------------------------
	// Captcha hand-off
	// -----------------------------------------------------------------

	#[Test]
	public function should_hand_the_script_no_captcha_when_no_provider_is_selected(): void {
		$this->pushListing();

		$html = $this->render();

		$this->assertStringContainsString( '&quot;captcha&quot;:null', $html );
		$this->assertStringNotContainsString( 'data-agend-enquiry-captcha', $html );
	}

	#[Test]
	public function should_hand_the_script_the_provider_and_public_site_key_but_never_the_secret(): void {
		update_option( Agend_Apps_Captcha::OPTION_PROVIDER, Agend_Apps_Captcha::PROVIDER_RECAPTCHA );
		update_option( Agend_Apps_Captcha::OPTION_SITE_KEY, 'public-site-key' );
		update_option( Agend_Apps_Captcha::OPTION_ACTION, 'smsf_contact' );
		Agend_Apps_Secret_Store::set( Agend_Apps_Captcha::SECRET_STORE_KEY, 'never-in-the-page' );
		$this->pushListing();

		$html = $this->render();

		$this->assertStringContainsString( '&quot;provider&quot;:&quot;recaptcha&quot;', $html );
		$this->assertStringContainsString( '&quot;siteKey&quot;:&quot;public-site-key&quot;', $html );
		$this->assertStringContainsString( '&quot;action&quot;:&quot;smsf_contact&quot;', $html );
		$this->assertStringNotContainsString( 'never-in-the-page', $html );
		$this->assertStringNotContainsString( 'data-agend-enquiry-captcha', $html, 'reCAPTCHA v3 has no visible widget' );
	}

	#[Test]
	public function should_hand_the_script_the_recaptcha_v2_provider_and_site_key_and_render_a_widget_container(): void {
		update_option( Agend_Apps_Captcha::OPTION_PROVIDER, Agend_Apps_Captcha::PROVIDER_RECAPTCHA_V2 );
		update_option( Agend_Apps_Captcha::OPTION_SITE_KEY, 'v2-site-key' );
		Agend_Apps_Secret_Store::set( Agend_Apps_Captcha::SECRET_STORE_KEY, 'never-in-the-page' );
		$this->pushListing();

		$html = $this->render();

		$this->assertStringContainsString( '&quot;provider&quot;:&quot;recaptcha_v2&quot;', $html );
		$this->assertStringContainsString( '&quot;siteKey&quot;:&quot;v2-site-key&quot;', $html );
		$this->assertStringContainsString( 'data-agend-enquiry-captcha', $html );
		$this->assertLessThan( strpos( $html, 'agend-enquiry__actions' ), strpos( $html, 'data-agend-enquiry-captcha' ) );
		$this->assertStringNotContainsString( 'never-in-the-page', $html );
	}

	#[Test]
	public function should_include_a_widget_container_for_turnstile(): void {
		update_option( Agend_Apps_Captcha::OPTION_PROVIDER, Agend_Apps_Captcha::PROVIDER_TURNSTILE );
		update_option( Agend_Apps_Captcha::OPTION_SITE_KEY, 'public-site-key' );
		Agend_Apps_Secret_Store::set( Agend_Apps_Captcha::SECRET_STORE_KEY, 'secret' );
		$this->pushListing();

		$this->assertStringContainsString( 'data-agend-enquiry-captcha', $this->render() );
	}

	private function useRecaptcha(): void {
		update_option( Agend_Apps_Captcha::OPTION_PROVIDER, Agend_Apps_Captcha::PROVIDER_RECAPTCHA );
		update_option( Agend_Apps_Captcha::OPTION_SITE_KEY, 'public-site-key' );
		Agend_Apps_Secret_Store::set( Agend_Apps_Captcha::SECRET_STORE_KEY, 'secret' );
		$this->pushListing();
	}

	#[Test]
	public function should_render_the_linked_recaptcha_notice_beside_the_button_and_hide_the_badge_when_the_notice_is_on(): void {
		$this->useRecaptcha();

		$html = $this->render( array( 'recaptcha_notice' => 'yes' ) );

		$this->assertStringContainsString( '.grecaptcha-badge{visibility:hidden}', $html );
		$this->assertStringContainsString( 'href="https://policies.google.com/privacy"', $html );
		$this->assertStringContainsString( 'href="https://policies.google.com/terms"', $html );
		$this->assertStringContainsString( 'Protected by reCAPTCHA.', $html );
		$this->assertLessThan( strpos( $html, 'data-agend-enquiry-submit' ), strpos( $html, 'agend-enquiry__recaptcha' ) );
	}

	#[Test]
	public function should_render_no_notice_and_leave_the_badge_alone_when_the_notice_is_off(): void {
		$this->useRecaptcha();

		$html = $this->render();

		$this->assertStringNotContainsString( 'grecaptcha-badge', $html );
		$this->assertStringNotContainsString( 'agend-enquiry__recaptcha', $html );
	}

	#[Test]
	public function should_render_no_notice_for_turnstile_or_no_provider_when_the_notice_is_on(): void {
		$this->pushListing();
		$this->assertStringNotContainsString( 'agend-enquiry__recaptcha', $this->render( array( 'recaptcha_notice' => 'yes' ) ) );

		update_option( Agend_Apps_Captcha::OPTION_PROVIDER, Agend_Apps_Captcha::PROVIDER_TURNSTILE );
		update_option( Agend_Apps_Captcha::OPTION_SITE_KEY, 'public-site-key' );
		Agend_Apps_Secret_Store::set( Agend_Apps_Captcha::SECRET_STORE_KEY, 'secret' );
		$html = $this->render( array( 'recaptcha_notice' => 'yes' ) );

		$this->assertStringNotContainsString( 'agend-enquiry__recaptcha', $html );
		$this->assertStringNotContainsString( 'grecaptcha-badge', $html );
	}

	// -----------------------------------------------------------------
	// Endpoint and privacy
	// -----------------------------------------------------------------

	#[Test]
	public function should_post_to_the_enquiry_route_for_this_listing(): void {
		$this->pushListing();

		$this->assertStringContainsString( 'https://example.test/wp-json/agend-apps/v1/directory/listings/jane-smith/enquiries', str_replace( '\\/', '/', htmlspecialchars_decode( $this->render() ) ) );
	}

	#[Test]
	public function should_never_render_the_listings_email_address(): void {
		$this->pushListing( array( 'email' => 'owner@example.test', 'contact_email' => 'owner@example.test' ) );

		$this->assertStringNotContainsString( 'owner@example.test', $this->render() );
	}

	#[Test]
	public function should_mark_the_form_as_a_preview_in_the_editor_so_it_never_submits(): void {
		Agend_Apps_Records_Record_Context::reset();

		$html = agend_apps_records_render_listing_enquiry(
			array(),
			array(
				'id'    => 'abc123',
				'state' => array(
					'status'     => 'ready',
					'identifier' => 'jane-smith',
					'name'       => 'Jane Smith Advisory',
					'terms_url'  => '',
					'is_preview' => true,
				),
				'preview' => true,
			)
		);

		$this->assertStringContainsString( 'data-agend-enquiry-preview="1"', $html );
	}

	// -----------------------------------------------------------------
	// Anchor and start folded
	// -----------------------------------------------------------------

	#[Test]
	public function should_give_the_wrapper_the_enquiry_anchor_id_when_it_is_the_first_form_on_the_page(): void {
		$this->pushListing();

		$this->assertStringContainsString( '<div class="agend-enquiry" id="agend-enquiry" data-agend-enquiry-config=', $this->render() );
	}

	#[Test]
	public function should_give_a_second_form_on_the_page_no_anchor_id_when_one_already_holds_it(): void {
		$this->pushListing();

		$first  = $this->render();
		$second = $this->render();

		$this->assertSame( 1, substr_count( $first . $second, 'id="agend-enquiry"' ) );
		$this->assertStringStartsWith( '<div class="agend-enquiry" data-agend-enquiry-config=', $second );
	}

	#[Test]
	public function should_render_the_form_unfolded_without_details_when_start_folded_is_off(): void {
		$this->pushListing();

		$html = $this->render( array( 'folded_intro' => 'Replies come straight to your inbox.' ) );

		$this->assertStringNotContainsString( '<details', $html );
		$this->assertStringNotContainsString( 'agend-enquiry--folded', $html );
		$this->assertStringNotContainsString( 'Replies come straight', $html );
		$this->assertStringContainsString( '<h3 class="agend-enquiry__heading">Get in touch with Jane Smith Advisory</h3><form', $html );
	}

	#[Test]
	public function should_render_the_heading_and_intro_in_the_summary_and_the_fields_inside_the_details_when_start_folded_is_on(): void {
		$this->pushListing();

		$html = $this->render(
			array(
				'start_folded' => 'yes',
				'heading_tag'  => 'h2',
				'folded_intro' => 'Replies come straight to your inbox.',
			)
		);

		$this->assertStringContainsString( 'agend-enquiry--folded', $html );
		$this->assertMatchesRegularExpression(
			'#<details class="agend-enquiry__details"><summary class="agend-enquiry__summary"><span class="agend-enquiry__summary-text"><h2 class="agend-enquiry__heading">Get in touch with Jane Smith Advisory</h2><span class="agend-enquiry__intro">Replies come straight to your inbox\.</span></span><span class="agend-enquiry__toggle" aria-hidden="true"></span></summary>#',
			$html
		);
		$this->assertGreaterThan( strpos( $html, '</summary>' ), strpos( $html, '<form class="agend-enquiry__form"' ) );
		$this->assertLessThan( strpos( $html, '</details>' ), strpos( $html, 'data-agend-enquiry-field="message"' ) );
	}

	#[Test]
	public function should_keep_the_status_region_inside_the_details_when_start_folded_is_on(): void {
		$this->pushListing();

		$html = $this->render( array( 'start_folded' => 'yes' ) );

		$this->assertLessThan( strpos( $html, '</details>' ), strpos( $html, 'role="status" aria-live="polite"' ) );
		$this->assertStringEndsWith( '</details></div>', $html );
	}

	#[Test]
	public function should_fall_back_to_a_summary_label_when_the_heading_is_blank_and_start_folded_is_on(): void {
		$this->pushListing();

		$html = $this->render( array( 'start_folded' => 'yes', 'heading' => '' ) );

		$this->assertStringContainsString( '<span class="agend-enquiry__heading">Send an enquiry</span>', $html );
	}

	#[Test]
	public function should_render_the_details_open_in_the_editor_even_when_start_folded_is_on(): void {
		$html = agend_apps_records_render_listing_enquiry(
			array( 'start_folded' => 'yes' ),
			array(
				'id'      => 'abc123',
				'preview' => true,
				'state'   => array(
					'status'     => 'ready',
					'identifier' => 'jane-smith',
					'name'       => 'Jane Smith Advisory',
					'terms_url'  => '',
					'is_preview' => true,
				),
			)
		);

		$this->assertStringContainsString( '<details class="agend-enquiry__details" open>', $html );
	}
}

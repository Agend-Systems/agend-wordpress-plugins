<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Apps_Key_Scopes;
use Agend_Test_WP;
use Agend_Apps_Records_Record_Context;
use Agend_Elementor_Listing_Enquiry;
use Agend\Tests\TestCase;
use Elementor\Plugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

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
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-field-widget-trait.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-listing-enquiry.php';

/**
 * Exposes the protected methods a test drives directly.
 */
final class Listing_Enquiry_Widget_Test_Harness extends Agend_Elementor_Listing_Enquiry {
	public function run_controls(): void {
		$this->register_controls();
	}

	public function run_render(): string {
		ob_start();
		$this->render();

		return (string) ob_get_clean();
	}
}

/**
 * The Agend Listing Enquiry Form widget: where it renders, what an editor is
 * told when it would not show a visitor, and the controls it offers.
 */
#[CoversClass( Agend_Elementor_Listing_Enquiry::class )]
final class ListingEnquiryWidgetTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Records_Record_Context::reset();
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => array( 'directory.enquiries.send' ) ) ) );
		Agend_Test_WP::set_filter( 'agend_apps_captcha_allow_none', true );
	}

	protected function tearDown(): void {
		Agend_Apps_Records_Record_Context::reset();
		parent::tearDown();
	}

	private function widget( array $settings = array() ): Listing_Enquiry_Widget_Test_Harness {
		return new Listing_Enquiry_Widget_Test_Harness( array(), null, $settings );
	}

	private function pushListing( array $record = array() ): void {
		Agend_Apps_Records_Record_Context::push(
			'listing',
			$record + array(
				'slug'              => 'jane-smith',
				'name'              => 'Jane Smith Advisory',
				'accepts_enquiries' => true,
			)
		);
	}

	private function openEditor(): void {
		Plugin::$instance->editor->is_edit_mode = true;
	}

	// -----------------------------------------------------------------
	// Front end
	// -----------------------------------------------------------------

	#[Test]
	public function should_render_the_form_for_a_listing_that_accepts_enquiries(): void {
		$this->pushListing();

		$html = $this->widget()->run_render();

		$this->assertStringContainsString( 'class="agend-enquiry"', $html );
		$this->assertStringContainsString( 'Get in touch with Jane Smith Advisory', $html );
		$this->assertStringNotContainsString( 'elementor-alert', $html );
		$this->assertStringNotContainsString( 'data-agend-enquiry-preview', $html );
	}

	#[Test]
	public function should_render_nothing_at_all_for_a_visitor_when_the_listing_does_not_accept_enquiries(): void {
		$this->pushListing( array( 'accepts_enquiries' => false ) );

		$this->assertSame( '', $this->widget()->run_render() );
	}

	#[Test]
	public function should_render_nothing_at_all_for_a_visitor_outside_a_listing_template(): void {
		$this->assertSame( '', $this->widget()->run_render() );
	}

	#[Test]
	public function should_render_nothing_at_all_for_a_visitor_when_the_key_lacks_the_scope(): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => array() ) ) );
		$this->pushListing();

		$this->assertSame( '', $this->widget()->run_render() );
	}

	#[Test]
	public function should_pass_the_widget_settings_through_to_the_form(): void {
		$this->pushListing();

		$html = $this->widget( array( 'button_text' => 'Send it', 'heading' => 'Ask {listing_name}' ) )->run_render();

		$this->assertStringContainsString( '>Send it</button>', $html );
		$this->assertStringContainsString( 'Ask Jane Smith Advisory', $html );
	}

	#[Test]
	public function should_give_each_widget_its_own_element_ids(): void {
		$this->pushListing();

		$this->assertStringContainsString( 'id="agend-enquiry-test-widget-id"', $this->widget()->run_render() );
	}

	// -----------------------------------------------------------------
	// Editor
	// -----------------------------------------------------------------

	#[Test]
	public function should_show_an_editor_only_notice_and_still_draw_the_form_when_the_listing_is_closed(): void {
		$this->openEditor();
		$this->pushListing( array( 'accepts_enquiries' => false ) );

		$html = $this->widget()->run_render();

		$this->assertStringContainsString( 'elementor-alert elementor-alert-warning', $html );
		$this->assertStringContainsString( 'does not accept enquiries, so visitors will not see this form', $html );
		$this->assertStringContainsString( 'class="agend-enquiry"', $html );
		$this->assertStringContainsString( 'data-agend-enquiry-preview="1"', $html );
	}

	#[Test]
	public function should_draw_an_inert_preview_form_with_no_notice_against_the_sample_listing(): void {
		$this->openEditor();

		$html = $this->widget()->run_render();

		$this->assertStringNotContainsString( 'elementor-alert', $html );
		$this->assertStringContainsString( 'data-agend-enquiry-preview="1"', $html );
		$this->assertStringContainsString( 'Get in touch with Sample Listing', $html );
	}

	#[Test]
	public function should_render_nothing_for_a_visitor_and_tell_an_editor_when_no_captcha_is_configured(): void {
		Agend_Test_WP::set_filter( 'agend_apps_captcha_allow_none', false );
		$this->pushListing();

		$this->assertSame( '', $this->widget()->run_render() );

		$this->openEditor();
		$html = $this->widget()->run_render();

		$this->assertStringContainsString( 'No captcha is configured', $html );
		$this->assertStringContainsString( 'data-agend-enquiry-preview="1"', $html );
	}

	#[Test]
	public function should_offer_a_heading_level_control_that_defaults_to_h3_and_apply_it(): void {
		$widget = $this->widget();
		$widget->run_controls();

		$control = array_values( array_filter( $widget->recordings, static fn( array $r ): bool => 'add_control' === $r['method'] && 'heading_tag' === $r['id'] ) );

		$this->assertSame( 'h3', $control[0]['args']['default'] );

		$this->pushListing();
		$this->assertStringContainsString( '<h4 class="agend-enquiry__heading">', $this->widget( array( 'heading_tag' => 'h4' ) )->run_render() );
	}

	#[Test]
	public function should_tell_an_editor_when_the_key_lacks_the_scope_and_draw_no_form(): void {
		$this->openEditor();
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => array() ) ) );
		$this->pushListing();

		$html = $this->widget()->run_render();

		$this->assertStringContainsString( 'elementor-alert', $html );
		$this->assertStringContainsString( 'directory.enquiries.send', $html );
		$this->assertStringNotContainsString( 'agend-enquiry__form', $html );
	}

	#[Test]
	public function should_tell_an_editor_when_the_widget_is_not_in_a_listing_template(): void {
		$this->openEditor();
		Agend_Apps_Records_Record_Context::push( 'event', array( 'slug' => 'gala', 'name' => 'Gala' ) );

		$html = $this->widget()->run_render();

		$this->assertStringContainsString( 'only available for directory listings', $html );
		$this->assertStringNotContainsString( 'agend-enquiry__form', $html );
	}

	// -----------------------------------------------------------------
	// Registration and controls
	// -----------------------------------------------------------------

	#[Test]
	public function should_load_its_script_and_style_by_handle_only_when_the_widget_is_used(): void {
		$widget = $this->widget();

		$this->assertSame( array( 'agend-apps-records-listing-enquiry' ), $widget->get_script_depends() );
		$this->assertSame( array( 'agend-apps-records-listing-enquiry' ), $widget->get_style_depends() );
		$this->assertSame( 'agend-listing-enquiry', $widget->get_name() );
	}

	#[Test]
	public function should_default_every_text_control_to_the_renderers_default_copy(): void {
		$widget = $this->widget();
		$widget->run_controls();

		$defaults = agend_apps_records_listing_enquiry_defaults();
		$controls = array();
		foreach ( $widget->recordings as $recording ) {
			if ( 'add_control' === $recording['method'] && isset( $recording['args']['default'] ) ) {
				$controls[ $recording['id'] ] = $recording['args']['default'];
			}
		}

		foreach ( array( 'heading', 'button_text', 'success_message', 'first_label', 'last_label', 'email_label', 'phone_label', 'message_label', 'terms_label', 'terms_link_text' ) as $key ) {
			$this->assertSame( $defaults[ $key ], $controls[ $key ], $key );
		}
		$this->assertSame( 'Get in touch with {listing_name}', $controls['heading'] );
		$this->assertSame( 'Thanks for contacting us! We will get in touch with you shortly.', $controls['success_message'] );
	}

	#[Test]
	public function should_offer_style_controls_for_heading_labels_fields_button_and_messages(): void {
		$widget = $this->widget();
		$widget->run_controls();

		$style_sections = array();
		foreach ( $widget->recordings as $recording ) {
			if ( 'start_controls_section' === $recording['method'] && 'style' === $recording['args']['tab'] ) {
				$style_sections[] = $recording['id'];
			}
		}

		$this->assertSame(
			array( 'section_style_heading', 'section_style_labels', 'section_style_fields', 'section_style_button', 'section_style_messages' ),
			$style_sections
		);
	}
}

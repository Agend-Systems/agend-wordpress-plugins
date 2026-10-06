<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Identity_Admin;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/admin/class-agend-apps-identity-admin.php';

/**
 * The Identity and SSO page must never nest a `<form>` inside its
 * options.php form. A browser drops the inner tag, so the inner button
 * submits the settings form: the member lookup posted to options.php and the
 * panel fell back to the signed-in admin.
 */
final class IdentityAdminFormsTest extends TestCase {

	/** Fields whose markup carries their own `<form>`. */
	private const FORM_BEARING_FIELDS = array( 'agend_apps_connect_site', 'agend_apps_wp_idp_diagnostics' );

	/**
	 * @return array<string, array<string, mixed>> field id => field, for one page.
	 */
	private function fieldsOn( string $page ): array {
		$fields = array();

		foreach ( Agend_Test_WP::$settings_sections[ $page ] ?? array() as $section ) {
			$fields += $section['fields'] ?? array();
		}

		return $fields;
	}

	#[Test]
	public function should_register_the_lookup_and_connect_fields_on_the_tools_page_when_settings_register(): void {
		( new Agend_Apps_Identity_Admin() )->register_settings();

		$tools    = $this->fieldsOn( Agend_Apps_Identity_Admin::TOOLS_PAGE_SLUG );
		$settings = $this->fieldsOn( Agend_Apps_Identity_Admin::PAGE_SLUG );

		foreach ( self::FORM_BEARING_FIELDS as $field_id ) {
			$this->assertArrayHasKey( $field_id, $tools );
			$this->assertArrayNotHasKey( $field_id, $settings );
		}
	}

	#[Test]
	public function should_render_no_form_tag_when_a_field_inside_the_options_form_renders(): void {
		( new Agend_Apps_Identity_Admin() )->register_settings();

		$fields = $this->fieldsOn( Agend_Apps_Identity_Admin::PAGE_SLUG );
		$this->assertNotEmpty( $fields );

		foreach ( $fields as $field_id => $field ) {
			ob_start();
			call_user_func( $field['callback'] );
			$html = (string) ob_get_clean();

			$this->assertStringNotContainsStringIgnoringCase( '<form', $html, "Field {$field_id} renders inside the options.php form." );
		}
	}

	#[Test]
	public function should_render_the_tools_sections_after_the_options_form_closes_when_the_page_renders(): void {
		add_settings_section( 'settings_section', 'Settings', null, Agend_Apps_Identity_Admin::PAGE_SLUG );
		add_settings_field( 'settings_field', 'Setting', static fn() => print( '[settings-field]' ), Agend_Apps_Identity_Admin::PAGE_SLUG, 'settings_section' );
		add_settings_section( 'tools_section', 'Tools', null, Agend_Apps_Identity_Admin::TOOLS_PAGE_SLUG );
		add_settings_field( 'tools_field', 'Tool', static fn() => print( '<form method="get">[tools-field]</form>' ), Agend_Apps_Identity_Admin::TOOLS_PAGE_SLUG, 'tools_section' );

		ob_start();
		require AGEND_TESTS_ROOT . '/agend-apps-core/admin/views/identity-settings.php';
		$html = (string) ob_get_clean();

		$options_form_open  = strpos( $html, 'action="options.php"' );
		$options_form_close = strpos( $html, '</form>', (int) $options_form_open );

		$this->assertNotFalse( $options_form_open );
		$this->assertNotFalse( $options_form_close );
		$this->assertGreaterThan( $options_form_open, strpos( $html, '[settings-field]' ) );
		$this->assertLessThan( $options_form_close, strpos( $html, '[settings-field]' ) );
		$this->assertGreaterThan( $options_form_close, strpos( $html, '<form method="get">' ) );
	}
}

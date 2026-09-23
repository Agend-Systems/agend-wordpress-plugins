<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Key_Scopes;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/directory-export-reports.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/filters.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema/export-reports.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/export-reports.php';

/**
 * The parameter picker replaces a free-text key nobody could guess, and the
 * automatic pairing removes the second and third guesses that went with it.
 * These cover the parts that decide what a configured row actually sends.
 */
final class ExportReportsParameterPickerTest extends TestCase {

	/**
	 * @param array<int, array<string, mixed>> $reports
	 */
	private function seedListing( array $reports ): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'scopes' => array( 'directory.export_reports.browse' ) ) ) );
		Agend_Apps_Key_Scopes::refresh();
		Agend_Test_WP::$requests = array();

		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => $reports ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function sampleReports(): array {
		return array(
			array(
				'id'         => 'rep-a',
				'name'       => 'Member directory',
				'audience'   => 'anonymous',
				'parameters' => array(
					array( 'name' => 'c1', 'field' => 'location.state' ),
					array( 'name' => 'c2', 'field' => 'category' ),
				),
			),
			array(
				'id'         => 'rep-b',
				'name'       => 'Training providers',
				'audience'   => 'members_only',
				'parameters' => array(
					array( 'name' => 'c3', 'field' => 'custom.education_level' ),
					array( 'name' => 'c4', 'field' => 'location.state' ),
				),
			),
			array(
				'id'         => 'rep-c',
				'name'       => 'Venue list',
				'audience'   => 'restricted',
				'parameters' => array(),
			),
		);
	}

	// -------------------------------------------------------------------
	// Which key a row resolves to
	// -------------------------------------------------------------------

	#[Test]
	public function the_picker_wins_when_it_holds_a_key(): void {
		$field = agend_apps_records_export_reports_row_field(
			array( 'param_field_pick' => 'location.state', 'param_field' => 'state' )
		);

		$this->assertSame( 'location.state', $field );
	}

	#[Test]
	public function the_free_text_key_answers_when_the_picker_is_set_to_other(): void {
		$field = agend_apps_records_export_reports_row_field(
			array( 'param_field_pick' => '__other', 'param_field' => ' custom.membership_tier ' )
		);

		$this->assertSame( 'custom.membership_tier', $field );
	}

	#[Test]
	public function a_row_saved_before_the_picker_existed_still_resolves_through_the_free_text_key(): void {
		// The whole point of adding a control rather than retyping the existing
		// one: an existing row has no picker value and must keep behaving
		// exactly as it did, including a row whose key matches nothing.
		$this->assertSame( 'state', agend_apps_records_export_reports_row_field( array( 'param_field' => 'state' ) ) );
		$this->assertSame( '', agend_apps_records_export_reports_row_field( array() ) );
	}

	// -------------------------------------------------------------------
	// Automatic filter pairing
	// -------------------------------------------------------------------

	#[Test]
	public function a_location_parameter_pairs_with_its_catalogue_filter(): void {
		$resolved = agend_apps_records_export_reports_resolve_auto_filter( 'location.state' );

		$this->assertSame( 'state', $resolved['filter'] );
		$this->assertSame( '', $resolved['custom_key'] );
	}

	#[Test]
	public function a_custom_field_parameter_supplies_its_own_filter_and_key(): void {
		$resolved = agend_apps_records_export_reports_resolve_auto_filter( 'custom.education_level' );

		$this->assertSame( 'custom_field', $resolved['filter'] );
		$this->assertSame( 'education_level', $resolved['custom_key'] );
	}

	#[Test]
	public function a_parameter_with_no_obvious_filter_pairs_with_nothing(): void {
		foreach ( array( 'text', 'location_radius', 'invented' ) as $field ) {
			$resolved = agend_apps_records_export_reports_resolve_auto_filter( $field );

			$this->assertSame( '', $resolved['filter'], $field . ' should not be paired automatically' );
		}
	}

	#[Test]
	public function the_mapper_resolves_an_automatic_row_end_to_end(): void {
		$rows = agend_apps_records_export_reports_parameter_map(
			array(
				'parameters' => array(
					array(
						'param_field_pick'       => 'custom.education_level',
						'param_source'           => 'catalogue',
						'param_catalogue_filter' => '__auto',
					),
				),
			)
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( 'custom.education_level', $rows[0]['field'] );
		$this->assertSame( 'custom_field', $rows[0]['filter'] );
		$this->assertSame( 'education_level', $rows[0]['customKey'] );
	}

	#[Test]
	public function an_unchosen_filter_keeps_meaning_unchosen(): void {
		// '' is what a row saved before the automatic option existed stores.
		// It must not quietly acquire a filter on upgrade.
		$rows = agend_apps_records_export_reports_parameter_map(
			array(
				'parameters' => array(
					array(
						'param_field'            => 'location.state',
						'param_source'           => 'catalogue',
						'param_catalogue_filter' => '',
					),
				),
			)
		);

		$this->assertSame( '', $rows[0]['filter'] );
	}

	#[Test]
	public function an_explicit_filter_is_never_overridden_by_the_pairing(): void {
		$rows = agend_apps_records_export_reports_parameter_map(
			array(
				'parameters' => array(
					array(
						'param_field_pick'       => 'location.state',
						'param_source'           => 'catalogue',
						'param_catalogue_filter' => 'city',
					),
				),
			)
		);

		$this->assertSame( 'city', $rows[0]['filter'] );
	}

	// -------------------------------------------------------------------
	// The picker's options
	// -------------------------------------------------------------------

	#[Test]
	public function the_picker_lists_only_keys_a_report_actually_declares(): void {
		$this->seedListing( $this->sampleReports() );

		$groups = agend_apps_records_export_reports_parameter_field_groups();

		$options = array();
		foreach ( $groups as $group ) {
			foreach ( $group['options'] as $value => $label ) {
				$options[ $value ] = $label;
			}
		}

		$this->assertArrayHasKey( 'location.state', $options );
		$this->assertArrayHasKey( 'category', $options );
		$this->assertArrayHasKey( 'custom.education_level', $options );
		$this->assertArrayNotHasKey( 'keyword', $options, 'no report declares keyword, so offering it would recreate the silent failure' );
		$this->assertArrayHasKey( '__other', $options );
	}

	#[Test]
	public function the_picker_groups_and_labels_each_key(): void {
		$this->seedListing( $this->sampleReports() );

		$groups = agend_apps_records_export_reports_parameter_field_groups();
		$byName = array();
		foreach ( $groups as $group ) {
			$byName[ $group['label'] ] = $group['options'];
		}

		$this->assertSame( 'State (location.state)', $byName['Location']['location.state'] );
		$this->assertSame( 'Category (category)', $byName['Listing']['category'] );
		$this->assertSame( 'Education Level (custom.education_level)', $byName['Custom fields']['custom.education_level'] );
		$this->assertArrayHasKey( 'Advanced', $byName );
	}

	#[Test]
	public function the_escape_hatch_is_offered_even_when_no_report_declares_anything(): void {
		$this->seedListing( array() );

		$groups = agend_apps_records_export_reports_parameter_field_groups();

		$this->assertCount( 1, $groups );
		$this->assertSame( 'Advanced', $groups[0]['label'] );
		$this->assertArrayHasKey( '__other', $groups[0]['options'] );
	}

	#[Test]
	public function the_union_deduplicates_across_reports(): void {
		$this->seedListing( $this->sampleReports() );

		$union = agend_apps_records_export_reports_parameter_field_union();

		$this->assertSame( array( 'category', 'custom.education_level', 'location.state' ), $union );
	}

	#[Test]
	public function each_report_reports_the_parameters_it_declares(): void {
		$this->seedListing( $this->sampleReports() );

		$declared = agend_apps_records_export_reports_declared_parameters();

		$this->assertCount( 3, $declared );
		$this->assertSame( 'Member directory', $declared[0]['name'] );
		$this->assertSame( array( 'location.state', 'category' ), $declared[0]['fields'] );
		$this->assertSame( array(), $declared[2]['fields'] );
	}

	#[Test]
	public function the_filter_state_keys_come_from_the_registry(): void {
		$keys = agend_apps_records_export_reports_filter_state_keys();

		$this->assertSame( 'location_state', $keys['state'] );
		$this->assertSame( 'custom_fields', $keys['custom_field'] );
	}

	// -------------------------------------------------------------------
	// Sign in pre-emption
	// -------------------------------------------------------------------

	#[Test]
	public function a_signed_out_visitor_is_offered_a_sign_in_for_a_members_only_report(): void {
		$this->seedListing( $this->sampleReports() );

		$needs = agend_apps_records_export_reports_sign_in_required(
			array(
				array( 'id' => 'rep-a', 'label' => '' ),
				array( 'id' => 'rep-b', 'label' => '' ),
				array( 'id' => 'rep-c', 'label' => '' ),
			)
		);

		$this->assertArrayNotHasKey( 'rep-a', $needs, 'an anonymous-audience report downloads without signing in' );
		$this->assertArrayHasKey( 'rep-b', $needs );
		$this->assertArrayHasKey( 'rep-c', $needs );
	}

	#[Test]
	public function a_signed_in_visitor_is_never_pre_empted(): void {
		$GLOBALS['agend_test_current_user_id'] = 12;
		$this->seedListing( $this->sampleReports() );

		$needs = agend_apps_records_export_reports_sign_in_required( array( array( 'id' => 'rep-b', 'label' => '' ) ) );

		$this->assertSame( array(), $needs );
		$this->assertCount( 0, Agend_Test_WP::$requests, 'a signed-in visitor should not cost a listing call' );
	}

	#[Test]
	public function a_report_missing_from_the_listing_is_left_to_the_ordinary_path(): void {
		// Absence is ambiguous: unpublished and deleted look the same as
		// members-only from here, and signing in would not fix either.
		$this->seedListing( $this->sampleReports() );

		$needs = agend_apps_records_export_reports_sign_in_required( array( array( 'id' => 'rep-gone', 'label' => '' ) ) );

		$this->assertSame( array(), $needs );
	}

	#[Test]
	public function the_button_becomes_a_sign_in_link_for_a_report_a_visitor_cannot_reach(): void {
		$this->seedListing( $this->sampleReports() );

		$html = agend_apps_records_render_export_reports(
			array( 'mode' => 'button', 'report' => 'rep-b', 'button_text' => 'Export' )
		);

		$this->assertStringContainsString( 'agend-export__signin', $html );
		$this->assertStringContainsString( 'Sign in to download', $html );
		$this->assertStringNotContainsString( 'data-agend-export-submit', $html );
	}

	#[Test]
	public function a_reachable_report_still_renders_an_ordinary_download_button(): void {
		$this->seedListing( $this->sampleReports() );

		$html = agend_apps_records_render_export_reports(
			array( 'mode' => 'button', 'report' => 'rep-a', 'button_text' => 'Export' )
		);

		$this->assertStringContainsString( 'data-agend-export-submit', $html );
		$this->assertStringNotContainsString( 'agend-export__signin', $html );
	}

	#[Test]
	public function a_menu_offers_a_sign_in_only_for_the_entries_that_need_one(): void {
		$this->seedListing( $this->sampleReports() );

		$html = agend_apps_records_render_export_reports(
			array(
				'mode'    => 'dropdown',
				'reports' => array(
					array( 'report_id' => 'rep-a', 'report_label' => 'Open report' ),
					array( 'report_id' => 'rep-b', 'report_label' => 'Members report' ),
				),
			)
		);

		$this->assertStringContainsString( 'Sign in to download Members report', $html );
		$this->assertStringContainsString( 'data-report-id="rep-a"', $html );
		$this->assertStringNotContainsString( 'data-report-id="rep-b"', $html );
	}

	// -------------------------------------------------------------------
	// What the client script is given to render a refusal with
	// -------------------------------------------------------------------

	#[Test]
	public function the_config_carries_what_a_refusal_notice_needs(): void {
		$GLOBALS['agend_test_current_user_id'] = 3;

		$html = agend_apps_records_render_export_reports(
			array( 'mode' => 'button', 'report' => 'rep-a', 'button_text' => 'Export' )
		);

		$this->assertMatchesRegularExpression( '/data-agend-export-config="([^"]*)"/', $html );
		preg_match( '/data-agend-export-config="([^"]*)"/', $html, $matches );
		$config = json_decode( html_entity_decode( $matches[1], ENT_QUOTES ), true );

		$this->assertArrayHasKey( 'loginUrl', $config );
		$this->assertArrayHasKey( 'canManage', $config );
		$this->assertArrayHasKey( 'signIn', $config['labels'] );
		$this->assertArrayHasKey( 'featureUnavailable', $config['labels'] );
		$this->assertStringContainsString( 'membership or entitlements', $config['labels']['failed'] );
		$this->assertStringNotContainsString( 'Try again shortly', $config['labels']['failed'] );
	}

	#[Test]
	public function the_admin_only_hint_is_withheld_from_a_visitor_who_could_not_act_on_it(): void {
		$GLOBALS['agend_test_current_user_id']                      = 3;
		$GLOBALS['agend_test_current_user_can']['manage_options'] = false;

		$html = agend_apps_records_render_export_reports(
			array( 'mode' => 'button', 'report' => 'rep-a', 'button_text' => 'Export' )
		);

		preg_match( '/data-agend-export-config="([^"]*)"/', $html, $matches );
		$config = json_decode( html_entity_decode( $matches[1], ENT_QUOTES ), true );

		$this->assertFalse( $config['canManage'] );
	}

	// -------------------------------------------------------------------
	// Forwarding the gateway's own refusal
	// -------------------------------------------------------------------

	#[Test]
	public function a_canonical_refusal_envelope_is_forwarded_verbatim(): void {
		$error = new WP_Error(
			'agend_api_error',
			'generic',
			array(
				'status_code' => 403,
				'body'        => (string) wp_json_encode(
					array(
						'success' => false,
						'error'   => array(
							'code'    => 'entitlement_required',
							'message' => 'You do not have the required entitlement to download this report.',
						),
					)
				),
			)
		);

		$forwarded = agend_apps_directory_export_report_error_envelope( $error );

		$this->assertNotNull( $forwarded );
		$this->assertSame( 403, $forwarded['status'] );
		$this->assertSame( 'entitlement_required', $forwarded['body']['error']['code'] );
		$this->assertStringContainsString( 'required entitlement', $forwarded['body']['error']['message'] );
	}

	#[Test]
	public function an_older_gateway_keeps_the_existing_behaviour(): void {
		foreach (
			array(
				'no body'          => array( 'status_code' => 403 ),
				'not json'         => array( 'status_code' => 403, 'body' => 'Forbidden' ),
				'no error object'  => array( 'status_code' => 403, 'body' => '{"success":false}' ),
				'no code'          => array( 'status_code' => 403, 'body' => '{"error":{"message":"nope"}}' ),
				'no message'       => array( 'status_code' => 403, 'body' => '{"error":{"code":"x"}}' ),
			) as $case => $data
		) {
			$error = new WP_Error( 'agend_api_error', 'generic', $data );

			$this->assertNull(
				agend_apps_directory_export_report_error_envelope( $error ),
				$case . ' should fall back to the generic message'
			);
		}
	}

	#[Test]
	public function a_successful_download_is_never_treated_as_a_refusal(): void {
		$error = new WP_Error( 'agend_api_error', 'generic', array( 'status_code' => 200, 'body' => '{"error":{"code":"x","message":"y"}}' ) );

		$this->assertNull( agend_apps_directory_export_report_error_envelope( $error ) );
	}
}

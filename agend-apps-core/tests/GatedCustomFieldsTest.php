<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/ssr-detail.php';

/**
 * A gated custom field arrives from the gateway without a value and with a
 * `state` naming why; the detail must show that reason, never a blank.
 */
final class GatedCustomFieldsTest extends TestCase {

	#[Test]
	public function should_name_the_gate_reason_in_place_of_a_withheld_value(): void {
		$html = agend_apps_records_ssr_cf_value( array( 'key' => 'mat', 'label' => 'MAT', 'gated' => true, 'state' => 'authentication_required' ) );

		$this->assertStringContainsString( 'agend-dir-cf__value--gated', $html );
		$this->assertStringContainsString( 'Sign in to view', $html );
	}

	#[Test]
	public function should_map_every_gateway_state_to_a_non_disclosing_label(): void {
		$this->assertSame( 'Members only', agend_apps_records_gated_field_label( 'membership_required' ) );
		$this->assertSame( 'Not included in your subscription', agend_apps_records_gated_field_label( 'plan_required' ) );
		$this->assertSame( 'Restricted', agend_apps_records_gated_field_label( 'access_restricted' ) );
		$this->assertSame( 'Restricted', agend_apps_records_gated_field_label( '' ) );
	}

	#[Test]
	public function should_render_a_granted_value_unchanged(): void {
		$html = agend_apps_records_ssr_cf_value( array( 'key' => 'centre_type', 'label' => 'Centre Type', 'type' => 'dropdown', 'value' => 'Open Mall' ) );

		$this->assertStringContainsString( 'Open Mall', $html );
		$this->assertStringNotContainsString( 'gated', $html );
	}

	#[Test]
	public function should_keep_the_details_section_for_a_listing_with_only_gated_fields(): void {
		$html = agend_apps_records_ssr_custom_fields( array( array( 'key' => 'mat', 'label' => 'MAT', 'gated' => true, 'state' => 'plan_required' ) ) );

		$this->assertStringContainsString( 'MAT', $html );
		$this->assertStringContainsString( 'Not included in your subscription', $html );
	}
}

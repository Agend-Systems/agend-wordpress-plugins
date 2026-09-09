<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Records;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/preview-records.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';

/**
 * The name a field is printed under when a widget is asked to show its label,
 * and the custom field keys the editor can offer an author to pick from.
 */
#[CoversFunction( 'agend_apps_records_field_label' )]
#[CoversFunction( 'agend_apps_records_custom_field_key_options' )]
final class FieldLabelTest extends TestCase {

	#[Test]
	public function should_use_the_registrys_name_for_an_ordinary_field(): void {
		$this->assertSame( 'Phone', \agend_apps_records_field_label( 'listing:phone' ) );
		$this->assertSame( 'Opening hours', \agend_apps_records_field_label( 'listing:hours' ) );
		$this->assertSame( 'Title', \agend_apps_records_field_label( 'common:title' ) );
	}

	#[Test]
	public function should_name_nothing_for_a_key_the_registry_does_not_hold(): void {
		$this->assertSame( '', \agend_apps_records_field_label( 'listing:not_a_field' ) );
	}

	#[Test]
	public function should_read_a_custom_fields_label_off_the_record(): void {
		$record = array(
			'custom_fields' => array(
				array( 'key' => 'education_level', 'label' => 'Education level', 'type' => 'text', 'value' => 'Postgraduate' ),
			),
		);

		$this->assertSame(
			'Education level',
			\agend_apps_records_field_label( 'common:custom_field', $record, array( 'custom_field_key' => 'education_level' ) )
		);
	}

	#[Test]
	public function should_fall_back_to_the_generic_name_when_the_record_withholds_the_custom_field(): void {
		// A visitor who is not entitled to the field, or a key that does not
		// exist: the record carries no entry, so there is no label to read.
		$label = \agend_apps_records_field_label( 'common:custom_field', array(), array( 'custom_field_key' => 'education_level' ) );

		$this->assertNotSame( '', $label );
		$this->assertSame( \agend_apps_records_field_registry()['common:custom_field']['label'], $label );
	}

	#[Test]
	public function should_offer_the_custom_field_keys_the_preview_record_carries(): void {
		$options = \agend_apps_records_custom_field_key_options();

		// The empty key is the "not listed" escape the free-text control is
		// conditioned on, so it must stay first and must stay empty-keyed.
		$this->assertSame( '', array_key_first( $options ) );
		$this->assertSame( array( '', 'abn', 'established', 'services' ), array_keys( $options ) );
		$this->assertSame( 'ABN', $options['abn'] );
	}

	#[Test]
	public function should_render_the_opening_hours_field_as_the_detail_table(): void {
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/ssr-detail.php';

		$record = \agend_apps_records_preview_placeholder_record( 'listing' );
		$html   = (string) \agend_apps_records_field_value( 'listing:hours', 'listing', $record );

		$this->assertStringContainsString( 'agend-dir-hours', $html );
		$this->assertStringContainsString( 'Monday', $html );
		// The field prints no section heading: an Agend Field supplies its own
		// label when asked to.
		$this->assertStringNotContainsString( 'agend-dir-detail__section-title', $html );
	}
}

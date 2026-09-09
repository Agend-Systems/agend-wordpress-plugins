<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';

/**
 * agend_apps_records_schema_colour_fields(), the helper every colour-aware
 * surface now calls instead of hand-declaring its own Colours style section.
 * The field names and defaults it emits come solely from
 * AGEND_APPS_RECORDS_COLOUR_SETTING_KEYS and AGEND_APPS_RECORDS_COLOUR_DEFAULTS,
 * which is the whole point of the helper existing: those two constants become
 * the only place a field name or default colour is written, so they cannot
 * drift out of step with agend_apps_records_resolve_colours(), the resolver
 * that reads settings back by the same names.
 */
final class ColourFieldsSchemaTest extends TestCase {

	private const ALL_ROLES = array( 'heading', 'body', 'accent', 'button', 'buttonText' );

	/**
	 * Every colour-aware surface, keyed to the function that builds it, so the
	 * regression guard below can loop instead of repeating five near-identical
	 * assertions. Keep this list current with schema.php's require_once list
	 * whenever a new surface starts calling agend_apps_records_schema_colour_fields().
	 *
	 * @var string[]
	 */
	private const COLOUR_SURFACES = array(
		'directory-catalogue',
		'events-catalogue',
		'courses-catalogue',
		'member-login',
		'memberships-catalogue',
		'record-block',
	);

	#[Test]
	public function should_return_the_section_id_label_and_style_tab(): void {
		$section = agend_apps_records_schema_colour_fields( self::ALL_ROLES );

		self::assertSame( 'section_style_colours', $section['id'] );
		self::assertSame( 'Colours', $section['label'] );
		self::assertSame( 'style', $section['tab'] );
	}

	#[Test]
	public function should_derive_every_field_name_and_default_from_the_shared_constants_for_the_full_role_list(): void {
		$section = agend_apps_records_schema_colour_fields( self::ALL_ROLES );

		// The toggle is field 0; the colour fields follow in role order.
		$fields = array_slice( $section['fields'], 1 );
		self::assertCount( count( self::ALL_ROLES ), $fields );

		foreach ( self::ALL_ROLES as $index => $role ) {
			self::assertSame( \AGEND_APPS_RECORDS_COLOUR_SETTING_KEYS[ $role ], $fields[ $index ]['name'] );
			self::assertSame( \AGEND_APPS_RECORDS_COLOUR_DEFAULTS[ $role ], $fields[ $index ]['default'] );
			self::assertSame( 'colour', $fields[ $index ]['type'] );
		}
	}

	#[Test]
	public function should_emit_fields_in_the_order_the_roles_list_gives_rather_than_a_fixed_order(): void {
		// Deliberately reordered and reduced from the canonical
		// AGEND_APPS_RECORDS_COLOUR_DEFAULTS key order, to prove the helper
		// does not silently re-sort back to it.
		$roles = array( 'button', 'accent', 'heading' );

		$section = agend_apps_records_schema_colour_fields( $roles );

		$names = array_column( array_slice( $section['fields'], 1 ), 'name' );

		self::assertSame(
			array(
				\AGEND_APPS_RECORDS_COLOUR_SETTING_KEYS['button'],
				\AGEND_APPS_RECORDS_COLOUR_SETTING_KEYS['accent'],
				\AGEND_APPS_RECORDS_COLOUR_SETTING_KEYS['heading'],
			),
			$names
		);
	}

	#[Test]
	public function should_emit_only_the_fields_for_a_role_subset(): void {
		$section = agend_apps_records_schema_colour_fields( array( 'heading', 'body' ) );

		$names = array_column( array_slice( $section['fields'], 1 ), 'name' );

		self::assertSame(
			array(
				\AGEND_APPS_RECORDS_COLOUR_SETTING_KEYS['heading'],
				\AGEND_APPS_RECORDS_COLOUR_SETTING_KEYS['body'],
			),
			$names
		);
	}

	#[Test]
	public function should_skip_an_unknown_role_name_rather_than_emit_a_broken_field(): void {
		$section = agend_apps_records_schema_colour_fields( array( 'heading', 'not-a-real-role', 'body' ) );

		$names = array_column( array_slice( $section['fields'], 1 ), 'name' );

		self::assertSame(
			array(
				\AGEND_APPS_RECORDS_COLOUR_SETTING_KEYS['heading'],
				\AGEND_APPS_RECORDS_COLOUR_SETTING_KEYS['body'],
			),
			$names
		);
	}

	#[Test]
	public function should_default_inherit_to_true_and_apply_a_condition_to_every_colour_field(): void {
		$section = agend_apps_records_schema_colour_fields( self::ALL_ROLES );

		$fields  = array_column( $section['fields'], null, 'name' );
		$inherit = $fields['inherit_colours'];

		self::assertSame( 'toggle', $inherit['type'] );
		self::assertTrue( $inherit['default'] );

		foreach ( self::ALL_ROLES as $role ) {
			$field = $fields[ \AGEND_APPS_RECORDS_COLOUR_SETTING_KEYS[ $role ] ];
			self::assertSame( array( 'inherit_colours!' => 'yes' ), $field['condition'] );
		}
	}

	#[Test]
	public function should_omit_the_toggle_and_every_condition_when_inherit_is_null(): void {
		$section = agend_apps_records_schema_colour_fields( self::ALL_ROLES, array( 'inherit' => null ) );

		$names = array_column( $section['fields'], 'name' );
		self::assertNotContains( 'inherit_colours', $names );

		foreach ( $section['fields'] as $field ) {
			self::assertArrayNotHasKey( 'condition', $field );
		}
	}

	#[Test]
	public function should_keep_the_toggle_and_conditions_when_inherit_is_false(): void {
		$section = agend_apps_records_schema_colour_fields( self::ALL_ROLES, array( 'inherit' => false ) );

		$fields  = array_column( $section['fields'], null, 'name' );
		$inherit = $fields['inherit_colours'];

		self::assertFalse( $inherit['default'] );

		foreach ( self::ALL_ROLES as $role ) {
			$field = $fields[ \AGEND_APPS_RECORDS_COLOUR_SETTING_KEYS[ $role ] ];
			self::assertSame( array( 'inherit_colours!' => 'yes' ), $field['condition'] );
		}
	}

	#[Test]
	public function should_override_labels_descriptions_and_defaults_per_role_and_omit_the_description_key_for_a_role_with_none(): void {
		$section = agend_apps_records_schema_colour_fields(
			array( 'heading', 'body' ),
			array(
				'labels'       => array( 'heading' => 'Custom heading label' ),
				'descriptions' => array( 'heading' => 'Custom heading description' ),
				'defaults'     => array( 'heading' => '#123456' ),
			)
		);

		$fields  = array_column( $section['fields'], null, 'name' );
		$heading = $fields[ \AGEND_APPS_RECORDS_COLOUR_SETTING_KEYS['heading'] ];
		$body    = $fields[ \AGEND_APPS_RECORDS_COLOUR_SETTING_KEYS['body'] ];

		self::assertSame( 'Custom heading label', $heading['label'] );
		self::assertSame( 'Custom heading description', $heading['description'] );
		self::assertSame( '#123456', $heading['default'] );

		// 'body' has no entry in descriptions at all, so its field must carry
		// no description key rather than an empty one.
		self::assertArrayNotHasKey( 'description', $body );
		self::assertSame( \AGEND_APPS_RECORDS_COLOUR_DEFAULTS['body'], $body['default'] );
	}

	#[Test]
	public function should_override_the_toggles_description_with_inherit_text(): void {
		$section = agend_apps_records_schema_colour_fields( self::ALL_ROLES, array( 'inherit_text' => 'Custom inherit copy.' ) );

		$fields = array_column( $section['fields'], null, 'name' );

		self::assertSame( 'Custom inherit copy.', $fields['inherit_colours']['description'] );
	}

	/**
	 * The regression guard the duplication this helper replaced could not
	 * offer: before agend_apps_records_schema_colour_fields() existed, each
	 * surface hand-declared its own copy of the Colours section, and a field
	 * renamed in one copy but not in AGEND_APPS_RECORDS_COLOUR_SETTING_KEYS (or
	 * vice versa) failed silently. agend_apps_records_resolve_colours() would
	 * simply never find that surface's manual colour in $settings and fall
	 * back to the default, with no error and no visible symptom beyond an
	 * author's colour choice being ignored. Looping every real surface's
	 * built schema here, rather than only the helper's own return value,
	 * proves each call site actually passes the shared constants through and
	 * not a stray literal.
	 */
	#[Test]
	public function should_name_every_colour_field_after_a_shared_setting_key_on_every_surface_that_has_one(): void {
		$known_names = array_values( \AGEND_APPS_RECORDS_COLOUR_SETTING_KEYS );

		foreach ( self::COLOUR_SURFACES as $surface ) {
			$schema = agend_apps_records_surface_schema( $surface );

			$colours_section = null;
			foreach ( $schema['sections'] as $section ) {
				if ( 'section_style_colours' === $section['id'] ) {
					$colours_section = $section;
					break;
				}
			}

			self::assertNotNull( $colours_section, $surface . ' schema has no section_style_colours section' );

			foreach ( $colours_section['fields'] as $field ) {
				if ( 'colour' !== $field['type'] ) {
					continue;
				}

				self::assertContains(
					$field['name'],
					$known_names,
					$surface . ' colour field "' . $field['name'] . '" is not a value in AGEND_APPS_RECORDS_COLOUR_SETTING_KEYS'
				);
			}
		}
	}
}

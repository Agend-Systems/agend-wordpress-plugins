<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Shop;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The three cart surfaces declare their block-editor schema once
 * (agend_apps_records_schema_<surface>()), while their Elementor widgets keep
 * hand-declared register_controls() rather than building from that schema
 * (see each widget's docblock: agend-apps-shop hard-depends only on
 * agend-apps-core, and Agend_Elementor_Schema_Controls lives in the separate
 * agend-elementor plugin). That is two declarations of the same content
 * controls, kept from drifting apart only by this test: every schema field
 * must have a matching widget control with the same name, the same mapped
 * Elementor control type, and the same default; and every widget content
 * control must appear in the schema, so a control added to one side is never
 * silently missing from the other.
 */
final class SchemaWidgetParityTest extends TestCase {

	/**
	 * The Elementor control type a schema field type must have been declared
	 * with. Only the types the three cart surfaces actually use; add an entry
	 * here rather than skipping the check if a later field introduces one of
	 * the vocabulary's other types.
	 */
	private const EXPECTED_CONTROL_TYPE = array(
		'select'   => 'select',
		'text'     => 'text',
		'textarea' => 'textarea',
		'number'   => 'number',
		'colour'   => 'color',
		'note'     => 'raw_html',
		'url'      => 'url',
	);

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-shop/includes/records/schema.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-shop/includes/widgets/class-agend-apps-shop-add-to-cart.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-shop/includes/widgets/class-agend-apps-shop-cart-header.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-shop/includes/widgets/class-agend-apps-shop-cart-view.php';
	}

	/** @return array<string, array{string, class-string}> Surface id => [surface id, widget class]. */
	public static function surfaces(): array {
		return array(
			'add-to-cart' => array( 'add-to-cart', \Agend_Apps_Shop_Add_To_Cart::class ),
			'cart-header' => array( 'cart-header', \Agend_Apps_Shop_Cart_Header::class ),
			'cart-view'   => array( 'cart-view', \Agend_Apps_Shop_Cart_View::class ),
		);
	}

	#[Test]
	#[DataProvider( 'surfaces' )]
	public function every_schema_field_should_match_a_widget_control_with_the_same_name_type_and_default( string $surface, string $widget_class ): void {
		$schema   = agend_apps_records_surface_schema( $surface );
		$controls = $this->widgetControls( $widget_class );

		foreach ( $schema['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$name = $field['name'];

				self::assertArrayHasKey(
					$name,
					$controls,
					"Drift: the '{$surface}' schema declares field '{$name}', but {$widget_class}::register_controls() has no control with that name. Either the widget dropped a control the schema still lists, or the field name was renamed on only one side."
				);

				if ( 'adapter' === $field['type'] ) {
					// Builder-specific control (an icon picker, a URL field, ...):
					// the schema only pins its name and position, not its
					// Elementor type or default -- see the vocabulary docblock in
					// agend-apps-core/includes/records/schema.php.
					continue;
				}

				$expected_type = self::EXPECTED_CONTROL_TYPE[ $field['type'] ] ?? null;
				self::assertNotNull(
					$expected_type,
					"Schema field '{$name}' ({$surface}) has type '{$field['type']}', which SchemaWidgetParityTest::EXPECTED_CONTROL_TYPE does not map to an Elementor control type. Add it there rather than skipping the check."
				);
				self::assertSame(
					$expected_type,
					$controls[ $name ]['type'],
					"Drift: field '{$name}' ({$surface}) is schema type '{$field['type']}' (expects Elementor type '{$expected_type}'), but the widget control is Elementor type '{$controls[ $name ]['type']}'."
				);

				if ( 'note' === $field['type'] ) {
					// The note's own text is deliberately not compared: nothing
					// is persisted for a note, and the schema's copy was
					// reworded to drop an em dash the widget's original RAW_HTML
					// control still carries (see
					// agend_apps_records_schema_cart_view()'s docblock).
					continue;
				}

				// A control with no explicit 'default' key resolves to '' in
				// Elementor (e.g. add-to-cart's product_id), so an absent key is
				// compared as '' rather than failing outright.
				$widget_default = array_key_exists( 'default', $controls[ $name ] ) ? $controls[ $name ]['default'] : '';
				self::assertSame(
					$field['default'],
					$widget_default,
					"Drift: field '{$name}' ({$surface}) default is " . var_export( $field['default'], true )
					. ' in the schema but ' . var_export( $widget_default, true ) . " on the widget control."
				);
			}
		}
	}

	#[Test]
	#[DataProvider( 'surfaces' )]
	public function every_widget_content_control_should_appear_in_the_schema( string $surface, string $widget_class ): void {
		$schema        = agend_apps_records_surface_schema( $surface );
		$schema_fields = array();
		foreach ( $schema['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$schema_fields[] = $field['name'];
			}
		}

		foreach ( array_keys( $this->widgetControls( $widget_class ) ) as $control_id ) {
			self::assertContains(
				$control_id,
				$schema_fields,
				"Drift: {$widget_class}::register_controls() declares control '{$control_id}' ({$surface}), which has no matching field in the schema. A control was added to the widget without adding it to agend_apps_records_schema_" . str_replace( '-', '_', $surface ) . '().'
			);
		}
	}

	/**
	 * Runs a widget's register_controls() against the recording Widget_Base
	 * double and returns every add_control() call keyed by control id.
	 *
	 * @param class-string $widget_class Widget class name.
	 * @return array<string, array<string, mixed>> Control id => its add_control() args.
	 */
	private function widgetControls( string $widget_class ): array {
		$widget = new $widget_class();
		// register_controls() is protected on \Elementor\Widget_Base, same as
		// render(); bound and called the same way MemberLoginRenderTest calls
		// the widget's render().
		( function () {
			$this->register_controls();
		} )->call( $widget );

		$controls = array();
		foreach ( $widget->recordings as $entry ) {
			if ( 'add_control' === $entry['method'] ) {
				$controls[ $entry['id'] ] = $entry['args'];
			}
		}
		return $controls;
	}
}

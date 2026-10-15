<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Elementor_Template_Field_Keys_Adapter;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/interface-agend-apps-template-field-keys.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/template-field-keys.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema/record-field.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema/record-pills.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema/record-image.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-template-renderer.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/adapters/class-agend-elementor-template-field-keys-adapter.php';

/**
 * {@see Agend_Elementor_Template_Field_Keys_Adapter}: the record fields an
 * Elementor card template's Agend widgets are bound to, wherever they sit in
 * the element tree.
 */
#[CoversClass( Agend_Elementor_Template_Field_Keys_Adapter::class )]
final class TemplateFieldKeysAdapterTest extends TestCase {

	/**
	 * One widget element, in `_elementor_data` shape.
	 */
	private function widget( string $type, array $settings = array() ): array {
		return array(
			'elType'     => 'widget',
			'widgetType' => $type,
			'settings'   => $settings,
		);
	}

	private function container( array ...$children ): array {
		return array(
			'elType'   => 'container',
			'settings' => array(),
			'elements' => $children,
		);
	}

	#[Test]
	public function should_report_every_bound_agend_field_when_widgets_are_nested_in_containers(): void {
		$keys = agend_apps_records_merge_binding_keys(
			...Agend_Elementor_Template_Field_Keys_Adapter::from_elements(
				array(
					$this->container(
						$this->widget( 'agend-record-pills', array( 'field' => 'listing:categories' ) ),
						$this->container( $this->widget( 'agend-record-field', array( 'field' => 'listing:phone' ) ) )
					),
					$this->widget( 'agend-record-field', array( 'field' => 'common:custom_field', 'custom_field_key_choice' => 'payment_options' ) ),
				)
			)
		);

		$this->assertSame( array( 'common:custom_field', 'listing:categories', 'listing:phone' ), $keys['fields'] );
		$this->assertSame( array( 'payment_options' ), $keys['custom_fields'] );
	}

	#[Test]
	public function should_report_the_default_field_when_an_agend_widget_never_changed_it(): void {
		$keys = agend_apps_records_merge_binding_keys(
			...Agend_Elementor_Template_Field_Keys_Adapter::from_elements( array( $this->widget( 'agend-record-pills' ) ) )
		);

		$this->assertSame( array( 'common:category' ), $keys['fields'] );
	}

	#[Test]
	public function should_ignore_widgets_that_are_not_agend_field_elements(): void {
		$keys = agend_apps_records_merge_binding_keys(
			...Agend_Elementor_Template_Field_Keys_Adapter::from_elements(
				array(
					$this->widget( 'heading', array( 'field' => 'listing:phone' ) ),
					$this->widget( 'agend-record-block', array( 'block' => 'listing_about' ) ),
				)
			)
		);

		$this->assertSame( array(), $keys['fields'] );
	}

	#[Test]
	public function should_read_the_bound_fields_when_the_saved_element_data_is_a_json_string(): void {
		$meta = wp_json_encode(
			array( $this->container( $this->widget( 'agend-record-pills', array( 'field' => 'listing:categories' ) ) ) )
		);

		$this->assertSame( array( 'listing:categories' ), Agend_Elementor_Template_Field_Keys_Adapter::from_meta( $meta )['fields'] );
		$this->assertSame( array(), Agend_Elementor_Template_Field_Keys_Adapter::from_meta( '' )['fields'] );
	}

	#[Test]
	public function should_report_the_term_field_when_an_agend_image_chooses_its_picture_by_term(): void {
		$keys = Agend_Elementor_Template_Field_Keys_Adapter::from_meta(
			array( $this->widget( 'agend-record-image', array( 'use_term_images' => 'yes', 'term_field' => 'listing:categories' ) ) )
		);

		$this->assertContains( 'listing:categories', $keys['fields'] );
	}

	#[Test]
	public function should_own_no_keys_when_the_id_is_not_an_elementor_template(): void {
		$this->assertNull( ( new Agend_Elementor_Template_Field_Keys_Adapter() )->field_keys( 0 ) );
	}
}

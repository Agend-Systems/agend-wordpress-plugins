<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Elementor_Preview_Type;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-preview-type.php';

/**
 * {@see Agend_Elementor_Preview_Type}: the record type a card/detail template
 * is being built for, read back out of the template's own widgets while it is
 * open in the editor.
 */
#[CoversClass( Agend_Elementor_Preview_Type::class )]
final class PreviewTypeTest extends TestCase {

	/**
	 * One Agend widget element, in `_elementor_data` shape.
	 *
	 * @param string $widget_type Elementor widget type.
	 * @param array  $settings    Widget settings.
	 * @return array<string, mixed>
	 */
	private function widget( string $widget_type, array $settings ): array {
		return array(
			'id'         => substr( md5( $widget_type . wp_json_encode( $settings ) ), 0, 7 ),
			'elType'     => 'widget',
			'widgetType' => $widget_type,
			'settings'   => $settings,
		);
	}

	/**
	 * A container holding the given elements.
	 *
	 * @param array $elements Child elements.
	 * @return array<string, mixed>
	 */
	private function container( array $elements ): array {
		return array(
			'id'       => 'container',
			'elType'   => 'container',
			'settings' => array(),
			'elements' => $elements,
		);
	}

	#[Test]
	public function should_read_the_type_out_of_a_nested_content_block(): void {
		$elements = array(
			$this->container(
				array(
					$this->widget( 'agend-record-field', array( 'field' => 'common:title' ) ),
					$this->container( array( $this->widget( 'agend-record-block', array( 'block' => 'listing_business_hours' ) ) ) ),
				)
			),
		);

		$this->assertSame( 'listing', Agend_Elementor_Preview_Type::from_elements( $elements ) );
	}

	#[Test]
	public function should_prefer_a_record_type_an_author_set_by_hand_over_one_a_field_key_implies(): void {
		$elements = array(
			$this->widget( 'agend-record-field', array( 'field' => 'listing:name' ) ),
			$this->widget( 'agend-record-field', array( 'field' => 'common:title', 'record_type' => 'course' ) ),
		);

		$this->assertSame( 'course', Agend_Elementor_Preview_Type::from_elements( $elements ) );
	}

	#[Test]
	public function should_ignore_a_record_type_left_on_auto(): void {
		$elements = array(
			$this->widget( 'agend-record-block', array( 'block' => 'listing_about', 'record_type' => 'auto' ) ),
		);

		$this->assertSame( 'listing', Agend_Elementor_Preview_Type::from_elements( $elements ) );
	}

	#[Test]
	public function should_ignore_a_widget_that_is_not_one_of_ours(): void {
		$elements = array(
			$this->widget( 'third-party-thing', array( 'field' => 'listing:name' ) ),
			$this->widget( 'agend-record-field', array( 'field' => 'event:start_date' ) ),
		);

		$this->assertSame( 'event', Agend_Elementor_Preview_Type::from_elements( $elements ) );
	}

	#[Test]
	public function should_name_no_type_for_a_template_that_declares_none(): void {
		$elements = array(
			$this->container( array( $this->widget( 'agend-record-field', array( 'field' => 'common:title' ) ) ) ),
			array( 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array() ),
		);

		$this->assertSame( '', Agend_Elementor_Preview_Type::from_elements( $elements ) );
	}

	#[Test]
	public function should_name_no_type_for_an_empty_or_malformed_tree(): void {
		$this->assertSame( '', Agend_Elementor_Preview_Type::from_elements( array() ) );
		$this->assertSame( '', Agend_Elementor_Preview_Type::from_elements( array( 'not-an-element', 7 ) ) );
	}

	#[Test]
	public function should_read_a_saved_document_from_its_elementor_data(): void {
		$GLOBALS['agend_test_post_meta'][ 4101 ] = array(
			'_elementor_data' => wp_json_encode( array( $this->widget( 'agend-record-block', array( 'block' => 'listing_gallery' ) ) ) ),
		);

		$this->assertSame( 'listing', Agend_Elementor_Preview_Type::for_document( 4101 ) );
	}

	#[Test]
	public function should_name_no_type_for_a_document_with_no_elementor_data(): void {
		$this->assertSame( '', Agend_Elementor_Preview_Type::for_document( 4102 ) );
		$this->assertSame( '', Agend_Elementor_Preview_Type::for_document( 0 ) );
	}
}

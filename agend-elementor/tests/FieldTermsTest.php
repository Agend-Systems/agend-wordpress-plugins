<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-format.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-fields.php';

/**
 * Terms feed the Agend Pills widget: one pill per returned item.
 */
final class FieldTermsTest extends TestCase {

	#[Test]
	public function should_return_one_term_per_tag_when_tags_are_objects(): void {
		$record = array( 'tags' => array( array( 'name' => 'Beginner Friendly' ), array( 'name' => 'Recording Available' ) ) );

		$this->assertSame(
			array( 'Beginner Friendly', 'Recording Available' ),
			agend_elementor_field_terms( 'event:tags', 'event', $record )
		);
	}

	#[Test]
	public function should_return_empty_when_tags_absent_from_the_payload(): void {
		$this->assertSame( array(), agend_elementor_field_terms( 'event:tags', 'event', array( 'name' => 'Gala' ) ) );
	}

	#[Test]
	public function should_wrap_a_single_value_field_as_one_term(): void {
		$record = array( 'category' => array( 'name' => 'Professional Development' ) );

		$this->assertSame( array( 'Professional Development' ), agend_elementor_field_terms( 'event:category', 'event', $record ) );
	}

	#[Test]
	public function should_list_every_category_when_the_detail_payload_carries_an_array(): void {
		$record = array( 'categories' => array( array( 'name' => 'Webinars' ), array( 'name' => 'Professional Development' ) ) );

		$this->assertSame(
			array( 'Webinars', 'Professional Development' ),
			agend_elementor_field_terms( 'event:categories', 'event', $record )
		);
	}

	#[Test]
	public function should_drop_blank_terms_and_trim_whitespace(): void {
		$record = array( 'tags' => array( array( 'name' => '  Spaced  ' ), array( 'name' => '' ), 'Plain' ) );

		$this->assertSame( array( 'Spaced', 'Plain' ), agend_elementor_field_terms( 'event:tags', 'event', $record ) );
	}

	#[Test]
	public function should_return_empty_when_the_field_does_not_apply_to_the_record_type(): void {
		$this->assertSame( array(), agend_elementor_field_terms( 'event:tags', 'course', array( 'tags' => array( 'x' ) ) ) );
	}

	#[Test]
	public function should_offer_only_pill_marked_fields_in_the_pill_picker(): void {
		$groups   = agend_elementor_pill_field_options();
		$registry = agend_elementor_field_registry();
		$keys     = array();
		foreach ( $groups as $group ) {
			$keys = array_merge( $keys, array_keys( $group['options'] ) );
		}

		$this->assertContains( 'event:tags', $keys );
		$this->assertContains( 'course:difficulty', $keys );
		$this->assertNotContains( 'common:description', $keys );
		foreach ( $keys as $key ) {
			$this->assertNotEmpty( $registry[ $key ]['pill'], $key );
		}
	}
}

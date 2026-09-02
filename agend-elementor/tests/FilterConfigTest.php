<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Elementor_Filter_Context;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-filters.php';

/**
 * The filter registry and the config each Agend Filter widget hands to the
 * catalogue script.
 */
final class FilterConfigTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Agend_Elementor_Filter_Context::reset();
	}

	#[Test]
	public function should_write_the_state_key_the_query_builder_reads(): void {
		$config = agend_elementor_filter_config( 'event', 'category', array() );

		$this->assertSame( 'categories', $config['state'] );
		$this->assertSame( 'array', $config['mode'] );
	}

	#[Test]
	public function should_use_a_scalar_state_key_for_single_value_filters(): void {
		$course = agend_elementor_filter_config( 'course', 'difficulty', array() );
		$rating = agend_elementor_filter_config( 'listing', 'rating', array() );

		$this->assertSame( 'difficulty', $course['state'] );
		$this->assertSame( 'scalar', $course['mode'] );
		$this->assertSame( 'rating', $rating['state'] );
	}

	#[Test]
	public function should_resolve_static_values_server_side(): void {
		$config = agend_elementor_filter_config( 'event', 'venue_type', array() );

		$labels = array_column( $config['values'], 'label' );
		$this->assertContains( 'In-Person', $labels );
		$this->assertSame( array( 'physical' ), $config['values'][0]['value'] );
		$this->assertNull( $config['source'] );
	}

	#[Test]
	public function should_name_the_endpoint_for_values_it_cannot_resolve_server_side(): void {
		$config = agend_elementor_filter_config( 'listing', 'category', array() );

		$this->assertSame( '/directory/categories', $config['source']['path'] );
		$this->assertSame( 'id', $config['source']['value'] );
		$this->assertSame( array(), $config['values'] );
	}

	#[Test]
	public function should_send_every_value_of_an_author_defined_choice(): void {
		$config = agend_elementor_filter_config(
			'event',
			'city',
			array(
				'values_mode' => 'choices',
				'choices'     => array(
					array( 'choice_label' => 'All States', 'choice_value' => 'NSW, VIC ,QLD' ),
					array( 'choice_label' => 'All Territories', 'choice_value' => 'ACT,NT' ),
				),
			)
		);

		$this->assertCount( 2, $config['values'] );
		$this->assertSame( 'All States', $config['values'][0]['label'] );
		$this->assertSame( array( 'NSW', 'VIC', 'QLD' ), $config['values'][0]['value'] );
		$this->assertSame( array( 'ACT', 'NT' ), $config['values'][1]['value'] );
		$this->assertNull( $config['source'], 'defined choices never fetch a value list' );
	}

	#[Test]
	public function should_drop_empty_choice_rows(): void {
		$config = agend_elementor_filter_config(
			'event',
			'city',
			array(
				'values_mode' => 'choices',
				'choices'     => array(
					array( 'choice_label' => '', 'choice_value' => '' ),
					array( 'choice_label' => 'Sydney', 'choice_value' => 'Sydney' ),
				),
			)
		);

		$this->assertCount( 1, $config['values'] );
	}

	#[Test]
	public function should_fall_back_when_the_presentation_is_not_supported(): void {
		$search = agend_elementor_filter_config( 'event', 'search', array( 'control' => 'buttons' ) );
		$chosen = agend_elementor_filter_config( 'event', 'category', array( 'control' => 'buttons' ) );

		$this->assertSame( 'search', $search['control'], 'a search box has no other presentation' );
		$this->assertSame( 'buttons', $chosen['control'] );
	}

	#[Test]
	public function should_return_null_for_a_filter_the_type_does_not_offer(): void {
		$this->assertNull( agend_elementor_filter_config( 'course', 'city', array() ) );
		$this->assertNull( agend_elementor_filter_descriptor( 'listing', 'difficulty' ) );
	}

	#[Test]
	public function should_group_picker_options_by_catalogue(): void {
		$groups = agend_elementor_filter_options();
		$labels = array_column( $groups, 'label' );

		$this->assertSame( array( 'Events', 'Courses', 'Directory' ), $labels );
		$this->assertArrayHasKey( 'event:category', $groups[0]['options'] );
		$this->assertArrayHasKey( 'listing:rating', $groups[2]['options'] );
	}

	#[Test]
	public function should_hold_the_record_type_for_the_template_render_in_progress(): void {
		$this->assertSame( '', Agend_Elementor_Filter_Context::type() );

		Agend_Elementor_Filter_Context::set( 'listing' );
		$this->assertSame( 'listing', Agend_Elementor_Filter_Context::type() );

		Agend_Elementor_Filter_Context::reset();
		$this->assertSame( '', Agend_Elementor_Filter_Context::type() );
	}

	#[Test]
	public function should_mark_course_categories_approximate_because_they_cannot_be_enumerated(): void {
		$descriptor = agend_elementor_filter_descriptor( 'course', 'category' );

		$this->assertTrue( $descriptor['approximate'] );
		$this->assertSame( 100, $descriptor['source']['limit'] );
	}
}

<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/class-agend-elementor-query.php';

/**
 * agend_elementor_fragment_query_args(): the REST fragment endpoint forwards
 * exactly what the Agend Apps Core proxy would, and nothing else.
 */
final class FragmentQueryArgsTest extends TestCase {

	#[Test]
	public function should_pass_only_proxy_allow_listed_keys_when_given_extra_params(): void {
		$params = array(
			'page'        => 2,
			'limit'       => 9,
			'search'      => 'gala',
			'template'    => 42,
			'detail_page' => 7,
			'with_css'    => '1',
			'_wpnonce'    => 'abc',
			'apiKey'      => 'leak',
		);

		$this->assertSame( array( 'page' => 2, 'limit' => 9, 'search' => 'gala' ), agend_elementor_fragment_query_args( $params, 'event' ) );
	}

	#[Test]
	public function should_keep_array_params_as_arrays_and_drop_empty_scalars(): void {
		$params = array(
			'excludeCategories' => array( 'a', '', 'b' ),
			'categories'        => array(),
			'category'          => '',
			'type'              => 'virtual',
		);

		$this->assertSame(
			array( 'excludeCategories' => array( 'a', 'b' ), 'type' => 'virtual' ),
			agend_elementor_fragment_query_args( $params, 'event' )
		);
	}

	#[Test]
	public function should_use_course_allow_list_when_type_is_course(): void {
		$params = array( 'deliveryMode' => 'self_paced', 'timeframe' => 'upcoming', 'excludeDifficulties' => array( 'advanced' ) );

		$this->assertSame(
			array( 'deliveryMode' => 'self_paced', 'excludeDifficulties' => array( 'advanced' ) ),
			agend_elementor_fragment_query_args( $params, 'course' )
		);
	}

	#[Test]
	public function should_clamp_limit_and_page_when_out_of_range(): void {
		$out = agend_elementor_fragment_query_args( array( 'limit' => 500, 'page' => 0, 'per_page' => -3 ), 'course' );

		$this->assertSame( 100, $out['limit'] );
		$this->assertSame( 1, $out['page'] );
		$this->assertSame( 1, $out['per_page'] );
	}

	#[Test]
	public function should_unwrap_gateway_list_shape_and_errors(): void {
		$list = agend_elementor_unwrap_list( array( 'data' => array( array( 'slug' => 'a' ), 'junk' ), 'meta' => array( 'pagination' => array( 'page' => 1 ) ) ) );

		$this->assertSame( array( array( 'slug' => 'a' ) ), $list['items'] );
		$this->assertSame( array( 'page' => 1 ), $list['pagination'] );
		$this->assertFalse( $list['error'] );
		$this->assertTrue( agend_elementor_unwrap_list( new \WP_Error( 'x', 'y' ) )['error'] );
	}
}

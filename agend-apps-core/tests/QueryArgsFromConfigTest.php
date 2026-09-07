<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/query.php';

/**
 * The server-rendered first page asks the gateway the same question the
 * catalogue scripts ask for every later page.
 */
final class QueryArgsFromConfigTest extends TestCase {

	#[Test]
	public function should_mirror_events_reload_keys_when_built_from_widget_config(): void {
		$config = array(
			'pagination' => array( 'perPage' => 6 ),
			'timeframe'  => 'past',
			'filters'    => array( 'categoryMatch' => 'all' ),
			'exclusions' => array( 'categories' => array( 'Internal' ), 'venueTypes' => array( 'virtual' ), 'cities' => array(), 'categoryMatch' => 'any' ),
		);

		$this->assertSame(
			array(
				'page'                   => 1,
				'limit'                  => 6,
				'categoriesMatch'        => 'all',
				'timeframe'              => 'past',
				'excludeCategories'      => array( 'Internal' ),
				'excludeVenueTypes'      => array( 'virtual' ),
				'excludeCategoriesMatch' => 'any',
			),
			agend_apps_records_events_list_args( $config, 1 )
		);
	}

	#[Test]
	public function should_include_visitor_filter_state_when_given(): void {
		$out = agend_apps_records_events_list_args( array( 'pagination' => array( 'perPage' => 9 ) ), 3, array( 'search' => 'gala', 'cities' => array( 'Sydney' ) ) );

		$this->assertSame( 3, $out['page'] );
		$this->assertSame( 'gala', $out['search'] );
		$this->assertSame( array( 'Sydney' ), $out['cities'] );
	}

	#[Test]
	public function should_mirror_courses_reload_keys_when_built_from_widget_config(): void {
		$config = array(
			'pagination' => array( 'perPage' => 12 ),
			'exclusions' => array( 'categories' => array(), 'difficulties' => array( 'advanced' ), 'deliveryModes' => array( 'in_person' ) ),
		);

		$this->assertSame(
			array(
				'page'                 => 1,
				'limit'                => 12,
				'excludeDifficulties'  => array( 'advanced' ),
				'excludeDeliveryModes' => array( 'in_person' ),
			),
			agend_apps_records_courses_list_args( $config, 1 )
		);
	}

	#[Test]
	public function should_default_page_size_when_config_is_missing_pagination(): void {
		$this->assertSame( 9, agend_apps_records_events_list_args( array(), 1 )['limit'] );
	}
}

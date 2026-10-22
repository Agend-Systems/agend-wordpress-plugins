<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/query.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/search-link.php';

/**
 * The server's search link parse rules, held to the case table the browser's
 * are held to by tests/js/search-link.test.mjs.
 */
final class SearchLinkParseTest extends TestCase {

	protected function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	private static function fixture(): array {
		return json_decode( (string) file_get_contents( __DIR__ . '/fixtures/directory-search-link-cases.json' ), true );
	}

	/**
	 * Every decode case of tests/fixtures/directory-search-link-cases.json.
	 *
	 * @return array<string, array{array, bool, string, array}>
	 */
	public static function cases(): array {
		$fixture = self::fixture();
		$cases   = array();
		foreach ( $fixture['cases'] as $case ) {
			$cases[ 'should ' . $case['outcome'] . ' when ' . $case['when'] ] = array( $fixture['controls'][ $case['controls'] ], $case['bbox'], $case['query'], $case['expect'] );
		}
		return $cases;
	}

	/**
	 * The request values WordPress would hand the parser for a query string:
	 * PHP's own decoding of it, then the slashes wp_magic_quotes() adds. The
	 * suite's wp_slash() stub adds none, so they are added here.
	 */
	private static function request( string $query ): array {
		parse_str( $query, $params );
		return self::slashed( $params );
	}

	/**
	 * @param mixed $value A request value.
	 * @return mixed
	 */
	private static function slashed( $value ) {
		return is_array( $value ) ? array_map( array( self::class, 'slashed' ), $value ) : addslashes( (string) $value );
	}

	/**
	 * JSON has one number type: a whole float compares equal to the integer
	 * the fixture holds, and key order is not part of the shape.
	 *
	 * @param mixed $value Decoded state or a part of it.
	 * @return mixed
	 */
	private static function comparable( $value ) {
		if ( is_float( $value ) && floor( $value ) === $value ) {
			return (int) $value;
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$value = array_map( array( self::class, 'comparable' ), $value );
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}
		return $value;
	}

	/**
	 * @param array<int, array> $controls Filter configs on the page.
	 * @param bool              $bbox     Whether a map search area is read.
	 * @param string            $query    The query string.
	 * @param array             $expect   The state and page the browser reads.
	 */
	#[DataProvider( 'cases' )]
	#[Test]
	public function should_read_a_search_link_as_the_browser_does( array $controls, bool $bbox, string $query, array $expect ): void {
		$decoded = agend_apps_records_search_link_decode( self::request( $query ), agend_apps_records_search_link_readable( $controls ), $bbox );

		$this->assertSame( self::comparable( $expect ), self::comparable( $decoded ) );
	}

	#[Test]
	public function should_sort_custom_fields_by_key_when_their_parameters_arrive_in_any_order(): void {
		$readable = agend_apps_records_search_link_readable( self::fixture()['controls']['full'] );
		$decoded  = agend_apps_records_search_link_decode( self::request( 'rf_price=1:2&cf_funding=ndis&cf_education_level=masters' ), $readable );

		$this->assertSame( array( 'education_level', 'funding', 'price' ), array_keys( $decoded['state']['custom_fields'] ) );
	}

	#[Test]
	public function should_build_one_query_when_two_links_differ_only_in_parameter_order(): void {
		$readable = agend_apps_records_search_link_readable( self::fixture()['controls']['full'] );
		$config   = array( 'pagination' => array( 'perPage' => 12 ), 'exclusions' => array() );
		$queries  = array_map(
			static function ( string $query ) use ( $readable, $config ): string {
				$decoded = agend_apps_records_search_link_decode( self::request( $query ), $readable );
				return serialize( agend_apps_records_listings_list_args( $config, $decoded['page'], agend_apps_records_search_link_list_state( $decoded['state'] ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			},
			array(
				'cf_funding=ndis&q=physio&rf_fees=10:20&cf_education_level=masters&rating=4',
				'rating=4&cf_education_level=masters&rf_fees=10:20&q=physio&cf_funding=ndis',
			)
		);

		$this->assertSame( $queries[0], $queries[1] );
	}

	#[Test]
	public function should_send_a_location_name_with_an_apostrophe_and_a_backslash_exactly_as_typed_when_the_server_reads_the_link(): void {
		$readable = agend_apps_records_search_link_readable( self::fixture()['controls']['full'] );
		$decoded  = agend_apps_records_search_link_decode( self::request( 'location_name=St+Vincent%27s+%5C+Co' ), $readable );
		$args     = agend_apps_records_listings_list_args( array(), 1, agend_apps_records_search_link_list_state( $decoded['state'] ) );

		$this->assertSame( "St Vincent's \\ Co", $args['locationName'] );
	}

	#[Test]
	public function should_send_the_restored_search_when_the_server_builds_its_query(): void {
		$readable = agend_apps_records_search_link_readable( self::fixture()['controls']['full'] );
		$decoded  = agend_apps_records_search_link_decode( self::request( 'q=physio&near=-37.8,144.96&radius=20&address=Carlton&sort=name&pg=3' ), $readable );
		$args     = agend_apps_records_listings_list_args( array( 'pagination' => array( 'perPage' => 12 ) ), $decoded['page'], agend_apps_records_search_link_list_state( $decoded['state'] ) );

		$this->assertSame( 3, $args['page'] );
		$this->assertSame( 'physio', $args['search'] );
		$this->assertSame( 'name', $args['sortBy'] );
		$this->assertSame( array( '-37.8', '144.96', '20' ), array( $args['lat'], $args['lng'], $args['radius'] ) );
	}

	#[Test]
	public function should_read_the_request_when_it_carries_a_search(): void {
		$_GET = self::request( 'rating=4&utm_source=mail' );

		$this->assertSame( array( 'state' => array( 'rating' => '4' ), 'page' => 1 ), agend_apps_records_search_link_from_request( self::fixture()['controls']['full'] ) );
	}

	#[Test]
	public function should_read_nothing_when_the_request_sets_only_the_view(): void {
		$_GET = self::request( 'view=map' );

		$this->assertNull( agend_apps_records_search_link_from_request( self::fixture()['controls']['full'] ) );
	}

	#[Test]
	public function should_read_nothing_when_the_catalogue_rendered_no_filters(): void {
		$_GET = self::request( 'q=physio' );

		$this->assertNull( agend_apps_records_search_link_from_request( array() ) );
	}

	#[Test]
	public function should_ignore_a_value_that_is_not_utf8_when_the_request_carries_one(): void {
		$readable = agend_apps_records_search_link_readable( self::fixture()['controls']['full'] );

		$this->assertSame( array(), agend_apps_records_search_link_decode( array( 'q' => "\xff\xfe" ), $readable )['state'] );
	}
}

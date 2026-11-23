<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_CRM_REST_Controller;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use WP_REST_Request;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/crm-routes.php';

/**
 * The `/agend-apps/v1/crm/tiers` proxy is the anonymous website catalogue, so
 * it must only ever ask the gateway for publicly listed tiers.
 */
final class CrmTiersRouteQueryTest extends TestCase {

	/**
	 * @param array<string, mixed> $params Request parameters.
	 * @return array<string, mixed> The query the tier catalogue received.
	 */
	private function catalogue_query_for( array $params ): array {
		Agend_Test_WP::$tiers_response = array( 'success' => true, 'data' => array() );

		$request = new WP_REST_Request( 'GET', '/agend-apps/v1/crm/tiers' );
		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $value );
		}

		( new Agend_Apps_CRM_REST_Controller() )->get_tiers( $request );

		$this->assertCount( 1, Agend_Test_WP::$tiers_queries );

		return Agend_Test_WP::$tiers_queries[0];
	}

	#[Test]
	public function should_request_only_publicly_listed_tiers_when_no_filter_is_sent(): void {
		$query = $this->catalogue_query_for( array() );

		$this->assertSame( 'true', $query['isPubliclyListed'] );
	}

	#[Test]
	public function should_keep_the_listed_only_filter_when_the_visitor_asks_for_hidden_tiers(): void {
		$query = $this->catalogue_query_for(
			array(
				'isPubliclyListed' => 'false',
				'includeInactive'  => 'true',
			)
		);

		$this->assertSame( 'true', $query['isPubliclyListed'] );
		$this->assertArrayNotHasKey( 'includeInactive', $query );
	}

	#[Test]
	public function should_forward_the_tier_type_filter_when_one_is_sent(): void {
		$query = $this->catalogue_query_for( array( 'tierType' => 'corporate' ) );

		$this->assertSame( 'corporate', $query['tierType'] );
		$this->assertSame( 'true', $query['isPubliclyListed'] );
	}
}

<?php
/** @package Agend\Tests */
declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_CRM_REST_Controller;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use WP_REST_Request;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/crm-routes.php';

#[RunTestsInSeparateProcesses]
final class CrmContactRoutesTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		// Other CRM helpers are already doubled by the bootstrap. As in
		// CrmEntitlementTypesWrapperTest, load only the real wrapper needed here.
		$source = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/crm.php' );
		if ( 1 !== preg_match( '/^function agend_apps_crm_create_contact\(.*?^}$/ms', $source, $match ) ) {
			throw new \RuntimeException( 'Contact helper not found.' );
		}
		// phpcs:ignore Squiz.PHP.Eval.Discouraged
		eval( $match[0] );
	}

	private function createContact( string $json ): array {
		$request = new class( 'POST', '/agend-apps/v1/crm/contacts' ) extends WP_REST_Request {
			public function get_json_params(): ?array {
				return json_decode( $this->get_body(), true );
			}
		};
		$request->set_body( $json );
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'id' => 'contact-1' ) ) );
		$response = ( new Agend_Apps_CRM_REST_Controller() )->create_contact( $request );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'contact-1', $response->get_data()['data']['id'] );
		self::assertSame( 'POST', Agend_Test_WP::$requests[0]['method'] );
		self::assertStringEndsWith( '/crm/contacts', Agend_Test_WP::$requests[0]['url'] );
		return json_decode( Agend_Test_WP::$requests[0]['body'], true );
	}

	#[Test]
	public function should_drop_empty_custom_fields_when_creating_a_contact(): void {
		$body = $this->createContact( '{"first_name":"Alex","last_name":"Example","email":"alex@example.test","custom_fields":{}}' );
		self::assertSame( array( 'first_name' => 'Alex', 'last_name' => 'Example', 'email' => 'alex@example.test' ), $body );
	}

	#[Test]
	public function should_keep_non_empty_custom_fields_when_creating_a_contact(): void {
		$body = $this->createContact( '{"first_name":"Alex","last_name":"Example","email":"alex@example.test","custom_fields":{"requested_tier":"professional","interests":["events"],"phone":{"number":"0400000000"}}}' );
		self::assertSame( array( 'requested_tier' => 'professional', 'interests' => array( 'events' ), 'phone' => array( 'number' => '0400000000' ) ), $body['custom_fields'] );
		self::assertSame( 'Alex', $body['first_name'] );
	}
}

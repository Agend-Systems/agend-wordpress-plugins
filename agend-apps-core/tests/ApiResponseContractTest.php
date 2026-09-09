<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend\Tests\TestCase;
use Agend_Apps_API;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';

/**
 * `Agend_Apps_API::request()` returns an array or a `WP_Error`, never anything
 * else. Callers rely on that pair, and several pass the result straight to a
 * helper, so a third possibility surfaces as an uncaught TypeError rather than
 * a failed request: a 2xx with an empty body used to return bare null, which
 * fataled the wp-login.php `authenticate` filter where no route handler exists
 * to turn a thrown error into a response.
 */
#[CoversClass( Agend_Apps_API::class )]
final class ApiResponseContractTest extends TestCase {

	private function api(): Agend_Apps_API {
		return new Agend_Apps_API();
	}

	#[Test]
	public function should_return_an_error_when_a_2xx_response_has_an_empty_body(): void {
		Agend_Test_WP::queue_response( 200, '' );

		$result = $this->api()->request( 'POST', '/auth/login' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'agend_apps_invalid_response', $result->get_error_code() );
		$this->assertSame( 200, $result->get_error_data()['status_code'] );
		$this->assertSame( '/auth/login', $result->get_error_data()['path'] );
	}

	#[Test]
	public function should_return_an_error_when_a_2xx_body_decodes_to_a_scalar(): void {
		Agend_Test_WP::queue_response( 201, 'true' );

		$result = $this->api()->request( 'POST', '/auth/register' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'agend_apps_invalid_response', $result->get_error_code() );
		$this->assertSame( 201, $result->get_error_data()['status_code'] );
	}

	#[Test]
	public function should_return_an_error_when_a_response_filter_drops_the_array(): void {
		Agend_Test_WP::queue_response( 200, array( 'data' => array( 'ok' => true ) ) );
		Agend_Test_WP::set_filter( 'agend_apps_api_response', null );

		$result = $this->api()->request( 'GET', '/health' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'agend_apps_invalid_response', $result->get_error_code() );
	}

	#[Test]
	public function should_return_an_empty_array_when_the_response_is_204_no_content(): void {
		Agend_Test_WP::queue_response( 204, '' );

		$this->assertSame( array(), $this->api()->request( 'POST', '/auth/logout' ) );
	}

	#[Test]
	public function should_stamp_the_status_code_when_the_body_decodes_to_an_array(): void {
		Agend_Test_WP::queue_response( 202, array( 'data' => array( 'status' => 'verification_required' ) ) );

		$this->assertSame(
			array(
				'data'        => array( 'status' => 'verification_required' ),
				'status_code' => 202,
			),
			$this->api()->request( 'POST', '/auth/login' )
		);
	}

	#[Test]
	public function should_keep_reporting_a_non_2xx_response_as_a_gateway_error(): void {
		Agend_Test_WP::queue_response(
			401,
			array( 'error' => array( 'code' => 'INVALID_CREDENTIALS', 'message' => 'Invalid credentials.' ) )
		);

		$result = $this->api()->request( 'POST', '/auth/login' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'agend_api_error', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status_code'] );
	}

	#[Test]
	public function should_keep_reporting_a_non_2xx_response_with_an_empty_body_as_a_gateway_error(): void {
		Agend_Test_WP::queue_response( 500, '' );

		$result = $this->api()->request( 'POST', '/auth/login' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'agend_api_error', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status_code'] );
	}
}

<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Apps_Log_Admin;
use Agend_Apps_Logger;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-redactor.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-logger.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/admin/class-agend-apps-log-admin.php';

#[CoversClass( Agend_Apps_Log_Admin::class )]
final class ApiLogAdminFilterTest extends TestCase {

	#[Test]
	public function should_replace_an_email_with_its_pseudonym_when_building_the_filter_url(): void {
		$url = Agend_Apps_Log_Admin::filter_redirect_url(
			array(
				'user'         => 'Jane@Example.com',
				'status_class' => '5xx',
				'path'         => '/v1/crm',
			)
		);

		$this->assertStringNotContainsString( 'jane', strtolower( rawurldecode( $url ) ) );
		$this->assertStringContainsString( 'user_hash=' . Agend_Apps_Logger::user_hash( 'jane@example.com' ), $url );
		$this->assertStringContainsString( 'status_class=5xx', $url );
	}

	#[Test]
	public function should_keep_a_numeric_user_id_when_building_the_filter_url(): void {
		$url = Agend_Apps_Log_Admin::filter_redirect_url( array( 'user' => '42' ) );

		$this->assertStringContainsString( 'user=42', $url );
		$this->assertStringNotContainsString( 'user_hash', $url );
	}
}

<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Apps_Templates;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/interface-agend-apps-template-renderer.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/interface-agend-apps-template-source.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/class-agend-apps-templates.php';
require_once __DIR__ . '/TemplateRegistryTest.php';

/**
 * `Agend_Apps_Templates::page_contains_surface()`: the registry passthrough
 * that lets the widget-presence advisory ask every registered source without
 * depending on a concrete page-builder plugin.
 */
#[CoversClass( Agend_Apps_Templates::class )]
final class TemplatePageSurfaceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Templates::reset();
	}

	protected function tearDown(): void {
		Agend_Apps_Templates::reset();
		parent::tearDown();
	}

	#[Test]
	public function should_return_null_when_no_source_is_registered(): void {
		$this->assertNull( Agend_Apps_Templates::page_contains_surface( 1, 'events-catalogue' ) );
	}

	#[Test]
	public function should_return_null_when_the_only_source_cannot_tell(): void {
		Agend_Apps_Templates::register_source( new Template_Registry_Test_Source( 'Stub', array(), null ) );

		$this->assertNull( Agend_Apps_Templates::page_contains_surface( 1, 'events-catalogue' ) );
	}

	#[Test]
	public function should_return_true_when_one_source_says_true_and_another_cannot_tell(): void {
		Agend_Apps_Templates::register_source( new Template_Registry_Test_Source( 'A', array(), true ) );
		Agend_Apps_Templates::register_source( new Template_Registry_Test_Source( 'B', array(), null ) );

		$this->assertTrue( Agend_Apps_Templates::page_contains_surface( 1, 'events-catalogue' ) );
	}

	#[Test]
	public function should_return_false_when_one_source_says_false_and_another_cannot_tell(): void {
		Agend_Apps_Templates::register_source( new Template_Registry_Test_Source( 'A', array(), false ) );
		Agend_Apps_Templates::register_source( new Template_Registry_Test_Source( 'B', array(), null ) );

		$this->assertFalse( Agend_Apps_Templates::page_contains_surface( 1, 'events-catalogue' ) );
	}

	#[Test]
	public function should_return_true_when_one_source_says_true_and_another_says_false(): void {
		Agend_Apps_Templates::register_source( new Template_Registry_Test_Source( 'A', array(), true ) );
		Agend_Apps_Templates::register_source( new Template_Registry_Test_Source( 'B', array(), false ) );

		$this->assertTrue( Agend_Apps_Templates::page_contains_surface( 1, 'events-catalogue' ) );
	}
}

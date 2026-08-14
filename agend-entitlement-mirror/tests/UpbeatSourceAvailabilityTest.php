<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\EntitlementMirror;

use Agend_Entitlement_Mirror_Upbeat_Source;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/interface-source.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-upbeat-source.php';

/**
 * Agend_Entitlement_Mirror_Upbeat_Source reports itself unavailable, and
 * every fetch method fails loudly (or degrades to blanks for the profile
 * courtesy lookup) rather than fataling, when Iugo_Membership_Kiosk_API is
 * not defined -- exactly the standing condition in this unit suite, which
 * never loads the kiosk plugin.
 */
#[CoversClass( Agend_Entitlement_Mirror_Upbeat_Source::class )]
final class UpbeatSourceAvailabilityTest extends TestCase {

	private Agend_Entitlement_Mirror_Upbeat_Source $source;

	protected function setUp(): void {
		parent::setUp();
		$this->source = new Agend_Entitlement_Mirror_Upbeat_Source();
	}

	#[Test]
	public function it_reports_unavailable_when_the_kiosk_api_class_does_not_exist(): void {
		$this->assertFalse( $this->source->is_available() );
		$this->assertNotSame( '', $this->source->get_unavailable_reason() );
	}

	#[Test]
	public function fetching_member_entitlements_throws_when_unavailable(): void {
		$this->expectException( \RuntimeException::class );

		$this->source->fetch_member_entitlements( 'MEM-1' );
	}

	#[Test]
	public function fetching_entitlement_types_throws_when_unavailable(): void {
		$this->expectException( \RuntimeException::class );

		$this->source->fetch_entitlement_types();
	}

	#[Test]
	public function enumerating_members_throws_when_unavailable(): void {
		$this->expectException( \RuntimeException::class );

		// enumerate_members() is a generator: the body (and its exception)
		// only runs once the iterable is actually iterated.
		foreach ( $this->source->enumerate_members( 0 ) as $member ) {
			unset( $member );
		}
	}

	#[Test]
	public function fetching_a_member_profile_degrades_to_blanks_when_unavailable(): void {
		$profile = $this->source->fetch_member_profile( 'MEM-1' );

		$this->assertSame( array( 'email' => '', 'first_name' => '', 'last_name' => '' ), $profile );
	}

	#[Test]
	public function the_source_key_and_label_are_stable(): void {
		$this->assertSame( 'upbeat', $this->source->get_key() );
		$this->assertNotSame( '', $this->source->get_label() );
	}
}

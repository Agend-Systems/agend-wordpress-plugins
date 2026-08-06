<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\EntitlementMirror;

use Agend_Entitlement_Collector;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-entitlement-collector.php';

/**
 * `Agend_Entitlement_Collector::gate_key()` converts an Upbeat
 * category/type pair into the platform's `crm_benefits.gate_key` shape.
 *
 * SPEC-CRM-20260805-member-entitlement-grants v1.2 US-5.1 AC1 (the
 * conversion itself, deterministic and regex-valid) and AC9 (must never
 * emit a key from the code-owned platform `MEMBER_GATES` registry, mirrored
 * here as `RESERVED_PLATFORM_GATE_KEYS`).
 */
#[CoversClass( Agend_Entitlement_Collector::class )]
final class GateKeyConversionTest extends TestCase {

	private const GATE_KEY_PATTERN = '/^[a-z][a-z0-9_.]{1,62}[a-z0-9]$/';

	/**
	 * Category/type pairs that assemble to exactly one reserved platform key.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function reservedKeyPairs(): array {
		return array(
			'api.access'          => array( 'Api', 'Access', 'api.access' ),
			'community.forum'     => array( 'Community', 'Forum', 'community.forum' ),
			'content.sponsored'   => array( 'Content', 'Sponsored', 'content.sponsored' ),
			'directory.access'    => array( 'Directory', 'Access', 'directory.access' ),
			'events.discount'     => array( 'Events', 'Discount', 'events.discount' ),
			'jobs.board'          => array( 'Jobs', 'Board', 'jobs.board' ),
			'lms.certifications'  => array( 'LMS', 'Certifications', 'lms.certifications' ),
			'lms.courses'         => array( 'LMS', 'Courses', 'lms.courses' ),
			'mentorship.program'  => array( 'Mentorship', 'Program', 'mentorship.program' ),
			'resources.library'   => array( 'Resources', 'Library', 'resources.library' ),
		);
	}

	#[Test]
	public function it_converts_category_and_type_into_a_dotted_underscore_key(): void {
		// The spec's illustrative example ("...premium_directory") drops the
		// type's trailing word, but no word-stripping rule exists anywhere in
		// SPEC-CRM-20260805 — the conversion slugifies each segment as a whole
		// and joins with a dot, so "Premium Directory Access" keeps "access".
		$key = Agend_Entitlement_Collector::gate_key( 'Web Personalisation', 'Premium Directory Access' );

		$this->assertSame( 'web_personalisation.premium_directory_access', $key );
	}

	#[Test]
	public function the_same_input_always_produces_the_same_output(): void {
		$first  = Agend_Entitlement_Collector::gate_key( 'Web Personalisation', 'Premium Directory Access' );
		$second = Agend_Entitlement_Collector::gate_key( 'Web Personalisation', 'Premium Directory Access' );

		$this->assertSame( $first, $second );
	}

	#[Test]
	public function accented_input_transliterates_rather_than_dropping(): void {
		$key = Agend_Entitlement_Collector::gate_key( 'Café Membership', 'Découverte Access' );

		$this->assertSame( 'cafe_membership.decouverte_access', $key );
		$this->assertMatchesRegularExpression( self::GATE_KEY_PATTERN, $key );
	}

	#[Test]
	public function a_digit_led_category_gets_a_letter_prefix_and_stays_valid(): void {
		$key = Agend_Entitlement_Collector::gate_key( '2026 Life Member', 'Access' );

		$this->assertSame( 'k2026_life_member.access', $key );
		$this->assertMatchesRegularExpression( self::GATE_KEY_PATTERN, $key );
	}

	#[Test]
	public function overlong_input_truncates_to_at_most_64_chars_and_stays_valid(): void {
		$category = str_repeat( 'category word ', 10 );
		$type     = str_repeat( 'type word ', 10 );

		$key = Agend_Entitlement_Collector::gate_key( $category, $type );

		$this->assertLessThanOrEqual( 64, strlen( $key ) );
		$this->assertMatchesRegularExpression( self::GATE_KEY_PATTERN, $key );
	}

	#[Test]
	public function an_empty_category_produces_no_key(): void {
		$this->assertSame( '', Agend_Entitlement_Collector::gate_key( '', 'Access' ) );
	}

	#[Test]
	public function an_empty_type_produces_no_key(): void {
		$this->assertSame( '', Agend_Entitlement_Collector::gate_key( 'Directory', '' ) );
	}

	#[Test]
	public function a_whitespace_only_category_produces_no_key(): void {
		$this->assertSame( '', Agend_Entitlement_Collector::gate_key( '   ', 'Access' ) );
	}

	#[Test]
	public function a_symbol_only_type_produces_no_key(): void {
		$this->assertSame( '', Agend_Entitlement_Collector::gate_key( 'Directory', '***' ) );
	}

	/**
	 * @param string $category         Category that assembles to the reserved key.
	 * @param string $type             Type that assembles to the reserved key.
	 * @param string $expected_reserved The reserved key the pair would otherwise produce.
	 */
	#[Test]
	#[\PHPUnit\Framework\Attributes\DataProvider( 'reservedKeyPairs' )]
	public function every_reserved_platform_gate_key_is_withheld( string $category, string $type, string $expected_reserved ): void {
		$this->assertContains(
			$expected_reserved,
			Agend_Entitlement_Collector::RESERVED_PLATFORM_GATE_KEYS,
			'test fixture drifted from the reserved list'
		);

		$this->assertSame( '', Agend_Entitlement_Collector::gate_key( $category, $type ) );
	}

	#[Test]
	public function every_non_empty_output_matches_the_platform_gate_key_pattern(): void {
		$pairs = array(
			array( 'Web Personalisation', 'Premium Directory Access' ),
			array( 'Membership', 'Gold Tier' ),
			array( 'CPD', 'Annual Points Waiver' ),
			array( '2026 Cohort', 'Founding Member' ),
			array( 'Café Membership', 'Découverte Access' ),
		);

		foreach ( $pairs as [ $category, $type ] ) {
			$key = Agend_Entitlement_Collector::gate_key( $category, $type );

			$this->assertNotSame( '', $key );
			$this->assertMatchesRegularExpression( self::GATE_KEY_PATTERN, $key );
		}
	}
}

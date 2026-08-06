<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Compat;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-compat.php';

/**
 * Elementor compatibility range and the suppression-chain probe (US-4.4).
 *
 * The chain probe is the one that matters. Fragment suppression works because
 * every renderable Elementor element, atomic V4 ones included, inherits
 * `Element_Base::print_element()`, which is where the `should_render` filter
 * fires. If a future release gives atomic elements their own render path, the
 * failure is silent: restricted V4 sections render to everyone, with no error.
 *
 * Both halves are injected rather than reading the real class table, so the
 * boundaries are asserted here without Elementor installed. The live assertion
 * against the actual installed version is the runtime probe.
 */
#[CoversClass( Agend_Content_Access_Compat::class )]
final class CompatTest extends TestCase {

	private const BASE   = 'Elementor\\Element_Base';
	private const ATOMIC = 'Elementor\\Modules\\AtomicWidgets\\Elements\\Atomic_Element_Base';
	private const WIDGET = 'Elementor\\Modules\\AtomicWidgets\\Elements\\Atomic_Widget_Base';

	/**
	 * Builds the two probes from a simple class map.
	 *
	 * @param array<string, string|null> $classes Class name to parent, or null for no parent.
	 * @return string[]
	 */
	private function probe( array $classes ): array {
		$exists = static function ( string $class ) use ( $classes ): bool {
			return array_key_exists( $class, $classes );
		};

		$is_subclass = static function ( string $class, string $parent ) use ( $classes ): bool {
			$seen = 0;

			while ( isset( $classes[ $class ] ) && $seen < 10 ) {
				$class = (string) $classes[ $class ];

				if ( $class === $parent ) {
					return true;
				}

				++$seen;
			}

			return false;
		};

		return Agend_Content_Access_Compat::broken_atomic_chains_for( $exists, $is_subclass );
	}

	#[Test]
	public function an_intact_chain_reports_nothing(): void {
		$broken = $this->probe(
			array(
				self::BASE   => null,
				self::ATOMIC => self::BASE,
				self::WIDGET => self::BASE,
			)
		);

		$this->assertSame( array(), $broken );
	}

	/**
	 * The case the probe exists for. An atomic class that EXISTS but no longer
	 * inherits the base is precisely the state in which suppression stops
	 * applying without anything erroring.
	 */
	#[Test]
	public function an_atomic_class_that_no_longer_inherits_the_base_is_reported(): void {
		$broken = $this->probe(
			array(
				self::BASE   => null,
				self::ATOMIC => 'Elementor\\Something_Else',
				self::WIDGET => self::BASE,
			)
		);

		$this->assertSame( array( self::ATOMIC ), $broken );
	}

	#[Test]
	public function both_atomic_classes_can_break_together(): void {
		$broken = $this->probe(
			array(
				self::BASE   => null,
				self::ATOMIC => 'Elementor\\Something_Else',
				self::WIDGET => 'Elementor\\Something_Else',
			)
		);

		$this->assertSame( array( self::ATOMIC, self::WIDGET ), $broken );
	}

	/**
	 * Editor V4 is opt-in, so most sites have no atomic classes at all. That is
	 * normal, not a fault, and must not raise the alarm: crying wolf on every
	 * ordinary site would train an administrator to ignore the one notice that
	 * means their content is exposed.
	 */
	#[Test]
	public function absent_atomic_classes_are_not_a_failure(): void {
		$broken = $this->probe( array( self::BASE => null ) );

		$this->assertSame( array(), $broken );
	}

	/**
	 * An indirect chain still routes through the base, so it is intact.
	 */
	#[Test]
	public function an_indirect_chain_through_widget_base_is_intact(): void {
		$broken = $this->probe(
			array(
				self::BASE     => null,
				'Elementor\\Widget_Base' => self::BASE,
				self::WIDGET   => 'Elementor\\Widget_Base',
			)
		);

		$this->assertSame( array(), $broken );
	}

	#[Test]
	public function a_missing_base_class_short_circuits(): void {
		// No Elementor at all. Nothing to report; the version notice covers it.
		$this->assertSame( array(), $this->probe( array() ) );
	}

	#[Test]
	public function the_version_range_boundaries_are_inclusive(): void {
		$state = static fn( string $v ): string =>
			Agend_Content_Access_Compat::version_state_for( $v, '3.0.0', '4.2.0' );

		$this->assertSame( 'absent', $state( '' ) );
		$this->assertSame( 'too_old', $state( '2.9.9' ) );
		$this->assertSame( 'ok', $state( '3.0.0' ) );
		$this->assertSame( 'ok', $state( '3.32.3' ) );
		$this->assertSame( 'ok', $state( '4.2.0' ) );
		$this->assertSame( 'untested', $state( '4.2.1' ) );
		$this->assertSame( 'untested', $state( '5.0.0' ) );
	}

	/**
	 * The declared maximum must match the version US-4.4 was actually validated
	 * on. Bumping the constant without re-running that validation is how a
	 * support statement becomes a fiction.
	 */
	#[Test]
	public function the_declared_tested_ceiling_matches_the_validated_version(): void {
		$this->assertSame( '4.2.0', Agend_Content_Access_Compat::MAX_TESTED_ELEMENTOR );
		$this->assertSame( '3.0.0', Agend_Content_Access_Compat::MIN_ELEMENTOR );
	}
}

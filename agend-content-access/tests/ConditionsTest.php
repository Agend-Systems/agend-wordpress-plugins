<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Conditions as Conditions;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';

/**
 * The Agend-native ESAC condition engine (US-6.1, reframed).
 *
 * Two things are under test. First, parity with the reference model, because
 * 1,106 existing conditions across nine sites are written against it and must
 * keep meaning what they meant. Second, the ONE deliberate divergence: the
 * reference fails OPEN on an unregistered provider, and this must fail closed.
 */
#[CoversClass( Conditions::class )]
final class ConditionsTest extends TestCase {

	/** A checker that knows the two default provider families. */
	private function checker( array $facts ): callable {
		return static function ( string $provider, string $value ) use ( $facts ): ?bool {
			if ( ! array_key_exists( $provider, $facts ) ) {
				return null; // unknown provider
			}

			return in_array( $value, $facts[ $provider ], true );
		};
	}

	private function wp( array $values ): callable {
		return $this->checker( array( 'iugo_esac_wordpress' => $values ) );
	}

	#[Test]
	public function no_conditions_is_no_restriction(): void {
		$result = Conditions::evaluate( array(), $this->wp( array() ) );

		$this->assertTrue( $result['passes'] );
	}

	#[Test]
	public function any_mode_passes_when_one_condition_passes(): void {
		$result = Conditions::evaluate(
			array( 'iugo_esac_wordpress:role_editor', 'iugo_esac_wordpress:logged_in' ),
			$this->wp( array( 'logged_in' ) ),
			Conditions::MATCH_ANY
		);

		$this->assertTrue( $result['passes'] );
	}

	#[Test]
	public function all_mode_requires_every_condition(): void {
		$conditions = array(
			'iugo_esac_wordpress:role_editor',
			'iugo_esac_wordpress:logged_in',
		);

		$this->assertFalse(
			Conditions::evaluate(
				$conditions,
				$this->wp( array( 'logged_in' ) ),
				Conditions::MATCH_ALL
			)['passes']
		);

		$this->assertTrue(
			Conditions::evaluate(
				$conditions,
				$this->wp( array( 'logged_in', 'role_editor' ) ),
				Conditions::MATCH_ALL
			)['passes']
		);
	}

	/**
	 * `logged_out` is why negation has to exist. 25 surveyed conditions use the
	 * login-state family, and content shown only to signed-out visitors cannot
	 * be expressed at all without it.
	 */
	#[Test]
	public function invert_negates_the_combined_result(): void {
		$signedIn = $this->wp( array( 'logged_in' ) );

		$this->assertTrue(
			Conditions::evaluate(
				array( 'iugo_esac_wordpress:logged_in' ),
				$signedIn,
				Conditions::MATCH_ANY,
				false
			)['passes']
		);

		$this->assertFalse(
			Conditions::evaluate(
				array( 'iugo_esac_wordpress:logged_in' ),
				$signedIn,
				Conditions::MATCH_ANY,
				true
			)['passes']
		);
	}

	#[Test]
	public function invert_applies_to_an_empty_condition_list_too(): void {
		// No conditions passes; inverted, it denies. Consistent rather than
		// special-cased, so a rule builder cannot produce a surprising result.
		$this->assertFalse(
			Conditions::evaluate( array(), $this->wp( array() ), Conditions::MATCH_ANY, true )['passes']
		);
	}

	// ---------------------------------------------------------------------
	// The divergence from the reference implementation.
	// ---------------------------------------------------------------------

	/**
	 * The reference defaults its check filter to TRUE, so an unregistered
	 * provider PASSES. Deactivate a plugin, misspell a prefix, or load in the
	 * wrong order, and restricted content becomes visible to everyone.
	 */
	#[Test]
	public function an_unknown_provider_denies_rather_than_passing(): void {
		$result = Conditions::evaluate(
			array( 'plugin_that_is_not_active:some_value' ),
			$this->wp( array() )
		);

		$this->assertFalse( $result['passes'] );
		$this->assertSame( array( 'plugin_that_is_not_active:some_value' ), $result['unknown'] );
	}

	#[Test]
	public function an_unknown_provider_denies_even_when_another_condition_passes(): void {
		// ANY mode with a passing condition would otherwise mask the problem.
		$result = Conditions::evaluate(
			array( 'iugo_esac_wordpress:logged_in', 'gone_away:x' ),
			$this->wp( array( 'logged_in' ) ),
			Conditions::MATCH_ANY
		);

		$this->assertFalse( $result['passes'] );
		$this->assertSame( array( 'gone_away:x' ), $result['unknown'] );
	}

	/**
	 * Every condition is evaluated even once the outcome is settled, so an
	 * unknown provider late in an ANY list is still reported. The reference
	 * short-circuits and would never surface it.
	 */
	#[Test]
	public function every_unknown_provider_is_reported_not_just_the_first(): void {
		$result = Conditions::evaluate(
			array( 'a_gone:1', 'iugo_esac_wordpress:logged_in', 'b_gone:2' ),
			$this->wp( array( 'logged_in' ) )
		);

		$this->assertSame( array( 'a_gone:1', 'b_gone:2' ), $result['unknown'] );
	}

	/**
	 * The reference discards a condition that does not split into exactly two
	 * parts. A two-condition ALL rule with one typo silently becomes a
	 * one-condition rule, which passes more often than the author intended.
	 */
	#[Test]
	public function a_malformed_condition_denies_rather_than_shrinking_the_rule(): void {
		$result = Conditions::evaluate(
			array( 'iugo_esac_wordpress:logged_in', 'no_colon_here' ),
			$this->wp( array( 'logged_in' ) ),
			Conditions::MATCH_ALL
		);

		$this->assertFalse( $result['passes'] );
		$this->assertContains( 'no_colon_here', $result['unknown'] );
	}

	// ---------------------------------------------------------------------
	// Parsing
	// ---------------------------------------------------------------------

	/**
	 * Only the FIRST colon splits. A segment id or URL in the value would
	 * otherwise be truncated, and the reference's `explode` would discard the
	 * condition entirely.
	 */
	#[Test]
	public function only_the_first_colon_splits_the_condition(): void {
		$parsed = Conditions::parse( array( 'provider:a:b:c' ) );

		$this->assertSame(
			array( 'provider' => 'provider', 'value' => 'a:b:c' ),
			$parsed['valid']['provider:a:b:c']
		);
	}

	#[Test]
	public function blank_entries_are_skipped_but_edge_colons_are_malformed(): void {
		$parsed = Conditions::parse( array( '', '   ', ':novalue', 'noprovider:' ) );

		$this->assertSame( array(), $parsed['valid'] );
		$this->assertSame( array( ':novalue', 'noprovider:' ), $parsed['malformed'] );
	}

	#[Test]
	public function a_non_string_entry_is_malformed_not_ignored(): void {
		// @phpstan-ignore-next-line deliberately wrong type
		$parsed = Conditions::parse( array( 123, null ) );

		$this->assertCount( 2, $parsed['malformed'] );
	}

	#[Test]
	public function duplicate_conditions_are_evaluated_once(): void {
		$calls = 0;

		$result = Conditions::evaluate(
			array( 'iugo_esac_wordpress:logged_in', 'iugo_esac_wordpress:logged_in' ),
			static function () use ( &$calls ): ?bool {
				++$calls;
				return true;
			}
		);

		$this->assertTrue( $result['passes'] );
		$this->assertSame( 1, $calls );
	}

	#[Test]
	public function providers_used_lists_each_family_once(): void {
		$this->assertSame(
			array( 'iugo_esac_wordpress', 'iugo_esac_segments' ),
			Conditions::providers_used(
				array(
					'iugo_esac_wordpress:logged_in',
					'iugo_esac_segments:segment_1',
					'iugo_esac_wordpress:role_editor',
					'iugo_esac_segments:segment_2',
				)
			)
		);
	}

	#[Test]
	public function the_evaluation_trace_records_each_conditions_result(): void {
		$result = Conditions::evaluate(
			array( 'iugo_esac_wordpress:logged_in', 'iugo_esac_wordpress:role_editor' ),
			$this->wp( array( 'logged_in' ) ),
			Conditions::MATCH_ALL
		);

		// The trace is what makes a denial explicable to an administrator
		// looking at a page they expected to see.
		$this->assertSame(
			array(
				'iugo_esac_wordpress:logged_in'   => true,
				'iugo_esac_wordpress:role_editor' => false,
			),
			$result['evaluated']
		);
	}
}

<?php
/**
 * Condition providers: where each condition family gets its answer.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supplies the facts the condition engine evaluates against
 * (SPEC-CMS-20260727 US-6.1, reframed as ESAC feature parity).
 *
 * Three sources, matching the ESAC families found in real data:
 *
 *   - `agend_wordpress`   login state and roles, read locally, no network.
 *   - `agend_membership`  member standing and tiers, from the Agend API.
 *   - `agend_segments`    segment membership, from the Agend API.
 *
 * The legacy `iugo_esac_*` prefixes are accepted as ALIASES so the 1,106
 * conditions already stored across nine sites keep working untouched. Rewriting
 * that data would be a migration with nothing to gain: the prefix is an opaque
 * key, and translating it risks exactly the silent mis-conversion the premise
 * check warned about.
 *
 * A provider that cannot answer returns NULL, never false. The distinction is
 * load-bearing: false means "this visitor does not qualify", null means "I
 * could not tell", and the engine denies on null while reporting it. Collapsing
 * the two would turn an outage into a silent mass-denial with no diagnostic, or
 * worse, if inverted, into a mass-disclosure.
 */
class Agend_Content_Access_Condition_Providers {

	/** Canonical provider keys. */
	const WORDPRESS  = 'agend_wordpress';
	const MEMBERSHIP = 'agend_membership';
	const SEGMENTS   = 'agend_segments';

	/**
	 * Legacy ESAC prefixes accepted verbatim.
	 *
	 * Stored data uses these. Mapping them here means no site needs its
	 * `agend_cp_access_conditions` rewritten to adopt this engine.
	 *
	 * @var array<string, string>
	 */
	const LEGACY_ALIASES = array(
		'iugo_esac_wordpress'       => self::WORDPRESS,
		'iugo_esac_segments'        => self::SEGMENTS,
		'upbeat_is_member'          => self::MEMBERSHIP,
		'upbeat_member_entitlement' => self::MEMBERSHIP,
		'upbeat_member_grades'      => self::MEMBERSHIP,
	);

	/**
	 * Resolves a provider key through the alias table.
	 *
	 * @param string $provider Provider as written in the condition.
	 * @return string|null Canonical key, or null when unrecognised.
	 */
	public static function canonical( string $provider ): ?string {
		if ( in_array( $provider, array( self::WORDPRESS, self::MEMBERSHIP, self::SEGMENTS ), true ) ) {
			return $provider;
		}

		return self::LEGACY_ALIASES[ $provider ] ?? null;
	}

	/**
	 * Builds the checker the engine calls.
	 *
	 * Facts are resolved LAZILY and then reused for the whole request. A page
	 * with thirty conditioned sections must not make thirty API calls, and every
	 * section must be judged against the same answer: a membership that expired
	 * between two calls would otherwise render half a page as a member and half
	 * as a visitor.
	 *
	 * @param callable $wordpress_facts fn(): array{logged_in: bool, roles: string[]}
	 * @param callable $member_facts    fn(): ?array{is_member: bool, tier_ids: string[], tier_slugs: string[]}
	 * @param callable $segment_facts   fn(): ?string[]
	 * @return callable fn(string $provider, string $value): ?bool
	 */
	public static function checker(
		callable $wordpress_facts,
		callable $member_facts,
		callable $segment_facts
	): callable {
		$wp       = null;
		$member   = null;
		$segments = null;
		$loaded   = array();

		return static function ( string $provider, string $value ) use (
			$wordpress_facts,
			$member_facts,
			$segment_facts,
			&$wp,
			&$member,
			&$segments,
			&$loaded
		): ?bool {
			$canonical = self::canonical( $provider );

			if ( null === $canonical ) {
				return null;
			}

			if ( self::WORDPRESS === $canonical ) {
				if ( ! isset( $loaded['wp'] ) ) {
					$wp            = $wordpress_facts();
					$loaded['wp'] = true;
				}

				return self::check_wordpress( $value, is_array( $wp ) ? $wp : array() );
			}

			if ( self::MEMBERSHIP === $canonical ) {
				if ( ! isset( $loaded['member'] ) ) {
					$member            = $member_facts();
					$loaded['member'] = true;
				}

				// The API could not be reached. Not "not a member": unknown.
				if ( ! is_array( $member ) ) {
					return null;
				}

				return self::check_membership( $value, $member );
			}

			if ( ! isset( $loaded['segments'] ) ) {
				$segments            = $segment_facts();
				$loaded['segments'] = true;
			}

			if ( ! is_array( $segments ) ) {
				return null;
			}

			return in_array( $value, $segments, true );
		};
	}

	/**
	 * WordPress login state and role conditions.
	 *
	 * Values mirror ESAC exactly: `logged_in`, `logged_out`, `role_<id>`.
	 *
	 * @param string $value Condition value.
	 * @param array  $facts Resolved WordPress facts.
	 * @return bool|null
	 */
	public static function check_wordpress( string $value, array $facts ): ?bool {
		$logged_in = ! empty( $facts['logged_in'] );
		$roles     = isset( $facts['roles'] ) && is_array( $facts['roles'] ) ? $facts['roles'] : array();

		if ( 'logged_in' === $value ) {
			return $logged_in;
		}

		if ( 'logged_out' === $value ) {
			return ! $logged_in;
		}

		if ( 0 === strpos( $value, 'role_' ) ) {
			return in_array( substr( $value, 5 ), $roles, true );
		}

		// A value this provider does not define. Unknown, not false: a typo in
		// a role name must not read as "the visitor lacks that role", which
		// would look like a working rule that never matches.
		return null;
	}

	/**
	 * Membership conditions.
	 *
	 * Accepts the legacy vocabulary as well as the canonical one, because the
	 * stored data uses it: `is_member` from `upbeat_is_member`, and a tier
	 * SLUG from `upbeat_member_entitlement` / `upbeat_member_grades`. Tier ids
	 * are matched too, for conditions authored against Agend directly.
	 *
	 * @param string $value Condition value.
	 * @param array  $facts Resolved member facts.
	 * @return bool|null
	 */
	public static function check_membership( string $value, array $facts ): ?bool {
		if ( 'is_member' === $value ) {
			return ! empty( $facts['is_member'] );
		}

		$ids   = isset( $facts['tier_ids'] ) && is_array( $facts['tier_ids'] ) ? $facts['tier_ids'] : array();
		$slugs = isset( $facts['tier_slugs'] ) && is_array( $facts['tier_slugs'] ) ? $facts['tier_slugs'] : array();

		// Matched against both, because the legacy conditions name a slug while
		// anything authored against Agend names a UUID. Checking both is not a
		// guess: each namespace is exact, and a slug cannot collide with a UUID.
		return in_array( $value, $ids, true ) || in_array( $value, $slugs, true );
	}

	/**
	 * WordPress facts for the current visitor. No network, no cache needed.
	 *
	 * @return array{logged_in: bool, roles: string[]}
	 */
	public static function wordpress_facts(): array {
		$user = wp_get_current_user();

		return array(
			'logged_in' => $user && $user->exists(),
			'roles'     => $user && $user->exists() ? array_values( (array) $user->roles ) : array(),
		);
	}
}

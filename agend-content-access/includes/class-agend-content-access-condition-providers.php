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
 * Four built-in sources. The first three match the ESAC families found in real
 * data; the fourth is new and has no legacy alias:
 *
 *   - `agend_wordpress`     login state and roles, read locally, no network.
 *   - `agend_membership`    member standing and tiers, from the Agend API.
 *   - `agend_segments`      segment membership, from the Agend API.
 *   - `agend_entitlements`  the entitlements the visitor holds, from the Agend
 *                           API. The value is a `gate_key`.
 *
 * Any other provider is answered by a site through the
 * `agend_content_access_check_condition` filter, and an unanswered one is
 * unknown and denies. `checker()` is the registry that dispatches between the
 * two.
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
	const WORDPRESS    = 'agend_wordpress';
	const MEMBERSHIP   = 'agend_membership';
	const SEGMENTS     = 'agend_segments';
	const ENTITLEMENTS = 'agend_entitlements';

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
		if ( in_array( $provider, array( self::WORDPRESS, self::MEMBERSHIP, self::SEGMENTS, self::ENTITLEMENTS ), true ) ) {
			return $provider;
		}

		return self::LEGACY_ALIASES[ $provider ] ?? null;
	}

	/**
	 * Builds the checker the engine calls.
	 *
	 * This is a provider registry, not a fixed set of arguments. The built-in
	 * providers (`agend_wordpress`, `agend_membership`, `agend_segments`,
	 * `agend_entitlements`) are explicit branches below, each fed by ONE fact resolver looked up by its
	 * canonical key. Adding a built-in provider is therefore one resolver entry
	 * plus one branch, and never another positional argument on this method.
	 *
	 * Any provider that is neither a built-in nor a legacy alias goes to the
	 * `agend_content_access_check_condition` filter, which the condition-sets
	 * docblock has always promised. A site that registers a provider through
	 * `agend_content_access_condition_sets` answers it there. The filter must
	 * return a bool: anything else, including no answer at all, is UNKNOWN, so
	 * the engine denies and names the provider. A provider nobody answers is
	 * never allowed to pass.
	 *
	 * Facts are resolved LAZILY and then reused for the whole request. A page
	 * with thirty conditioned sections must not make thirty API calls, and every
	 * section must be judged against the same answer: a membership that expired
	 * between two calls would otherwise render half a page as a member and half
	 * as a visitor. A built-in with no resolver is unknown rather than empty,
	 * because an absent source is not evidence that the visitor holds nothing.
	 *
	 * @param array<string, callable> $resolvers Fact resolvers keyed by canonical provider:
	 *        `self::WORDPRESS`    => fn(): array{logged_in: bool, roles: string[]}
	 *        `self::MEMBERSHIP`   => fn(): ?array{is_member: bool, tier_ids: string[], tier_slugs: string[]}
	 *        `self::SEGMENTS`     => fn(): ?string[]
	 *        `self::ENTITLEMENTS` => fn(): ?string[] Gate keys the visitor holds.
	 * @return callable fn(string $provider, string $value): ?bool
	 */
	public static function checker( array $resolvers ): callable {
		$facts  = array();
		$loaded = array();

		return static function ( string $provider, string $value ) use ( $resolvers, &$facts, &$loaded ): ?bool {
			$canonical = self::canonical( $provider );

			if ( null === $canonical ) {
				return self::check_custom( $provider, $value );
			}

			$resolver = isset( $resolvers[ $canonical ] ) && is_callable( $resolvers[ $canonical ] )
				? $resolvers[ $canonical ]
				: null;

			if ( null === $resolver ) {
				return null;
			}

			if ( ! isset( $loaded[ $canonical ] ) ) {
				$facts[ $canonical ]  = $resolver();
				$loaded[ $canonical ] = true;
			}

			$resolved = $facts[ $canonical ];

			if ( self::WORDPRESS === $canonical ) {
				return self::check_wordpress( $value, is_array( $resolved ) ? $resolved : array() );
			}

			// The API could not be reached. Not "not a member": unknown.
			if ( ! is_array( $resolved ) ) {
				return null;
			}

			if ( self::MEMBERSHIP === $canonical ) {
				return self::check_membership( $value, $resolved );
			}

			if ( self::SEGMENTS === $canonical ) {
				return self::check_segments( $value, $resolved );
			}

			if ( self::ENTITLEMENTS === $canonical ) {
				return self::check_entitlements( $value, $resolved );
			}

			return null;
		};
	}

	/**
	 * A provider this plugin does not define, answered by the site.
	 *
	 * @param string $provider Provider as written in the condition.
	 * @param string $value    Condition value.
	 * @return bool|null Null unless a filter callback returned a bool.
	 */
	private static function check_custom( string $provider, string $value ): ?bool {
		/**
		 * Answers a display condition for a provider this plugin does not define.
		 *
		 * Return true when the visitor satisfies the condition and false when
		 * they do not. Return anything else, or nothing, when the provider
		 * cannot tell: that is UNKNOWN, which the engine denies and reports,
		 * never passes. Only the built-in providers and the legacy ESAC aliases
		 * are answered by this plugin.
		 *
		 * @param bool|null $result   Null until a callback answers.
		 * @param string    $provider Provider as written in the condition.
		 * @param string    $value    Condition value.
		 */
		$result = apply_filters( 'agend_content_access_check_condition', null, $provider, $value );

		return is_bool( $result ) ? $result : null;
	}

	/**
	 * Segment conditions: the visitor belongs to the named segment.
	 *
	 * Matched against segment SLUGS, for compatibility with stored ESAC data.
	 *
	 * @param string   $value    Condition value, a segment slug.
	 * @param string[] $segments Slugs of the segments the visitor belongs to.
	 * @return bool
	 */
	public static function check_segments( string $value, array $segments ): bool {
		return in_array( $value, $segments, true );
	}

	/**
	 * Entitlement conditions: the visitor holds the named entitlement.
	 *
	 * Matched against gate keys, the identity an entitlement has everywhere
	 * else: access policies, the sync endpoints and `has_entitlement` all speak
	 * it. A key survives a benefit being re-created, which a row id does not.
	 *
	 * @param string   $value        Condition value, a gate key.
	 * @param string[] $entitlements Gate keys the visitor holds.
	 * @return bool
	 */
	public static function check_entitlements( string $value, array $entitlements ): bool {
		return in_array( $value, $entitlements, true );
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

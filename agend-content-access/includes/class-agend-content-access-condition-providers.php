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
 *
 * The three built-ins are dispatched through a REGISTRY rather than a closed
 * if-chain, so a site can add a fourth family (an `agend_entitlements`
 * provider, say) without this class changing at all. The editor-facing
 * vocabulary is already extensible through `agend_content_access_condition_sets`
 * (see class-agend-content-access-condition-sets.php); this filter is that
 * extension point's other half. Registering a set without a matching provider
 * here produces conditions that always deny, which is a denial by design: an
 * unrecognised provider is unknown, and unknown denies.
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
	 * The provider registry: canonical key to its `facts` and `check` callables.
	 *
	 * A registration is an array{
	 *     facts: ?callable(): array|null,
	 *     check: callable(string $value, array $facts): ?bool,
	 * }. `facts` may be null here for a built-in whose facts require a live
	 * source (membership and segments need the Agend API): the caller of
	 * `checker()` supplies the real one, since this class deliberately does not
	 * know how to reach the network. A site's own provider has no such split;
	 * it registers both callables together because nothing else can supply the
	 * `facts` half for it.
	 *
	 * Built via `apply_filters()` on every call rather than cached statically:
	 * a filter callback may close over per-request state (a request-scoped API
	 * client, a feature flag read fresh each time), and caching the merged
	 * registry would freeze that state past the request it was captured in.
	 *
	 * @return array<string, array{facts: ?callable, check: callable}>
	 */
	public static function registry(): array {
		$builtins = array(
			self::WORDPRESS  => array(
				'facts' => array( __CLASS__, 'wordpress_facts' ),
				'check' => array( __CLASS__, 'check_wordpress' ),
			),
			self::MEMBERSHIP => array(
				'facts' => null,
				'check' => array( __CLASS__, 'check_membership' ),
			),
			self::SEGMENTS   => array(
				'facts' => null,
				'check' => array( __CLASS__, 'check_segments' ),
			),
		);

		/**
		 * Filters the condition provider registry the evaluator dispatches
		 * through.
		 *
		 * A site adds its own family here, keyed by a canonical provider name
		 * that does not collide with `agend_wordpress`, `agend_membership`, or
		 * `agend_segments`. Each entry provides:
		 *
		 *   - `facts`: `fn(): array|null`, returning the facts for the CURRENT
		 *     visitor, or null when they could not be determined (an outage,
		 *     not "the visitor lacks this"). Called at most once per checker
		 *     instance, so it is safe to make a network call here.
		 *   - `check`: `fn( string $value, array $facts ): ?bool`, returning
		 *     whether a specific condition value holds against those facts, or
		 *     null when the value is not one this provider defines.
		 *
		 * Adding a matching entry here for a set registered through
		 * `agend_content_access_condition_sets` is not optional: an editor sees
		 * the condition either way, but without an entry here it always
		 * evaluates to unknown and the engine denies.
		 *
		 * @param array $providers Canonical key to its facts and check callables.
		 */
		return (array) apply_filters( 'agend_content_access_condition_providers', $builtins );
	}

	/**
	 * Resolves a provider key through the alias table.
	 *
	 * @param string $provider Provider as written in the condition.
	 * @return string|null Canonical key, or null when unrecognised.
	 */
	public static function canonical( string $provider ): ?string {
		return self::resolve_canonical( $provider, self::registry() );
	}

	/**
	 * The alias resolution logic, taking the registry as an argument so
	 * `checker()` can resolve against ONE snapshot of it instead of re-running
	 * `apply_filters()` for every condition on the page.
	 *
	 * @param string $provider Provider as written in the condition.
	 * @param array  $registry A registry as returned by `registry()`.
	 * @return string|null Canonical key, or null when unrecognised.
	 */
	private static function resolve_canonical( string $provider, array $registry ): ?string {
		if ( array_key_exists( $provider, $registry ) ) {
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
	 * as a visitor. The memoisation is generic over the registry, so a fourth
	 * provider gets the same once-per-instance guarantee for free.
	 *
	 * @param array<string, callable> $facts_sources Canonical provider key to a
	 *                                `fn(): array|null` live facts source. Only
	 *                                needed for a built-in whose registry entry
	 *                                has no `facts` of its own (membership and
	 *                                segments); a site's own provider carries its
	 *                                `facts` callable in the registry already and
	 *                                has no reason to appear here.
	 * @return callable fn(string $provider, string $value): ?bool
	 */
	public static function checker( array $facts_sources = array() ): callable {
		$registry = self::registry();
		$resolved = array();
		$loaded   = array();

		return static function ( string $provider, string $value ) use (
			$registry,
			$facts_sources,
			&$resolved,
			&$loaded
		): ?bool {
			$canonical = self::resolve_canonical( $provider, $registry );

			if ( null === $canonical || ! isset( $registry[ $canonical ] ) ) {
				return null;
			}

			if ( ! isset( $loaded[ $canonical ] ) ) {
				$source = $facts_sources[ $canonical ] ?? ( $registry[ $canonical ]['facts'] ?? null );

				$resolved[ $canonical ] = is_callable( $source ) ? $source() : null;
				$loaded[ $canonical ]   = true;
			}

			$facts = $resolved[ $canonical ];

			// Not fetched, or the source could not answer (an outage). Not
			// "this visitor does not qualify": unknown, so the engine denies
			// and reports it rather than settling the question on their behalf.
			if ( ! is_array( $facts ) ) {
				return null;
			}

			$check = $registry[ $canonical ]['check'] ?? null;

			return is_callable( $check ) ? $check( $value, $facts ) : null;
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
	 * Segment membership conditions.
	 *
	 * Unlike WordPress and membership, segment facts are already the flat list
	 * a value is matched against, so there is no sub-vocabulary to branch on:
	 * every value not present is simply absent, never undefined. This used to
	 * be the checker's unconditional final fallthrough (any canonical key that
	 * was not WordPress or membership landed here, whether it meant to or not);
	 * it is now reached only when the value's provider resolves to
	 * `agend_segments`.
	 *
	 * @param string $value Condition value (a segment slug).
	 * @param array  $facts Resolved segment slugs.
	 * @return bool|null
	 */
	public static function check_segments( string $value, array $facts ): ?bool {
		return in_array( $value, $facts, true );
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

<?php
/**
 * The one source of the current visitor's member facts.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads, normalises and caches what Agend says the current visitor holds.
 *
 * Two code paths used to ask `GET /v1/crm/me/entitlements` separately: the
 * document path (`Decision::viewer()`, memoised for the request and never
 * stored) and the display condition path (`Condition_Runtime::member_facts()`,
 * a per-user transient). A page with a document policy and a conditioned
 * section therefore made the same call twice, under two different lifetimes.
 * This class replaces both. The two public methods they exposed are now thin
 * adapters over it, and they keep the distinct rules each one had for a
 * signed-out visitor, because those are deliberate: the document path keys on
 * a bearer being present, the condition path treats a visitor who is not
 * logged in as definitively holding nothing.
 *
 * WHAT IS CACHED, AND FOR HOW LONG. The normalised facts are held for the
 * request and in a per-member transient. Before this change a page policy asked
 * Agend on every request, so a revocation that fires no webhook now takes up to
 * the cache lifetime to apply (display conditions already worked that way).
 * That is a deliberate trade, and it is bounded: the lifetime is Core's
 * `crm_me_entitlements` setting, five minutes by default, capped so an entry
 * never outlives the earliest entitlement expiry the gateway reports. Webhook
 * invalidation arrives in a later layer, through `bump_generation()` and
 * `flush()`. It is an accelerator only. The TTL stays the backstop, because
 * several changes to what a member can see happen with no write for an event to
 * announce (a grace period ending, a grant reaching its expiry, a segment rule
 * with a relative date).
 *
 * RULES THAT HOLD WHATEVER THE CALLER:
 *
 *   - One gateway call per request at most. The result, a failure included, is
 *     memoised for the request, so the document path and the condition path
 *     share it.
 *   - The cache is per member and never shared. The key carries the WordPress
 *     user id and a generation number (see `bump_generation()`).
 *   - Nothing is written to the transient store when the user id is 0. A bearer
 *     can reach this code through the `agend_apps_bearer_token` filter without a
 *     WordPress login, and a user-0 key would be one entry shared by every such
 *     visitor. That visitor is memoised for the request and nothing more.
 *   - A failure is never cached. It yields "unknown" (null), which each adapter
 *     maps to its own denial. Caching it would stretch one blip into a
 *     TTL-long outage for that member.
 */
class Agend_Content_Access_Viewer_Facts {

	/**
	 * Option holding the cache generation.
	 *
	 * Every cached entry is keyed by the generation it was written under, so
	 * bumping the number makes every member's entry unreachable at once, without
	 * iterating users. The orphaned entries expire on their own.
	 *
	 * @var string
	 */
	const GENERATION_OPTION = 'agend_content_access_facts_generation';

	/**
	 * Transient key prefixes. The per-member membership facts and the
	 * per-member segment facts are separate entries because segments are
	 * fetched only when something on the page needs them.
	 *
	 * @var string
	 */
	const MEMBER_KEY_PREFIX  = 'agend_content_access_member_facts';
	const SEGMENT_KEY_PREFIX = 'agend_content_access_segment_facts';

	/**
	 * A gate key as the gateway defines it (`crm_benefits.gate_key`), and as the
	 * access policy parser accepts it. Anything else is dropped: a key that does
	 * not match can never equal one a policy names.
	 *
	 * The `D` modifier matters. Without it `$` also matches before a trailing
	 * newline, which would let `"key\n"` through.
	 *
	 * @var string
	 */
	const ENTITLEMENT_KEY_PATTERN = '/^[a-z][a-z0-9_.]{1,62}[a-z0-9]$/D';

	/**
	 * The shortest an entry is held because of an entitlement expiry. Without a
	 * floor a grant about to expire would be re-read on every request.
	 *
	 * @var int
	 */
	const MIN_EXPIRY_TTL = 30;

	/**
	 * Per-request memo, keyed by WordPress user id.
	 *
	 * Keyed by user id rather than held as a single value so a request that
	 * switches user (a login completing, an impersonation that restores
	 * afterwards) cannot be answered with the previous user's facts. The value
	 * is the facts array, or null for a failure: a failure is remembered for the
	 * request so one outage does not become one gateway call per caller.
	 *
	 * @var array<int, array|null>
	 */
	private static array $memo = array();

	/**
	 * Resets the per-request memo. Tests, and `bump_generation()`.
	 */
	public static function reset(): void {
		self::$memo = array();
	}

	/**
	 * The current visitor's facts from Agend, or null when they cannot be known.
	 *
	 * Null covers every failure: the Core wrapper is missing (an older Core), the
	 * gateway returned an error, or the response was not an array. The caller
	 * decides what unknown means. This method never answers "holds nothing" for
	 * a failure, because on an inverted rule that would disclose rather than
	 * deny.
	 *
	 * It does NOT decide whether the visitor is signed in. That is the caller's
	 * call, for the reasons the class docblock gives.
	 *
	 * @return array{
	 *     is_member: bool,
	 *     tier_ids: string[],
	 *     tier_slugs: string[],
	 *     entitlement_keys: string[],
	 *     entitlement_expires_at: string|null
	 * }|null
	 */
	public static function member(): ?array {
		$user_id = (int) get_current_user_id();

		if ( array_key_exists( $user_id, self::$memo ) ) {
			return self::$memo[ $user_id ];
		}

		$facts = self::resolve( $user_id );

		self::$memo[ $user_id ] = $facts;

		return $facts;
	}

	/**
	 * Resolves the facts for a user: transient first, then the gateway.
	 *
	 * @param int $user_id WordPress user id, 0 when none.
	 * @return array|null Facts, or null when unknown.
	 */
	private static function resolve( int $user_id ): ?array {
		if ( ! function_exists( 'agend_apps_crm_get_my_entitlements' ) ) {
			// Agend Apps Core predates the resolved-standing wrapper. Unknown,
			// not "holds nothing". Deliberately NOT a hard dependency in the
			// gate: an older Core still supports document policies for
			// anonymous visitors, and refusing to load would turn a partial
			// capability into no protection at all.
			return null;
		}

		// Never cached for user 0. See the class docblock: a bearer can arrive
		// without a login, and one key would then serve every such visitor.
		$cacheable = $user_id > 0;

		// The key is built ONCE, before the gateway is asked, and used for both
		// the read and the write. Reading the generation again at write time
		// would let a bump that lands mid-request store pre-change data under
		// the new generation, which is exactly the entry the bump meant to kill.
		$key = $cacheable ? self::member_cache_key( $user_id ) : '';

		if ( $cacheable ) {
			$cached = get_transient( $key );

			if ( is_array( $cached ) ) {
				return array_merge( self::empty_facts(), $cached );
			}
		}

		$response = agend_apps_crm_get_my_entitlements();

		if ( is_wp_error( $response ) ) {
			// Deliberately NOT cached. Caching a failure would extend one blip
			// into a TTL-long outage for that member.
			return null;
		}

		$data = isset( $response['data'] ) && is_array( $response['data'] )
			? $response['data']
			: $response;

		if ( ! is_array( $data ) ) {
			return null;
		}

		$facts = self::normalise( $data );

		if ( $cacheable ) {
			set_transient( $key, $facts, self::cache_ttl( $facts['entitlement_expires_at'] ) );
		}

		return $facts;
	}

	/**
	 * Facts with nothing held. The defaults every cached entry is merged over,
	 * so an entry written with fewer fields still reads as a complete shape.
	 *
	 * @return array
	 */
	private static function empty_facts(): array {
		return array(
			'is_member'              => false,
			'tier_ids'               => array(),
			'tier_slugs'             => array(),
			'entitlement_keys'       => array(),
			'entitlement_expires_at' => null,
		);
	}

	/**
	 * Reduces a gateway response to the fields both paths read.
	 *
	 * Strict about shape, because a malformed field must grant nothing. A tier
	 * list that is not a list of non-empty strings contributes only its valid
	 * entries, which can only make a visitor hold LESS.
	 *
	 * Entitlements come from `capabilities[].gate_key`. The gateway does not
	 * report a per-capability `expires_at` yet; when it does, the earliest one
	 * is kept so the cache entry can be capped to it. Until then it is null.
	 * Nothing consumes the entitlement fields in this layer.
	 *
	 * @param array $data The response's `data` object.
	 * @return array
	 */
	private static function normalise( array $data ): array {
		$facts = self::empty_facts();

		$facts['is_member']  = ! empty( $data['is_member'] );
		$facts['tier_ids']   = self::strings( $data['tier_ids'] ?? null );
		$facts['tier_slugs'] = self::strings( $data['tier_slugs'] ?? null );

		$earliest = null;

		if ( isset( $data['capabilities'] ) && is_array( $data['capabilities'] ) ) {
			foreach ( $data['capabilities'] as $capability ) {
				if ( ! is_array( $capability ) || ! isset( $capability['gate_key'] ) || ! is_string( $capability['gate_key'] ) ) {
					continue;
				}

				if ( 1 !== preg_match( self::ENTITLEMENT_KEY_PATTERN, $capability['gate_key'] ) ) {
					continue;
				}

				$facts['entitlement_keys'][] = $capability['gate_key'];

				$expires = self::timestamp( $capability['expires_at'] ?? null );

				if ( null !== $expires && ( null === $earliest || $expires < $earliest ) ) {
					$earliest = $expires;
				}
			}
		}

		$facts['entitlement_keys'] = array_values( array_unique( $facts['entitlement_keys'] ) );

		if ( null !== $earliest ) {
			$facts['entitlement_expires_at'] = gmdate( 'Y-m-d\TH:i:s\Z', $earliest );
		}

		return $facts;
	}

	/**
	 * The non-empty strings in a value, as a list.
	 *
	 * @param mixed $value Candidate list.
	 * @return string[]
	 */
	private static function strings( $value ): array {
		$out = array();

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( is_string( $item ) && '' !== $item ) {
					$out[] = $item;
				}
			}
		}

		return $out;
	}

	/**
	 * An ISO-8601 date-time as a Unix timestamp, or null when it is not one.
	 *
	 * Strict on the shape, because `strtotime()` on its own accepts phrases such
	 * as "next Thursday", and a timestamp that decides how long access is cached
	 * should come from a date the gateway actually wrote.
	 *
	 * @param mixed $value Candidate date-time.
	 * @return int|null
	 */
	private static function timestamp( $value ): ?int {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value ) ) {
			return null;
		}

		$timestamp = strtotime( $value );

		return false === $timestamp ? null : $timestamp;
	}

	/**
	 * How long an entry is cached.
	 *
	 * Short by intent. Membership standing decides what a visitor can see, so a
	 * long TTL means a lapsed member keeps their access for that long. Uses
	 * Core's configured value when available so an operator tunes one setting,
	 * and five minutes otherwise.
	 *
	 * When the earliest entitlement expiry is known the entry is capped so it
	 * does not outlive it, with a floor of `MIN_EXPIRY_TTL` seconds so a grant
	 * about to expire is not re-read on every request. The floor applies to the
	 * cap only: an operator who configures a shorter lifetime keeps it.
	 *
	 * @param string|null $expires_at Earliest entitlement expiry, ISO-8601, or null.
	 * @return int Seconds.
	 */
	public static function cache_ttl( ?string $expires_at = null ): int {
		$ttl = 5 * MINUTE_IN_SECONDS;

		if ( class_exists( 'Agend_Apps_Settings' ) ) {
			$configured = (int) Agend_Apps_Settings::get_cache_ttl( 'crm_me_entitlements' );

			if ( $configured > 0 ) {
				$ttl = $configured;
			}
		}

		$expires = self::timestamp( $expires_at );

		if ( null !== $expires ) {
			$ttl = min( $ttl, max( self::MIN_EXPIRY_TTL, $expires - time() ) );
		}

		return $ttl;
	}

	/**
	 * The current cache generation.
	 *
	 * @return int
	 */
	public static function generation(): int {
		return max( 0, (int) get_option( self::GENERATION_OPTION, 0 ) );
	}

	/**
	 * Invalidates every member's cached facts at once.
	 *
	 * For an account-wide change: a segment's rules, or the benefits a tier
	 * includes. It does not iterate users. The generation is part of every key,
	 * so every existing entry simply stops being found, and the request memo is
	 * dropped so a lookup later in this same request reads fresh.
	 *
	 * Two bumps racing can land on the same number. That is harmless: either one
	 * is already different from every key written before it.
	 *
	 * @return int The new generation.
	 */
	public static function bump_generation(): int {
		$next = self::generation() + 1;

		update_option( self::GENERATION_OPTION, $next, true );

		self::reset();

		return $next;
	}

	/**
	 * Transient key for a member's membership facts.
	 *
	 * Carries the WordPress user id, so one member's facts are never served to
	 * another, and the generation the entry was written under, so a bump makes
	 * every older entry unreachable. Both are integers joined by underscores,
	 * which keeps the pair unambiguous.
	 *
	 * @param int $user_id WordPress user id.
	 * @return string
	 */
	public static function member_cache_key( int $user_id ): string {
		return self::MEMBER_KEY_PREFIX . '_g' . self::generation() . '_u' . $user_id;
	}

	/**
	 * Transient key for a member's segment facts, under the same generation.
	 *
	 * @param int $user_id WordPress user id.
	 * @return string
	 */
	public static function segment_cache_key( int $user_id ): string {
		return self::SEGMENT_KEY_PREFIX . '_g' . self::generation() . '_u' . $user_id;
	}

	/**
	 * Forgets one member's cached facts, membership and segments, and drops
	 * their entry from the request memo.
	 *
	 * Called when a change for that member arrives, so an upgrade or a lapse
	 * takes effect without waiting out the TTL.
	 *
	 * @param int $user_id WordPress user id.
	 */
	public static function flush( int $user_id ): void {
		delete_transient( self::member_cache_key( $user_id ) );
		delete_transient( self::segment_cache_key( $user_id ) );

		unset( self::$memo[ $user_id ] );
	}
}

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
 * SEGMENTS ARE A SEPARATE, ON-DEMAND FACT. `member()` answers from
 * `/crm/me/entitlements`; `segments()` answers from `/crm/me/segments`. They are
 * two gateway calls, memoised and cached separately, because most pages need
 * the first and no page needs the second unless it carries a segments policy or
 * a segment condition. Nothing here fetches segments as a side effect of
 * `member()`, and a caller that never asks never pays. Both facts follow the
 * same rules below, and share the generation number so one bump clears both.
 *
 * RULES THAT HOLD WHATEVER THE CALLER:
 *
 *   - One gateway call per fact per request at most. The result, a failure
 *     included, is memoised for the request, so the document path and the
 *     condition path share it.
 *   - The cache is per member AND per bearer, and never shared. The key carries
 *     the WordPress user id, a digest of the bearer the facts were fetched
 *     with, the global generation (see `bump_generation()`) and the user's own
 *     generation (see `flush()`). The bearer is in the key because the user id
 *     alone is not the identity the gateway answered for: a re-link, or any
 *     `agend_apps_bearer_token` provider not tied to the WordPress user, can put
 *     a different member's bearer behind the same user id, and an entry fetched
 *     under the old bearer would then be served for the new one until it
 *     expired. Core's `identity_cache_suffix()` is the same rule for the same
 *     reason.
 *   - Nothing is read from or written to the transient store without a bearer,
 *     or when the user id is 0. A bearer can reach this code through the
 *     `agend_apps_bearer_token` filter without a WordPress login, and a user-0
 *     key would be one entry shared by every such visitor. A logged-in user with
 *     no bearer has nothing to key an entry on, and reading one written under an
 *     earlier bearer would be serving stale facts to someone the gateway cannot
 *     now identify. Those visitors are memoised for the request and nothing
 *     more.
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
	 * User meta holding one user's own cache generation.
	 *
	 * `flush()` increments it, and it is part of every key that user's entries are
	 * stored under. That is how one user's entries are all invalidated by a caller
	 * that holds no bearer, which is the only kind of caller a webhook can be: the
	 * bearer digest is in the key, so without a per-user number there would be no
	 * way to name the entries to delete.
	 *
	 * @var string
	 */
	const USER_GENERATION_META = '_agend_content_access_facts_gen';

	/**
	 * The endpoint key every entry is stored under, and the transient prefix Core
	 * puts in front of it.
	 *
	 * Core's Cache tab "Clear" button deletes every transient whose name starts
	 * `agend_apps_crm_me_entitlements`, so building the key under that prefix means
	 * the button clears these entries too. It is a setting in Core 1.48.0 and later;
	 * on an older Core the key is simply not listed and nothing else changes.
	 *
	 * @var string
	 */
	const CACHE_ENDPOINT   = 'crm_me_entitlements';
	const TRANSIENT_PREFIX = 'agend_apps_';

	/**
	 * Which fact an entry holds. Part of the key, so the membership entry and the
	 * segment entry for one member under one bearer are separate transients.
	 *
	 * @var string
	 */
	const FACT_MEMBER   = 'member';
	const FACT_SEGMENTS = 'segments';

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
	 * Per-request memo of the membership facts, keyed by WordPress user id.
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
	 * Per-request memo of the segment facts, on the same terms as `$memo`.
	 *
	 * @var array<int, array|null>
	 */
	private static array $segment_memo = array();

	/**
	 * Per-request memo of the bearer, keyed by WordPress user id.
	 *
	 * The bearer comes out of a filter chain that can read user meta and refresh a
	 * token, so it is asked once per user per request. Keyed by user id for the
	 * same reason the fact memos are: a request that switches user must not be
	 * handed the previous user's bearer.
	 *
	 * @var array<int, string>
	 */
	private static array $bearer_memo = array();

	/**
	 * Per-request memo of each user's own cache generation, read from user meta
	 * once per user per request.
	 *
	 * @var array<int, int>
	 */
	private static array $user_generation_memo = array();

	/**
	 * Resets the per-request memos. Tests, and `bump_generation()`.
	 */
	public static function reset(): void {
		self::$memo                 = array();
		self::$segment_memo         = array();
		self::$bearer_memo          = array();
		self::$user_generation_memo = array();
	}

	/**
	 * The bearer the current visitor's gateway calls are made under, or '' when
	 * there is none.
	 *
	 * Asked of `agend_apps_get_bearer_token()` once per user per request, and only
	 * when Core provides it. It is the identity every cached fact is bound to, and
	 * the document path's definition of "identified", so both read it here and
	 * cannot disagree about it within a request.
	 *
	 * @return string
	 */
	public static function bearer(): string {
		$user_id = (int) get_current_user_id();

		if ( ! array_key_exists( $user_id, self::$bearer_memo ) ) {
			self::$bearer_memo[ $user_id ] = function_exists( 'agend_apps_get_bearer_token' )
				? (string) agend_apps_get_bearer_token()
				: '';
		}

		return self::$bearer_memo[ $user_id ];
	}

	/**
	 * A short, non-reversible tag for a bearer, safe to put in a cache key or a
	 * memo key. '' for no bearer.
	 *
	 * The same form as Core's `identity_cache_suffix()`: the first 16 hex digits of
	 * a SHA-256. Truncated for key length only. The token never appears in a key.
	 *
	 * @param string $bearer Bearer token.
	 * @return string
	 */
	public static function bearer_digest( string $bearer ): string {
		return '' === $bearer ? '' : substr( hash( 'sha256', $bearer ), 0, 16 );
	}

	/**
	 * The current visitor's facts from Agend, or null when they cannot be known.
	 *
	 * Null covers every failure: the Core wrapper is missing (an older Core), the
	 * gateway returned an error, or the response was a 2xx that is not the
	 * documented answer (see `entitlements_data()`). The caller decides what
	 * unknown means. This method never answers "holds nothing" for
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

		// Cached only for a real user holding a bearer. See the class docblock: a
		// bearer can arrive without a login, and one key would then serve every
		// such visitor; and without a bearer there is no identity to key on, so a
		// stale entry from an earlier session must not be read.
		$bearer    = $user_id > 0 ? self::bearer() : '';
		$cacheable = $user_id > 0 && '' !== $bearer;

		// The key is built ONCE, before the gateway is asked, and used for both
		// the read and the write. Reading the generation again at write time
		// would let a bump that lands mid-request store pre-change data under
		// the new generation, which is exactly the entry the bump meant to kill.
		$key = $cacheable ? self::member_cache_key( $user_id, $bearer ) : '';

		if ( $cacheable ) {
			$cached = self::cached_member_facts( get_transient( $key ) );

			if ( null !== $cached ) {
				return $cached;
			}
		}

		$response = agend_apps_crm_get_my_entitlements();

		if ( is_wp_error( $response ) ) {
			// Deliberately NOT cached. Caching a failure would extend one blip
			// into a TTL-long outage for that member.
			return null;
		}

		$data = self::entitlements_data( $response );

		if ( null === $data ) {
			// A 2xx that is not the documented answer. Unknown, and not cached:
			// normalising it would read it as "holds nothing", which on an
			// inverted rule discloses rather than denies.
			return null;
		}

		$facts = self::normalise( $data );

		if ( $cacheable ) {
			$ttl = self::entry_ttl( $facts['entitlement_expires_at'] );

			// A TTL of 0 is "do not store", not a lifetime: WordPress reads a
			// transient expiry of 0 as "never expires".
			if ( $ttl > 0 ) {
				set_transient( $key, $facts, $ttl );
			}
		}

		return $facts;
	}

	/**
	 * The current visitor's segments from Agend, or null when they cannot be known.
	 *
	 * Fetched ON DEMAND and only by a caller that needs them: a `segments`
	 * policy being evaluated, or a segment display condition. Reads
	 * `GET /v1/crm/me/segments`, which answers for the bearer alone. The admin
	 * `/crm/segments/{id}/contacts` route is deliberately not used: it lists who
	 * is in a segment, so answering a question about one visitor with it would
	 * pull the tenant's whole member list onto this server.
	 *
	 * Both ids and slugs are kept. A policy names a segment by id, which
	 * survives a rename; stored display conditions name it by slug, for
	 * compatibility with existing ESAC data.
	 *
	 * Null covers every failure, exactly as it does for `member()`: the Core
	 * wrapper is missing, the gateway returned an error, or the response was a 2xx
	 * that is not the documented answer (see `segments_data()`). It is never
	 * "belongs to no segments", because that is a different claim and a segment
	 * rule may be inverted.
	 *
	 * Like `member()` this does not decide whether the visitor is signed in.
	 * Each caller applies its own rule for that and does not call this for a
	 * visitor it already knows holds nothing.
	 *
	 * @return array{segment_ids: string[], segment_slugs: string[]}|null
	 */
	public static function segments(): ?array {
		$user_id = (int) get_current_user_id();

		if ( array_key_exists( $user_id, self::$segment_memo ) ) {
			return self::$segment_memo[ $user_id ];
		}

		$facts = self::resolve_segments( $user_id );

		self::$segment_memo[ $user_id ] = $facts;

		return $facts;
	}

	/**
	 * Resolves a user's segments: transient first, then the gateway.
	 *
	 * Follows the same rules as `resolve()`: nothing is read or cached for user 0
	 * or without a bearer, the key is built once before the call, and a failure is
	 * never cached.
	 *
	 * @param int $user_id WordPress user id, 0 when none.
	 * @return array{segment_ids: string[], segment_slugs: string[]}|null
	 */
	private static function resolve_segments( int $user_id ): ?array {
		if ( ! function_exists( 'agend_apps_crm_get_my_segments' ) ) {
			// Unknown, not "in no segments". See `resolve()`.
			return null;
		}

		$bearer    = $user_id > 0 ? self::bearer() : '';
		$cacheable = $user_id > 0 && '' !== $bearer;
		$key       = $cacheable ? self::segment_cache_key( $user_id, $bearer ) : '';

		if ( $cacheable ) {
			$cached = self::cached_segment_facts( get_transient( $key ) );

			if ( null !== $cached ) {
				return $cached;
			}
		}

		$response = agend_apps_crm_get_my_segments();

		if ( is_wp_error( $response ) ) {
			// Not cached: a blip must not become a TTL-long outage.
			return null;
		}

		$rows = self::segments_data( $response );

		if ( null === $rows ) {
			// Not the documented answer. Unknown, never "in no segments". See
			// `resolve()`.
			return null;
		}

		$facts = array(
			'segment_ids'   => array(),
			'segment_slugs' => array(),
		);

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( isset( $row['id'] ) && is_string( $row['id'] ) && '' !== $row['id'] ) {
				$facts['segment_ids'][] = $row['id'];
			}

			if ( isset( $row['slug'] ) && '' !== (string) $row['slug'] ) {
				$facts['segment_slugs'][] = (string) $row['slug'];
			}
		}

		$facts['segment_ids']   = array_values( array_unique( $facts['segment_ids'] ) );
		$facts['segment_slugs'] = array_values( array_unique( $facts['segment_slugs'] ) );

		if ( $cacheable ) {
			set_transient( $key, $facts, self::cache_ttl() );
		}

		return $facts;
	}

	/**
	 * A cached membership entry, validated field by field, or null for a miss.
	 *
	 * The transient store is not ours alone: another plugin, a manual edit or an
	 * older release can leave anything under a key. A field of the wrong type is
	 * not repaired or skipped, because "skipped" is a guess about what the entry
	 * meant. It makes the entry a miss and the gateway is asked. This also keeps a
	 * malformed field from reaching an adapter, where a non-array
	 * `entitlement_keys` would be a TypeError.
	 *
	 * A field that is ABSENT is completed with "holds nothing", which can only
	 * deny, as it always was for an entry written with fewer fields.
	 *
	 * @param mixed $cached Value read from the transient.
	 * @return array|null Facts in the full shape, or null when malformed.
	 */
	private static function cached_member_facts( $cached ): ?array {
		if ( ! is_array( $cached ) ) {
			return null;
		}

		$facts = self::empty_facts();

		if ( array_key_exists( 'is_member', $cached ) ) {
			if ( ! is_bool( $cached['is_member'] ) ) {
				return null;
			}

			$facts['is_member'] = $cached['is_member'];
		}

		foreach ( array( 'tier_ids', 'tier_slugs', 'entitlement_keys' ) as $field ) {
			if ( ! array_key_exists( $field, $cached ) ) {
				continue;
			}

			if ( ! self::is_string_list( $cached[ $field ] ) ) {
				return null;
			}

			$facts[ $field ] = $cached[ $field ];
		}

		if ( array_key_exists( 'entitlement_expires_at', $cached ) ) {
			if ( null !== $cached['entitlement_expires_at'] && ! is_string( $cached['entitlement_expires_at'] ) ) {
				return null;
			}

			$facts['entitlement_expires_at'] = $cached['entitlement_expires_at'];
		}

		return $facts;
	}

	/**
	 * A cached segment entry, validated the same way, or null for a miss.
	 *
	 * Both lists must be present: the entry used to hold a bare list of slugs, and
	 * one written before an upgrade would have no ids. Treating it as "no ids"
	 * would deny a policy the visitor satisfies for up to the cache lifetime.
	 *
	 * @param mixed $cached Value read from the transient.
	 * @return array{segment_ids: string[], segment_slugs: string[]}|null
	 */
	private static function cached_segment_facts( $cached ): ?array {
		if ( ! is_array( $cached )
			|| ! isset( $cached['segment_ids'], $cached['segment_slugs'] )
			|| ! self::is_string_list( $cached['segment_ids'] )
			|| ! self::is_string_list( $cached['segment_slugs'] ) ) {
			return null;
		}

		return array(
			'segment_ids'   => $cached['segment_ids'],
			'segment_slugs' => $cached['segment_slugs'],
		);
	}

	/**
	 * Whether a value is a list of non-empty strings. An empty list is.
	 *
	 * @param mixed $value Candidate list.
	 * @return bool
	 */
	private static function is_string_list( $value ): bool {
		if ( ! is_array( $value ) || ! self::is_list( $value ) ) {
			return false;
		}

		foreach ( $value as $item ) {
			if ( ! is_string( $item ) || '' === $item ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The `data` of an entitlements response, or null when it is not the
	 * documented answer.
	 *
	 * A 2xx status says the request was answered, not that the body is the answer.
	 * Core hands back the decoded body as it is, so a `{"success": false}` sent
	 * with a 200, an empty array (Core's result for a 204), or a body with no
	 * member standing in it all arrive here looking like successes. Normalising
	 * any of them reads as "not a member", which is cached and, on an inverted
	 * rule, passes. So the answer is accepted only in the documented shape: no
	 * `success` flag, or one that is `true`; an array `data`; and an array
	 * `tier_ids` inside it, which the gateway always sends, empty for a
	 * non-member. A body with no `data` envelope is not read as though it were the
	 * data, because that is exactly how an empty body would pass.
	 *
	 * @param mixed $response Return value of `agend_apps_crm_get_my_entitlements()`.
	 * @return array|null
	 */
	private static function entitlements_data( $response ): ?array {
		$data = self::envelope_data( $response );

		if ( null === $data || ! isset( $data['tier_ids'] ) || ! is_array( $data['tier_ids'] ) ) {
			return null;
		}

		return $data;
	}

	/**
	 * The rows of a segments response, or null when it is not the documented
	 * answer.
	 *
	 * Same rule as `entitlements_data()`, with a list for `data`: an empty list is
	 * the genuine "in no segments", while a `data` that is null, an object or a
	 * scalar is not an answer at all.
	 *
	 * @param mixed $response Return value of `agend_apps_crm_get_my_segments()`.
	 * @return array|null
	 */
	private static function segments_data( $response ): ?array {
		$rows = self::envelope_data( $response );

		return null !== $rows && self::is_list( $rows ) ? $rows : null;
	}

	/**
	 * Whether an array is a list: keys 0..n-1 in order. An empty array is.
	 * (`array_is_list()` needs PHP 8.1.)
	 *
	 * @param array $value Candidate list.
	 * @return bool
	 */
	private static function is_list( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * The array under `data` of a gateway envelope, or null.
	 *
	 * Fails closed on the `success` flag: when it is present it must be `true`.
	 *
	 * @param mixed $response Decoded response.
	 * @return array|null
	 */
	private static function envelope_data( $response ): ?array {
		if ( ! is_array( $response ) ) {
			return null;
		}

		if ( array_key_exists( 'success', $response ) && true !== $response['success'] ) {
			return null;
		}

		return isset( $response['data'] ) && is_array( $response['data'] )
			? $response['data']
			: null;
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
	 * They feed `selected_entitlements` policies and the entitlements display
	 * condition.
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
	 * @return int Seconds, always at least 1.
	 */
	public static function cache_ttl(): int {
		$ttl = 5 * MINUTE_IN_SECONDS;

		if ( class_exists( 'Agend_Apps_Settings' ) ) {
			$configured = (int) Agend_Apps_Settings::get_cache_ttl( 'crm_me_entitlements' );

			if ( $configured > 0 ) {
				$ttl = $configured;
			}
		}

		return $ttl;
	}

	/**
	 * How long an entry holding these facts may be stored: `cache_ttl()`, cut so
	 * the entry does not outlive the earliest entitlement expiry the gateway
	 * reported.
	 *
	 * 0 means DO NOT STORE, and the caller must not pass it on as a lifetime,
	 * because WordPress reads a transient expiry of 0 as "never expires". It is
	 * returned when the expiry has already passed or is less than a second away.
	 * There is no floor: an earlier version held such an entry for at least 30
	 * seconds so a grant about to expire was not re-read on every request, which
	 * kept an already-expired entitlement in the cache. Re-reading it is the
	 * correct cost of that grant ending.
	 *
	 * An expiry that is not a readable ISO-8601 date-time is treated as no expiry.
	 *
	 * @param string|null $expires_at Earliest entitlement expiry, ISO-8601, or null.
	 * @return int Seconds, or 0 for "do not store".
	 */
	public static function entry_ttl( ?string $expires_at ): int {
		$ttl     = self::cache_ttl();
		$expires = self::timestamp( $expires_at );

		if ( null === $expires ) {
			return $ttl;
		}

		$remaining = $expires - time();

		return $remaining < 1 ? 0 : min( $ttl, $remaining );
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
	 * A user's own cache generation, read once per request.
	 *
	 * @param int $user_id WordPress user id.
	 * @return int
	 */
	private static function user_generation( int $user_id ): int {
		if ( ! array_key_exists( $user_id, self::$user_generation_memo ) ) {
			self::$user_generation_memo[ $user_id ] = self::stored_user_generation( $user_id );
		}

		return self::$user_generation_memo[ $user_id ];
	}

	/**
	 * A user's own cache generation as stored, bypassing the request memo.
	 *
	 * Anything that is not a non-negative integer reads as 0.
	 *
	 * @param int $user_id WordPress user id.
	 * @return int
	 */
	private static function stored_user_generation( int $user_id ): int {
		return max( 0, (int) get_user_meta( $user_id, self::USER_GENERATION_META, true ) );
	}

	/**
	 * Transient key for a member's membership facts.
	 *
	 * Binds the entry to the WordPress user id (one member's facts are never
	 * served to another), to the bearer it was fetched under (a different bearer
	 * for the same user is a miss, never someone else's answer), and to both
	 * generations (a global bump or a per-user flush makes every older entry
	 * unreachable).
	 *
	 * @param int    $user_id WordPress user id.
	 * @param string $bearer  The bearer the facts are fetched with. Never '' for a cached entry.
	 * @return string
	 */
	public static function member_cache_key( int $user_id, string $bearer ): string {
		return self::cache_key( self::FACT_MEMBER, $user_id, $bearer );
	}

	/**
	 * Transient key for a member's segment facts, on the same terms.
	 *
	 * @param int    $user_id WordPress user id.
	 * @param string $bearer  The bearer the facts are fetched with.
	 * @return string
	 */
	public static function segment_cache_key( int $user_id, string $bearer ): string {
		return self::cache_key( self::FACT_SEGMENTS, $user_id, $bearer );
	}

	/**
	 * The transient name for one fact of one user under one bearer.
	 *
	 * Built through Core's `Agend_Apps_Cache::build_key()` whenever it exists, so
	 * the name is `agend_apps_crm_me_entitlements_<hash>` and Core's Cache tab
	 * "Clear" button, which deletes transients by that prefix, clears these entries
	 * too. When it does not exist the same shape is produced locally, so the prefix
	 * (and therefore the Clear button) works the same either way.
	 *
	 * The hash is over every input that decides who may read the entry. The bearer
	 * enters as its digest only.
	 *
	 * @param string $fact    FACT_MEMBER or FACT_SEGMENTS.
	 * @param int    $user_id WordPress user id.
	 * @param string $bearer  Bearer token.
	 * @return string
	 */
	private static function cache_key( string $fact, int $user_id, string $bearer ): string {
		$parts = array(
			'fact'     => $fact,
			'user'     => $user_id,
			'bearer'   => self::bearer_digest( $bearer ),
			'gen'      => self::generation(),
			'user_gen' => self::user_generation( $user_id ),
		);

		if ( class_exists( 'Agend_Apps_Cache' ) && method_exists( 'Agend_Apps_Cache', 'build_key' ) ) {
			return self::TRANSIENT_PREFIX . Agend_Apps_Cache::build_key( self::CACHE_ENDPOINT, $parts );
		}

		return self::fallback_key( $parts );
	}

	/**
	 * The key shape Core's `build_key()` produces, for a Core that predates it.
	 *
	 * Same prefix and the same hash formula, so an entry is findable by Core's
	 * prefix-based clear whichever path built it.
	 *
	 * @param array $parts Everything that decides who may read the entry.
	 * @return string
	 */
	private static function fallback_key( array $parts ): string {
		return self::TRANSIENT_PREFIX . self::CACHE_ENDPOINT . '_' . md5( self::CACHE_ENDPOINT . serialize( $parts ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	/**
	 * Forgets everything cached for one member, whatever bearer it was fetched
	 * under, and drops their entries from the request memos.
	 *
	 * Called when a change for that member arrives, so an upgrade or a lapse
	 * takes effect without waiting out the TTL. The caller is a webhook, which has
	 * no bearer, and the bearer is part of every key, so the entries cannot be
	 * named to delete them. Instead the member's own generation is incremented, and
	 * it is in every key, so every existing entry for that member (membership and
	 * segments, under any bearer) stops being found. The orphaned entries expire on
	 * their own, within the cache lifetime.
	 *
	 * Two flushes racing can land on the same number. That is harmless: either one
	 * is already different from every key written before it.
	 *
	 * @param int $user_id WordPress user id.
	 */
	public static function flush( int $user_id ): void {
		if ( $user_id > 0 ) {
			// Read from storage, not the request memo: another request may have
			// flushed since this one first looked.
			$next = self::stored_user_generation( $user_id ) + 1;

			update_user_meta( $user_id, self::USER_GENERATION_META, $next );

			self::$user_generation_memo[ $user_id ] = $next;
		}

		unset( self::$memo[ $user_id ], self::$segment_memo[ $user_id ], self::$bearer_memo[ $user_id ] );
	}
}

<?php
/**
 * Access policy value object.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses, validates and serialises an access policy.
 *
 * The canonical shapes, which must stay in lockstep with the two other places
 * the same rules are expressed:
 *
 *   - the `cms_content_items_access_policy_valid` CHECK constraint
 *     (migration 20260727093300)
 *   - `parseAccessPolicy()` in `@agend/cms` `policy/fragments.ts`
 *
 * Three implementations of one rule set is a real duplication risk. It is
 * accepted because each sits on a different side of a trust boundary and none
 * can call the others: the database must reject a bad row whatever wrote it,
 * the gateway must not trust what it reads back, and WordPress must not send a
 * shape the gateway will reject. If the shapes ever diverge, the database wins
 * and the others are wrong.
 *
 *   {"mode":"public"}
 *   {"mode":"active_member"}
 *   {"mode":"selected_tiers","tier_ids":["<uuid>", ...]}                at least one
 *   {"mode":"segments","segment_ids":["<uuid>", ...]}                  at least one
 *   {"mode":"selected_entitlements","entitlement_keys":["<gate_key>", ...]}  at least one
 *
 * The last two are the gateway's `segments` and `selected_entitlements` arms,
 * copied from `@agend/access-policy` (`types.ts` and `fragments.ts`), which is
 * the authority for what each means. A segment is an arbitrary audience over
 * ALL contacts and an entitlement can be granted to someone with no
 * membership, so neither is a membership concept and neither is a subset of
 * "all active members". The gateway's `authenticated` arm is not modelled here
 * and stays unparseable: a value we do not understand restricts.
 *
 * Anything else is invalid. Invalid never means public: a policy we cannot read
 * is not evidence that the content is unrestricted (Decision 2.12).
 */
class Agend_Content_Access_Policy {

	/**
	 * Post meta key. Underscore-prefixed so WordPress treats it as protected
	 * and hides it from the custom-fields UI.
	 *
	 * @var string
	 */
	const META_KEY = '_agend_access_policy';

	const MODE_PUBLIC       = 'public';
	const MODE_MEMBERS      = 'active_member';
	const MODE_TIERS        = 'selected_tiers';
	const MODE_SEGMENTS     = 'segments';
	const MODE_ENTITLEMENTS = 'selected_entitlements';

	/**
	 * Accepted modes, in the order the editor presents them.
	 *
	 * @var string[]
	 */
	const MODES = array(
		self::MODE_PUBLIC,
		self::MODE_MEMBERS,
		self::MODE_TIERS,
		self::MODE_SEGMENTS,
		self::MODE_ENTITLEMENTS,
	);

	/**
	 * The set-modes: the modes whose policy is a list of things the visitor
	 * must hold at least one of, mapped to the key that carries the list.
	 *
	 * One table drives parsing, intersection and the accessors, so a fourth
	 * set-mode is one entry here rather than another branch in each of them.
	 *
	 * @var array<string, string>
	 */
	const SET_MODE_KEYS = array(
		self::MODE_TIERS        => 'tier_ids',
		self::MODE_SEGMENTS     => 'segment_ids',
		self::MODE_ENTITLEMENTS => 'entitlement_keys',
	);

	/**
	 * Canonical UUID shape. Deliberately the same expression as the database
	 * CHECK, hex being case insensitive.
	 *
	 * @var string
	 */
	const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D';

	/**
	 * An entitlement key: the `crm_benefits.gate_key` lexical shape, mirrored
	 * from its CHECK constraint and from `GATE_KEY_RE` in `@agend/access-policy`.
	 *
	 * Policies name an entitlement by key, not by row id, because the key
	 * survives a benefit being re-created.
	 *
	 * The `D` modifier matters on both this and the UUID pattern. Without it `$`
	 * also matches before a trailing newline, so `"key\n"` would parse here and
	 * be rejected by the database and the gateway, whose anchors do not.
	 *
	 * @var string
	 */
	const ENTITLEMENT_KEY_PATTERN = '/^[a-z][a-z0-9_.]{1,62}[a-z0-9]$/D';

	/**
	 * Fragment settings keys: the audience half of the editor-neutral settings
	 * array.
	 *
	 * An editor driver (Elementor today, the block editor next) stores a
	 * fragment's audience as a flat settings array under these keys, and
	 * `from_fragment_settings()` is the single reader of it. The display
	 * condition half of the same array is keyed by the constants on
	 * `Agend_Content_Access_Fragment_Rules`. The key names and the `'yes'`
	 * toggle values are Elementor-shaped because Elementor wrote them first and
	 * every stored document uses them; they are the neutral format now, so a
	 * second editor converts INTO them rather than inventing its own.
	 *
	 * @var string
	 */
	const FRAGMENT_MODE_KEY         = 'agend_access_mode';
	const FRAGMENT_TIERS_KEY        = 'agend_access_tier_ids';
	const FRAGMENT_SEGMENTS_KEY     = 'agend_access_segment_ids';
	const FRAGMENT_ENTITLEMENTS_KEY = 'agend_access_entitlement_keys';

	/**
	 * Parses an arbitrary value into a valid policy, or null.
	 *
	 * As strict as the database CHECK, including rejecting an unrecognised
	 * extra top-level key, so a typo or a smuggled field cannot ride along
	 * beside a valid mode.
	 *
	 * @param mixed $value Candidate policy.
	 * @return array|null Canonical policy array, or null when invalid.
	 */
	public static function parse( $value ): ?array {
		if ( ! is_array( $value ) || ! isset( $value['mode'] ) ) {
			return null;
		}

		$mode = $value['mode'];
		$keys = array_keys( $value );

		if ( self::MODE_PUBLIC === $mode || self::MODE_MEMBERS === $mode ) {
			return array( 'mode' ) === $keys ? array( 'mode' => $mode ) : null;
		}

		if ( ! is_string( $mode ) || ! isset( self::SET_MODE_KEYS[ $mode ] ) ) {
			return null;
		}

		$list_key = self::SET_MODE_KEYS[ $mode ];

		sort( $keys );
		$expected = array( 'mode', $list_key );
		sort( $expected );

		if ( $expected !== $keys ) {
			return null;
		}

		$list = self::valid_list( $value[ $list_key ], self::pattern_for( $mode ) );

		if ( null === $list ) {
			return null;
		}

		return array(
			'mode'    => $mode,
			$list_key => $list,
		);
	}

	/**
	 * The lexical pattern each set-mode's entries must match.
	 *
	 * @param string $mode A key of SET_MODE_KEYS.
	 * @return string
	 */
	private static function pattern_for( string $mode ): string {
		return self::MODE_ENTITLEMENTS === $mode
			? self::ENTITLEMENT_KEY_PATTERN
			: self::UUID_PATTERN;
	}

	/**
	 * A set-mode's list when it is a non-empty LIST of entries that all match
	 * the pattern, otherwise null.
	 *
	 * @param mixed  $list    Candidate list.
	 * @param string $pattern Pattern every entry must match.
	 * @return string[]|null
	 */
	private static function valid_list( $list, string $pattern ): ?array {
		if ( ! is_array( $list ) || array() === $list ) {
			return null;
		}

		// A list, not a map: json_encode would otherwise emit an object and the
		// gateway would reject the shape.
		if ( array_keys( $list ) !== range( 0, count( $list ) - 1 ) ) {
			return null;
		}

		foreach ( $list as $entry ) {
			if ( ! is_string( $entry ) || 1 !== preg_match( $pattern, $entry ) ) {
				return null;
			}
		}

		return array_values( $list );
	}

	/**
	 * Reads the stored policy for a post.
	 *
	 * A stored value that no longer parses returns null, which the editor
	 * surfaces as "no policy set" so it can be repaired. It is never coerced
	 * into a mode nobody chose.
	 *
	 * @param int $post_id Post id.
	 * @return array|null
	 */
	public static function get_for_post( int $post_id ): ?array {
		$stored = get_post_meta( $post_id, self::META_KEY, true );

		return self::parse( $stored );
	}

	/**
	 * Persists a policy for a post, or removes it.
	 *
	 * @param int        $post_id Post id.
	 * @param array|null $policy  Canonical policy, or null to clear.
	 * @return bool True when the stored value changed or was already correct.
	 */
	public static function save_for_post( int $post_id, ?array $policy ): bool {
		if ( null === $policy ) {
			return (bool) delete_post_meta( $post_id, self::META_KEY );
		}

		$parsed = self::parse( $policy );

		if ( null === $parsed ) {
			// Refuse rather than store something the gateway will reject at
			// ingestion. The caller keeps its previous policy.
			return false;
		}

		update_post_meta( $post_id, self::META_KEY, $parsed );

		return true;
	}

	/**
	 * Builds a policy from raw editor input.
	 *
	 * Returns the policy and a human-readable error, exactly one of which is
	 * non-null. The error is what the panel shows inline; the caller must not
	 * save when it is set (US-3.2 criterion 4).
	 *
	 * The third argument carries the selections for the two newer set-modes,
	 * keyed `segment_ids` and `entitlement_keys`. It is optional so the panel's
	 * existing two-argument call keeps working, and a missing key reads as
	 * nothing selected, which refuses the save rather than guessing.
	 *
	 * @param string                  $mode       Submitted mode.
	 * @param string[]                $tier_ids   Submitted tier ids, unfiltered.
	 * @param array<string, string[]> $selections Optional `segment_ids` and `entitlement_keys`, unfiltered.
	 * @return array{policy: array|null, error: string|null}
	 */
	public static function from_input( string $mode, array $tier_ids, array $selections = array() ): array {
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return array(
				'policy' => null,
				'error'  => __( 'Choose who can read this content.', 'agend-content-access' ),
			);
		}

		if ( ! isset( self::SET_MODE_KEYS[ $mode ] ) ) {
			return array(
				'policy' => array( 'mode' => $mode ),
				'error'  => null,
			);
		}

		$list_key = self::SET_MODE_KEYS[ $mode ];
		$raw      = self::MODE_TIERS === $mode
			? $tier_ids
			: ( isset( $selections[ $list_key ] ) && is_array( $selections[ $list_key ] ) ? $selections[ $list_key ] : array() );
		$clean    = self::clean_list( $raw, self::pattern_for( $mode ) );

		if ( array() === $clean ) {
			// Saving a set-mode with nothing selected is the single most
			// dangerous input this panel can receive: read charitably it means
			// "restrict to nobody", read carelessly it becomes "restrict to
			// nothing", which is public. Reject it and keep the old policy.
			return array(
				'policy' => null,
				'error'  => self::nothing_selected_message( $mode ),
			);
		}

		return array(
			'policy' => array(
				'mode'    => $mode,
				$list_key => $clean,
			),
			'error'  => null,
		);
	}

	/**
	 * The entries of a submitted list that are well formed, trimmed and unique.
	 *
	 * Used for editor input and for stored fragment settings alike. Dropping
	 * what does not match can only make the list shorter, which can only make
	 * the policy narrower.
	 *
	 * @param array  $raw     Submitted entries, unfiltered.
	 * @param string $pattern Pattern every kept entry must match.
	 * @return string[]
	 */
	private static function clean_list( array $raw, string $pattern ): array {
		$clean = array();

		foreach ( $raw as $entry ) {
			$entry = is_string( $entry ) ? trim( $entry ) : '';

			if ( 1 === preg_match( $pattern, $entry ) && ! in_array( $entry, $clean, true ) ) {
				$clean[] = $entry;
			}
		}

		return $clean;
	}

	/**
	 * The refusal shown when a set-mode is chosen with nothing selected.
	 *
	 * @param string $mode A key of SET_MODE_KEYS.
	 * @return string
	 */
	private static function nothing_selected_message( string $mode ): string {
		switch ( $mode ) {
			case self::MODE_SEGMENTS:
				return __(
					'Select at least one segment, or choose a different audience. Your previous setting has been kept.',
					'agend-content-access'
				);
			case self::MODE_ENTITLEMENTS:
				return __(
					'Select at least one entitlement, or choose a different audience. Your previous setting has been kept.',
					'agend-content-access'
				);
			default:
				return __(
					'Select at least one membership plan, or choose a different audience. Your previous setting has been kept.',
					'agend-content-access'
				);
		}
	}

	/**
	 * Reads a fragment's own policy from its settings array.
	 *
	 * Neutral by design: this takes the flat settings array an editor driver
	 * hands over and knows nothing about which editor wrote it. It was an
	 * Elementor method that happened to be pure policy validation, so it lives
	 * here with the other shapes and the same fail-closed rules.
	 *
	 * Fails closed: an unrecognised mode, or any set-mode with nothing valid
	 * selected, both yield a policy nobody satisfies rather than being ignored.
	 * The experiment this replaces treated both as "no condition" and rendered
	 * the element to everyone.
	 *
	 * @param array $settings Fragment settings, keyed by the FRAGMENT_*_KEY constants.
	 * @return array|null Fragment policy, or null when the fragment opts out.
	 */
	public static function from_fragment_settings( array $settings ): ?array {
		$mode = isset( $settings[ self::FRAGMENT_MODE_KEY ] ) ? (string) $settings[ self::FRAGMENT_MODE_KEY ] : '';

		if ( '' === $mode || self::MODE_INHERIT === $mode ) {
			return null;
		}

		if ( self::MODE_MEMBERS === $mode ) {
			return array( 'mode' => self::MODE_MEMBERS );
		}

		$settings_keys = array(
			self::MODE_TIERS        => self::FRAGMENT_TIERS_KEY,
			self::MODE_SEGMENTS     => self::FRAGMENT_SEGMENTS_KEY,
			self::MODE_ENTITLEMENTS => self::FRAGMENT_ENTITLEMENTS_KEY,
		);

		if ( isset( $settings_keys[ $mode ] ) ) {
			$raw = isset( $settings[ $settings_keys[ $mode ] ] ) && is_array( $settings[ $settings_keys[ $mode ] ] )
				? $settings[ $settings_keys[ $mode ] ]
				: array();

			// An empty list here is NOT "no restriction". The editor asked for
			// a set-mode, so the honest reading is a restriction nobody
			// satisfies, which hides the element until the policy is repaired.
			return array(
				'mode'                       => $mode,
				self::SET_MODE_KEYS[ $mode ] => self::clean_list( $raw, self::pattern_for( $mode ) ),
			);
		}

		// An unrecognised mode is a policy we cannot honour. Treat it as
		// members-only rather than ignoring it.
		return array( 'mode' => self::MODE_MEMBERS );
	}

	/**
	 * Fragment-only mode: defer entirely to the document.
	 *
	 * @var string
	 */
	const MODE_INHERIT = 'inherit';

	/**
	 * How restrictive a mode is, for picking the narrower of two policies.
	 *
	 * The three set-modes rank EQUALLY, above active_member, as the gateway
	 * ranks them. A scalar rank cannot compare unlike sets, so the pairs it
	 * cannot express are decided before this is consulted: see
	 * `is_unintersectable()`.
	 *
	 * @param string $mode Mode.
	 * @return int
	 */
	private static function restrictiveness( string $mode ): int {
		switch ( $mode ) {
			case self::MODE_PUBLIC:
				return 0;
			case self::MODE_MEMBERS:
				return 1;
			case self::MODE_TIERS:
			case self::MODE_SEGMENTS:
			case self::MODE_ENTITLEMENTS:
				return 2;
			default:
				// An unrecognised mode is treated as maximally restrictive so it
				// can never widen anything by being unreadable.
				return 3;
		}
	}

	/**
	 * Whether two modes cannot be intersected, so their composition must deny.
	 *
	 * Two cases, both from the gateway's `effectivePolicy()`:
	 *
	 *   - two DIFFERENT set-modes, in either direction. Plans, segments and
	 *     entitlements are unlike sets, and ranking one above another could
	 *     broaden what the other allowed.
	 *   - active_member against segments or selected_entitlements, in either
	 *     direction. A segment selects over all contacts, and a direct grant
	 *     needs no membership, so neither set contains the other and
	 *     substituting one for the other could broaden.
	 *
	 * Plans against active_member is NOT in this list: every plan holder is an
	 * active member, so that pair has a genuine subset relationship and the
	 * ranking handles it.
	 *
	 * @param string $document_mode Document mode.
	 * @param string $fragment_mode Fragment mode.
	 * @return bool
	 */
	private static function is_unintersectable( string $document_mode, string $fragment_mode ): bool {
		$member_unsafe = array( self::MODE_SEGMENTS, self::MODE_ENTITLEMENTS );

		if ( isset( self::SET_MODE_KEYS[ $document_mode ], self::SET_MODE_KEYS[ $fragment_mode ] ) ) {
			return $document_mode !== $fragment_mode;
		}

		return ( self::MODE_MEMBERS === $document_mode && in_array( $fragment_mode, $member_unsafe, true ) )
			|| ( self::MODE_MEMBERS === $fragment_mode && in_array( $document_mode, $member_unsafe, true ) );
	}

	/**
	 * The effective policy of a fragment: the INTERSECTION of its document's
	 * policy and its own.
	 *
	 * A fragment may narrow its document's audience. It can never broaden it.
	 * This mirrors `effectivePolicy()` in `@agend/cms`, which is the same rule
	 * applied on the projection side, and the two must agree: WordPress decides
	 * what to render locally, Agend decides what to serve through the API, and a
	 * visitor must not see different things depending on which door they used.
	 *
	 * Two policies of the same set-mode intersect to the entries in BOTH: plans
	 * with plans, segments with segments, entitlement keys with entitlement
	 * keys. A disjoint intersection collapses to an EMPTY list, which nobody
	 * satisfies. That is the honest reading of "the page allows only A, this
	 * section allows only B": unsatisfiable, not "pick one".
	 *
	 * Pairs that cannot be intersected (see `is_unintersectable()`) collapse to
	 * the same unsatisfiable policy, an empty `selected_tiers`, which is the
	 * representation the gateway uses. Otherwise the narrower policy wins, and
	 * a tie keeps the document's.
	 *
	 * @param array|null $document Document policy, or null when none is set.
	 * @param array|null $fragment Fragment policy, or null.
	 * @return array|null Effective policy.
	 */
	public static function effective_policy( ?array $document, ?array $fragment ): ?array {
		$fragment_mode = $fragment['mode'] ?? self::MODE_INHERIT;

		if ( self::MODE_INHERIT === $fragment_mode || null === $fragment ) {
			return $document;
		}

		// No document policy means the document is public, so any fragment
		// policy is a narrowing and simply applies.
		if ( null === $document ) {
			return $fragment;
		}

		$document_mode = $document['mode'] ?? '';

		if ( $document_mode === $fragment_mode && isset( self::SET_MODE_KEYS[ $fragment_mode ] ) ) {
			$list_key = self::SET_MODE_KEYS[ $fragment_mode ];

			return array(
				'mode'    => $fragment_mode,
				$list_key => array_values(
					array_intersect(
						(array) ( $fragment[ $list_key ] ?? array() ),
						(array) ( $document[ $list_key ] ?? array() )
					)
				),
			);
		}

		if ( self::is_unintersectable( (string) $document_mode, (string) $fragment_mode ) ) {
			return array(
				'mode'     => self::MODE_TIERS,
				'tier_ids' => array(),
			);
		}

		return self::restrictiveness( (string) $fragment_mode ) > self::restrictiveness( (string) $document_mode )
			? $fragment
			: $document;
	}

	/**
	 * The tier ids a policy names, or an empty list for other modes.
	 *
	 * @param array|null $policy Policy.
	 * @return string[]
	 */
	public static function tier_ids( ?array $policy ): array {
		return self::list_for( $policy, self::MODE_TIERS );
	}

	/**
	 * The segment ids a policy names, or an empty list for other modes.
	 *
	 * @param array|null $policy Policy.
	 * @return string[]
	 */
	public static function segment_ids( ?array $policy ): array {
		return self::list_for( $policy, self::MODE_SEGMENTS );
	}

	/**
	 * The entitlement keys a policy names, or an empty list for other modes.
	 *
	 * @param array|null $policy Policy.
	 * @return string[]
	 */
	public static function entitlement_keys( ?array $policy ): array {
		return self::list_for( $policy, self::MODE_ENTITLEMENTS );
	}

	/**
	 * Whether evaluating a policy needs the visitor's segments.
	 *
	 * True only for a segments policy that names at least one segment. An empty
	 * list (a disjoint intersection) can never be satisfied, so asking Agend
	 * which segments the visitor is in would be a network call with a
	 * foregone answer. Segments are the one fact fetched on demand rather than
	 * with the member's standing, and this is what the decision layer asks
	 * before fetching them.
	 *
	 * @param array|null $policy Policy.
	 * @return bool
	 */
	public static function needs_segments( ?array $policy ): bool {
		return array() !== self::segment_ids( $policy );
	}

	/**
	 * The list a policy carries for one set-mode, or empty for any other mode.
	 *
	 * @param array|null $policy Policy.
	 * @param string     $mode   A key of SET_MODE_KEYS.
	 * @return string[]
	 */
	private static function list_for( ?array $policy, string $mode ): array {
		if ( null === $policy || $mode !== ( $policy['mode'] ?? '' ) ) {
			return array();
		}

		$list = $policy[ self::SET_MODE_KEYS[ $mode ] ] ?? array();

		return is_array( $list ) ? array_values( $list ) : array();
	}

	/**
	 * Human-readable label for a mode, for the editor and for list columns.
	 *
	 * @param string $mode Mode.
	 * @return string
	 */
	public static function label( string $mode ): string {
		switch ( $mode ) {
			case self::MODE_PUBLIC:
				return __( 'Public', 'agend-content-access' );
			case self::MODE_MEMBERS:
				return __( 'All active members', 'agend-content-access' );
			case self::MODE_TIERS:
				return __( 'Selected membership plans', 'agend-content-access' );
			case self::MODE_SEGMENTS:
				return __( 'Selected segments', 'agend-content-access' );
			case self::MODE_ENTITLEMENTS:
				return __( 'Selected entitlements', 'agend-content-access' );
			default:
				return __( 'Not set', 'agend-content-access' );
		}
	}
}

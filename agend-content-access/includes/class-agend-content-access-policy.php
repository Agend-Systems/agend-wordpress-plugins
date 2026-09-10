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
 *   {"mode":"selected_tiers","tier_ids":["<uuid>", ...]}   at least one
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

	const MODE_PUBLIC   = 'public';
	const MODE_MEMBERS  = 'active_member';
	const MODE_TIERS    = 'selected_tiers';

	/**
	 * Accepted modes, in the order the editor presents them.
	 *
	 * @var string[]
	 */
	const MODES = array( self::MODE_PUBLIC, self::MODE_MEMBERS, self::MODE_TIERS );

	/**
	 * Canonical UUID shape. Deliberately the same expression as the database
	 * CHECK, hex being case insensitive.
	 *
	 * @var string
	 */
	const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

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

		if ( self::MODE_TIERS !== $mode ) {
			return null;
		}

		sort( $keys );
		if ( array( 'mode', 'tier_ids' ) !== $keys ) {
			return null;
		}

		$tier_ids = $value['tier_ids'];

		if ( ! is_array( $tier_ids ) || array() === $tier_ids ) {
			return null;
		}

		// A list, not a map: json_encode would otherwise emit an object and the
		// gateway would reject the shape.
		if ( array_keys( $tier_ids ) !== range( 0, count( $tier_ids ) - 1 ) ) {
			return null;
		}

		foreach ( $tier_ids as $tier_id ) {
			if ( ! is_string( $tier_id ) || 1 !== preg_match( self::UUID_PATTERN, $tier_id ) ) {
				return null;
			}
		}

		return array(
			'mode'     => self::MODE_TIERS,
			'tier_ids' => array_values( $tier_ids ),
		);
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
	 * @param string   $mode     Submitted mode.
	 * @param string[] $tier_ids Submitted tier ids, unfiltered.
	 * @return array{policy: array|null, error: string|null}
	 */
	public static function from_input( string $mode, array $tier_ids ): array {
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return array(
				'policy' => null,
				'error'  => __( 'Choose who can read this content.', 'agend-content-access' ),
			);
		}

		if ( self::MODE_TIERS !== $mode ) {
			return array(
				'policy' => array( 'mode' => $mode ),
				'error'  => null,
			);
		}

		$clean = array();

		foreach ( $tier_ids as $tier_id ) {
			$tier_id = is_string( $tier_id ) ? trim( $tier_id ) : '';

			if ( 1 === preg_match( self::UUID_PATTERN, $tier_id ) && ! in_array( $tier_id, $clean, true ) ) {
				$clean[] = $tier_id;
			}
		}

		if ( array() === $clean ) {
			// Saving "selected plans" with nothing selected is the single most
			// dangerous input this panel can receive: read charitably it means
			// "restrict to nobody", read carelessly it becomes "restrict to
			// nothing", which is public. Reject it and keep the old policy.
			return array(
				'policy' => null,
				'error'  => __(
					'Select at least one membership plan, or choose a different audience. Your previous setting has been kept.',
					'agend-content-access'
				),
			);
		}

		return array(
			'policy' => array(
				'mode'     => self::MODE_TIERS,
				'tier_ids' => $clean,
			),
			'error'  => null,
		);
	}

	/**
	 * Fragment-only mode: defer entirely to the document.
	 *
	 * @var string
	 */
	const MODE_INHERIT = 'inherit';

	/**
	 * Builds a fragment policy from already-extracted editor values.
	 *
	 * Fails closed: an unrecognised mode, or selected-plans with nothing
	 * selected, both yield a policy nobody satisfies rather than being ignored.
	 * The experiment this replaces treated both as "no condition" and rendered
	 * the element to everyone.
	 *
	 * Takes plain values rather than an editor-shaped settings array: each
	 * editor stores these under its own attribute names, and none of them
	 * should have to fake another editor's shape to reuse this validation.
	 *
	 * @param string $mode     Submitted mode.
	 * @param array  $tier_ids Submitted tier ids, unfiltered.
	 * @return array|null Fragment policy, or null when the fragment opts out.
	 */
	public static function from_fragment_values( string $mode, array $tier_ids ): ?array {
		if ( '' === $mode || self::MODE_INHERIT === $mode ) {
			return null;
		}

		if ( self::MODE_MEMBERS === $mode ) {
			return array( 'mode' => self::MODE_MEMBERS );
		}

		if ( self::MODE_TIERS === $mode ) {
			$clean = array();

			foreach ( $tier_ids as $tier_id ) {
				$tier_id = is_string( $tier_id ) ? trim( $tier_id ) : '';

				if ( 1 === preg_match( self::UUID_PATTERN, $tier_id ) ) {
					$clean[] = $tier_id;
				}
			}

			// An empty list here is NOT "no restriction". The editor asked for
			// selected plans, so the honest reading is a restriction nobody
			// satisfies, which hides the element until the policy is repaired.
			return array(
				'mode'     => self::MODE_TIERS,
				'tier_ids' => array_values( array_unique( $clean ) ),
			);
		}

		// An unrecognised mode is a policy we cannot honour. Treat it as
		// members-only rather than ignoring it.
		return array( 'mode' => self::MODE_MEMBERS );
	}

	/**
	 * How restrictive a mode is, for picking the narrower of two policies.
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
				return 2;
			default:
				// An unrecognised mode is treated as maximally restrictive so it
				// can never widen anything by being unreadable.
				return 3;
		}
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
	 * Two selected-plans policies intersect to the tiers in BOTH. A disjoint
	 * intersection collapses to an EMPTY tier list, which nobody satisfies. That
	 * is the honest reading of "the page allows only A, this section allows only
	 * B": unsatisfiable, not "pick one".
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

		if ( self::MODE_TIERS === $document_mode && self::MODE_TIERS === $fragment_mode ) {
			$allowed = $document['tier_ids'] ?? array();

			return array(
				'mode'     => self::MODE_TIERS,
				'tier_ids' => array_values(
					array_intersect( $fragment['tier_ids'] ?? array(), $allowed )
				),
			);
		}

		return self::restrictiveness( $fragment_mode ) > self::restrictiveness( $document_mode )
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
		if ( null === $policy || self::MODE_TIERS !== ( $policy['mode'] ?? '' ) ) {
			return array();
		}

		return $policy['tier_ids'];
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
			default:
				return __( 'Not set', 'agend-content-access' );
		}
	}
}

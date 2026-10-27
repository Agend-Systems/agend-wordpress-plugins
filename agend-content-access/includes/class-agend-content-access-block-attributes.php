<?php
/**
 * The `agendAccess` block attribute, read as neutral fragment settings.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns the one attribute a block carries into the settings array every other
 * part of this plugin already reads.
 *
 * Every block may carry ONE non-sourced object attribute, `agendAccess`. Absent
 * or `{}` means "inherit the document, no conditions". The keys, all optional:
 *
 *   mode               string    inherit (default), active_member,
 *                                selected_tiers, segments, selected_entitlements
 *   tierIds            string[]  tier UUIDs for selected_tiers
 *   segmentIds         string[]  segment UUIDs for segments
 *   entitlementKeys    string[]  gate keys for selected_entitlements
 *   conditionsEnabled  bool      display conditions switched on
 *   conditions         string[]  "<provider>:<value>" strings
 *   conditionsMatch    string    any (default) or all
 *   conditionsInvert   bool      reverse the result
 *   fallbackId         int       post id of replacement content, 0 for none
 *
 * `to_settings()` is the single converter. It produces the flat, Elementor
 * shaped array that `Agend_Content_Access_Policy::from_fragment_settings()` and
 * `Agend_Content_Access_Fragment_Rules::conditions_pass()` read, so one function
 * validates a fragment's audience and one evaluates its conditions, whichever
 * editor wrote them. The exporter uses it today; render-time enforcement and
 * the editor panel read the same attribute through it, which is why it lives in
 * a class of its own rather than inside the exporter's driver.
 *
 * Nothing here decides anything. It reshapes, and where a value is unusable it
 * keeps that unusability visible to the reader that DOES decide, rather than
 * quietly turning it into a value that grants access:
 *
 *   - An unrecognised or non-string `mode` reaches `from_fragment_settings()`
 *     as an unrecognised mode, which that function fails closed on.
 *   - A non-string id is dropped from its list. That only ever narrows an
 *     audience, and an emptied list is a restriction nobody satisfies, not "no
 *     restriction".
 *   - A condition that is not a string is KEPT, so the condition engine reports
 *     it as malformed and denies. Dropping it would shrink the rule, which is
 *     the defect that engine documents in the reference implementation.
 */
class Agend_Content_Access_Block_Attributes {

	/**
	 * The attribute every block may carry.
	 *
	 * @var string
	 */
	const ATTRIBUTE = 'agendAccess';

	/**
	 * The synced pattern block, and the post type it points at.
	 *
	 * A `core/block` stores only a `ref` to a `wp_block` post, so its content is
	 * not in the page's `post_content` at all and has to be fetched.
	 *
	 * @var string
	 */
	const SYNCED_BLOCK     = 'core/block';
	const SYNCED_POST_TYPE = 'wp_block';

	/**
	 * Most synced patterns that may be open inside one another.
	 *
	 * A cycle is caught by the path of refs already being resolved; this stops a
	 * long chain of distinct patterns, which no real site builds and a hostile or
	 * broken one might.
	 *
	 * @var int
	 */
	const MAX_SYNCED_DEPTH = 10;

	/**
	 * What a mode that is not a string is reported as.
	 *
	 * Any string `from_fragment_settings()` does not recognise fails closed
	 * there. Dropping the value instead would read as "no mode", which is
	 * `inherit`, which is permissive.
	 *
	 * @var string
	 */
	private const UNRECOGNISED_MODE = 'unrecognised';

	/**
	 * Settings keys for the two audience lists the segments and entitlements
	 * layer adds.
	 *
	 * Spelled out here because the constants for them live on
	 * `Agend_Content_Access_Policy` in a sibling layer that is rebased under
	 * this one. Once both are in, these two literals should become those
	 * constants.
	 *
	 * @var array<string, string>
	 */
	private const SIBLING_LIST_KEYS = array(
		'segmentIds'      => 'agend_access_segment_ids',
		'entitlementKeys' => 'agend_access_entitlement_keys',
	);

	/**
	 * Converts an `agendAccess` object into neutral fragment settings.
	 *
	 * Only keys that are present and usable produce a setting, so an empty or
	 * absent attribute converts to an empty array, which every reader treats as
	 * "inherit, no conditions".
	 *
	 * @param array $block_attrs The `agendAccess` attribute object.
	 * @return array Settings keyed by the `Agend_Content_Access_Policy::FRAGMENT_*`
	 *               and `Agend_Content_Access_Fragment_Rules::*_KEY` constants,
	 *               plus the two sibling list keys. Toggles are `'yes'` or `''`.
	 */
	public static function to_settings( array $block_attrs ): array {
		$settings = array();

		if ( isset( $block_attrs['mode'] ) ) {
			$settings[ Agend_Content_Access_Policy::FRAGMENT_MODE_KEY ] = is_string( $block_attrs['mode'] )
				? $block_attrs['mode']
				: self::UNRECOGNISED_MODE;
		}

		$lists = array_merge(
			array( 'tierIds' => Agend_Content_Access_Policy::FRAGMENT_TIERS_KEY ),
			self::SIBLING_LIST_KEYS
		);

		foreach ( $lists as $attribute => $key ) {
			if ( isset( $block_attrs[ $attribute ] ) && is_array( $block_attrs[ $attribute ] ) ) {
				$settings[ $key ] = self::strings( $block_attrs[ $attribute ] );
			}
		}

		if ( isset( $block_attrs['conditionsEnabled'] ) ) {
			$settings[ Agend_Content_Access_Fragment_Rules::ENABLED_KEY ] = self::toggle( $block_attrs['conditionsEnabled'] );
		}

		if ( isset( $block_attrs['conditions'] ) ) {
			// A list is passed through UNFILTERED, non-strings included: the
			// condition engine reports each as malformed and denies. A value
			// that is not a list at all becomes one non-string entry for the
			// same reason, instead of vanishing into "no conditions".
			$settings[ Agend_Content_Access_Fragment_Rules::CONDITIONS_KEY ] = is_array( $block_attrs['conditions'] )
				? array_values( $block_attrs['conditions'] )
				: array( null );
		}

		if ( isset( $block_attrs['conditionsMatch'] ) && is_string( $block_attrs['conditionsMatch'] ) ) {
			$settings[ Agend_Content_Access_Fragment_Rules::MATCH_KEY ] = $block_attrs['conditionsMatch'];
		}

		if ( isset( $block_attrs['conditionsInvert'] ) ) {
			$settings[ Agend_Content_Access_Fragment_Rules::INVERT_KEY ] = self::toggle( $block_attrs['conditionsInvert'] );
		}

		// Zero means "no replacement content" and is the same as absent.
		if ( isset( $block_attrs['fallbackId'] ) && is_int( $block_attrs['fallbackId'] ) && $block_attrs['fallbackId'] > 0 ) {
			$settings[ Agend_Content_Access_Fragment_Rules::FALLBACK_KEY ] = $block_attrs['fallbackId'];
		}

		return $settings;
	}

	/**
	 * The neutral settings a parsed block carries.
	 *
	 * Takes the block as `parse_blocks()` returns it, so a caller never has to
	 * check that `attrs` is an array or that the attribute is an object. A block
	 * whose attribute is missing or is not an object is read as carrying none.
	 *
	 * @param array $block Parsed block.
	 * @return array Neutral settings, empty when the block carries none.
	 */
	public static function settings_for_block( array $block ): array {
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$value = isset( $attrs[ self::ATTRIBUTE ] ) ? $attrs[ self::ATTRIBUTE ] : null;

		return is_array( $value ) ? self::to_settings( $value ) : array();
	}

	/**
	 * Whether the block carries an audience policy of its own.
	 *
	 * Asks `Agend_Content_Access_Policy::from_fragment_settings()` rather than
	 * re-deciding what counts, so the two cannot disagree: an unrecognised mode
	 * fails closed THERE into a policy, and so counts here as carrying one.
	 *
	 * @param array $block Parsed block.
	 * @return bool
	 */
	public static function carries_policy( array $block ): bool {
		return null !== Agend_Content_Access_Policy::from_fragment_settings( self::settings_for_block( $block ) );
	}

	/**
	 * Whether the block has display conditions switched on.
	 *
	 * Switched on, even with none chosen, which is what takes a block out of a
	 * shared cache. The same test `Agend_Content_Access_Fragment_Rules` uses, so
	 * a cache check and an evaluation cannot disagree.
	 *
	 * @param array $block Parsed block.
	 * @return bool
	 */
	public static function carries_conditions( array $block ): bool {
		return Agend_Content_Access_Fragment_Rules::conditions_enabled( self::settings_for_block( $block ) );
	}

	/**
	 * Whether the block or anything inside it carries an audience policy.
	 *
	 * "Inside" includes the content of a synced pattern the block references,
	 * which is not among its `innerBlocks`.
	 *
	 * @param array $block Parsed block.
	 * @param int[] $refs  Synced patterns already being resolved on the path to
	 *                     this block. Callers walking a pattern pass their own.
	 * @return bool
	 */
	public static function subtree_carries_policy( array $block, array $refs = array() ): bool {
		return self::carries_policy( $block ) || self::descendants_carry_policy( $block, $refs );
	}

	/**
	 * Whether anything STRICTLY below the block carries an audience policy.
	 *
	 * This is the test that decides whether a block is exported whole or split:
	 * a block whose own policy is the only one in its subtree can safely be one
	 * fragment, because the policy folds onto all of it.
	 *
	 * @param array $block Parsed block.
	 * @param int[] $refs  Synced patterns already being resolved on the path.
	 * @return bool
	 */
	public static function descendants_carry_policy( array $block, array $refs = array() ): bool {
		list( $children, $child_refs ) = self::children_of( $block, $refs );

		foreach ( $children as $child ) {
			if ( is_array( $child ) && self::subtree_carries_policy( $child, $child_refs ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The blocks directly inside a block.
	 *
	 * Its `innerBlocks`, or, for a synced pattern reference, the top-level
	 * blocks of the pattern it points at. A reference that cannot be resolved
	 * has no children, which is right for the caller: there is nothing there to
	 * carry a policy or to leak.
	 *
	 * @param array $block Parsed block.
	 * @param int[] $refs  Synced patterns already being resolved on the path.
	 * @return array{0: array, 1: int[]} The children, and the path of refs to
	 *                                   pass on when walking them.
	 */
	public static function children_of( array $block, array $refs = array() ): array {
		if ( self::is_synced_reference( $block ) ) {
			$ref      = self::synced_ref( $block );
			$resolved = self::resolve_synced( $ref, $refs );

			if ( null === $resolved ) {
				return array( array(), $refs );
			}

			return array( array_values( $resolved ), array_merge( $refs, array( $ref ) ) );
		}

		$children = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] )
			? array_values( $block['innerBlocks'] )
			: array();

		return array( $children, $refs );
	}

	/**
	 * Whether the block is a synced pattern reference.
	 *
	 * @param array $block Parsed block.
	 * @return bool
	 */
	public static function is_synced_reference( array $block ): bool {
		return isset( $block['blockName'] ) && self::SYNCED_BLOCK === $block['blockName'];
	}

	/**
	 * The `wp_block` post id a synced pattern reference points at.
	 *
	 * @param array $block Parsed block.
	 * @return int The id, or 0 when the block is not a reference or names none.
	 */
	public static function synced_ref( array $block ): int {
		if ( ! self::is_synced_reference( $block ) || ! isset( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
			return 0;
		}

		$ref = isset( $block['attrs']['ref'] ) ? $block['attrs']['ref'] : 0;

		if ( is_string( $ref ) && ctype_digit( $ref ) ) {
			$ref = (int) $ref;
		}

		return is_int( $ref ) && $ref > 0 ? $ref : 0;
	}

	/**
	 * The parsed blocks of a synced pattern.
	 *
	 * Refuses, with null, anything WordPress itself would not render for a
	 * visitor: a missing post, one that is not a `wp_block`, one that is not
	 * published, or a password protected one. Also refuses a pattern already
	 * being resolved on the path, which would never terminate, and a path longer
	 * than MAX_SYNCED_DEPTH.
	 *
	 * @param int   $ref  `wp_block` post id.
	 * @param int[] $refs Synced patterns already being resolved on the path.
	 * @return array|null Parsed blocks, or null when the pattern cannot be used.
	 */
	public static function resolve_synced( int $ref, array $refs = array() ): ?array {
		if ( $ref <= 0 || in_array( $ref, $refs, true ) || count( $refs ) >= self::MAX_SYNCED_DEPTH ) {
			return null;
		}

		$post = get_post( $ref );

		if (
			! $post instanceof WP_Post
			|| self::SYNCED_POST_TYPE !== $post->post_type
			|| 'publish' !== $post->post_status
			|| ! empty( $post->post_password )
		) {
			return null;
		}

		return parse_blocks( (string) $post->post_content );
	}

	/**
	 * An attribute toggle as the settings array holds it.
	 *
	 * Only the boolean `true` switches a toggle on. Anything else, a string
	 * included, is off: the contract says boolean, and reading `"false"` as on
	 * because it is a non-empty string is the kind of coercion this class avoids.
	 *
	 * @param mixed $value Attribute value.
	 * @return string `'yes'` or `''`.
	 */
	private static function toggle( $value ): string {
		return true === $value ? 'yes' : '';
	}

	/**
	 * The string entries of a list, reindexed.
	 *
	 * @param array $values Candidate list.
	 * @return string[]
	 */
	private static function strings( array $values ): array {
		return array_values( array_filter( $values, 'is_string' ) );
	}
}

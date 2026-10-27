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
 *   - An `agendAccess` that is present but is not an object (a string, a
 *     number, a boolean, a list) is read the same way, as an unrecognised mode.
 *     Only absent or `null` means "no attribute". Someone who typed
 *     `"agendAccess":"members"` meant to restrict the block, and reading that as
 *     no restriction would publish it.
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
	 * Most synced pattern references expanded in one document.
	 *
	 * The cycle guard and the depth limit bound how DEEP patterns nest, not how
	 * WIDE: a pattern holding ten references to a pattern holding ten more, ten
	 * levels down, is ten billion expansions without repeating a pattern on any
	 * one path. This is the cap on that, counted over the whole document in
	 * document order. A reference past it is treated exactly like a cycle.
	 *
	 * @var int
	 */
	const MAX_SYNCED_RESOLUTIONS = 50;

	/**
	 * Marks a synced pattern reference that `expand()` has dealt with.
	 *
	 * `true` when its pattern was resolved and now sits in the reference's
	 * `innerBlocks`, `false` when it could not be. Also what makes expanding
	 * idempotent, so a tree is never expanded twice.
	 *
	 * @var string
	 */
	const EXPANDED_KEY = 'agendExpanded';

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
	 * The attribute key and settings key of each audience list.
	 *
	 * @var array<string, string>
	 */
	private const LIST_KEYS = array(
		'tierIds'         => Agend_Content_Access_Policy::FRAGMENT_TIERS_KEY,
		'segmentIds'      => Agend_Content_Access_Policy::FRAGMENT_SEGMENTS_KEY,
		'entitlementKeys' => Agend_Content_Access_Policy::FRAGMENT_ENTITLEMENTS_KEY,
	);

	/**
	 * Converts an `agendAccess` object into neutral fragment settings.
	 *
	 * Only keys that are present and usable produce a setting, so an empty or
	 * absent attribute converts to an empty array, which every reader treats as
	 * "inherit, no conditions". A non-empty list is not an object, and converts
	 * to an unrecognised mode.
	 *
	 * @param array $block_attrs The `agendAccess` attribute object.
	 * @return array Settings keyed by the `Agend_Content_Access_Policy::FRAGMENT_*`
	 *               and `Agend_Content_Access_Fragment_Rules::*_KEY` constants,
	 *               Toggles are `'yes'` or `''`.
	 */
	public static function to_settings( array $block_attrs ): array {
		if ( ! self::is_object_like( $block_attrs ) ) {
			return self::unreadable();
		}

		$settings = array();

		if ( isset( $block_attrs['mode'] ) ) {
			$settings[ Agend_Content_Access_Policy::FRAGMENT_MODE_KEY ] = is_string( $block_attrs['mode'] )
				? $block_attrs['mode']
				: self::UNRECOGNISED_MODE;
		}

		foreach ( self::LIST_KEYS as $attribute => $key ) {
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
	 * whose attribute is missing or `null` is read as carrying none. One whose
	 * attribute is present and is anything but an object is read as carrying an
	 * unrecognised mode, which fails closed: absent means "no restriction", and
	 * a value somebody wrote that cannot be read is not absence.
	 *
	 * @param array $block Parsed block.
	 * @return array Neutral settings, empty when the block carries none.
	 */
	public static function settings_for_block( array $block ): array {
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$value = isset( $attrs[ self::ATTRIBUTE ] ) ? $attrs[ self::ATTRIBUTE ] : null;

		if ( null === $value ) {
			return array();
		}

		return is_array( $value ) ? self::to_settings( $value ) : self::unreadable();
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
	 * which is not among its `innerBlocks` until `expand()` puts it there. This
	 * expands first, on a budget of its own, so it can be handed a block straight
	 * from `parse_blocks()`. Handed one that has already been expanded it does no
	 * further lookups.
	 *
	 * @param array $block Parsed block.
	 * @param int[] $refs  Synced patterns already being resolved on the path to
	 *                     this block. Callers walking a pattern pass their own.
	 * @return bool
	 */
	public static function subtree_carries_policy( array $block, array $refs = array() ): bool {
		$ledger = self::ledger();

		return self::scan( self::expand_block( $block, $ledger, $refs ) );
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
		$ledger = self::ledger();

		return self::scan_children( self::expand_block( $block, $ledger, $refs ) );
	}

	/**
	 * A fresh account of the synced pattern references expanded in one document.
	 *
	 * Pass it, by reference, to every `expand()` call that belongs to the same
	 * document, and read `posts` afterwards for the patterns that were resolved.
	 *
	 * @return array{left: int, posts: array<int, WP_Post>} References still
	 *         allowed, and the pattern posts resolved so far, keyed by id.
	 */
	public static function ledger(): array {
		return array(
			'left'  => self::MAX_SYNCED_RESOLUTIONS,
			'posts' => array(),
		);
	}

	/**
	 * Puts each synced pattern's blocks where its reference is.
	 *
	 * After this a reference is an ordinary container whose `innerBlocks` are the
	 * pattern's blocks, so the rest of the work (is there a policy below this,
	 * what does this subtree hold, which pattern did the document use) is a plain
	 * walk of `innerBlocks` and cannot disagree with itself about what a
	 * reference contains. Resolution happens here and nowhere else, in document
	 * order, which is also what makes the budget mean something: the first
	 * MAX_SYNCED_RESOLUTIONS references are expanded and the rest are not.
	 *
	 * A reference that cannot be expanded keeps no children and is marked
	 * `false`. That covers a pattern already being resolved on the path (a
	 * cycle), one nested past MAX_SYNCED_DEPTH, one past the budget, and one
	 * that is missing, unpublished, protected or not a pattern. None of them is
	 * a failure.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param array $ledger By reference. From `ledger()`.
	 * @param int[] $refs   Synced patterns already being resolved on the way here.
	 * @return array The blocks, with every reference expanded.
	 */
	public static function expand( array $blocks, array &$ledger, array $refs = array() ): array {
		$expanded = array();

		foreach ( $blocks as $key => $block ) {
			$expanded[ $key ] = is_array( $block ) ? self::expand_block( $block, $ledger, $refs ) : $block;
		}

		return $expanded;
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
	 * Whether the block is a synced pattern reference whose pattern was expanded
	 * into its `innerBlocks`.
	 *
	 * @param array $block Parsed block.
	 * @return bool
	 */
	public static function is_expanded_reference( array $block ): bool {
		return self::is_synced_reference( $block )
			&& isset( $block[ self::EXPANDED_KEY ] )
			&& true === $block[ self::EXPANDED_KEY ];
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
	 * Whether a reference to the pattern may be resolved at all from here.
	 *
	 * @param int   $ref  `wp_block` post id.
	 * @param int[] $refs Synced patterns already being resolved on the path.
	 * @return bool False for no id, a pattern already on the path, or a path
	 *              already MAX_SYNCED_DEPTH patterns deep.
	 */
	private static function may_resolve( int $ref, array $refs ): bool {
		return $ref > 0 && ! in_array( $ref, $refs, true ) && count( $refs ) < self::MAX_SYNCED_DEPTH;
	}

	/**
	 * The pattern post a reference points at, if WordPress would render it.
	 *
	 * Refuses, with null, anything WordPress itself would not render for a
	 * visitor: a missing post, one that is not a `wp_block`, one that is not
	 * published, or a password protected one. Also refuses what `may_resolve()`
	 * does.
	 *
	 * @param int   $ref  `wp_block` post id.
	 * @param int[] $refs Synced patterns already being resolved on the path.
	 * @return WP_Post|null
	 */
	public static function synced_post( int $ref, array $refs = array() ): ?WP_Post {
		if ( ! self::may_resolve( $ref, $refs ) ) {
			return null;
		}

		return self::lookup_synced( $ref );
	}

	/**
	 * Looks a pattern post up and applies WordPress's own render conditions.
	 *
	 * @param int $ref `wp_block` post id.
	 * @return WP_Post|null
	 */
	private static function lookup_synced( int $ref ): ?WP_Post {
		$post = get_post( $ref );

		if (
			! $post instanceof WP_Post
			|| self::SYNCED_POST_TYPE !== $post->post_type
			|| 'publish' !== $post->post_status
			|| ! empty( $post->post_password )
		) {
			return null;
		}

		return $post;
	}

	/**
	 * Expands one block: a reference is replaced by its pattern, anything else
	 * has its inner blocks expanded.
	 *
	 * @param array $block  Parsed block.
	 * @param array $ledger By reference. From `ledger()`.
	 * @param int[] $refs   Synced patterns already being resolved on the way here.
	 * @return array
	 */
	private static function expand_block( array $block, array &$ledger, array $refs ): array {
		if ( array_key_exists( self::EXPANDED_KEY, $block ) ) {
			return $block;
		}

		if ( ! self::is_synced_reference( $block ) ) {
			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::expand( $block['innerBlocks'], $ledger, $refs );
			}

			return $block;
		}

		// Whatever markup the reference carries of its own is not what
		// WordPress renders for it, so it is not kept.
		$block['innerBlocks']        = array();
		$block[ self::EXPANDED_KEY ] = false;

		$ref = self::synced_ref( $block );

		// The guards come before the budget: a cycle or a missing id costs
		// nothing to refuse, and should not use up a reference that could
		// have been resolved.
		if ( ! self::may_resolve( $ref, $refs ) || $ledger['left'] < 1 ) {
			return $block;
		}

		--$ledger['left'];

		$post = self::lookup_synced( $ref );

		if ( null === $post ) {
			return $block;
		}

		$ledger['posts'][ $ref ]     = $post;
		$block['innerBlocks']        = self::expand( parse_blocks( (string) $post->post_content ), $ledger, array_merge( $refs, array( $ref ) ) );
		$block[ self::EXPANDED_KEY ] = true;

		return $block;
	}

	/**
	 * Whether the block or any block inside it carries a policy.
	 *
	 * @param array $block Parsed block, already expanded.
	 * @return bool
	 */
	private static function scan( array $block ): bool {
		return self::carries_policy( $block ) || self::scan_children( $block );
	}

	/**
	 * Whether any block strictly inside the block carries a policy.
	 *
	 * @param array $block Parsed block, already expanded.
	 * @return bool
	 */
	private static function scan_children( array $block ): bool {
		if ( ! isset( $block['innerBlocks'] ) || ! is_array( $block['innerBlocks'] ) ) {
			return false;
		}

		foreach ( $block['innerBlocks'] as $child ) {
			if ( is_array( $child ) && self::scan( $child ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The settings an attribute that cannot be read converts to.
	 *
	 * @return array An unrecognised mode, which `from_fragment_settings()` turns
	 *               into a members-only policy.
	 */
	private static function unreadable(): array {
		return array( Agend_Content_Access_Policy::FRAGMENT_MODE_KEY => self::UNRECOGNISED_MODE );
	}

	/**
	 * Whether an array could have come from a JSON object.
	 *
	 * An empty array might (`{}` and `[]` both decode to it, and `{}` is the
	 * documented way to say "nothing"). A non-empty one does only if it has a
	 * string key: a JSON list decodes to integer keys alone.
	 *
	 * @param array $value Decoded attribute.
	 * @return bool
	 */
	private static function is_object_like( array $value ): bool {
		if ( array() === $value ) {
			return true;
		}

		foreach ( array_keys( $value ) as $key ) {
			if ( is_string( $key ) ) {
				return true;
			}
		}

		return false;
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

<?php
/**
 * Block editor document to canonical fragments.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flattens a block editor document into the ordered fragments Agend ingests.
 *
 * The Elementor driver flattens to LEAVES, because a widget is the smallest
 * thing with a policy. A block has no such natural leaf: a Group holds blocks
 * that are themselves just markup. Exporting every leaf would shred ordinary
 * content into hundreds of fragments to protect the one block that carries a
 * restriction. So the rule here is the opposite way round:
 *
 *   - A block with NO policy anywhere strictly below it is exported whole, as
 *     one fragment, carrying the policy folded down its ancestor chain.
 *   - A block with a policy somewhere below it is STRUCTURAL. It emits no
 *     fragment of its own and its children are walked with the policy folded so
 *     far. Its own wrapper markup (the Group's `<div>`, a Columns wrapper) is
 *     dropped. That is a fidelity gap and never a security one: the failure mode
 *     is a region whose wrapper is missing, not one that is served to the wrong
 *     audience.
 *
 * Folding downward cannot lose a restriction, only apply it more widely, so a
 * fragment is never broader than its ancestors, exactly as in the Elementor
 * driver. A fragment's own policy is read from the `agendAccess` block
 * attribute through `Agend_Content_Access_Block_Attributes`, and folded with
 * `Agend_Content_Access_Policy::effective_policy()`.
 *
 * NOTHING HERE RENDERS. `render_block()` and `do_blocks()` are never called.
 * Rendering runs dynamic blocks (a query loop, latest posts, post content),
 * which pull OTHER posts' content into this document. That content may be
 * restricted, and Agend would store it as part of this document's fragment and
 * serve it under this document's policy. Instead a static block's HTML is
 * rebuilt from its saved markup, and a dynamic block contributes nothing and is
 * reported as not extracted. A block type is dynamic when it has a render
 * callback registered, or is one of a short list that is dynamic on every
 * WordPress site whether or not the registry is populated yet.
 *
 * Synced patterns (`core/block`) hold only a `ref` to a `wp_block` post.
 * They are resolved in place, so a restriction inside a pattern is seen, with a
 * cycle guard and a depth limit. One that is missing, unpublished or not a
 * pattern yields an empty, non-extracted fragment, never a failure.
 *
 * Display conditions are not exported, matching the Elementor driver: they are
 * evaluated in the theme, against the visitor, and Agend has no vocabulary for
 * them. Only the audience policy travels.
 *
 * Block markup is user content, so this never throws: an unknown block name is
 * normal, and an unreadable region becomes an empty fragment that keeps its
 * policy. The Elementor driver fails the revision on a node type it does not
 * know because it cannot tell whether that node was a restricted region. Here
 * every block is read the same way, whatever its name, so there is no such
 * thing as a block this driver does not understand.
 */
class Agend_Content_Access_Block_Parser implements Agend_Content_Access_Document_Parser {

	/**
	 * Block types that are dynamic on every WordPress site.
	 *
	 * A floor under the registry check, not a replacement for it. The registry
	 * is the authority on a site's own and third-party dynamic blocks, but it is
	 * only as complete as what has registered by the time the connector asks,
	 * and these are the ones whose output is another post's content.
	 *
	 * @var string[]
	 */
	const ALWAYS_DYNAMIC = array(
		'core/query',
		'core/post-template',
		'core/latest-posts',
		'core/post-content',
		'core/template-part',
		'core/navigation',
	);

	/**
	 * Whether the post holds a block editor document.
	 *
	 * A post Elementor owns is never this driver's, whatever its `post_content`
	 * holds, so the answer does not depend on where the drivers sit in the list.
	 * Beyond that it is `has_blocks()`: a post with any block delimiter in it.
	 * A classic post, with none, falls through to the single `body` fragment.
	 *
	 * A post that is only PARTLY blocks is still recognised, and its plain
	 * stretches are exported as freeform fragments, so nothing is dropped.
	 *
	 * @param WP_Post $post Post being exported.
	 * @return bool
	 */
	public function recognises( $post ): bool {
		if ( '' !== trim( (string) get_post_meta( (int) $post->ID, Agend_Content_Access_Elementor_Parser::META_KEY, true ) ) ) {
			return false;
		}

		return has_blocks( (string) $post->post_content );
	}

	/**
	 * The post's block document as ordered fragments.
	 *
	 * Fragment ids are positional: `block-` plus the index path joined by `-`,
	 * top-level index first (`block-0`, `block-2-1-0`). The gateway stores a
	 * document's fragments as one value per revision, so an id only has to be
	 * unique and deterministic within a revision, and a path is both without
	 * needing an id to be minted and persisted in the content. Index paths
	 * continue through a resolved synced pattern, so a pattern used twice does
	 * not repeat an id.
	 *
	 * @param WP_Post $post Post being exported.
	 * @return array<int, array<string, mixed>>
	 */
	public function fragments( $post ): array {
		$state = array(
			'fragments' => array(),
			'position'  => 0,
		);

		foreach ( array_values( parse_blocks( (string) $post->post_content ) ) as $index => $block ) {
			self::walk( $block, array( $index ), null, array(), $state );
		}

		return $state['fragments'];
	}

	/**
	 * Walks one block, folding policy downward.
	 *
	 * @param mixed      $block     Block from `parse_blocks()`.
	 * @param int[]      $path      Index path to this block.
	 * @param array|null $inherited Effective policy of the ancestor chain.
	 * @param int[]      $refs      Synced patterns being resolved on the way here.
	 * @param array      $state     Accumulator, by reference.
	 */
	private static function walk( $block, array $path, ?array $inherited, array $refs, array &$state ): void {
		if ( ! is_array( $block ) ) {
			return;
		}

		$name = self::name_of( $block );

		// A run of plain content, which `parse_blocks()` reports as a block with
		// no name. The blank ones are the "\n\n" WordPress leaves between two
		// blocks, and are not content.
		if ( null === $name ) {
			$html = self::string_of( isset( $block['innerHTML'] ) ? $block['innerHTML'] : '' );

			if ( '' !== trim( $html ) ) {
				self::emit( 'freeform', $path, $html, true, $inherited, $state );
			}

			return;
		}

		// The block's own policy, narrowed against everything above it. Passing
		// the inherited policy as the "document" argument is what enforces the
		// never-widen rule at every level, not just at the page boundary.
		$own       = Agend_Content_Access_Policy::from_fragment_settings( Agend_Content_Access_Block_Attributes::settings_for_block( $block ) );
		$effective = null === $own
			? $inherited
			: Agend_Content_Access_Policy::effective_policy( $inherited, $own );

		if ( Agend_Content_Access_Block_Attributes::descendants_carry_policy( $block, $refs ) ) {
			list( $children, $child_refs ) = Agend_Content_Access_Block_Attributes::children_of( $block, $refs );

			foreach ( $children as $index => $child ) {
				self::walk( $child, array_merge( $path, array( $index ) ), $effective, $child_refs, $state );
			}

			return;
		}

		$complete = true;
		$html     = self::html_of( $block, $refs, $complete );

		self::emit( $name, $path, $html, $complete, $effective, $state );
	}

	/**
	 * Appends one fragment.
	 *
	 * @param string     $type      Block name, or `freeform`.
	 * @param int[]      $path      Index path.
	 * @param string     $html      Rebuilt markup.
	 * @param bool       $extracted False when any part was dynamic or unresolvable.
	 * @param array|null $effective Folded policy.
	 * @param array      $state     Accumulator, by reference.
	 */
	private static function emit( string $type, array $path, string $html, bool $extracted, ?array $effective, array &$state ): void {
		$state['fragments'][] = array(
			'id'       => 'block-' . implode( '-', $path ),
			'position' => $state['position'],
			'type'     => 'block:' . $type,
			'payload'  => array(
				'html'      => $html,
				'extracted' => $extracted,
			),
			// `inherit` is stored explicitly: an absent fragment policy is a
			// malformed fragment on the Agend side, not an implicit inherit.
			'policy'   => null === $effective
				? array( 'mode' => Agend_Content_Access_Policy::MODE_INHERIT )
				: $effective,
		);

		++$state['position'];
	}

	/**
	 * Rebuilds a block's HTML from its saved markup, without rendering it.
	 *
	 * This is what WordPress itself renders for a STATIC block: the strings in
	 * `innerContent`, with each `null` placeholder replaced by the next inner
	 * block. A dynamic block contributes the empty string and clears `$complete`,
	 * and so does a synced pattern that cannot be used. A dynamic block inside a
	 * static parent is simply absent from the parent's HTML.
	 *
	 * @param array $block    Parsed block.
	 * @param int[] $refs     Synced patterns being resolved on the way here.
	 * @param bool  $complete By reference. Set to false when any part of the
	 *                        subtree was dynamic or unresolvable.
	 * @return string
	 */
	private static function html_of( array $block, array $refs, bool &$complete ): string {
		if ( null === self::name_of( $block ) ) {
			return self::string_of( isset( $block['innerHTML'] ) ? $block['innerHTML'] : '' );
		}

		// Ahead of the dynamic check: `core/block` has a render callback on a
		// real site, and is the one dynamic block that is resolved rather than
		// skipped, because what it renders is a pattern this document includes.
		if ( Agend_Content_Access_Block_Attributes::is_synced_reference( $block ) ) {
			$ref      = Agend_Content_Access_Block_Attributes::synced_ref( $block );
			$resolved = Agend_Content_Access_Block_Attributes::resolve_synced( $ref, $refs );

			if ( null === $resolved ) {
				$complete = false;

				return '';
			}

			$html = '';

			foreach ( $resolved as $child ) {
				if ( is_array( $child ) ) {
					$html .= self::html_of( $child, array_merge( $refs, array( $ref ) ), $complete );
				}
			}

			return $html;
		}

		if ( self::is_dynamic( (string) self::name_of( $block ) ) ) {
			$complete = false;

			return '';
		}

		$inner_blocks  = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? array_values( $block['innerBlocks'] ) : array();
		$inner_content = isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : array();

		// A block array that did not come from `parse_blocks()` may have inner
		// blocks and no placeholders for them. Its inner blocks follow its own
		// markup rather than being lost.
		if ( array() === $inner_content ) {
			$html = self::string_of( isset( $block['innerHTML'] ) ? $block['innerHTML'] : '' );

			foreach ( $inner_blocks as $inner_block ) {
				if ( is_array( $inner_block ) ) {
					$html .= self::html_of( $inner_block, $refs, $complete );
				}
			}

			return $html;
		}

		$html = '';
		$next = 0;

		foreach ( $inner_content as $chunk ) {
			if ( null === $chunk ) {
				if ( isset( $inner_blocks[ $next ] ) && is_array( $inner_blocks[ $next ] ) ) {
					$html .= self::html_of( $inner_blocks[ $next ], $refs, $complete );
				}

				++$next;
				continue;
			}

			$html .= self::string_of( $chunk );
		}

		return $html;
	}

	/**
	 * Whether a block type renders dynamically.
	 *
	 * An unregistered name is NOT dynamic. It is exported from its saved markup,
	 * which for a block whose plugin has been deactivated is whatever the block
	 * saved, and for a dynamic block is usually nothing. Either way nothing is
	 * pulled in from another post.
	 *
	 * @param string $name Block name.
	 * @return bool
	 */
	private static function is_dynamic( string $name ): bool {
		if ( in_array( $name, self::ALWAYS_DYNAMIC, true ) ) {
			return true;
		}

		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			return false;
		}

		$type = WP_Block_Type_Registry::get_instance()->get_registered( $name );

		return null !== $type && is_callable( $type->render_callback );
	}

	/**
	 * A block's name, or null for a freeform run of content.
	 *
	 * @param array $block Parsed block.
	 * @return string|null
	 */
	private static function name_of( array $block ): ?string {
		return isset( $block['blockName'] ) && is_string( $block['blockName'] ) && '' !== $block['blockName']
			? $block['blockName']
			: null;
	}

	/**
	 * A markup chunk as a string.
	 *
	 * @param mixed $value Chunk from a parsed block.
	 * @return string Empty when it is not a string.
	 */
	private static function string_of( $value ): string {
		return is_string( $value ) ? $value : '';
	}
}

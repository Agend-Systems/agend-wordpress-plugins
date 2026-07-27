<?php
/**
 * Elementor document to canonical fragments.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flattens an Elementor document into the ordered fragments Agend ingests.
 *
 * Elementor stores a nested tree; the projection needs a flat ordered list
 * where each entry carries the audience that applies to it. The mapping is:
 *
 *   - Structural nodes (section, column, container) are NOT fragments. They
 *     carry policy, which folds down into their descendants.
 *   - Leaf widgets ARE fragments, each with the intersection of every policy
 *     on its ancestor chain plus its own.
 *
 * Flattening to leaves is what makes nested policies safe. Emitting one
 * fragment per top-level section and hanging its own policy on it would lose a
 * stricter policy on a widget INSIDE that section, and the projection would
 * then render the restricted widget along with the rest of the section. Folding
 * downward cannot lose a restriction, only apply it more widely, and a fragment
 * can never end up broader than its ancestors (Decision 2.3).
 *
 * Raw Elementor JSON is never emitted (US-4.3 criterion 1). Fragments carry the
 * widget type and an extracted text payload, not the source node.
 */
class Agend_Content_Access_Elementor_Parser {

	/**
	 * Structural node types whose children are walked.
	 *
	 * @var string[]
	 */
	const STRUCTURAL_TYPES = array( 'section', 'column', 'container' );

	/**
	 * Settings keys commonly holding a widget's visible text, in preference
	 * order. Best-effort: a widget whose content is not found still yields a
	 * fragment, so its POLICY is never lost even when its content is not
	 * carried.
	 *
	 * @var string[]
	 */
	const TEXT_KEYS = array( 'title', 'editor', 'text', 'heading', 'description', 'html', 'caption' );

	/**
	 * Parses `_elementor_data` into fragments.
	 *
	 * @param string $raw Stored Elementor document JSON.
	 * @return array{fragments: array<int, array<string, mixed>>, errors: string[]}
	 *         Fragments in render order, and any structural errors. A non-empty
	 *         `errors` means the caller must FAIL the sync revision rather than
	 *         ship a partial document.
	 */
	public static function parse( string $raw ): array {
		$decoded = json_decode( $raw, true );

		if ( '' === trim( $raw ) ) {
			return array( 'fragments' => array(), 'errors' => array() );
		}

		if ( ! is_array( $decoded ) ) {
			return array(
				'fragments' => array(),
				'errors'    => array( 'Elementor document is not valid JSON.' ),
			);
		}

		$state = array( 'fragments' => array(), 'errors' => array(), 'position' => 0 );

		foreach ( $decoded as $node ) {
			self::walk( $node, null, $state );
		}

		return array( 'fragments' => $state['fragments'], 'errors' => $state['errors'] );
	}

	/**
	 * Walks one node, folding policy downward.
	 *
	 * @param mixed      $node      Node from the document.
	 * @param array|null $inherited Effective policy of the ancestor chain.
	 * @param array      $state     Accumulator, by reference.
	 */
	private static function walk( $node, ?array $inherited, array &$state ): void {
		if ( ! is_array( $node ) ) {
			$state['errors'][] = 'Elementor document contains a node that is not an object.';
			return;
		}

		$el_type = isset( $node['elType'] ) ? (string) $node['elType'] : '';

		if ( '' === $el_type ) {
			$state['errors'][] = 'Elementor document contains a node with no elType.';
			return;
		}

		$settings = isset( $node['settings'] ) && is_array( $node['settings'] )
			? $node['settings']
			: array();

		// The node's own policy, narrowed against everything above it. Passing
		// the inherited policy as the "document" argument is what enforces the
		// never-widen rule at every level, not just at the page boundary.
		$own       = Agend_Content_Access_Elementor::policy_from_settings( $settings );
		$effective = null === $own
			? $inherited
			: Agend_Content_Access_Policy::effective_policy( $inherited, $own );

		if ( in_array( $el_type, self::STRUCTURAL_TYPES, true ) ) {
			$children = isset( $node['elements'] ) && is_array( $node['elements'] )
				? $node['elements']
				: array();

			foreach ( $children as $child ) {
				self::walk( $child, $effective, $state );
			}

			return;
		}

		if ( 'widget' === $el_type ) {
			$state['fragments'][] = self::fragment( $node, $settings, $effective, $state['position'] );
			++$state['position'];
			return;
		}

		// An elType this parser does not know. FAIL the revision rather than
		// skip the node: skipping is exactly how a restricted region silently
		// stops being represented, and the projection would then have no idea
		// it should have gated anything.
		$state['errors'][] = sprintf(
			'Unsupported Elementor node type "%s". This document cannot be synced until the parser supports it.',
			$el_type
		);
	}

	/**
	 * Builds one fragment from a widget node.
	 *
	 * @param array      $node      Widget node.
	 * @param array      $settings  Widget settings.
	 * @param array|null $effective Folded policy.
	 * @param int        $position  Render order.
	 * @return array<string, mixed>
	 */
	private static function fragment( array $node, array $settings, ?array $effective, int $position ): array {
		$widget_type = isset( $node['widgetType'] ) ? (string) $node['widgetType'] : 'unknown';

		return array(
			'id'       => isset( $node['id'] ) ? (string) $node['id'] : 'fragment-' . $position,
			'position' => $position,
			'type'     => 'elementor:' . $widget_type,
			'payload'  => self::payload( $settings ),
			// `inherit` is stored explicitly: an absent fragment policy is a
			// malformed fragment on the Agend side, not an implicit inherit.
			'policy'   => null === $effective
				? array( 'mode' => Agend_Content_Access_Policy::MODE_INHERIT )
				: $effective,
		);
	}

	/**
	 * Extracts a widget's visible text.
	 *
	 * Best-effort by design. A widget whose content this does not recognise
	 * still produces a fragment carrying its POLICY, with `extracted` false, so
	 * the projection gates it correctly even though its content is not carried.
	 * That is a fidelity gap, deliberately, and never a security one: the
	 * failure mode is a region that renders empty, not one that renders to the
	 * wrong audience.
	 *
	 * @param array $settings Widget settings.
	 * @return array{html: string, extracted: bool}
	 */
	private static function payload( array $settings ): array {
		foreach ( self::TEXT_KEYS as $key ) {
			if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) && '' !== trim( $settings[ $key ] ) ) {
				return array( 'html' => (string) $settings[ $key ], 'extracted' => true );
			}
		}

		return array( 'html' => '', 'extracted' => false );
	}
}

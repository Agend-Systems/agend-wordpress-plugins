<?php
/**
 * Elementor fragment policies.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds an Agend Access control to Elementor elements and suppresses those the
 * visitor may not see.
 *
 * The registration and render mechanics here are ported from the
 * `agend-elementor` usermeta conditions experiment, which got the hard parts
 * right: registering once per control stack, injecting into the Advanced tab
 * without disturbing Elementor's own tab order, and recognising the preview
 * iframe. Those are kept.
 *
 * What is NOT kept is that experiment's rule model. It authorised from
 * arbitrary WordPress user metadata and failed OPEN: an enabled condition with
 * no rules, or a rule with a blank meta key, rendered the element to everyone.
 * This replaces it with typed policies over Agend tier UUIDs, evaluated against
 * a member standing Agend resolved, and fails closed at every branch.
 *
 * Suppression only ever REMOVES an element. It cannot reveal one the document
 * policy hides, because the effective policy is the intersection of the two
 * (Decision 2.3).
 */
class Agend_Content_Access_Elementor {

	const SECTION_ID = 'agend_access_section';
	const MODE_KEY   = 'agend_access_mode';
	const TIERS_KEY  = 'agend_access_tier_ids';

	/**
	 * Control stacks already given the section this request.
	 *
	 * Keyed on the stack's unique name, not an object id: Elementor caches
	 * control registration per stack for the life of the request, so this must
	 * dedupe per stack rather than per element instance.
	 *
	 * @var array<string, true>
	 */
	private array $processed_stacks = array();

	/**
	 * Registers hooks. Only ever called when Elementor is loaded.
	 */
	public function __construct() {
		add_action( 'elementor/element/after_section_end', array( $this, 'maybe_add_section' ), 10, 3 );

		foreach ( array( 'widget', 'section', 'column', 'container' ) as $type ) {
			add_filter( "elementor/frontend/{$type}/should_render", array( $this, 'maybe_suppress' ), 10, 2 );
		}

		add_action( 'elementor/element/after_add_attributes', array( $this, 'maybe_mark_in_editor' ) );
	}

	/**
	 * Adds the Agend Access section to an element's Advanced tab.
	 *
	 * Waits for one of Elementor's OWN Advanced sections to close rather than
	 * injecting after the element's first section. Elementor builds the editor
	 * tab strip in the order each tab's first control is registered, so
	 * registering an Advanced control straight after a Content section would put
	 * Advanced before Style in the panel.
	 *
	 * @param \Elementor\Controls_Stack $element    Stack being registered.
	 * @param string                    $section_id Section that just ended.
	 * @param array                     $args       Section args, including its tab.
	 */
	public function maybe_add_section( $element, $section_id, $args = array() ): void {
		if ( ! isset( $args['tab'] ) || \Elementor\Controls_Manager::TAB_ADVANCED !== $args['tab'] ) {
			return;
		}

		if ( ! self::stack_accepts_controls( $element ) ) {
			return;
		}

		$stack = $element->get_unique_name();

		if ( isset( $this->processed_stacks[ $stack ] ) ) {
			return;
		}

		$this->processed_stacks[ $stack ] = true;

		$element->start_controls_section(
			self::SECTION_ID,
			array(
				'label' => __( 'Agend Access', 'agend-content-access' ),
				'tab'   => \Elementor\Controls_Manager::TAB_ADVANCED,
			)
		);

		$element->add_control(
			self::MODE_KEY,
			array(
				'label'       => __( 'Who can see this', 'agend-content-access' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => Agend_Content_Access_Policy::MODE_INHERIT,
				'options'     => array(
					Agend_Content_Access_Policy::MODE_INHERIT => __( 'Same as the page', 'agend-content-access' ),
					Agend_Content_Access_Policy::MODE_MEMBERS => __( 'All active members', 'agend-content-access' ),
					Agend_Content_Access_Policy::MODE_TIERS   => __( 'Selected membership plans', 'agend-content-access' ),
				),
				'description' => __(
					'A section can only narrow the page\'s audience, never widen it. If the page is already restricted, this section stays restricted too.',
					'agend-content-access'
				),
			)
		);

		$element->add_control(
			self::TIERS_KEY,
			array(
				'label'       => __( 'Membership plans', 'agend-content-access' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'multiple'    => true,
				'options'     => self::plan_options(),
				'default'     => array(),
				'condition'   => array(
					self::MODE_KEY => Agend_Content_Access_Policy::MODE_TIERS,
				),
				'description' => __(
					'Members on any one of these plans can see this section. Selecting none hides it from everybody.',
					'agend-content-access'
				),
			)
		);

		$element->end_controls_section();
	}

	/**
	 * Plan choices for the editor, id to name.
	 *
	 * Reuses the reduced catalogue, so the Elementor control and the native
	 * panel offer exactly the same list from the same cache. An outage yields an
	 * empty list, which the control's own copy explains.
	 *
	 * @return array<string, string>
	 */
	public static function plan_options(): array {
		$catalogue = Agend_Content_Access_Catalogue::get();
		$options   = array();

		foreach ( Agend_Content_Access_Catalogue::selectable( $catalogue['plans'] ) as $plan ) {
			$options[ $plan['id'] ] = $plan['name'];
		}

		return $options;
	}

	/**
	 * Which control stacks get the section.
	 *
	 * `after_section_end` fires for every stack, including documents, kits and
	 * site settings, where a per-element policy is meaningless and would surface
	 * under Page Settings.
	 *
	 * Widgets are handled through Elementor's shared common stack rather than
	 * individually: `Widget_Base::get_stack()` merges it into every widget that
	 * is not itself the common base, so registering there covers core, Pro and
	 * third-party widgets exactly once. Registering per widget as well would
	 * declare the same controls twice.
	 *
	 * @param \Elementor\Controls_Stack $element Stack.
	 * @return bool
	 */
	private static function stack_accepts_controls( $element ): bool {
		if ( ! $element instanceof \Elementor\Element_Base ) {
			return false;
		}

		if ( $element instanceof \Elementor\Widget_Base ) {
			return $element instanceof \Elementor\Widget_Common_Base;
		}

		return true;
	}

	/**
	 * Reads an element's fragment policy from its settings.
	 *
	 * Fails closed: an unrecognised mode, or selected-plans with nothing
	 * selected, both yield a policy nobody satisfies rather than being ignored.
	 * The experiment this replaces treated both as "no condition" and rendered
	 * the element to everyone.
	 *
	 * @param array $settings Element settings.
	 * @return array|null Fragment policy, or null when the element opts out.
	 */
	public static function policy_from_settings( array $settings ): ?array {
		$mode = isset( $settings[ self::MODE_KEY ] ) ? (string) $settings[ self::MODE_KEY ] : '';

		if ( '' === $mode || Agend_Content_Access_Policy::MODE_INHERIT === $mode ) {
			return null;
		}

		if ( Agend_Content_Access_Policy::MODE_MEMBERS === $mode ) {
			return array( 'mode' => Agend_Content_Access_Policy::MODE_MEMBERS );
		}

		if ( Agend_Content_Access_Policy::MODE_TIERS === $mode ) {
			$raw   = isset( $settings[ self::TIERS_KEY ] ) && is_array( $settings[ self::TIERS_KEY ] )
				? $settings[ self::TIERS_KEY ]
				: array();
			$clean = array();

			foreach ( $raw as $tier_id ) {
				$tier_id = is_string( $tier_id ) ? trim( $tier_id ) : '';

				if ( 1 === preg_match( Agend_Content_Access_Policy::UUID_PATTERN, $tier_id ) ) {
					$clean[] = $tier_id;
				}
			}

			// An empty list here is NOT "no restriction". The editor asked for
			// selected plans, so the honest reading is a restriction nobody
			// satisfies, which hides the element until the policy is repaired.
			return array(
				'mode'     => Agend_Content_Access_Policy::MODE_TIERS,
				'tier_ids' => array_values( array_unique( $clean ) ),
			);
		}

		// An unrecognised mode is a policy we cannot honour. Treat it as
		// members-only rather than ignoring it.
		return array( 'mode' => Agend_Content_Access_Policy::MODE_MEMBERS );
	}

	/**
	 * Suppresses an element the current visitor cannot see.
	 *
	 * Returning false removes the element's ENTIRE render, wrapper markup
	 * included, so nothing is emitted and then hidden.
	 *
	 * Never suppresses in an authoring context: an editor must see the sections
	 * they are laying out, including ones they have just restricted.
	 *
	 * @param bool                    $should_render Elementor's decision so far.
	 * @param \Elementor\Element_Base $element       Element.
	 * @return bool
	 */
	public function maybe_suppress( $should_render, $element ) {
		if ( ! $should_render ) {
			return $should_render;
		}

		if ( Agend_Content_Access_Frontend::is_authoring_context() ) {
			return $should_render;
		}

		$fragment = self::policy_from_settings( $element->get_settings_for_display() );

		if ( null === $fragment ) {
			// Inherits the page, which the page-level gate already handled.
			return $should_render;
		}

		$document  = Agend_Content_Access_Policy::get_for_post( (int) get_queried_object_id() );
		$effective = Agend_Content_Access_Policy::effective_policy( $document, $fragment );

		$state = Agend_Content_Access_Decision::evaluate(
			$effective,
			Agend_Content_Access_Decision::viewer()
		);

		return Agend_Content_Access_Decision::STATE_GRANTED === $state;
	}

	/**
	 * Marks a restricted element in the editor so the author can see which
	 * sections carry a policy.
	 *
	 * @param \Elementor\Element_Base $element Element about to render its wrapper.
	 */
	public function maybe_mark_in_editor( $element ): void {
		if ( ! Agend_Content_Access_Frontend::is_authoring_context() ) {
			return;
		}

		$fragment = self::policy_from_settings( $element->get_settings_for_display() );

		if ( null === $fragment ) {
			return;
		}

		$element->add_render_attribute(
			'_wrapper',
			array(
				'class'                  => 'agend-access-restricted',
				'data-agend-access-mode' => (string) $fragment['mode'],
			)
		);
	}
}

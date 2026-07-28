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
	 * Display-condition controls (US-6.1, ESAC parity).
	 *
	 * Kept in their own control group and evaluated separately from the access
	 * POLICY above, because the two are different things wearing similar
	 * clothes. The policy travels to Agend and drives the server-side
	 * projection: it is a security boundary, enforced whether or not WordPress
	 * renders correctly. Display conditions are evaluated HERE, in the theme,
	 * and personalise what a page shows.
	 *
	 * Merging them into one control would invite an editor to treat a local
	 * render decision as protection. They compose instead: either can hide an
	 * element, neither can reveal one the other hid.
	 */
	const CONDITIONS_ENABLED_KEY = 'agend_conditions_enabled';
	const CONDITIONS_KEY         = 'agend_conditions';
	const CONDITIONS_MATCH_KEY   = 'agend_conditions_match';
	const CONDITIONS_INVERT_KEY  = 'agend_conditions_invert';

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

		// Keep policy-bearing elements out of Elementor's frozen-HTML cache.
		add_filter( 'elementor/element/is_dynamic_content', array( $this, 'policy_is_dynamic_content' ), 10, 3 );
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

		$element->add_control(
			'agend_conditions_divider',
			array(
				'type' => \Elementor\Controls_Manager::DIVIDER,
			)
		);

		$element->add_control(
			self::CONDITIONS_ENABLED_KEY,
			array(
				'label'        => __( 'Add display conditions', 'agend-content-access' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
				'description'  => __(
					'Show or hide this element based on the visitor: their WordPress role, their membership, or a segment. This is presentation, applied by this site. For content that must be protected, use the setting above, which Agend enforces.',
					'agend-content-access'
				),
			)
		);

		$element->add_control(
			self::CONDITIONS_KEY,
			array(
				'label'       => __( 'Conditions', 'agend-content-access' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'multiple'    => true,
				'options'     => Agend_Content_Access_Condition_Sets::as_options(),
				'default'     => array(),
				'label_block' => true,
				'condition'   => array( self::CONDITIONS_ENABLED_KEY => 'yes' ),
				'description' => __(
					'Choosing none shows the element to everybody.',
					'agend-content-access'
				),
			)
		);

		$element->add_control(
			self::CONDITIONS_MATCH_KEY,
			array(
				'label'     => __( 'Match', 'agend-content-access' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => Agend_Content_Access_Conditions::MATCH_ANY,
				'options'   => array(
					Agend_Content_Access_Conditions::MATCH_ANY => __( 'Any of these', 'agend-content-access' ),
					Agend_Content_Access_Conditions::MATCH_ALL => __( 'All of these', 'agend-content-access' ),
				),
				'condition' => array( self::CONDITIONS_ENABLED_KEY => 'yes' ),
			)
		);

		$element->add_control(
			self::CONDITIONS_INVERT_KEY,
			array(
				'label'        => __( 'Reverse the result', 'agend-content-access' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
				'condition'    => array( self::CONDITIONS_ENABLED_KEY => 'yes' ),
				'description'  => __(
					'Show the element to everyone the conditions do NOT match. Use this for content aimed at signed-out visitors, or at people who are not yet members.',
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

		$settings = $element->get_settings_for_display();

		// Display conditions are evaluated FIRST and independently. They can
		// only ever remove an element, so an element hidden here is hidden
		// whatever the access policy says, and vice versa. Neither can reveal
		// what the other hid.
		if ( ! self::conditions_pass( $settings ) ) {
			return false;
		}

		$fragment = self::policy_from_settings( $settings );

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
	 * Treats any element carrying an access policy as dynamic content.
	 *
	 * This is a SECURITY control, not an optimisation, and it is the reason
	 * `should_render` can be trusted at all under Elementor's element cache.
	 *
	 * That cache (`_elementor_element_cache`, on by default with a 24 hour TTL)
	 * works by re-rendering a document once and storing the result in post meta.
	 * During that pass Elementor asks each element whether it should be stored as
	 * a re-expanded shortcode or baked to final HTML. `should_render_shortcode()`
	 * answers "shortcode" only for elements that opt in or are dynamic, so a
	 * PLAIN STATIC section is frozen as finished markup.
	 *
	 * Frozen markup never runs `should_render` again, and the cache has no viewer
	 * dimension: one blob per document, shared by everybody. So whichever visitor
	 * happens to build the cache decides what every later visitor sees. If a
	 * member builds it, the restricted section is baked in and served to anonymous
	 * visitors for up to 24 hours. That is a straight content leak, and it is
	 * invisible in testing because it only appears once a member has loaded the
	 * page before an anonymous one does.
	 *
	 * Declaring the element dynamic forces the shortcode path, so the element is
	 * re-rendered per request and `should_render` is consulted every time.
	 *
	 * The check covers DESCENDANTS as well as the element itself. A restricted
	 * widget inside an unrestricted static section would otherwise be frozen along
	 * with its parent, which reintroduces the same leak one level down.
	 *
	 * @param bool                    $is_dynamic Elementor's decision so far.
	 * @param array                   $raw_data   The element's raw data.
	 * @param \Elementor\Element_Base $element    Element.
	 * @return bool
	 */
	public function policy_is_dynamic_content( $is_dynamic, $raw_data, $element = null ) {
		if ( $is_dynamic ) {
			return $is_dynamic;
		}

		return self::subtree_carries_policy( is_array( $raw_data ) ? $raw_data : array() );
	}

	/**
	 * Whether a raw element node, or anything beneath it, carries a policy.
	 *
	 * @param array $node Raw element data.
	 * @return bool
	 */
	public static function subtree_carries_policy( array $node ): bool {
		$settings = isset( $node['settings'] ) && is_array( $node['settings'] )
			? $node['settings']
			: array();

		if ( null !== self::policy_from_settings( $settings ) ) {
			return true;
		}

		$children = isset( $node['elements'] ) && is_array( $node['elements'] )
			? $node['elements']
			: array();

		foreach ( $children as $child ) {
			if ( is_array( $child ) && self::subtree_carries_policy( $child ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Do this element's display conditions admit the current visitor?
	 *
	 * Returns true when conditions are switched off or none are chosen, which
	 * is the overwhelmingly common case and must cost nothing.
	 *
	 * An unknown provider or a malformed condition DENIES, and the reason is
	 * logged for an administrator rather than surfaced to the visitor. The
	 * reference implementation passes in both cases, which turns a deactivated
	 * plugin into a silent disclosure.
	 *
	 * @param array $settings Element settings.
	 * @return bool
	 */
	public static function conditions_pass( array $settings ): bool {
		if ( empty( $settings[ self::CONDITIONS_ENABLED_KEY ] )
			|| 'yes' !== $settings[ self::CONDITIONS_ENABLED_KEY ] ) {
			return true;
		}

		$conditions = isset( $settings[ self::CONDITIONS_KEY ] ) && is_array( $settings[ self::CONDITIONS_KEY ] )
			? $settings[ self::CONDITIONS_KEY ]
			: array();

		$match = ( isset( $settings[ self::CONDITIONS_MATCH_KEY ] )
			&& Agend_Content_Access_Conditions::MATCH_ALL === $settings[ self::CONDITIONS_MATCH_KEY ] )
			? Agend_Content_Access_Conditions::MATCH_ALL
			: Agend_Content_Access_Conditions::MATCH_ANY;

		$invert = ! empty( $settings[ self::CONDITIONS_INVERT_KEY ] )
			&& 'yes' === $settings[ self::CONDITIONS_INVERT_KEY ];

		$result = Agend_Content_Access_Conditions::evaluate(
			$conditions,
			Agend_Content_Access_Condition_Runtime::checker(),
			$match,
			$invert
		);

		if ( array() !== $result['unknown'] ) {
			// Loud in the log, silent to the visitor. An administrator needs to
			// know a rule is broken; a visitor must not learn which providers a
			// site uses.
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'[Agend Content Access] Denied an element: unrecognised display conditions (%s). A provider plugin may be inactive.',
					implode( ', ', $result['unknown'] )
				)
			);
		}

		return (bool) $result['passes'];
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

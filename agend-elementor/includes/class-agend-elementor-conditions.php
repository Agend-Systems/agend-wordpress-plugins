<?php
/**
 * Custom usermeta display conditions for Agend Elementor Widgets.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds an "Agend Display Conditions" section to every Elementor element's
 * Advanced tab, letting an editor restrict rendering to visitors whose
 * WordPress usermeta matches one or more rules.
 *
 * Elementor's own conditions systems (Theme Builder `register_conditions`,
 * Pro popup "Advanced Rules") only govern whole-template assignment and are
 * Elementor Pro-only with no documented third-party registration API for
 * per-widget rules. This mirrors the approach used by established
 * third-party plugins (Dynamic Content for Elementor, Visibility Logic for
 * Elementor): a self-contained control plus a render-time check, requiring
 * only free Elementor.
 */
class Agend_Elementor_Conditions {

	/**
	 * Advanced-tab section id added to every element.
	 *
	 * @var string
	 */
	const SECTION_ID = 'agend_conditions_section';

	/**
	 * Setting key: whether the condition is enabled for this element.
	 *
	 * @var string
	 */
	const ENABLE_KEY = 'agend_conditions_enabled';

	/**
	 * Setting key: how multiple rules combine (all/any).
	 *
	 * @var string
	 */
	const RELATION_KEY = 'agend_conditions_relation';

	/**
	 * Setting key: the repeater of usermeta rules.
	 *
	 * @var string
	 */
	const RULES_KEY = 'agend_conditions_rules';

	/**
	 * Element instances (by spl_object_id) already given the conditions
	 * section during this request, so it is added once per element class
	 * rather than once per section in that class's control registration.
	 *
	 * @var array<int, true>
	 */
	private array $processed_elements = array();

	/**
	 * Registers Elementor hooks.
	 */
	public function __construct() {
		add_action( 'elementor/element/after_section_end', array( $this, 'maybe_add_conditions_section' ), 10, 2 );

		foreach ( array( 'widget', 'section', 'column', 'container' ) as $element_type ) {
			add_filter( "elementor/frontend/{$element_type}/should_render", array( $this, 'maybe_suppress_render' ), 10, 2 );
		}

		add_action( 'elementor/element/after_add_attributes', array( $this, 'maybe_add_editor_badge_attributes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_editor_styles' ), 20 );
	}

	/**
	 * Adds the "Agend Display Conditions" section to an element's Advanced
	 * tab, once per element class.
	 *
	 * Elementor caches each element class's control registration for the
	 * life of the request (`Controls_Stack::get_stack()`), so
	 * `register_controls()` — and therefore this callback — runs once per
	 * class, not once per element instance placed on a page. The same
	 * `$element` instance is reused for every `after_section_end` call within
	 * one class's registration, so `spl_object_id()` reliably dedupes across
	 * that class's own sections while still firing for every other class.
	 *
	 * @param \Elementor\Controls_Stack $element    The element/control stack being registered.
	 * @param string                    $section_id The section that just ended.
	 */
	public function maybe_add_conditions_section( $element, $section_id ): void {
		$object_id = spl_object_id( $element );

		if ( isset( $this->processed_elements[ $object_id ] ) ) {
			return;
		}
		$this->processed_elements[ $object_id ] = true;

		$element->start_controls_section(
			self::SECTION_ID,
			array(
				'label' => __( 'Agend Display Conditions', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_ADVANCED,
			)
		);

		$element->add_control(
			self::ENABLE_KEY,
			array(
				'label'       => __( 'Restrict by user meta', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::SWITCHER,
				'label_on'    => __( 'Yes', 'agend-elementor' ),
				'label_off'   => __( 'No', 'agend-elementor' ),
				'default'     => '',
				'description' => __( 'When enabled, this element only renders for visitors whose WordPress user meta matches the rules below. Logged-out visitors are treated as having no user meta, so only "Does not equal" / "Does not exist" rules can match them.', 'agend-elementor' ),
			)
		);

		$element->add_control(
			self::RELATION_KEY,
			array(
				'label'     => __( 'Match', 'agend-elementor' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'options'   => array(
					'all' => __( 'All rules (AND)', 'agend-elementor' ),
					'any' => __( 'Any rule (OR)', 'agend-elementor' ),
				),
				'default'   => 'all',
				'condition' => array( self::ENABLE_KEY => 'yes' ),
			)
		);

		$element->add_control(
			self::RULES_KEY,
			array(
				'label'       => __( 'Rules', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::REPEATER,
				'fields'      => array(
					array(
						'name'        => 'meta_key',
						'label'       => __( 'User meta key', 'agend-elementor' ),
						'type'        => \Elementor\Controls_Manager::TEXT,
						'placeholder' => 'crm_member_tier',
					),
					array(
						'name'    => 'operator',
						'label'   => __( 'Operator', 'agend-elementor' ),
						'type'    => \Elementor\Controls_Manager::SELECT,
						'options' => array(
							'equals'       => __( 'Equals', 'agend-elementor' ),
							'not_equals'   => __( 'Does not equal', 'agend-elementor' ),
							'contains'     => __( 'Contains', 'agend-elementor' ),
							'not_contains' => __( 'Does not contain', 'agend-elementor' ),
							'exists'       => __( 'Exists', 'agend-elementor' ),
							'not_exists'   => __( 'Does not exist', 'agend-elementor' ),
						),
						'default' => 'equals',
					),
					array(
						'name'        => 'value',
						'label'       => __( 'Value', 'agend-elementor' ),
						'type'        => \Elementor\Controls_Manager::TEXT,
						'placeholder' => __( 'Ignored for Exists / Does not exist', 'agend-elementor' ),
					),
				),
				'default'     => array(),
				'title_field' => '{{{ "undefined" !== typeof meta_key && meta_key ? meta_key : "New rule" }}}',
				'condition'   => array( self::ENABLE_KEY => 'yes' ),
			)
		);

		$element->end_controls_section();
	}

	/**
	 * Suppresses an element's entire render (including its wrapper markup)
	 * when its usermeta conditions do not match the current visitor.
	 *
	 * Never suppresses inside the editor — Elementor's editor preview renders
	 * server-side through this same filter, and editors always see their own
	 * content regardless of the currently logged-in user's meta (see
	 * `maybe_add_editor_badge_attributes()` for the editor-only visual
	 * indicator instead).
	 *
	 * @param bool                      $should_render Whether Elementor would otherwise render the element.
	 * @param \Elementor\Element_Base   $element       The element being rendered.
	 * @return bool Whether the element should render.
	 */
	public function maybe_suppress_render( $should_render, $element ) {
		if ( ! $should_render ) {
			return $should_render;
		}

		if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			return $should_render;
		}

		if ( ! self::evaluate( $element->get_settings_for_display() ) ) {
			return false;
		}

		return $should_render;
	}

	/**
	 * Evaluates an element's condition settings against the current visitor.
	 *
	 * @param array $settings Element settings, as returned by `get_settings_for_display()`.
	 * @return bool True when the element should be visible (or has no condition configured).
	 */
	public static function evaluate( array $settings ): bool {
		if ( empty( $settings[ self::ENABLE_KEY ] ) || 'yes' !== $settings[ self::ENABLE_KEY ] ) {
			return true;
		}

		$rules = isset( $settings[ self::RULES_KEY ] ) && is_array( $settings[ self::RULES_KEY ] ) ? $settings[ self::RULES_KEY ] : array();

		if ( empty( $rules ) ) {
			return true;
		}

		$relation = ( 'any' === ( $settings[ self::RELATION_KEY ] ?? 'all' ) ) ? 'any' : 'all';
		$user_id  = get_current_user_id();

		foreach ( $rules as $rule ) {
			$rule_matches = self::evaluate_rule( $user_id, (array) $rule );

			if ( 'any' === $relation && $rule_matches ) {
				return true;
			}

			if ( 'all' === $relation && ! $rule_matches ) {
				return false;
			}
		}

		return 'all' === $relation;
	}

	/**
	 * Evaluates a single usermeta rule.
	 *
	 * A logged-out visitor (`$user_id === 0`) is treated as having no
	 * usermeta at all, so only `not_equals`/`not_exists` rules can pass for
	 * them.
	 *
	 * @param int   $user_id Current user id, 0 when logged out.
	 * @param array $rule    Repeater row: `meta_key`, `operator`, `value`.
	 * @return bool Whether the rule matches.
	 */
	private static function evaluate_rule( int $user_id, array $rule ): bool {
		$meta_key = isset( $rule['meta_key'] ) ? trim( (string) $rule['meta_key'] ) : '';

		if ( '' === $meta_key ) {
			return true; // An unconfigured rule never fails a set.
		}

		$operator = isset( $rule['operator'] ) ? (string) $rule['operator'] : 'equals';
		$expected = isset( $rule['value'] ) ? (string) $rule['value'] : '';
		$has_meta = ( 0 !== $user_id ) && metadata_exists( 'user', $user_id, $meta_key );
		$actual   = $has_meta ? (string) get_user_meta( $user_id, $meta_key, true ) : '';

		switch ( $operator ) {
			case 'exists':
				return $has_meta;
			case 'not_exists':
				return ! $has_meta;
			case 'not_equals':
				return ! $has_meta || $actual !== $expected;
			case 'contains':
				return $has_meta && '' !== $expected && false !== strpos( $actual, $expected );
			case 'not_contains':
				return ! $has_meta || '' === $expected || false === strpos( $actual, $expected );
			case 'equals':
			default:
				return $has_meta && $actual === $expected;
		}
	}

	/**
	 * Marks a conditioned element's wrapper so the editor can show a visual
	 * "conditionally hidden" badge, without ever suppressing the element in
	 * the editor itself.
	 *
	 * @param \Elementor\Element_Base $element The element about to render its wrapper.
	 */
	public function maybe_add_editor_badge_attributes( $element ): void {
		if ( ! \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			return;
		}

		$settings = $element->get_settings_for_display();

		if ( empty( $settings[ self::ENABLE_KEY ] ) || 'yes' !== $settings[ self::ENABLE_KEY ] ) {
			return;
		}

		$rules      = isset( $settings[ self::RULES_KEY ] ) && is_array( $settings[ self::RULES_KEY ] ) ? $settings[ self::RULES_KEY ] : array();
		$relation   = ( 'any' === ( $settings[ self::RELATION_KEY ] ?? 'all' ) ) ? __( 'ANY', 'agend-elementor' ) : __( 'ALL', 'agend-elementor' );
		$rule_count = count( $rules );

		$element->add_render_attribute(
			'_wrapper',
			array(
				'class'                         => 'agend-conditional-element',
				'data-agend-conditions-relation' => $relation,
				'data-agend-conditions-count'    => (string) $rule_count,
			)
		);
	}

	/**
	 * Enqueues the editor-only badge stylesheet, only inside Elementor's live
	 * preview (never on a real frontend pageload).
	 */
	public function maybe_enqueue_editor_styles(): void {
		if ( ! did_action( 'elementor/loaded' ) || ! \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			return;
		}

		wp_enqueue_style(
			'agend-elementor-conditions-editor',
			AGEND_ELEMENTOR_URL . 'assets/css/conditions-editor.css',
			array(),
			AGEND_ELEMENTOR_VERSION
		);
	}
}

new Agend_Elementor_Conditions();

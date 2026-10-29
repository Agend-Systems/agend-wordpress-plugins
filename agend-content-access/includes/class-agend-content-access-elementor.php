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
 * This replaces it with one rule list per element
 * (`Agend_Content_Access_Rules::from_elementor_settings()`), judged by the one
 * evaluator, `Agend_Content_Access_Access`, against a member standing Agend
 * resolved, and fails closed at every branch.
 *
 * ONE LIST, NOT TWO SETTINGS. The section shows who can see the element as one
 * searchable list of rules, with Match, "Show to everyone except these people" and
 * "Show instead" under it, the same words the block editor's panel uses. The
 * section used to show an audience select AND "Add display conditions", two
 * settings an author could not tell apart.
 *
 * OLDER SETTINGS ARE NEVER DROPPED. An element saved before the rule list keeps its
 * `agend_access_mode` (and its plan, segment and entitlement lists) and its
 * `agend_conditions_*` keys, and `Agend_Content_Access_Rules::from_elementor_settings()`
 * still enforces them, alongside any rules, as an intersection that can only narrow.
 * The new controls cannot show them, and a restriction an author cannot see would be
 * a trap, so an element that still holds one shows a notice and the controls that
 * remove it. Those controls are hidden on every element that does not.
 *
 * A RULE THE AUTHOR CANNOT SEE IS KEPT. A multi-select writes back only the values it
 * shows, so a stored rule the list does not offer was lost on the next edit of the
 * list, and under "all of these" or "show to everyone except these people" losing a
 * rule widens who sees the element. Two things stop it. The editor offers every rule
 * the element holds: a plan that was switched off as "Plan (inactive): Gold", and one
 * the list cannot name as "Unavailable: <rule>" (`rule_list_options()`). And a save
 * puts back any stored rule the list does not offer that the saved data has lost
 * (`preserve_unseen_rules()`).
 *
 * Suppression only ever REMOVES an element. It cannot reveal one the document
 * hides, because an element is shown only when its own rules AND the rules of its
 * document both allow (Decision 2.3).
 */
class Agend_Content_Access_Elementor {

	const SECTION_ID = 'agend_access_section';

	/**
	 * The keys of the new controls, aliased from the editor-neutral definitions, whose
	 * one reader is `Agend_Content_Access_Rules::from_elementor_settings()`. They cannot
	 * drift from what it reads because they ARE what it reads.
	 */
	const RULES_KEY  = Agend_Content_Access_Rules::ELEMENTOR_RULES_KEY;
	const MATCH_KEY  = Agend_Content_Access_Rules::ELEMENTOR_MATCH_KEY;
	const INVERT_KEY = Agend_Content_Access_Rules::ELEMENTOR_INVERT_KEY;

	/**
	 * The keys the earlier releases wrote, which are still read and still enforced.
	 *
	 * Only the audience mode and the display conditions switch have a control now, to
	 * remove what they hold. The plan, segment and entitlement lists and the conditions,
	 * match and reverse keys are no longer registered as controls: Elementor passes a
	 * stored setting with no control through to `get_settings_for_display()` untouched,
	 * so what they hold keeps applying until the mode or the switch is turned off.
	 */
	const MODE_KEY         = Agend_Content_Access_Policy::FRAGMENT_MODE_KEY;
	const TIERS_KEY        = Agend_Content_Access_Policy::FRAGMENT_TIERS_KEY;
	const SEGMENTS_KEY     = Agend_Content_Access_Policy::FRAGMENT_SEGMENTS_KEY;
	const ENTITLEMENTS_KEY = Agend_Content_Access_Policy::FRAGMENT_ENTITLEMENTS_KEY;

	const CONDITIONS_ENABLED_KEY = Agend_Content_Access_Fragment_Rules::ENABLED_KEY;
	const CONDITIONS_KEY         = Agend_Content_Access_Fragment_Rules::CONDITIONS_KEY;
	const CONDITIONS_MATCH_KEY   = Agend_Content_Access_Fragment_Rules::MATCH_KEY;
	const CONDITIONS_INVERT_KEY  = Agend_Content_Access_Fragment_Rules::INVERT_KEY;

	/**
	 * The replacement content's key. It is the old conditions' key, kept: the control
	 * is the same "Show instead", and an element that already has one keeps it.
	 */
	const FALLBACK_KEY            = Agend_Content_Access_Fragment_Rules::FALLBACK_KEY;
	const CONDITIONS_FALLBACK_KEY = Agend_Content_Access_Fragment_Rules::FALLBACK_KEY;

	/**
	 * The notice shown on an element that still holds an older setting.
	 */
	const OLDER_NOTICE_ID = 'agend_access_older_notice';

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
	 * The section's controls, with their options, built once per request.
	 *
	 * Elementor registers a control stack for every widget type when the editor
	 * loads, and the rule list is the largest thing in it: one SELECT2 option per plan,
	 * segment, entitlement and role, about 800 entitlements on one account. A list that
	 * could not be loaded is never cached, so building it per stack would ask the
	 * gateway again each time, during an outage as much as at any other time. The
	 * options are the same for every stack, so they are read once, and every stack
	 * shares the one array.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $controls = null;

	/**
	 * Registers hooks. Only ever called when Elementor is loaded.
	 */
	public function __construct() {
		add_action( 'elementor/element/after_section_end', array( $this, 'maybe_add_section' ), 10, 3 );

		foreach ( array( 'widget', 'section', 'column', 'container' ) as $type ) {
			add_filter( "elementor/frontend/{$type}/should_render", array( $this, 'maybe_suppress' ), 10, 2 );

			// `after_render` fires OUTSIDE Elementor's `if ( $should_render )`
			// block, so it still runs for an element that was suppressed. That
			// is what makes replacement content possible at all.
			add_action( "elementor/frontend/{$type}/after_render", array( $this, 'maybe_render_fallback' ), 10, 1 );
		}

		add_action( 'elementor/element/after_add_attributes', array( $this, 'maybe_mark_in_editor' ) );

		// After Elementor's own content sanitiser (10), so what it leaves is what is checked.
		add_filter( 'elementor/document/save/data', array( $this, 'preserve_unseen_rules' ), 20, 2 );

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

		if ( null === $this->controls ) {
			$this->controls = self::access_controls();
		}

		foreach ( $this->controls as $control_id => $control_args ) {
			$element->add_control( $control_id, $control_args );
		}

		$element->end_controls_section();
	}

	/**
	 * The section's controls, in the order they appear.
	 *
	 * Public to be testable without an Elementor control stack. The first four are the
	 * rule list and what goes with it. They are shown for every element, and the last
	 * three are the older settings: they are shown on an element only while it still
	 * holds one, so they cost an author who has none nothing and never offer a way to
	 * set one up.
	 *
	 * HOW A CONTROL IS HIDDEN, AND WHAT THAT DOES TO ENFORCEMENT. Elementor reads a
	 * control whose condition is not met as unset (`null`) in `get_settings_for_display()`,
	 * which is what suppression reads. Every condition below hides a control only where
	 * its value could not change who sees the element: Match and the reverse switch
	 * mean nothing without rules, "Show instead" is only ever shown in place of an
	 * element something else hid, and the older mode and conditions switch are hidden
	 * only when they are off. The rule list itself has no condition.
	 *
	 * The editor evaluates the same conditions in the browser, where a list "not empty"
	 * cannot be told from an empty one (two arrays are never identical there), so it
	 * shows Match, the reverse switch and "Show instead" whether or not a rule is
	 * chosen. The server is exact, and what it reads is what counts.
	 *
	 * THE OPTIONS ARE BUILT IN THE EDITOR ONLY. The rule list and "Show instead" are
	 * the expensive part of this section: a catalogue read for the rules and three
	 * queries for the replacement content, and a control stack is registered on every
	 * front-end render of an Elementor page as well. The options are what the author
	 * picks from and nothing else. Elementor does not check a stored value against them
	 * when it renders, so enforcement reads the same settings with or without them, and a
	 * visitor's render is spared the cost.
	 *
	 * @return array<string, array<string, mixed>> Control id to Elementor control args.
	 */
	public static function access_controls(): array {
		$rules_chosen = array(
			'name'     => self::RULES_KEY,
			'operator' => '!==',
			'value'    => array(),
		);

		$conditions_on = array(
			'name'     => self::CONDITIONS_ENABLED_KEY,
			'operator' => '==',
			'value'    => 'yes',
		);

		$older_mode_set = array(
			'name'     => self::MODE_KEY,
			'operator' => '!in',
			'value'    => array( Agend_Content_Access_Policy::MODE_INHERIT, '' ),
		);

		return array(
			self::RULES_KEY               => array(
				'label'       => __( 'Who can see this', 'agend-content-access' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'multiple'    => true,
				'options'     => self::rule_list_options(),
				'default'     => array(),
				'label_block' => true,
				'description' => __(
					'Leave empty to show this to everyone who can see the page. Visitors who are not allowed never receive this element.',
					'agend-content-access'
				),
			),
			self::MATCH_KEY               => array(
				'label'      => __( 'Match', 'agend-content-access' ),
				'type'       => \Elementor\Controls_Manager::SELECT,
				'default'    => Agend_Content_Access_Rules::MATCH_ANY,
				'options'    => array(
					Agend_Content_Access_Rules::MATCH_ANY => __( 'Any of these', 'agend-content-access' ),
					Agend_Content_Access_Rules::MATCH_ALL => __( 'All of these', 'agend-content-access' ),
				),
				'conditions' => array( 'terms' => array( $rules_chosen ) ),
			),
			self::INVERT_KEY              => array(
				'label'        => __( 'Show to everyone except these people', 'agend-content-access' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
				'conditions'   => array( 'terms' => array( $rules_chosen ) ),
				'description'  => __( 'Useful for a join banner that members should not see.', 'agend-content-access' ),
			),
			self::FALLBACK_KEY            => array(
				'label'       => __( 'Show instead', 'agend-content-access' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => '',
				'options'     => self::show_instead_options(),
				'label_block' => true,
				// Also an element with only older display conditions, which had a "Show
				// instead" before: hiding it there would drop the content they show.
				'conditions'  => array(
					'relation' => 'or',
					'terms'    => array( $rules_chosen, $conditions_on ),
				),
				'description' => __(
					'Optional. Content to show to visitors who are not allowed, for example a sign-in prompt. Only public content can be chosen: anything restricted would be shown to exactly the people who were just refused.',
					'agend-content-access'
				),
			),
			self::OLDER_NOTICE_ID         => array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'This element still uses an older access setting, which keeps applying alongside the rules above. You can remove it below.', 'agend-content-access' ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-warning',
				'conditions'      => array(
					'relation' => 'or',
					'terms'    => array( $older_mode_set, $conditions_on ),
				),
			),
			self::MODE_KEY                => array(
				'label'       => __( 'Older audience setting', 'agend-content-access' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => Agend_Content_Access_Policy::MODE_INHERIT,
				'options'     => array(
					Agend_Content_Access_Policy::MODE_INHERIT      => __( 'Remove', 'agend-content-access' ),
					Agend_Content_Access_Policy::MODE_MEMBERS      => __( 'All active members', 'agend-content-access' ),
					Agend_Content_Access_Policy::MODE_TIERS        => __( 'Selected membership plans', 'agend-content-access' ),
					Agend_Content_Access_Policy::MODE_SEGMENTS     => __( 'Selected segments', 'agend-content-access' ),
					Agend_Content_Access_Policy::MODE_ENTITLEMENTS => __( 'Selected entitlements', 'agend-content-access' ),
				),
				'conditions'  => array( 'terms' => array( $older_mode_set ) ),
				'description' => __(
					'Choose Remove to stop applying it. Only this older audience is removed: the rules above stay.',
					'agend-content-access'
				),
			),
			self::CONDITIONS_ENABLED_KEY  => array(
				'label'        => __( 'Older display conditions', 'agend-content-access' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
				'conditions'   => array( 'terms' => array( $conditions_on ) ),
				'description'  => __(
					'Switch off to remove them. Only these older conditions are removed: the rules above stay.',
					'agend-content-access'
				),
			),
		);
	}

	/**
	 * Whether Elementor's editor is open on this request: the editor itself and its own
	 * AJAX, which is where the options are drawn.
	 *
	 * @return bool
	 */
	private static function in_editor(): bool {
		if ( ! agend_content_access_has_elementor() ) {
			return false;
		}

		$plugin = \Elementor\Plugin::$instance;

		return is_object( $plugin ) && isset( $plugin->editor ) && is_object( $plugin->editor ) && $plugin->editor->is_edit_mode();
	}

	/**
	 * The options of the "Who can see this" list: empty outside the editor, and in it
	 * everything the author may pick plus every rule the document being edited already
	 * holds that the list would not name.
	 *
	 * The stored rules are read from the document, once, when the editor loads, and added
	 * as "Unavailable" entries (or an alias under its rule's name), so they are shown, and
	 * stay in the list when the author changes it. See the class docblock.
	 *
	 * @return array<string, string>
	 */
	private static function rule_list_options(): array {
		if ( ! self::in_editor() ) {
			return array();
		}

		$held = array();

		foreach ( self::stored_rules_by_element( self::rendering_document_id() ) as $rules ) {
			$held = array_merge( $held, $rules );
		}

		return Agend_Content_Access_Condition_Sets::rule_options() + Agend_Content_Access_Condition_Sets::unavailable_options( $held );
	}

	/**
	 * The options of "Show instead": "Nothing" outside the editor, and in it the public
	 * pages, patterns and templates that can be chosen.
	 *
	 * @return array<string, string>
	 */
	private static function show_instead_options(): array {
		return self::in_editor()
			? Agend_Content_Access_Condition_Sets::fallback_options()
			: array( '' => __( 'Nothing', 'agend-content-access' ) );
	}

	/**
	 * The rules each element of a stored document holds, by element id.
	 *
	 * Read from the document's `_elementor_data`, the JSON Elementor saves. An element with
	 * no rules is left out, and so is an entry that is not a string.
	 *
	 * @param int $post_id The post whose document to read.
	 * @return array<string, string[]> Element id to the rules it holds.
	 */
	private static function stored_rules_by_element( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return array();
		}

		$stored = get_post_meta( $post_id, '_elementor_data', true );

		if ( is_string( $stored ) && '' !== $stored ) {
			$stored = json_decode( $stored, true );
		}

		$found = array();

		if ( is_array( $stored ) ) {
			self::collect_rules( $stored, $found );
		}

		return $found;
	}

	/**
	 * Walks a list of elements and every list inside it, collecting the rules each holds.
	 *
	 * @param array                $elements Elements, as Elementor stores them.
	 * @param array<string, array> $found    Element id to rules, added to.
	 */
	private static function collect_rules( array $elements, array &$found ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$rules = isset( $element['settings'] ) && is_array( $element['settings'] ) ? ( $element['settings'][ self::RULES_KEY ] ?? null ) : null;

			if ( isset( $element['id'] ) && is_string( $element['id'] ) && is_array( $rules ) ) {
				$strings = array_values( array_filter( $rules, 'is_string' ) );

				if ( array() !== $strings ) {
					$found[ $element['id'] ] = $strings;
				}
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::collect_rules( $element['elements'], $found );
			}
		}
	}

	/**
	 * Puts back a rule an element held that the author could neither see nor remove,
	 * where losing it would widen who sees the element.
	 *
	 * Runs on `elementor/document/save/data`, the filter Elementor applies to the whole
	 * document before it saves it (3.3 and later), draft and autosave saves included, and
	 * the one place the saved elements can be changed. For each element it saved, a rule the
	 * element held before that the saved data has lost, and that the list does not offer,
	 * is a candidate. "Does not offer" is exactly what `rule_options()` returns now: a
	 * plan that was switched off IS offered, so an author who removes one means it, and so
	 * is anything the catalogue can name. A slug alias is not a choice of its own, and the
	 * list is built from the document the editor was opened on, so an element edited in
	 * place in another document (a header or footer part, a global widget) was never
	 * offered the alias it holds, and its save would drop it. So a lost alias is a
	 * candidate too, unless the rule it stands for is in the saved list.
	 *
	 * That leaves the rules nobody can name, a plan, segment or entitlement that was
	 * deleted, and every Agend rule for as long as the catalogue cannot be loaded. The
	 * editor shows those as "Unavailable", but a multi-select that does not offer a value
	 * cannot be trusted to hand it back.
	 *
	 * A candidate is put back ONLY WHERE LOSING IT WIDENS: when the saved element matches
	 * "all of these", when it is reversed ("everyone except these people"), or when it
	 * would otherwise be left with no rules at all (which shows it to everyone). Under
	 * "any of these" with other rules left, dropping a rule only narrows the audience, so a
	 * rule the author removed stays removed. A rule that matches nobody is not the safe
	 * side in every mode: reversed, it is a rule that lets everyone in. An element whose
	 * match or reverse setting is not one this reads is treated as widening, so doubt keeps
	 * the rule. THE COST IS HONEST: under those modes an author cannot remove an
	 * "Unavailable" rule from an element until the catalogue offers it again or the
	 * element is replaced.
	 *
	 * Only ever adds. It never removes a rule, never touches an element it has no stored
	 * copy of (a new one, or one whose id changed), and never reads the editor's own
	 * options, because a save is not an editor request: the options are asked for here.
	 *
	 * @param mixed $data     The document data being saved: `elements` and `settings`.
	 * @param mixed $document The `\Elementor\Core\Base\Document` being saved.
	 * @return mixed
	 */
	public function preserve_unseen_rules( $data, $document = null ) {
		if ( ! is_array( $data ) || ! isset( $data['elements'] ) || ! is_array( $data['elements'] ) || ! is_object( $document ) || ! method_exists( $document, 'get_main_id' ) ) {
			return $data;
		}

		$before = self::stored_rules_by_element( (int) $document->get_main_id() );

		if ( array() === $before ) {
			return $data;
		}

		$data['elements'] = self::restore_rules(
			$data['elements'],
			$before,
			array_keys( Agend_Content_Access_Condition_Sets::rule_options() ),
			Agend_Content_Access_Condition_Sets::rule_aliases()
		);

		return $data;
	}

	/**
	 * Adds the stored rules a list of saved elements has lost, in every element and every
	 * list inside it, where losing them widens.
	 *
	 * @param array                $elements Saved elements.
	 * @param array<string, array> $before   Element id to the rules it held.
	 * @param string[]             $offered  Rules the list offers as choices.
	 * @param array<string, string> $aliases  Slug aliases: alias to the rule it stands for.
	 * @return array
	 */
	private static function restore_rules( array $elements, array $before, array $offered, array $aliases ): array {
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['id'] ) && is_string( $element['id'] ) && isset( $before[ $element['id'] ] ) ) {
				$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
				$saved    = isset( $settings[ self::RULES_KEY ] ) && is_array( $settings[ self::RULES_KEY ] ) ? array_values( $settings[ self::RULES_KEY ] ) : array();
				$lost     = self::lost_rules( $before[ $element['id'] ], $saved, $offered, $aliases );

				if ( array() !== $lost && self::losing_widens( $settings, $saved ) ) {
					$settings[ self::RULES_KEY ] = array_merge( $saved, $lost );
					$element['settings']         = $settings;
				}
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = self::restore_rules( $element['elements'], $before, $offered, $aliases );
			}

			$elements[ $index ] = $element;
		}

		return $elements;
	}

	/**
	 * The stored rules a saved element has lost that are candidates to be put back.
	 *
	 * A rule the list offers as a choice is the author's to remove. A rule it does not
	 * name at all is a candidate. A slug alias is a candidate when the rule it stands for
	 * is not in the saved list either: the author who dropped it in favour of the rule it
	 * stands for keeps that rule, and one who never saw it (it was not offered where the
	 * element was edited in place, such as a header or footer part or a global widget,
	 * which the list is not built from) has neither, so it is the same loss.
	 *
	 * @param array                 $before  Rules the element held.
	 * @param array                 $saved   Rules the saved element holds.
	 * @param string[]              $offered Rules the list offers as choices.
	 * @param array<string, string> $aliases Alias to the rule it stands for.
	 * @return string[]
	 */
	private static function lost_rules( array $before, array $saved, array $offered, array $aliases ): array {
		$lost = array();

		foreach ( $before as $rule ) {
			if ( in_array( $rule, $saved, true ) || in_array( $rule, $offered, true ) ) {
				continue;
			}

			if ( isset( $aliases[ $rule ] ) && in_array( $aliases[ $rule ], $saved, true ) ) {
				continue;
			}

			$lost[] = $rule;
		}

		return array_values( array_unique( $lost ) );
	}

	/**
	 * Whether an element, as saved, is one a lost rule widens the audience of.
	 *
	 * Yes when it would be left with no rules (no rules is everyone), when it is reversed,
	 * and when it matches anything but "any of these". Only plain "any of these" with
	 * other rules left narrows. A match or reverse value this does not read is a yes.
	 *
	 * @param array $settings Saved element settings.
	 * @param array $saved    The rules the saved element holds.
	 * @return bool
	 */
	private static function losing_widens( array $settings, array $saved ): bool {
		$left = array_filter(
			$saved,
			static function ( $rule ): bool {
				return is_string( $rule ) && '' !== $rule;
			}
		);

		if ( array() === $left ) {
			return true;
		}

		$invert = $settings[ self::INVERT_KEY ] ?? '';

		if ( '' !== $invert && false !== $invert && null !== $invert ) {
			return true;
		}

		$match = $settings[ self::MATCH_KEY ] ?? '';

		return ! ( '' === $match || Agend_Content_Access_Rules::MATCH_ANY === $match );
	}

	/**
	 * Whether an element still holds a setting from the earlier releases.
	 *
	 * An audience mode other than "same as the page", or display conditions switched
	 * on. It reads the settings as `Agend_Content_Access_Rules::from_elementor_settings()`
	 * does, so what is called older here is exactly what that still enforces.
	 *
	 * @param array $settings Element settings.
	 * @return bool
	 */
	public static function has_older_settings( array $settings ): bool {
		return null !== Agend_Content_Access_Policy::from_fragment_settings( $settings )
			|| Agend_Content_Access_Fragment_Rules::conditions_enabled( $settings );
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
	 * Whether the render in progress is the author's, in front of an editor.
	 *
	 * Not the same question as `Frontend::is_authoring_context()`, which is about
	 * the requested page. Here a request is authoring when it is not one a visitor
	 * can be reading (a wp-admin screen, the editor), or when it is the editor's own
	 * AJAX. Everything else that reaches admin-ajax.php is a visitor.
	 *
	 * Both are asked of the DOCUMENT being rendered, not of the request. Elementor's
	 * edit mode is true for the whole editor AJAX request once the user may edit the
	 * one document the editor names, and that request can render others: a Loop
	 * Grid listing posts, a Template widget pulling in a template, a shortcode. A
	 * contributor who opens the editor on their own draft could otherwise put a
	 * widget in it that renders a restricted template, and have every section of
	 * every document that request renders shown to them with suppression off. So the
	 * editor's AJAX is authoring for a document only when the user may edit THAT
	 * document, and for one that cannot be identified it is not authoring at all,
	 * since the rule cannot be applied to a document it cannot name.
	 *
	 * Elementor's preview is authoring for the document being previewed and for no
	 * other. A template, a header, a loop item or an embedded section is a document
	 * of its own, and is suppressed in the preview as it is for a visitor.
	 *
	 * @return bool
	 */
	private static function is_authoring_render(): bool {
		$document_id = self::rendering_document_id();

		if ( ! Agend_Content_Access_Frontend::renders_for_visitors( $document_id ) ) {
			return true;
		}

		return $document_id > 0
			&& Agend_Content_Access_Frontend::is_elementor_editor_ajax()
			&& current_user_can( 'edit_post', $document_id );
	}

	/**
	 * The post whose Elementor document is rendering right now.
	 *
	 * Zero when Elementor does not say, and zero is never the previewed post, so an
	 * element whose document cannot be identified is treated as a visitor's.
	 *
	 * @return int
	 */
	private static function rendering_document_id(): int {
		if ( ! agend_content_access_has_elementor() ) {
			return 0;
		}

		$documents = \Elementor\Plugin::$instance->documents ?? null;

		if ( ! is_object( $documents ) || ! method_exists( $documents, 'get_current' ) ) {
			return 0;
		}

		$document = $documents->get_current();

		return is_object( $document ) && method_exists( $document, 'get_main_id' )
			? (int) $document->get_main_id()
			: 0;
	}

	/**
	 * Suppresses an element the current visitor cannot see.
	 *
	 * Returning false removes the element's ENTIRE render, wrapper markup
	 * included, so nothing is emitted and then hidden.
	 *
	 * Never suppresses in an authoring context: an editor must see the sections
	 * they are laying out, including ones they have just restricted. A request is
	 * authoring only when somebody is actually authoring. admin-ajax.php is not
	 * that: WordPress reports `is_admin()` for it, and JetEngine, Ajax Load More
	 * and their kind render Elementor templates through it for ANONYMOUS visitors,
	 * so treating every `is_admin()` request as authoring stood suppression down
	 * for exactly those renders and printed every restricted section.
	 *
	 * In a feed the element is judged for nobody, conditions and policy both, as the
	 * page gate judges the document and block enforcement judges a block: the
	 * output is shared and cached, whoever fetched it.
	 *
	 * @param bool                    $should_render Elementor's decision so far.
	 * @param \Elementor\Element_Base $element       Element.
	 * @return bool
	 */
	public function maybe_suppress( $should_render, $element ) {
		if ( ! $should_render ) {
			return $should_render;
		}

		if ( self::is_authoring_render() ) {
			return $should_render;
		}

		$rules = Agend_Content_Access_Rules::from_elementor_settings( $element->get_settings_for_display() );

		if ( $rules->is_empty() ) {
			// Inherits the page, which the page-level gate already handled.
			return $should_render;
		}

		// A FEED is judged for nobody. Its output is shared and routinely cached, so
		// a copy personalised to whoever fetched it would be served to everyone
		// after them. Blocks and the page gate do the same.
		$anonymous = is_feed();

		// The element's rules AND the rules of its document, in one evaluation: it
		// can only ever remove an element, so one hidden here is hidden whatever the
		// document says, and the document's rules cannot be widened by it. The
		// enforced reading of the document: unreadable rules narrow the section to
		// nobody, not vanish and leave the section's own alone.
		$verdict = Agend_Content_Access_Access::verdict(
			Agend_Content_Access_Rules::all_of(
				Agend_Content_Access_Rules::from_post( (int) get_queried_object_id() ),
				$rules
			),
			array( 'anonymous' => $anonymous )
		);

		return $verdict['allowed'];
	}

	/**
	 * Treats any element carrying rules as dynamic content.
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

		return self::subtree_carries_rules( is_array( $raw_data ) ? $raw_data : array() );
	}

	/**
	 * Whether a raw element node, or anything beneath it, carries rules.
	 *
	 * Rules personalise per visitor just as much as an audience restricts per
	 * visitor, so an element with either must leave the shared cache. They are asked
	 * of `Rules::from_elementor_settings()`, the same reading `maybe_suppress()`
	 * judges, so the cache decision and the render decision cannot disagree about
	 * what a rule is.
	 *
	 * The US-4.4 fix covered policy-bearing elements only, and the condition control
	 * arrived later. Caught by loading a conditioned page as a member and being
	 * served the anonymous render. The reverse is worse, since a member-warmed cache
	 * would then show member-only sections to anonymous visitors.
	 *
	 * @param array $node Raw element data.
	 * @return bool
	 */
	public static function subtree_carries_rules( array $node ): bool {
		$settings = isset( $node['settings'] ) && is_array( $node['settings'] )
			? $node['settings']
			: array();

		if ( ! Agend_Content_Access_Rules::from_elementor_settings( $settings )->is_empty() ) {
			return true;
		}

		$children = isset( $node['elements'] ) && is_array( $node['elements'] )
			? $node['elements']
			: array();

		foreach ( $children as $child ) {
			if ( is_array( $child ) && self::subtree_carries_rules( $child ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Renders replacement content for an element the conditions excluded.
	 *
	 * Fires on `after_render`, which Elementor calls outside its
	 * `if ( $should_render )` block, so it still runs for a suppressed element.
	 *
	 * THE FALLBACK IS ITSELF ACCESS-CHECKED. This is the one place the
	 * reference implementation gets dangerously wrong: it renders the chosen
	 * post's content unconditionally. An editor who picks a members-only page
	 * as the fallback for a members-only section therefore discloses it to
	 * precisely the people who just failed the check, and the mistake looks
	 * like a thoughtful "here is what you are missing" until somebody notices.
	 *
	 * Here the fallback is only rendered when the CURRENT visitor could have
	 * viewed it on its own. The picker offers public content only, and this is
	 * the enforcement behind that: a page restricted after being chosen is
	 * silently dropped rather than leaked.
	 *
	 * @param \Elementor\Element_Base $element Element that just rendered, or did not.
	 */
	public function maybe_render_fallback( $element ): void {
		if ( self::is_authoring_render() ) {
			return;
		}

		$rules = Agend_Content_Access_Rules::from_elementor_settings( $element->get_settings_for_display() );
		$owner = $rules->fallback_owner();

		if ( null === $owner ) {
			return;
		}

		// Judged for nobody in a feed, as `maybe_suppress()` judges the element, so
		// the two cannot disagree about whether the rules excluded it.
		$anonymous = is_feed();

		// Only an element the part of its rules that carries the fallback excluded
		// gets one. An element hidden by the access policy is protected content, and
		// offering a consolation render there would blur the two.
		$own = Agend_Content_Access_Access::verdict( $owner, array( 'anonymous' => $anonymous ) );

		if ( $own['allowed'] ) {
			return;
		}

		echo Agend_Content_Access_Fragment_Rules::fallback_html( $owner->fallback_id(), $anonymous ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Marks a restricted element in the editor so the author can see which
	 * sections carry rules.
	 *
	 * Any element that carries rules is marked, whether they are the new rule list, an
	 * older audience or older display conditions, or a mix: all of them are read into
	 * the one rule set `maybe_suppress()` judges, so what is marked is what is enforced.
	 * An element that still holds an older setting says so as well, which is the
	 * setting its notice and removal control are about.
	 *
	 * @param \Elementor\Element_Base $element Element about to render its wrapper.
	 */
	public function maybe_mark_in_editor( $element ): void {
		if ( ! self::is_authoring_render() ) {
			return;
		}

		$settings = $element->get_settings_for_display();
		$rules    = Agend_Content_Access_Rules::from_elementor_settings( $settings );

		if ( $rules->is_empty() ) {
			return;
		}

		// The audience the rules say, when an access policy says it; `rules` for a
		// list no policy can (a role, a reverse, "all of"), and for one that cannot
		// be read.
		$policy = $rules->is_unreadable() ? null : $rules->to_policy();
		$mode   = null === $policy || Agend_Content_Access_Policy::UNSATISFIABLE === $policy ? 'rules' : (string) $policy['mode'];

		$attributes = array(
			'class'                  => 'agend-access-restricted',
			'data-agend-access-mode' => $mode,
		);

		if ( self::has_older_settings( $settings ) ) {
			$attributes['data-agend-access-older'] = 'yes';
		}

		$element->add_render_attribute( '_wrapper', $attributes );
	}
}

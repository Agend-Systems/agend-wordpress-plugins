<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Catalogue as Catalogue;
use Agend_Content_Access_Condition_Sets as Sets;
use Agend_Content_Access_Elementor as ElementorControl;
use Agend_Content_Access_Rules as Rules;
use Agend_Test_WP;
use WP_Error;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/has-elementor-double.php';
require_once __DIR__ . '/fixtures/elementor-active-settings-double.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-sets.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-elementor.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-attributes.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-access.php';

/**
 * The controls on an Elementor element: one rule list, and the way to remove an
 * older setting.
 *
 * What these protect: an author sees ONE "Who can see this" (not an audience and
 * display conditions that look alike), drawn from the same vocabulary the block
 * panel uses; an element that still holds a setting from an earlier release is told
 * so and can remove it, and no control is ever hidden in a way that changes who
 * sees an element, because Elementor reads a hidden control as unset.
 *
 * Each test runs in its own process because the control stack double stands in for
 * `Element_Base`, which other tests assert is absent.
 */
#[CoversClass( ElementorControl::class )]
#[RunTestsInSeparateProcesses]
final class ElementorRuleControlsTest extends TestCase {

	private const TIER_A   = '11111111-1111-1111-1111-111111111111';
	private const TIER_OLD = '22222222-2222-2222-2222-222222222222';
	private const SEG_A    = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
	private const SEG_B    = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2';

	/**
	 * Opens Elementor's editor, which is where the options are built. Outside it the
	 * control stacks are registered with none: see `Agend_Content_Access_Elementor::access_controls()`.
	 */
	private function inEditor( bool $open = true ): void {
		Agend_Test_WP::$did_action['elementor/loaded']           = 1;
		\Elementor\Plugin::$instance->editor->is_edit_mode = $open;
	}

	/** Loads the three lists through the cache, the seam the Core wrappers sit behind. */
	private function seed(): void {
		$this->inEditor();

		Agend_Test_WP::$tiers_response = array(
			'data' => array(
				array( 'id' => self::TIER_A, 'name' => 'Full Member', 'slug' => 'full', 'tier_type' => 'individual', 'is_active' => true ),
				array( 'id' => self::TIER_OLD, 'name' => 'Retired', 'slug' => 'retired', 'tier_type' => 'individual', 'is_active' => false ),
			),
		);

		Agend_Test_WP::$transients[ Catalogue::SEGMENTS_TRANSIENT ] = array(
			array( 'id' => self::SEG_A, 'slug' => 'overdue', 'name' => 'Overdue renewals' ),
			array( 'id' => self::SEG_B, 'slug' => 'volunteers', 'name' => 'Volunteers' ),
		);

		Agend_Test_WP::$transients[ Catalogue::ENTITLEMENTS_TRANSIENT ] = array(
			array( 'gate_key' => 'directory.access', 'name' => 'Directory access', 'description' => '', 'kind' => 'platform-gate' ),
			array( 'gate_key' => 'entitlement.early_bird', 'name' => 'Early bird', 'description' => '', 'kind' => 'entitlement' ),
		);
	}

	/** @return array<string, array<string, mixed>> */
	private function controls(): array {
		$this->seed();

		return ElementorControl::access_controls();
	}

	private function stack( string $name = 'section' ): \Elementor\Element_Base {
		require_once __DIR__ . '/fixtures/elementor-element-base-double.php';

		return new \Elementor\Element_Base( $name );
	}

	/** Whether a control is shown for an element that stores these settings. */
	private function shown( string $control, array $stored ): bool {
		$controls = $this->controls();

		return ElementorActiveSettings::visible( $controls[ $control ], ElementorActiveSettings::settings( $controls, $stored ) );
	}

	// -----------------------------------------------------------------
	// Keys
	// -----------------------------------------------------------------

	/** The control ids are the keys the one reader reads, so they cannot drift from it. */
	#[Test]
	public function the_control_keys_are_the_ones_the_rules_reader_reads(): void {
		$this->assertSame( Rules::ELEMENTOR_RULES_KEY, ElementorControl::RULES_KEY );
		$this->assertSame( Rules::ELEMENTOR_MATCH_KEY, ElementorControl::MATCH_KEY );
		$this->assertSame( Rules::ELEMENTOR_INVERT_KEY, ElementorControl::INVERT_KEY );

		// The on-disk names a stored document uses.
		$this->assertSame( 'agend_access_rules', ElementorControl::RULES_KEY );
		$this->assertSame( 'agend_access_match', ElementorControl::MATCH_KEY );
		$this->assertSame( 'agend_access_invert', ElementorControl::INVERT_KEY );
		$this->assertSame( 'agend_conditions_fallback_id', ElementorControl::FALLBACK_KEY, '"Show instead" keeps its key: an element that has one keeps it' );
		$this->assertSame( ElementorControl::FALLBACK_KEY, ElementorControl::CONDITIONS_FALLBACK_KEY );
	}

	// -----------------------------------------------------------------
	// The list, and the order
	// -----------------------------------------------------------------

	#[Test]
	public function the_controls_come_in_a_fixed_order_with_the_older_settings_last(): void {
		$this->assertSame(
			array(
				'agend_access_rules',
				'agend_access_match',
				'agend_access_invert',
				'agend_conditions_fallback_id',
				'agend_access_older_notice',
				'agend_access_mode',
				'agend_conditions_enabled',
			),
			array_keys( $this->controls() )
		);
	}

	/** The two settings that looked the same are gone, and so are the lists that only the older ones used. */
	#[Test]
	public function the_old_audience_lists_and_condition_controls_are_not_registered(): void {
		$ids = array_keys( $this->controls() );

		foreach ( array( 'agend_access_tier_ids', 'agend_access_segment_ids', 'agend_access_entitlement_keys', 'agend_conditions', 'agend_conditions_match', 'agend_conditions_invert', 'agend_conditions_divider' ) as $removed ) {
			$this->assertNotContains( $removed, $ids, $removed );
		}
	}

	#[Test]
	public function who_can_see_this_is_one_searchable_multi_select_with_no_condition(): void {
		$control = $this->controls()[ ElementorControl::RULES_KEY ];

		$this->assertSame( 'Who can see this', $control['label'] );
		$this->assertSame( \Elementor\Controls_Manager::SELECT2, $control['type'] );
		$this->assertTrue( $control['multiple'] );
		$this->assertSame( array(), $control['default'] );
		$this->assertTrue( $control['label_block'] );
		$this->assertArrayNotHasKey( 'condition', $control );
		$this->assertArrayNotHasKey( 'conditions', $control, 'the list is shown for every element: nothing about it may be hidden' );
		$this->assertSame(
			'Leave empty to show this to everyone who can see the page. Visitors who are not allowed never receive this element.',
			$control['description']
		);
	}

	/** The block panel's rules, named as its chips name them, and nothing it would not offer. */
	#[Test]
	public function the_options_are_the_grouped_vocabulary_flattened_with_the_kind_in_each_label(): void {
		$options = $this->controls()[ ElementorControl::RULES_KEY ]['options'];

		$this->assertSame(
			array(
				'agend_membership:is_member'                         => 'Any current member',
				'agend_membership:' . self::TIER_A                   => 'Plan: Full Member',
				'agend_membership:' . self::TIER_OLD                 => 'Plan (inactive): Retired',
				'agend_segments:' . self::SEG_A                      => 'Segment: Overdue renewals',
				'agend_segments:' . self::SEG_B                      => 'Segment: Volunteers',
				'agend_entitlements:directory.access'                => 'Platform: Directory access',
				'agend_entitlements:entitlement.early_bird'          => 'Entitlement: Early bird',
				'agend_wordpress:logged_in'                          => 'Signed in',
				'agend_wordpress:logged_out'                         => 'Signed out',
			),
			array_filter( $options, static fn( string $rule ): bool => ! str_starts_with( $rule, 'agend_wordpress:role_' ), ARRAY_FILTER_USE_KEY )
		);
		$this->assertArrayHasKey( 'agend_wordpress:role_administrator', $options );
		$this->assertSame( 'Role: Administrator', $options['agend_wordpress:role_administrator'] );
		$this->assertSame( Sets::rule_options(), $options, 'the shared vocabulary, with nothing of its own added' );
	}

	#[Test]
	public function a_plan_that_was_switched_off_is_offered_labelled_inactive_and_no_option_is_a_slug(): void {
		$options = $this->controls()[ ElementorControl::RULES_KEY ]['options'];

		$this->assertSame( 'Plan (inactive): Retired', $options[ 'agend_membership:' . self::TIER_OLD ] );
		$this->assertArrayNotHasKey( 'agend_membership:full', $options );
		$this->assertArrayNotHasKey( 'agend_segments:overdue', $options );
	}

	/** A list that could not be loaded leaves the control present, with what is left on offer. */
	#[Test]
	public function an_unavailable_list_leaves_the_control_present_with_the_rest(): void {
		$this->inEditor();

		// No cached sections and no Core wrappers in this process.
		Agend_Test_WP::$tiers_response = array( 'data' => array() );

		$options = ElementorControl::access_controls()[ ElementorControl::RULES_KEY ]['options'];

		$this->assertSame( 'Any current member', $options['agend_membership:is_member'] );
		$this->assertSame( 'Signed out', $options['agend_wordpress:logged_out'] );
		$this->assertSame( array(), array_filter( array_keys( $options ), static fn( string $rule ): bool => str_starts_with( $rule, 'agend_segments:' ) || str_starts_with( $rule, 'agend_entitlements:' ) ) );
	}

	// -----------------------------------------------------------------
	// Match, reverse and show instead
	// -----------------------------------------------------------------

	#[Test]
	public function match_is_any_or_all_and_shown_when_rules_are_chosen(): void {
		$control = $this->controls()[ ElementorControl::MATCH_KEY ];

		$this->assertSame( 'Match', $control['label'] );
		$this->assertSame( \Elementor\Controls_Manager::SELECT, $control['type'] );
		$this->assertSame( 'any', $control['default'] );
		$this->assertSame( array( 'any' => 'Any of these', 'all' => 'All of these' ), $control['options'] );
		$this->assertSame(
			array( 'terms' => array( array( 'name' => 'agend_access_rules', 'operator' => '!==', 'value' => array() ) ) ),
			$control['conditions']
		);
	}

	#[Test]
	public function the_reverse_switch_says_who_it_hides_and_is_shown_when_rules_are_chosen(): void {
		$control = $this->controls()[ ElementorControl::INVERT_KEY ];

		$this->assertSame( 'Show to everyone except these people', $control['label'] );
		$this->assertSame( \Elementor\Controls_Manager::SWITCHER, $control['type'] );
		$this->assertSame( 'yes', $control['return_value'], 'the value the one reader understands' );
		$this->assertSame( '', $control['default'] );
		$this->assertSame( 'Useful for a join banner that members should not see.', $control['description'] );
		$this->assertSame(
			array( 'terms' => array( array( 'name' => 'agend_access_rules', 'operator' => '!==', 'value' => array() ) ) ),
			$control['conditions']
		);
	}

	#[Test]
	public function show_instead_offers_public_content_and_belongs_to_rules_and_to_older_conditions(): void {
		$this->seed();

		$control = ElementorControl::access_controls()[ ElementorControl::FALLBACK_KEY ];

		$this->assertSame( 'Show instead', $control['label'] );
		$this->assertSame( \Elementor\Controls_Manager::SELECT, $control['type'] );
		$this->assertSame( Sets::fallback_options(), $control['options'], 'public content only, as the block panel offers' );
		$this->assertSame( '', $control['default'] );
		$this->assertSame(
			array(
				'relation' => 'or',
				'terms'    => array(
					array( 'name' => 'agend_access_rules', 'operator' => '!==', 'value' => array() ),
					array( 'name' => 'agend_conditions_enabled', 'operator' => '==', 'value' => 'yes' ),
				),
			),
			$control['conditions'],
			'an element with only older display conditions had a "Show instead", and must keep showing it'
		);
	}

	/** The same list the block panel offers, kinds and all: patterns and templates are chosen here too. */
	#[Test]
	public function show_instead_offers_pages_patterns_and_templates_labelled_by_kind(): void {
		$this->seed();

		Agend_Test_WP::$did_action['elementor/loaded'] = 1;

		foreach ( array(
			array( 41, 'elementor_library', 'Join banner' ),
			array( 42, 'wp_block', 'Members only' ),
			array( 43, 'page', 'Welcome' ),
		) as list( $id, $type, $title ) ) {
			$GLOBALS['agend_test_posts'][ $id ] = (object) array(
				'ID'           => $id,
				'post_status'  => 'publish',
				'post_type'    => $type,
				'post_title'   => $title,
				'post_content' => '',
			);
		}

		$options = ElementorControl::access_controls()[ ElementorControl::FALLBACK_KEY ]['options'];

		$this->assertSame(
			array( '' => 'Nothing', '43' => 'Page: Welcome', '42' => 'Pattern: Members only', '41' => 'Elementor template: Join banner' ),
			$options
		);

		// And what the route hands the block panel is the same choices, with their type.
		$this->assertSame(
			array(
				array( 'id' => 43, 'title' => 'Page: Welcome', 'type' => 'page' ),
				array( 'id' => 42, 'title' => 'Pattern: Members only', 'type' => 'pattern' ),
				array( 'id' => 41, 'title' => 'Elementor template: Join banner', 'type' => 'elementor_template' ),
			),
			Sets::vocabulary()['fallbacks']
		);
	}

	/** The rule controls follow what is chosen, by Elementor's own reading of the conditions. */
	#[Test]
	public function match_reverse_and_show_instead_are_hidden_until_a_rule_is_chosen(): void {
		foreach ( array( ElementorControl::MATCH_KEY, ElementorControl::INVERT_KEY, ElementorControl::FALLBACK_KEY ) as $control ) {
			$this->assertFalse( $this->shown( $control, array() ), $control . ' on an untouched element' );
			$this->assertFalse( $this->shown( $control, array( 'agend_access_rules' => array() ) ), $control . ' with the list emptied' );
			$this->assertTrue( $this->shown( $control, array( 'agend_access_rules' => array( 'agend_membership:is_member' ) ) ), $control . ' with a rule' );
		}
	}

	/** An element that has only older display conditions keeps the control for the content they show. */
	#[Test]
	public function show_instead_stays_for_an_element_with_older_conditions_only(): void {
		$this->assertTrue( $this->shown( ElementorControl::FALLBACK_KEY, array( 'agend_conditions_enabled' => 'yes' ) ) );
		$this->assertFalse( $this->shown( ElementorControl::FALLBACK_KEY, array( 'agend_conditions_enabled' => '' ) ) );
		$this->assertFalse( $this->shown( ElementorControl::MATCH_KEY, array( 'agend_conditions_enabled' => 'yes' ) ), 'only "Show instead" is kept: Match is the new list\'s' );
	}

	// -----------------------------------------------------------------
	// Older settings: surfaced, and removable
	// -----------------------------------------------------------------

	#[Test]
	public function the_notice_says_in_plain_words_that_an_older_setting_keeps_applying(): void {
		$control = $this->controls()[ ElementorControl::OLDER_NOTICE_ID ];

		$this->assertSame( \Elementor\Controls_Manager::RAW_HTML, $control['type'] );
		$this->assertStringContainsString( 'This element still uses an older access setting, which keeps applying alongside the rules above.', $control['raw'] );
		$this->assertStringNotContainsString( 'tier', strtolower( $control['raw'] ), 'general terms: it names no plan, segment or entitlement' );
	}

	/** @return array<string, array{0: array<string, mixed>, 1: bool}> */
	public static function olderSettings(): array {
		return array(
			'nothing stored'              => array( array(), false ),
			'mode is inherit'             => array( array( 'agend_access_mode' => 'inherit' ), false ),
			'mode is blank'               => array( array( 'agend_access_mode' => '' ), false ),
			'conditions off'              => array( array( 'agend_conditions_enabled' => '' ), false ),
			'conditions off, list kept'   => array( array( 'agend_conditions_enabled' => '', 'agend_conditions' => array( 'agend_wordpress:logged_in' ) ), false ),
			'new rules only'              => array( array( 'agend_access_rules' => array( 'agend_membership:is_member' ), 'agend_access_invert' => 'yes' ), false ),
			'all active members'          => array( array( 'agend_access_mode' => 'active_member' ), true ),
			'selected plans'              => array( array( 'agend_access_mode' => 'selected_tiers', 'agend_access_tier_ids' => array( self::TIER_A ) ), true ),
			'selected segments'           => array( array( 'agend_access_mode' => 'segments', 'agend_access_segment_ids' => array( self::SEG_A ) ), true ),
			'selected entitlements'       => array( array( 'agend_access_mode' => 'selected_entitlements' ), true ),
			'a mode this version does not know' => array( array( 'agend_access_mode' => 'bogus' ), true ),
			'conditions on'               => array( array( 'agend_conditions_enabled' => 'yes' ), true ),
			'both'                        => array( array( 'agend_access_mode' => 'active_member', 'agend_conditions_enabled' => 'yes' ), true ),
			'older and new together'      => array( array( 'agend_access_mode' => 'active_member', 'agend_access_rules' => array( 'agend_membership:is_member' ) ), true ),
		);
	}

	/** The notice and both removal controls appear only on an element that holds an older setting. */
	#[Test]
	#[DataProvider( 'olderSettings' )]
	public function the_notice_and_the_removal_controls_appear_only_when_an_older_setting_is_set( array $stored, bool $older ): void {
		$this->assertSame( $older, ElementorControl::has_older_settings( $stored ), 'the predicate the editor marker uses' );

		$mode_stored = isset( $stored['agend_access_mode'] ) && ! in_array( $stored['agend_access_mode'], array( '', 'inherit' ), true );
		$conditions  = isset( $stored['agend_conditions_enabled'] ) && 'yes' === $stored['agend_conditions_enabled'];

		$this->assertSame( $older, $this->shown( ElementorControl::OLDER_NOTICE_ID, $stored ), 'the notice' );
		$this->assertSame( $mode_stored, $this->shown( ElementorControl::MODE_KEY, $stored ), 'the older audience setting' );
		$this->assertSame( $conditions, $this->shown( ElementorControl::CONDITIONS_ENABLED_KEY, $stored ), 'the older display conditions' );
	}

	#[Test]
	public function the_older_audience_setting_is_removed_by_choosing_remove(): void {
		$control = $this->controls()[ ElementorControl::MODE_KEY ];

		$this->assertSame( 'Older audience setting', $control['label'] );
		$this->assertSame( \Elementor\Controls_Manager::SELECT, $control['type'] );
		$this->assertSame( 'inherit', $control['default'], 'Remove is the default, so nothing is written for an element without one' );
		$this->assertSame( 'Remove', $control['options']['inherit'] );
		$this->assertSame(
			array( 'inherit', 'active_member', 'selected_tiers', 'segments', 'selected_entitlements' ),
			array_keys( $control['options'] ),
			'the older audiences it can hold are named, and there is no Public: it can narrow its page, never widen it'
		);
	}

	#[Test]
	public function the_older_display_conditions_are_removed_by_switching_them_off(): void {
		$control = $this->controls()[ ElementorControl::CONDITIONS_ENABLED_KEY ];

		$this->assertSame( 'Older display conditions', $control['label'] );
		$this->assertSame( \Elementor\Controls_Manager::SWITCHER, $control['type'] );
		$this->assertSame( 'yes', $control['return_value'] );
		$this->assertSame( '', $control['default'] );
	}

	// -----------------------------------------------------------------
	// Hiding a control never changes who sees the element
	// -----------------------------------------------------------------

	/**
	 * What suppression reads is `get_settings_for_display()`, in which Elementor turns
	 * every hidden control into null and passes a setting with no control through. An
	 * element that holds an older setting must read as holding it, in every way the
	 * controls can be hidden.
	 */
	#[Test]
	#[DataProvider( 'olderSettings' )]
	public function an_older_setting_is_read_for_display_exactly_as_it_is_stored( array $stored, bool $older ): void {
		$controls = $this->controls();
		$stored  += array( 'agend_conditions' => array( 'agend_wordpress:logged_in' ) );

		$this->assertSame(
			Rules::from_elementor_settings( $stored )->to_array(),
			Rules::from_elementor_settings( ElementorActiveSettings::for_display( $controls, $stored ) )->to_array(),
			'what Elementor shows enforcement is what was stored'
		);
	}

	/** A rule list read for display is the list that was stored, however the other controls are set. */
	#[Test]
	public function the_rule_list_is_read_for_display_as_stored_with_or_without_the_other_controls(): void {
		$controls = $this->controls();

		foreach ( array(
			array( 'agend_access_rules' => array( 'agend_membership:is_member' ) ),
			array( 'agend_access_rules' => array( 'agend_membership:is_member' ), 'agend_access_match' => 'all', 'agend_access_invert' => 'yes', 'agend_conditions_fallback_id' => '9' ),
			array( 'agend_access_rules' => array( 'agend_membership:' . self::TIER_A, 'agend_wordpress:logged_in' ), 'agend_access_match' => 'all' ),
		) as $stored ) {
			$this->assertSame(
				Rules::from_elementor_settings( $stored )->to_array(),
				Rules::from_elementor_settings( ElementorActiveSettings::for_display( $controls, $stored ) )->to_array(),
				(string) wp_json_encode( $stored )
			);
		}
	}

	/** Match, reverse and Show instead mean nothing without rules, so an emptied list is no rule. */
	#[Test]
	public function an_emptied_list_leaves_no_rule_behind_even_with_a_reverse_still_stored(): void {
		$controls = $this->controls();
		$stored   = array( 'agend_access_rules' => array(), 'agend_access_match' => 'all', 'agend_access_invert' => 'yes', 'agend_conditions_fallback_id' => '9' );

		$this->assertTrue( Rules::from_elementor_settings( ElementorActiveSettings::for_display( $controls, $stored ) )->is_empty() );
	}

	/** The older display conditions keep their "Show instead" through what Elementor shows. */
	#[Test]
	public function older_conditions_keep_their_replacement_content_for_display(): void {
		$controls = $this->controls();
		$rules    = Rules::from_elementor_settings(
			ElementorActiveSettings::for_display(
				$controls,
				array(
					'agend_conditions_enabled'     => 'yes',
					'agend_conditions'             => array( 'agend_wordpress:logged_in' ),
					'agend_conditions_fallback_id' => '9',
				)
			)
		);

		$this->assertSame( array( 'agend_wordpress:logged_in' ), $rules->rules() );
		$this->assertSame( 9, $rules->fallback_id() );
	}

	// -----------------------------------------------------------------
	// Registration
	// -----------------------------------------------------------------

	#[Test]
	public function the_section_registers_the_controls_in_order_inside_the_advanced_tab(): void {
		$this->seed();

		$stack = $this->stack();

		( new ElementorControl() )->maybe_add_section( $stack, 'section_advanced', array( 'tab' => \Elementor\Controls_Manager::TAB_ADVANCED ) );

		$this->assertSame(
			array( 'agend_access_rules', 'agend_access_match', 'agend_access_invert', 'agend_conditions_fallback_id', 'agend_access_older_notice', 'agend_access_mode', 'agend_conditions_enabled' ),
			$stack->control_ids()
		);
		$this->assertSame( 'start_controls_section', $stack->recordings[0]['method'] );
		$this->assertSame( 'agend_access_section', $stack->recordings[0]['id'] );
		$this->assertSame( 'Agend Access', $stack->recordings[0]['args']['label'] );
		$this->assertSame( \Elementor\Controls_Manager::TAB_ADVANCED, $stack->recordings[0]['args']['tab'] );
		$this->assertSame( 'end_controls_section', $stack->recordings[ count( $stack->recordings ) - 1 ]['method'] );
	}

	/** A section that closes in another tab is not where the controls go. */
	#[Test]
	public function a_section_outside_the_advanced_tab_gets_nothing(): void {
		$this->seed();

		$stack = $this->stack();

		( new ElementorControl() )->maybe_add_section( $stack, 'section_content', array( 'tab' => \Elementor\Controls_Manager::TAB_CONTENT ) );

		$this->assertSame( array(), $stack->recordings );
	}

	/**
	 * Elementor registers a control stack for every widget type when the editor
	 * loads. A list that failed is never cached, so were each stack to build the
	 * options itself it would ask the gateway again every time, during an outage as
	 * much as any other time. Here both Core wrappers fail on every call, and the
	 * number of gateway calls after the first stack is the number after the third: it
	 * does not grow with the number of stacks.
	 */
	#[Test]
	public function the_options_are_built_once_however_many_stacks_are_registered(): void {
		require_once __DIR__ . '/fixtures/core-segments-double.php';
		require_once __DIR__ . '/fixtures/core-entitlement-types-double.php';

		$this->inEditor();

		Agend_Test_WP::$tiers_response = array( 'data' => array() );
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_segments_response', new WP_Error( 'http_request_failed', 'down' ) );
		Agend_Test_WP::set_filter( 'agend_apps_crm_get_entitlement_types_response', new WP_Error( 'http_request_failed', 'down' ) );

		$control = new ElementorControl();
		$args    = array( 'tab' => \Elementor\Controls_Manager::TAB_ADVANCED );
		$stacks  = array( $this->stack( 'widget-one' ), $this->stack( 'widget-two' ), $this->stack( 'widget-three' ) );

		$control->maybe_add_section( $stacks[0], 'a', $args );

		$after_one = array_count_values( array_column( Agend_Test_WP::$requests, 'url' ) );

		$this->assertArrayHasKey( '/crm/segments', $after_one, 'the failing wrapper was in fact reached' );
		$this->assertArrayHasKey( '/crm/entitlements/types', $after_one );

		$control->maybe_add_section( $stacks[1], 'a', $args );
		$control->maybe_add_section( $stacks[2], 'a', $args );

		$this->assertSame( $after_one, array_count_values( array_column( Agend_Test_WP::$requests, 'url' ) ) );

		foreach ( $stacks as $stack ) {
			$this->assertCount( 7, $stack->control_ids(), 'every stack still gets every control' );
		}
	}

	/** Every stack is handed the very same options, built the once. */
	#[Test]
	public function every_stack_is_given_the_same_options(): void {
		$this->seed();

		$control = new ElementorControl();
		$args    = array( 'tab' => \Elementor\Controls_Manager::TAB_ADVANCED );
		$one     = $this->stack( 'one' );
		$two     = $this->stack( 'two' );

		$control->maybe_add_section( $one, 'a', $args );

		// Anything that changes now would show in a second stack that rebuilt them.
		Agend_Test_WP::$transients[ Catalogue::SEGMENTS_TRANSIENT ] = array();
		Catalogue::reset();

		$control->maybe_add_section( $two, 'a', $args );

		$this->assertSame( $one->recordings[1]['args']['options'], $two->recordings[1]['args']['options'] );
		$this->assertSame( $one->recordings[4]['args']['options'], $two->recordings[4]['args']['options'], 'and Show instead' );
	}

	#[Test]
	public function a_stack_that_is_registered_twice_is_given_the_section_once(): void {
		$this->seed();

		$control = new ElementorControl();
		$stack   = $this->stack( 'same' );
		$args    = array( 'tab' => \Elementor\Controls_Manager::TAB_ADVANCED );

		$control->maybe_add_section( $stack, 'a', $args );
		$control->maybe_add_section( $stack, 'b', $args );

		$this->assertSame( 1, count( array_keys( $stack->control_ids(), 'agend_access_rules', true ) ) );
	}

	// -----------------------------------------------------------------
	// Options only where they are drawn
	// -----------------------------------------------------------------

	/**
	 * A control stack is registered on every front-end render of an Elementor page too, and the
	 * options are a catalogue read and three post queries. They are what an author picks from and
	 * nothing else, so a visitor's render does not build them.
	 */
	#[Test]
	public function outside_the_editor_the_options_are_not_built(): void {
		$this->seed();
		$this->inEditor( false );

		// A public page that "Show instead" would offer in the editor.
		$GLOBALS['agend_test_posts'][43] = (object) array(
			'ID'           => 43,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_title'   => 'Welcome',
			'post_content' => '',
		);

		$before   = count( Agend_Test_WP::$requests );
		$controls = ElementorControl::access_controls();

		$this->assertSame( array(), $controls[ ElementorControl::RULES_KEY ]['options'] );
		$this->assertSame( array( '' => 'Nothing' ), $controls[ ElementorControl::FALLBACK_KEY ]['options'] );
		$this->assertSame( $before, count( Agend_Test_WP::$requests ), 'no catalogue read' );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	/** Not in the editor means not in its preview iframe either: nothing there picks from the list. */
	#[Test]
	public function the_preview_iframe_does_not_build_them_either(): void {
		$this->seed();
		$this->inEditor( false );

		\Elementor\Plugin::$instance->preview->is_preview_mode = true;

		$this->assertSame( array(), ElementorControl::access_controls()[ ElementorControl::RULES_KEY ]['options'] );
	}

	// -----------------------------------------------------------------
	// A rule the author could not see
	// -----------------------------------------------------------------

	private const GONE = '99999999-9999-4999-8999-999999999999';

	/** A document double that says which post is open, as Elementor's documents manager does in the editor. */
	private function openDocument( int $id ): void {
		\Elementor\Plugin::$instance->documents = new class( $id ) {
			private int $id;

			public function __construct( int $id ) {
				$this->id = $id;
			}

			public function get_current() {
				$id = $this->id;

				return new class( $id ) {
					private int $id;

					public function __construct( int $id ) {
						$this->id = $id;
					}

					public function get_main_id(): int {
						return $this->id;
					}
				};
			}
		};
	}

	/** Stores a document the way Elementor does: JSON in `_elementor_data`. */
	private function storeDocument( int $post_id, array $elements ): void {
		$GLOBALS['agend_test_post_meta'][ $post_id ]['_elementor_data'] = json_encode( $elements );
	}

	/** One element holding the given rules, with the match mode. */
	private function element( string $id, array $rules, string $match = 'all', array $children = array() ): array {
		return array(
			'id'       => $id,
			'elType'   => 'container',
			'settings' => array( 'agend_access_rules' => $rules, 'agend_access_match' => $match ),
			'elements' => $children,
		);
	}

	/** The document double a save is handed. */
	private function savingDocument( int $main_id ): object {
		return new class( $main_id ) {
			private int $id;

			public function __construct( int $id ) {
				$this->id = $id;
			}

			public function get_main_id(): int {
				return $this->id;
			}
		};
	}

	/** @return string[] The rules an element of the saved data holds, by walking to it. */
	private function rulesOf( array $data, string ...$path ): array {
		$elements = $data['elements'];
		$element  = null;

		foreach ( $path as $id ) {
			foreach ( $elements as $candidate ) {
				if ( $id === $candidate['id'] ) {
					$element  = $candidate;
					$elements = $candidate['elements'] ?? array();
					break;
				}
			}
		}

		return $element['settings']['agend_access_rules'] ?? array();
	}

	/** In the editor the document's own rules are offered, so they show and stay in the list. */
	#[Test]
	public function the_editor_offers_every_rule_the_open_document_holds(): void {
		$this->seed();
		$this->openDocument( 500 );
		$this->storeDocument(
			500,
			array(
				$this->element(
					'aaaa001',
					array( 'agend_membership:' . self::TIER_A, 'agend_membership:' . self::TIER_OLD, 'agend_membership:' . self::GONE ),
					'all',
					array( $this->element( 'bbbb002', array( 'agend_membership:full', 'agend_wordpress:role_ghost' ) ) )
				),
			)
		);

		$options = ElementorControl::access_controls()[ ElementorControl::RULES_KEY ]['options'];

		$this->assertSame( 'Plan (inactive): Retired', $options[ 'agend_membership:' . self::TIER_OLD ], 'a plan that was switched off' );
		$this->assertSame( 'Unavailable: agend_membership:' . self::GONE, $options[ 'agend_membership:' . self::GONE ] );
		$this->assertSame( 'Plan: Full Member', $options['agend_membership:full'], 'a slug alias reads as the plan it stands for, in a child element too' );
		$this->assertSame( 'Unavailable: agend_wordpress:role_ghost', $options['agend_wordpress:role_ghost'] );
		$this->assertSame( 'Plan: Full Member', $options[ 'agend_membership:' . self::TIER_A ], 'what the list offers keeps its own label' );
	}

	/** During an outage every Agend rule drops out of the list, and the document's own are what is left to show. */
	#[Test]
	public function during_an_outage_the_editor_still_shows_what_the_document_holds(): void {
		$this->inEditor();
		$this->openDocument( 500 );
		$this->storeDocument( 500, array( $this->element( 'aaaa001', array( 'agend_membership:' . self::TIER_A ) ) ) );

		Agend_Test_WP::$tiers_response = array( 'data' => array() );

		$options = ElementorControl::access_controls()[ ElementorControl::RULES_KEY ]['options'];

		$this->assertSame( 'Unavailable: agend_membership:' . self::TIER_A, $options[ 'agend_membership:' . self::TIER_A ] );
	}

	/** A document the editor has not named has nothing to add, and breaks nothing. */
	#[Test]
	public function a_document_with_no_rules_adds_nothing(): void {
		$this->seed();
		$this->openDocument( 500 );
		$this->storeDocument( 500, array( array( 'id' => 'aaaa001', 'elType' => 'widget', 'settings' => array(), 'elements' => array() ) ) );

		$this->assertSame( Sets::rule_options(), ElementorControl::access_controls()[ ElementorControl::RULES_KEY ]['options'] );
	}

	// -----------------------------------------------------------------
	// A save keeps what the author could neither see nor remove
	// -----------------------------------------------------------------

	#[Test]
	public function the_save_filter_is_registered_with_the_document(): void {
		new ElementorControl();

		$this->assertSame(
			array( 'preserve_unseen_rules' ),
			array_map(
				static fn( array $entry ): string => $entry['callback'][1],
				Agend_Test_WP::$filter_registry['elementor/document/save/data'] ?? array()
			)
		);
		$this->assertSame( 2, Agend_Test_WP::$filter_registry['elementor/document/save/data'][0]['args'], 'it needs the document, to know what was stored' );
	}

	/**
	 * The whole finding. Under "all of these" the element is for people who hold every rule, and an
	 * edit that loses one the author never saw widens the audience.
	 */
	#[Test]
	public function an_edit_under_all_of_these_keeps_a_stored_rule_the_author_could_not_see(): void {
		$this->seed();
		$this->storeDocument( 500, array( $this->element( 'aaaa001', array( 'agend_membership:' . self::TIER_A, 'agend_membership:' . self::GONE ) ) ) );

		// The author's browser could only write back what the list showed.
		$saved = ( new ElementorControl() )->preserve_unseen_rules(
			array( 'elements' => array( $this->element( 'aaaa001', array( 'agend_membership:' . self::TIER_A ) ) ) ),
			$this->savingDocument( 500 )
		);

		$this->assertSame( array( 'agend_membership:' . self::TIER_A, 'agend_membership:' . self::GONE ), $this->rulesOf( $saved, 'aaaa001' ) );
		$this->assertSame( 'all', $saved['elements'][0]['settings']['agend_access_match'], 'nothing else about the element is touched' );
	}

	/** An element whose control the author cleared is saved with no key at all. */
	#[Test]
	public function a_cleared_list_comes_back_with_the_rule_nobody_could_name(): void {
		$this->seed();
		$this->storeDocument( 500, array( $this->element( 'aaaa001', array( 'agend_membership:' . self::GONE ) ) ) );

		$saved = ( new ElementorControl() )->preserve_unseen_rules(
			array( 'elements' => array( array( 'id' => 'aaaa001', 'elType' => 'container', 'settings' => array(), 'elements' => array() ) ) ),
			$this->savingDocument( 500 )
		);

		$this->assertSame( array( 'agend_membership:' . self::GONE ), $this->rulesOf( $saved, 'aaaa001' ) );

		$saved = ( new ElementorControl() )->preserve_unseen_rules(
			array( 'elements' => array( array( 'id' => 'aaaa001', 'elType' => 'container', 'elements' => array() ) ) ),
			$this->savingDocument( 500 )
		);

		$this->assertSame( array( 'agend_membership:' . self::GONE ), $this->rulesOf( $saved, 'aaaa001' ), 'no settings at all' );
	}

	#[Test]
	public function a_rule_in_a_nested_element_is_kept_too(): void {
		$this->seed();
		$this->storeDocument(
			500,
			array( $this->element( 'aaaa001', array(), 'any', array( $this->element( 'bbbb002', array( 'agend_membership:' . self::GONE ), 'all', array( $this->element( 'cccc003', array( 'agend_wordpress:role_ghost' ) ) ) ) ) ) )
		);

		$saved = ( new ElementorControl() )->preserve_unseen_rules(
			array(
				'elements' => array(
					$this->element( 'aaaa001', array(), 'any', array( $this->element( 'bbbb002', array(), 'all', array( $this->element( 'cccc003', array() ) ) ) ) ),
				),
			),
			$this->savingDocument( 500 )
		);

		$this->assertSame( array( 'agend_membership:' . self::GONE ), $this->rulesOf( $saved, 'aaaa001', 'bbbb002' ) );
		$this->assertSame( array( 'agend_wordpress:role_ghost' ), $this->rulesOf( $saved, 'aaaa001', 'bbbb002', 'cccc003' ) );
	}

	/**
	 * What the author COULD see is theirs to remove. The list offers a plan that was switched off, a
	 * slug alias reads as its plan, and anything the catalogue can name is offered: remove one of those and
	 * it stays removed.
	 */
	#[Test]
	public function a_rule_the_author_could_see_stays_removed(): void {
		$this->seed();
		$this->storeDocument(
			500,
			array(
				$this->element(
					'aaaa001',
					array( 'agend_membership:' . self::TIER_A, 'agend_membership:' . self::TIER_OLD, 'agend_segments:overdue', 'agend_wordpress:role_editor', 'agend_membership:' . self::GONE )
				),
			)
		);

		$saved = ( new ElementorControl() )->preserve_unseen_rules(
			array( 'elements' => array( $this->element( 'aaaa001', array( 'agend_wordpress:role_editor' ) ) ) ),
			$this->savingDocument( 500 )
		);

		$this->assertSame( array( 'agend_wordpress:role_editor', 'agend_membership:' . self::GONE ), $this->rulesOf( $saved, 'aaaa001' ) );
	}

	/** It only ever adds: a rule the author added is kept, and the rest of the settings are left as saved. */
	#[Test]
	public function it_only_adds_and_a_new_rule_is_kept(): void {
		$this->seed();
		$this->storeDocument( 500, array( $this->element( 'aaaa001', array( 'agend_membership:' . self::GONE ) ) ) );

		$saved = ( new ElementorControl() )->preserve_unseen_rules(
			array(
				'settings' => array( 'post_status' => 'draft' ),
				'elements' => array( $this->element( 'aaaa001', array( 'agend_membership:' . self::TIER_A ), 'any' ) ),
			),
			$this->savingDocument( 500 )
		);

		$this->assertSame( array( 'agend_membership:' . self::TIER_A, 'agend_membership:' . self::GONE ), $this->rulesOf( $saved, 'aaaa001' ) );
		$this->assertSame( array( 'post_status' => 'draft' ), $saved['settings'] );
		$this->assertSame( 'any', $saved['elements'][0]['settings']['agend_access_match'] );
	}

	/** A save already holding the rule adds nothing twice. */
	#[Test]
	public function a_rule_that_is_already_saved_is_not_added_again(): void {
		$this->seed();
		$this->storeDocument( 500, array( $this->element( 'aaaa001', array( 'agend_membership:' . self::GONE ) ) ) );

		$data  = array( 'elements' => array( $this->element( 'aaaa001', array( 'agend_membership:' . self::GONE ) ) ) );
		$saved = ( new ElementorControl() )->preserve_unseen_rules( $data, $this->savingDocument( 500 ) );

		$this->assertSame( $data, $saved );
	}

	/** An element the stored document did not have, or whose id changed, has nothing to keep. */
	#[Test]
	public function an_element_with_no_stored_copy_is_left_alone(): void {
		$this->seed();
		$this->storeDocument( 500, array( $this->element( 'aaaa001', array( 'agend_membership:' . self::GONE ) ) ) );

		$data  = array( 'elements' => array( $this->element( 'zzzz999', array( 'agend_membership:' . self::TIER_A ) ) ) );
		$saved = ( new ElementorControl() )->preserve_unseen_rules( $data, $this->savingDocument( 500 ) );

		$this->assertSame( $data, $saved );
	}

	/** While the catalogue cannot be loaded every Agend rule looks unknown: all of them are kept. */
	#[Test]
	public function during_an_outage_every_agend_rule_is_kept(): void {
		$this->storeDocument(
			500,
			array( $this->element( 'aaaa001', array( 'agend_membership:' . self::TIER_A, 'agend_segments:' . self::SEG_A, 'agend_wordpress:role_editor' ) ) )
		);

		Agend_Test_WP::$tiers_response = array( 'data' => array() );

		$saved = ( new ElementorControl() )->preserve_unseen_rules(
			array( 'elements' => array( $this->element( 'aaaa001', array( 'agend_wordpress:role_editor' ) ) ) ),
			$this->savingDocument( 500 )
		);

		$this->assertSame(
			array( 'agend_wordpress:role_editor', 'agend_membership:' . self::TIER_A, 'agend_segments:' . self::SEG_A ),
			$this->rulesOf( $saved, 'aaaa001' )
		);
	}

	/** A save is not an editor request, so it must not depend on the options the editor builds. */
	#[Test]
	public function the_save_does_not_depend_on_being_in_the_editor(): void {
		$this->seed();
		$this->inEditor( false );
		$this->storeDocument( 500, array( $this->element( 'aaaa001', array( 'agend_membership:' . self::TIER_A, 'agend_membership:' . self::GONE ) ) ) );

		$saved = ( new ElementorControl() )->preserve_unseen_rules(
			array( 'elements' => array( $this->element( 'aaaa001', array() ) ) ),
			$this->savingDocument( 500 )
		);

		$this->assertSame( array( 'agend_membership:' . self::GONE ), $this->rulesOf( $saved, 'aaaa001' ), 'the plan it can name is removed, the one it cannot is kept' );
	}

	#[Test]
	public function data_it_cannot_read_is_returned_as_it_came(): void {
		$this->seed();
		$this->storeDocument( 500, array( $this->element( 'aaaa001', array( 'agend_membership:' . self::GONE ) ) ) );

		$control = new ElementorControl();

		$this->assertSame( 'not data', $control->preserve_unseen_rules( 'not data', $this->savingDocument( 500 ) ) );
		$this->assertSame( array( 'settings' => array() ), $control->preserve_unseen_rules( array( 'settings' => array() ), $this->savingDocument( 500 ) ) );
		$this->assertSame( array( 'elements' => 'x' ), $control->preserve_unseen_rules( array( 'elements' => 'x' ), $this->savingDocument( 500 ) ) );

		$data = array( 'elements' => array( $this->element( 'aaaa001', array() ) ) );

		$this->assertSame( $data, $control->preserve_unseen_rules( $data, null ), 'no document, nothing to compare with' );
		$this->assertSame( $data, $control->preserve_unseen_rules( $data, $this->savingDocument( 0 ) ) );
		$this->assertSame( $data, $control->preserve_unseen_rules( $data, $this->savingDocument( 501 ) ), 'a document with nothing stored' );
	}
}

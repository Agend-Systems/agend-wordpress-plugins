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

	/** Loads the three lists through the cache, the seam the Core wrappers sit behind. */
	private function seed(): void {
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
	public function a_plan_that_was_switched_off_is_not_offered_and_no_option_is_a_slug(): void {
		$options = $this->controls()[ ElementorControl::RULES_KEY ]['options'];

		$this->assertArrayNotHasKey( 'agend_membership:' . self::TIER_OLD, $options );
		$this->assertArrayNotHasKey( 'agend_membership:full', $options );
		$this->assertArrayNotHasKey( 'agend_segments:overdue', $options );
	}

	/** A list that could not be loaded leaves the control present, with what is left on offer. */
	#[Test]
	public function an_unavailable_list_leaves_the_control_present_with_the_rest(): void {
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
}

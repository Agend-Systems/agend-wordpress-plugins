<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Catalogue as Catalogue;
use Agend_Content_Access_Elementor as ElementorControl;
use Agend_Content_Access_Policy as Policy;
use Agend_Test_WP;
use WP_Error;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

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
 * The audience controls on an Elementor element: who can see it, and the list
 * each set-audience chooses from.
 *
 * Each test runs in its own process because the control stack double stands in
 * for `Element_Base`, which other tests assert is absent.
 */
#[CoversClass( ElementorControl::class )]
#[RunTestsInSeparateProcesses]
final class ElementorAudienceControlsTest extends TestCase {

	private const TIER_A = '11111111-1111-1111-1111-111111111111';
	private const SEG_A  = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
	private const SEG_B  = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2';

	/** Loads the three lists through the cache, the seam the Core wrappers sit behind. */
	private function seed(): void {
		Agend_Test_WP::$tiers_response = array(
			'data' => array(
				array( 'id' => self::TIER_A, 'name' => 'Full Member', 'slug' => 'full', 'tier_type' => 'individual', 'is_active' => true ),
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

	// -----------------------------------------------------------------
	// Keys
	// -----------------------------------------------------------------

	/** The Elementor constants are aliases, so they cannot drift from what Policy reads. */
	#[Test]
	public function the_new_keys_are_aliases_of_the_neutral_ones(): void {
		$this->assertSame( Policy::FRAGMENT_SEGMENTS_KEY, ElementorControl::SEGMENTS_KEY );
		$this->assertSame( Policy::FRAGMENT_ENTITLEMENTS_KEY, ElementorControl::ENTITLEMENTS_KEY );

		// The on-disk names a stored document will use.
		$this->assertSame( 'agend_access_segment_ids', ElementorControl::SEGMENTS_KEY );
		$this->assertSame( 'agend_access_entitlement_keys', ElementorControl::ENTITLEMENTS_KEY );
	}

	// -----------------------------------------------------------------
	// Who can see this
	// -----------------------------------------------------------------

	#[Test]
	public function the_audience_options_include_segments_and_entitlements(): void {
		$this->seed();

		$controls = ElementorControl::audience_controls();

		$this->assertSame(
			array( 'inherit', 'active_member', 'selected_tiers', 'segments', 'selected_entitlements' ),
			array_keys( $controls[ ElementorControl::MODE_KEY ]['options'] ),
			'there is no Public option: an element can narrow its page, never widen it'
		);
		$this->assertSame( 'Selected segments', $controls[ ElementorControl::MODE_KEY ]['options']['segments'] );
		$this->assertSame( 'Selected entitlements', $controls[ ElementorControl::MODE_KEY ]['options']['selected_entitlements'] );
		$this->assertSame( 'inherit', $controls[ ElementorControl::MODE_KEY ]['default'], 'an untouched element still follows its page' );
	}

	#[Test]
	public function the_controls_come_in_a_fixed_order(): void {
		$this->seed();

		$this->assertSame(
			array( 'agend_access_mode', 'agend_access_tier_ids', 'agend_access_segment_ids', 'agend_access_entitlement_keys' ),
			array_keys( ElementorControl::audience_controls() )
		);
	}

	// -----------------------------------------------------------------
	// The two new lists
	// -----------------------------------------------------------------

	#[Test]
	public function the_segment_control_is_a_multi_select_of_segment_ids_shown_only_for_segments(): void {
		$this->seed();

		$control = ElementorControl::audience_controls()[ ElementorControl::SEGMENTS_KEY ];

		$this->assertSame( \Elementor\Controls_Manager::SELECT2, $control['type'] );
		$this->assertTrue( $control['multiple'] );
		$this->assertSame( array(), $control['default'] );
		$this->assertSame( array( ElementorControl::MODE_KEY => 'segments' ), $control['condition'] );
		$this->assertSame(
			array( self::SEG_A => 'Overdue renewals', self::SEG_B => 'Volunteers' ),
			$control['options'],
			'stored by id, shown by name'
		);
	}

	#[Test]
	public function the_entitlement_control_is_a_multi_select_of_gate_keys_with_platform_gates_labelled(): void {
		$this->seed();

		$control = ElementorControl::audience_controls()[ ElementorControl::ENTITLEMENTS_KEY ];

		$this->assertSame( \Elementor\Controls_Manager::SELECT2, $control['type'] );
		$this->assertTrue( $control['multiple'] );
		$this->assertSame( array(), $control['default'] );
		$this->assertSame( array( ElementorControl::MODE_KEY => 'selected_entitlements' ), $control['condition'] );
		$this->assertSame(
			array(
				'directory.access'       => 'Platform: Directory access',
				'entitlement.early_bird' => 'Early bird',
			),
			$control['options']
		);
	}

	/** In the voice of the plans control: any one admits, and none hides the element from everybody. */
	#[Test]
	public function each_list_control_says_that_selecting_none_hides_the_element(): void {
		$this->seed();

		$controls = ElementorControl::audience_controls();

		foreach ( array( ElementorControl::TIERS_KEY, ElementorControl::SEGMENTS_KEY, ElementorControl::ENTITLEMENTS_KEY ) as $key ) {
			$this->assertStringContainsString( 'any one of these', $controls[ $key ]['description'] );
			$this->assertStringContainsString( 'Selecting none hides it from everybody.', $controls[ $key ]['description'] );
		}

		$this->assertStringContainsString( 'Members on any one of these plans', $controls[ ElementorControl::TIERS_KEY ]['description'], 'the plans control is unchanged' );
	}

	/** A list that could not be loaded leaves the control present and empty, which is what the plan control always did. */
	#[Test]
	public function an_unavailable_list_leaves_its_control_present_and_empty(): void {
		// No cached sections and no Core wrappers in this process.
		Agend_Test_WP::$tiers_response = array( 'data' => array() );

		$controls = ElementorControl::audience_controls();

		$this->assertSame( array(), $controls[ ElementorControl::SEGMENTS_KEY ]['options'] );
		$this->assertSame( array(), $controls[ ElementorControl::ENTITLEMENTS_KEY ]['options'] );
	}

	// -----------------------------------------------------------------
	// What the controls store is what the policy reads
	// -----------------------------------------------------------------

	#[Test]
	public function the_policy_reads_the_segment_controls_settings(): void {
		$this->assertSame(
			array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_A ) ),
			Policy::from_fragment_settings(
				array(
					ElementorControl::MODE_KEY     => 'segments',
					ElementorControl::SEGMENTS_KEY => array( self::SEG_A, 'not-a-uuid', self::SEG_A ),
				)
			)
		);
	}

	#[Test]
	public function the_policy_reads_the_entitlement_controls_settings(): void {
		$this->assertSame(
			array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( 'directory.access' ) ),
			Policy::from_fragment_settings(
				array(
					ElementorControl::MODE_KEY         => 'selected_entitlements',
					ElementorControl::ENTITLEMENTS_KEY => array( 'directory.access', 'Bad Key' ),
				)
			)
		);
	}

	/** Selecting none restricts to nobody, as the controls' descriptions promise. */
	#[Test]
	public function a_set_audience_with_nothing_selected_is_a_policy_nobody_satisfies(): void {
		$this->assertSame(
			array( 'mode' => 'segments', 'segment_ids' => array() ),
			Policy::from_fragment_settings( array( ElementorControl::MODE_KEY => 'segments' ) )
		);
		$this->assertSame(
			array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array() ),
			Policy::from_fragment_settings( array( ElementorControl::MODE_KEY => 'selected_entitlements', ElementorControl::ENTITLEMENTS_KEY => array() ) )
		);
	}

	/** A list ticked while another audience was showing is ignored, as the plan list always was. */
	#[Test]
	public function only_the_chosen_audiences_list_is_read(): void {
		$settings = array(
			ElementorControl::TIERS_KEY        => array( self::TIER_A ),
			ElementorControl::SEGMENTS_KEY     => array( self::SEG_A ),
			ElementorControl::ENTITLEMENTS_KEY => array( 'directory.access' ),
		);

		$this->assertSame(
			array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_A ) ),
			Policy::from_fragment_settings( array_merge( $settings, array( ElementorControl::MODE_KEY => 'segments' ) ) )
		);
		$this->assertSame(
			array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( 'directory.access' ) ),
			Policy::from_fragment_settings( array_merge( $settings, array( ElementorControl::MODE_KEY => 'selected_entitlements' ) ) )
		);
	}

	// -----------------------------------------------------------------
	// Registration
	// -----------------------------------------------------------------

	private function stack( string $name = 'section' ): \Elementor\Element_Base {
		require_once __DIR__ . '/fixtures/elementor-element-base-double.php';

		return new \Elementor\Element_Base( $name );
	}

	#[Test]
	public function the_section_registers_the_audience_controls_before_the_display_conditions(): void {
		$this->seed();

		$stack = $this->stack();

		( new ElementorControl() )->maybe_add_section( $stack, 'section_advanced', array( 'tab' => \Elementor\Controls_Manager::TAB_ADVANCED ) );

		$this->assertSame(
			array_merge(
				array( 'agend_access_mode', 'agend_access_tier_ids', 'agend_access_segment_ids', 'agend_access_entitlement_keys' ),
				array( 'agend_conditions_divider', 'agend_conditions_enabled', 'agend_conditions', 'agend_conditions_match', 'agend_conditions_invert', 'agend_conditions_fallback_id' )
			),
			$stack->control_ids()
		);
	}

	/**
	 * Elementor registers a control stack for every widget type when the editor
	 * loads. A list that failed is never cached, so were each stack to ask for the
	 * options itself it would ask the gateway again every time, during an outage
	 * as much as any other time. Here both Core wrappers fail on every call, and
	 * the number of gateway calls after the first stack is the number after the
	 * third: it does not grow with the number of stacks.
	 */
	#[Test]
	public function the_lists_are_read_once_however_many_stacks_are_registered(): void {
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
			$this->assertCount( 10, $stack->control_ids(), 'every stack still gets every control' );
		}
	}

	#[Test]
	public function a_stack_that_is_registered_twice_is_given_the_section_once(): void {
		$this->seed();

		$control = new ElementorControl();
		$stack   = $this->stack( 'same' );
		$args    = array( 'tab' => \Elementor\Controls_Manager::TAB_ADVANCED );

		$control->maybe_add_section( $stack, 'a', $args );
		$control->maybe_add_section( $stack, 'b', $args );

		$this->assertSame( 1, count( array_keys( $stack->control_ids(), 'agend_access_mode', true ) ) );
	}
}

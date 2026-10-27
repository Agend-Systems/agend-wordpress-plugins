<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Meta_Box as MetaBox;
use Agend_Content_Access_Policy as Policy;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';

// The panel view is required relative to this constant, which the plugin's main
// file defines and the unit suite does not load.
if ( ! defined( 'AGEND_CONTENT_ACCESS_DIR' ) ) {
	define( 'AGEND_CONTENT_ACCESS_DIR', AGEND_TESTS_ROOT . '/agend-content-access/' );
}

/**
 * What the Agend Access panel shows for each of the five audiences.
 *
 * The panel is built from `payload()` and drawn by the view, so these tests feed
 * the pair a catalogue by hand, in each state a section can be in, and read the
 * markup. The rules they protect:
 *
 *  - the radio for the stored mode ALWAYS renders checked, whatever state its
 *    list is in. A disabled radio is not submitted, so the save keeps the stored
 *    policy; a radio that did not render, or rendered unchecked, would make a
 *    restricted page look unset and the next save would look like a choice;
 *  - a stored selection that is no longer offered is listed, checked and marked,
 *    never dropped;
 *  - what is wrong with a list is said where an editor will see it, which is
 *    under its radio and not inside a list that stays hidden.
 */
#[CoversClass( MetaBox::class )]
final class EditorPickersPanelTest extends TestCase {

	private const TIER_A = '11111111-1111-1111-1111-111111111111';
	private const TIER_B = '22222222-2222-2222-2222-222222222222';
	private const SEG_A  = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
	private const SEG_B  = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2';
	private const GONE   = '99999999-9999-4999-8999-999999999999';

	/** @return array{items: array, stale: bool, error: string} */
	private function section( array $items, bool $stale = false, string $error = '' ): array {
		return array(
			'items' => $items,
			'stale' => $stale,
			'error' => $error,
		);
	}

	/** A catalogue with every list loaded. */
	private function sections( array $over = array() ): array {
		return array_merge(
			array(
				'plans'        => $this->section(
					array(
						array( 'id' => self::TIER_A, 'name' => 'Full Member', 'slug' => 'full', 'type' => 'individual', 'active' => true ),
						array( 'id' => self::TIER_B, 'name' => 'Retired', 'slug' => 'retired', 'type' => 'individual', 'active' => false ),
					)
				),
				'segments'     => $this->section(
					array(
						array( 'id' => self::SEG_A, 'slug' => 'overdue', 'name' => 'Overdue renewals' ),
						array( 'id' => self::SEG_B, 'slug' => 'volunteers', 'name' => 'Volunteers' ),
					)
				),
				'entitlements' => $this->section(
					array(
						array( 'gate_key' => 'directory.access', 'name' => 'Directory access', 'description' => 'Browse the directory', 'kind' => 'platform-gate' ),
						array( 'gate_key' => 'entitlement.early_bird', 'name' => 'Early bird', 'description' => '', 'kind' => 'entitlement' ),
					)
				),
			),
			$over
		);
	}

	/** Renders the panel for a stored policy and a catalogue, as the post editor does. */
	private function html( ?array $policy, ?array $sections = null, bool $unreadable = false ): string {
		$data = MetaBox::payload( $policy, $sections ?? $this->sections(), array(), $unreadable );

		ob_start();
		require AGEND_TESTS_ROOT . '/agend-content-access/admin/views/policy-panel.php';

		return (string) ob_get_clean();
	}

	/** The opening tag of the radio for a mode. */
	private function radio( string $html, string $mode ): string {
		$this->assertSame(
			1,
			preg_match( '/<input\s+type="radio"[^>]*value="' . preg_quote( $mode, '/' ) . '"[^>]*>/s', $html, $matches ),
			"the {$mode} radio renders"
		);

		return $matches[0];
	}

	/** The opening tag of the list container for a mode. */
	private function container( string $html, string $mode ): string {
		$this->assertSame(
			1,
			preg_match( '/<div\s+class="agend-access-set"\s+data-agend-access-set="' . preg_quote( $mode, '/' ) . '"[^>]*>/s', $html, $matches ),
			"the {$mode} list renders"
		);

		return $matches[0];
	}

	/** @return array<string, array{0: string, 1: array, 2: string, 3: string}> mode, policy, section key, field */
	public static function setModes(): array {
		return array(
			'selected plans'        => array(
				Policy::MODE_TIERS,
				array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ),
				'plans',
				'agend_access_tier_ids',
			),
			'segments'              => array(
				Policy::MODE_SEGMENTS,
				array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_A ) ),
				'segments',
				'agend_access_segment_ids',
			),
			'selected entitlements' => array(
				Policy::MODE_ENTITLEMENTS,
				array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( 'directory.access' ) ),
				'entitlements',
				'agend_access_entitlement_keys',
			),
		);
	}

	// -----------------------------------------------------------------
	// Five audiences
	// -----------------------------------------------------------------

	#[Test]
	public function all_five_audiences_are_offered_in_order(): void {
		$html = $this->html( null );

		$this->assertSame( 5, substr_count( $html, 'type="radio"' ) );
		$this->assertSame( 5, preg_match_all( '/<strong>([^<]+)<\/strong>/', $html, $labels ) );
		$this->assertSame(
			array( 'Public', 'All active members', 'Selected membership plans', 'Selected segments', 'Selected entitlements' ),
			$labels[1]
		);
	}

	#[Test]
	public function with_nothing_stored_no_radio_is_checked_and_every_list_is_hidden(): void {
		$html = $this->html( null );

		$this->assertStringNotContainsString( "checked='checked'", $html );

		foreach ( array_keys( Policy::SET_MODE_KEYS ) as $mode ) {
			$this->assertStringContainsString( 'hidden', $this->container( $html, $mode ) );
		}
	}

	#[Test]
	#[DataProvider( 'setModes' )]
	public function a_stored_set_mode_checks_its_radio_and_shows_only_its_own_list( string $mode, array $policy ): void {
		$html = $this->html( $policy );

		$this->assertStringContainsString( "checked='checked'", $this->radio( $html, $mode ) );
		$this->assertStringNotContainsString( 'hidden', $this->container( $html, $mode ) );

		foreach ( array_diff( array_keys( Policy::SET_MODE_KEYS ), array( $mode ) ) as $other ) {
			$this->assertStringContainsString( 'hidden', $this->container( $html, $other ), "the {$other} list is hidden" );
			$this->assertStringNotContainsString( "checked='checked'", $this->radio( $html, $other ) );
		}
	}

	#[Test]
	#[DataProvider( 'setModes' )]
	public function each_list_submits_under_its_own_field_and_checks_what_is_stored( string $mode, array $policy, string $section, string $field ): void {
		$html = $this->html( $policy );

		$this->assertGreaterThanOrEqual( 1, substr_count( $html, 'name="' . $field . '[]"' ) );

		$stored = array_values( $policy[ Policy::SET_MODE_KEYS[ $mode ] ] )[0];

		$this->assertMatchesRegularExpression(
			'/name="' . preg_quote( $field, '/' ) . '\[\]"\s+value="' . preg_quote( $stored, '/' ) . '"\s+checked=\'checked\'/',
			$html
		);
	}

	#[Test]
	public function segments_are_offered_by_id_under_their_names(): void {
		$html = $this->html( null );

		$this->assertStringContainsString( 'value="' . self::SEG_A . '"', $html );
		$this->assertStringContainsString( 'Overdue renewals', $html );
		$this->assertStringContainsString( 'Volunteers', $html );
	}

	#[Test]
	public function entitlements_are_offered_by_key_with_platform_gates_labelled_and_descriptions_shown(): void {
		$html = $this->html( null );

		$this->assertStringContainsString( 'value="directory.access"', $html );
		$this->assertStringContainsString( 'Platform: Directory access', $html );
		$this->assertStringContainsString( 'Browse the directory', $html );
		$this->assertStringContainsString( '>Early bird<', $html );
		$this->assertStringNotContainsString( 'Platform: Early bird', $html );
	}

	#[Test]
	public function an_inactive_plan_is_not_offered_for_a_new_selection(): void {
		$html = $this->html( null );

		$this->assertStringContainsString( 'Full Member', $html );
		$this->assertStringNotContainsString( 'Retired', $html );
	}

	// -----------------------------------------------------------------
	// A stored selection that is no longer offered
	// -----------------------------------------------------------------

	/**
	 * @return array<string, array{0: string, 1: array, 2: string}>
	 */
	public static function goneSelections(): array {
		return array(
			'a plan that was deactivated' => array(
				Policy::MODE_TIERS,
				array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A, self::TIER_B ) ),
				'Retired',
			),
			'a plan that was deleted'     => array(
				Policy::MODE_TIERS,
				array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A, self::GONE ) ),
				'Unknown plan',
			),
			'a segment that was deleted'  => array(
				Policy::MODE_SEGMENTS,
				array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_A, self::GONE ) ),
				'Unknown segment',
			),
			'an entitlement that was removed' => array(
				Policy::MODE_ENTITLEMENTS,
				array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( 'directory.access', 'old.benefit' ) ),
				'old.benefit',
			),
		);
	}

	#[Test]
	#[DataProvider( 'goneSelections' )]
	public function a_selection_that_is_gone_is_listed_first_checked_and_marked_unavailable( string $mode, array $policy, string $label ): void {
		$html = $this->html( $policy );
		$set  = substr( $html, (int) strpos( $html, 'data-agend-access-set="' . $mode . '"' ) );
		$set  = substr( $set, 0, (int) strpos( $set, '</div>' ) );

		$this->assertSame( 1, substr_count( $set, 'agend-access-item--unavailable' ) );
		$this->assertStringContainsString( $label, $set );
		$this->assertStringContainsString( 'unavailable', $set );

		// First in the list, before anything still on offer, and still checked,
		// so that saving the page does not quietly drop it.
		$this->assertLessThan( (int) strpos( $set, 'class="agend-access-item" ' ), (int) strpos( $set, 'agend-access-item--unavailable' ) );
		$this->assertMatchesRegularExpression( '/agend-access-item--unavailable.*?checked/s', $set );
		$this->assertStringContainsString( '1 selected', $html, 'and the editor is told how many need repair' );
	}

	#[Test]
	public function a_list_with_every_selection_available_raises_no_repair_warning(): void {
		foreach ( self::setModes() as $case ) {
			$this->assertStringNotContainsString( 'no longer available', $this->html( $case[1] ) );
		}
	}

	// -----------------------------------------------------------------
	// A section that cannot be used
	// -----------------------------------------------------------------

	/** The radio is disabled so a new choice cannot be made, and says why, but a stored mode still renders checked. */
	#[Test]
	#[DataProvider( 'setModes' )]
	public function the_stored_modes_radio_renders_checked_even_when_its_list_failed( string $mode, array $policy, string $section ): void {
		$html = $this->html(
			$policy,
			$this->sections( array( $section => $this->section( array(), true, 'The list could not be loaded.' ) ) )
		);

		$this->assertMatchesRegularExpression(
			'/value="' . preg_quote( $mode, '/' ) . '"\s+checked=\'checked\'\s+disabled=\'disabled\'/',
			$this->radio( $html, $mode ),
			'checked so the panel does not look unset, disabled so nothing is submitted and the save keeps the policy'
		);
	}

	#[Test]
	#[DataProvider( 'setModes' )]
	public function an_unchosen_radio_is_disabled_when_its_list_failed( string $mode, array $policy, string $section ): void {
		$html = $this->html(
			null,
			$this->sections( array( $section => $this->section( array(), true, 'The list could not be loaded.' ) ) )
		);

		$radio = $this->radio( $html, $mode );

		$this->assertStringContainsString( "disabled='disabled'", $radio );
		$this->assertStringNotContainsString( "checked='checked'", $radio );

		// And only that one. A failed segment list does not switch plans off.
		foreach ( array_diff( array_keys( Policy::SET_MODE_KEYS ), array( $mode ) ) as $other ) {
			$this->assertStringNotContainsString( 'disabled', $this->radio( $html, $other ) );
		}
	}

	/** Said under the radio, where it is always visible, not in a list that is hidden unless the radio can be chosen. */
	#[Test]
	public function a_sections_error_is_shown_inline_under_its_radio_and_outside_the_hidden_list(): void {
		$error = 'This site\'s Agend API key needs the crm.entitlements.browse scope before entitlements can be listed. Add the scope to the key in Agend.';
		$html  = $this->html( null, $this->sections( array( 'entitlements' => $this->section( array(), true, $error ) ) ) );

		$this->assertStringContainsString( htmlspecialchars( $error, ENT_QUOTES ), $html );
		$this->assertStringContainsString( 'Entitlements are unavailable right now', $html );

		$notice = (int) strpos( $html, 'data-agend-access-notice="selected_entitlements"' );
		$radio  = (int) strpos( $html, 'value="selected_entitlements"' );
		$list   = (int) strpos( $html, 'data-agend-access-set="selected_entitlements"' );

		$this->assertGreaterThan( $radio, $notice );
		$this->assertLessThan( $list, $notice );
		$this->assertStringNotContainsString( 'data-agend-access-notice="segments"', $html, 'the lists that loaded have nothing to report' );
	}

	#[Test]
	public function an_error_is_escaped(): void {
		$html = $this->html(
			null,
			$this->sections( array( 'segments' => $this->section( array(), true, 'Broke <script>alert(1)</script>' ) ) )
		);

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	/** A name comes from Agend, so it is data and never markup. */
	#[Test]
	public function a_names_markup_is_escaped(): void {
		$html = $this->html(
			null,
			$this->sections(
				array(
					'segments' => $this->section(
						array( array( 'id' => self::SEG_A, 'slug' => 'x', 'name' => '<img src=x onerror=alert(1)>' ) )
					),
				)
			)
		);

		$this->assertStringNotContainsString( '<img src=x', $html );
	}

	/** A list that answered but is empty says so, which is a different statement from "could not be loaded". */
	#[Test]
	public function an_empty_list_that_loaded_says_there_are_none_rather_than_that_it_is_unavailable(): void {
		$html = $this->html( null, $this->sections( array( 'segments' => $this->section( array() ) ) ) );

		$this->assertStringContainsString( 'There are no segments in Agend yet', $html );
		$this->assertStringNotContainsString( 'Segments are unavailable right now', $html );
		$this->assertStringContainsString( "disabled='disabled'", $this->radio( $html, Policy::MODE_SEGMENTS ) );
	}

	/** An old list is still usable, so the radio stays enabled and the editor is told it may be out of date. */
	#[Test]
	public function a_stale_list_stays_selectable_and_says_it_may_be_out_of_date(): void {
		$html = $this->html(
			null,
			$this->sections(
				array(
					'segments' => $this->section(
						array( array( 'id' => self::SEG_A, 'slug' => 'overdue', 'name' => 'Overdue renewals' ) ),
						true,
						'The list of segments could not be loaded from Agend. Try again in a few minutes.'
					),
				)
			)
		);

		$this->assertStringNotContainsString( 'disabled', $this->radio( $html, Policy::MODE_SEGMENTS ) );
		$this->assertStringContainsString( 'The segment list could not be refreshed from Agend, so it may be out of date.', $html );
		$this->assertStringContainsString( 'Try again in a few minutes.', $html );
		$this->assertStringContainsString( 'Overdue renewals', $html );
	}

	/** "No longer available in Agend" would be untrue of a selection whose list simply could not be read. */
	#[Test]
	public function the_repair_warning_is_not_raised_when_the_list_could_not_be_read(): void {
		$html = $this->html(
			array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_A ) ),
			$this->sections( array( 'segments' => $this->section( array(), true, 'The list of segments could not be loaded from Agend. Try again in a few minutes.' ) ) )
		);

		$this->assertStringNotContainsString( 'no longer available', $html );
		$this->assertStringContainsString( 'Unknown segment', $html, 'the stored selection is still listed' );
		$this->assertMatchesRegularExpression( '/value="' . self::SEG_A . '"\s+checked/', $html );
	}

	// -----------------------------------------------------------------
	// The panel survives a catalogue it was not handed
	// -----------------------------------------------------------------

	#[Test]
	public function a_missing_section_renders_as_an_unusable_list_not_a_fatal(): void {
		$html = $this->html( array( 'mode' => 'segments', 'segment_ids' => array( self::SEG_A ) ), array() );

		$this->assertSame( 5, substr_count( $html, 'type="radio"' ) );
		$this->assertStringContainsString( "checked='checked'", $this->radio( $html, Policy::MODE_SEGMENTS ) );
	}

	/** The warning for a policy nobody can satisfy does not depend on any list. */
	#[Test]
	public function an_unreadable_policy_still_warns_and_still_offers_every_audience(): void {
		$html = $this->html( null, null, true );

		$this->assertStringContainsString( 'agend-access-unreadable', $html );
		$this->assertSame( 5, substr_count( $html, 'type="radio"' ) );
		$this->assertStringNotContainsString( "checked='checked'", $html );
	}

	// -----------------------------------------------------------------
	// Registration
	// -----------------------------------------------------------------

	/** The panel is plain form fields saved through save_post, so it works in the block editor's meta box area. */
	#[Test]
	public function the_meta_box_is_declared_block_editor_compatible(): void {
		( new MetaBox() )->add_meta_box();

		$this->assertCount( 1, Agend_Test_WP::$meta_boxes );
		$this->assertSame( array( '__block_editor_compatible_meta_box' => true ), Agend_Test_WP::$meta_boxes[0]['callback_args'] );
		$this->assertSame( 'agend-content-access', Agend_Test_WP::$meta_boxes[0]['id'] );
		$this->assertSame( 'side', Agend_Test_WP::$meta_boxes[0]['context'] );
	}
}

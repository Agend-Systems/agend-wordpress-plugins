<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Decision as Decision;
use Agend_Content_Access_Meta_Box as MetaBox;
use Agend_Content_Access_Policy as Policy;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WP_Post;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';

/**
 * What saving the editor panel does to the stored policy.
 *
 * The panel can only show the modes it has a radio for. A post holding a policy
 * in a mode it cannot show has no radio checked, so an ordinary save of that post
 * submits no mode at all. Reading "no mode" as "clear the policy" turned that
 * save into a silent publication of a restricted document, so the rule these
 * tests protect is: the stored policy is only ever replaced by a submitted mode
 * that produces a valid policy.
 */
#[CoversClass( MetaBox::class )]
final class MetaBoxSaveTest extends TestCase {

	private const POST = 501;
	private const SEG  = '22222222-2222-2222-2222-222222222222';
	private const TIER = '11111111-1111-1111-1111-111111111111';

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['agend_test_current_user_can']['edit_post'] = true;
		$_POST = array();
	}

	protected function tearDown(): void {
		$_POST = array();

		parent::tearDown();
	}

	private function store( array $policy ): void {
		$GLOBALS['agend_test_post_meta'][ self::POST ][ Policy::META_KEY ] = $policy;
	}

	/**
	 * Saves the post as the panel would, with a genuine nonce and whatever fields
	 * the test submits.
	 *
	 * @param array $fields Extra `$_POST` fields.
	 */
	private function savePanel( array $fields = array() ): void {
		$_POST = array_merge(
			array( MetaBox::NONCE_FIELD => wp_create_nonce( MetaBox::NONCE_ACTION ) ),
			$fields
		);

		( new MetaBox() )->save( self::POST, new WP_Post( array( 'ID' => self::POST, 'post_type' => 'page' ) ) );
	}

	// -----------------------------------------------------------------
	// A policy the panel cannot show survives a save
	// -----------------------------------------------------------------

	/**
	 * Review finding F3, ported. Saving a page that held a segments policy deleted
	 * it, and the page then rendered in full to everyone. The current wrong
	 * behaviour asserted `null` here.
	 */
	#[Test]
	public function saving_the_panel_does_not_delete_a_segments_policy(): void {
		$policy = array( 'mode' => 'segments', 'segment_ids' => array( self::SEG ) );
		$this->store( $policy );

		$this->savePanel(); // No radio is checked: none matches `segments`.

		$this->assertSame( $policy, Policy::get_for_post( self::POST ) );

		// And the page is still restricted to a visitor who is not in the segment.
		$GLOBALS['agend_test_current_user_can']['edit_post'] = false;
		Decision::reset_cache();
		$this->assertSame( Decision::STATE_AUTH, Decision::for_post( self::POST )['state'] );
	}

	#[Test]
	public function saving_the_panel_does_not_delete_a_selected_entitlements_policy(): void {
		$policy = array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( 'journal' ) );
		$this->store( $policy );

		$this->savePanel();

		$this->assertSame( $policy, Policy::get_for_post( self::POST ) );
	}

	/**
	 * @return array<string, array{0: array}>
	 */
	public static function stored_policies(): array {
		return array(
			'public'         => array( array( 'mode' => 'public' ) ),
			'active member'  => array( array( 'mode' => 'active_member' ) ),
			'selected tiers' => array( array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER ) ) ),
			'segments'       => array( array( 'mode' => 'segments', 'segment_ids' => array( self::SEG ) ) ),
		);
	}

	#[Test]
	#[DataProvider( 'stored_policies' )]
	public function a_save_with_no_mode_keeps_whatever_is_stored( array $policy ): void {
		$this->store( $policy );

		$this->savePanel();

		$this->assertSame( $policy, Policy::get_for_post( self::POST ) );
	}

	#[Test]
	#[DataProvider( 'stored_policies' )]
	public function a_save_with_an_empty_mode_keeps_whatever_is_stored( array $policy ): void {
		$this->store( $policy );

		$this->savePanel( array( MetaBox::FIELD_MODE => '' ) );

		$this->assertSame( $policy, Policy::get_for_post( self::POST ) );
	}

	#[Test]
	public function a_post_with_no_policy_still_has_none_after_a_save_with_no_mode(): void {
		$this->savePanel();

		$this->assertNull( Policy::get_for_post( self::POST ) );
		$this->assertArrayNotHasKey( Policy::META_KEY, $GLOBALS['agend_test_post_meta'][ self::POST ] ?? array() );
	}

	// -----------------------------------------------------------------
	// An unrecognised or unusable mode keeps it too
	// -----------------------------------------------------------------

	#[Test]
	public function an_unrecognised_mode_keeps_the_stored_policy(): void {
		$policy = array( 'mode' => 'segments', 'segment_ids' => array( self::SEG ) );
		$this->store( $policy );

		$this->savePanel( array( MetaBox::FIELD_MODE => 'everybody_free' ) );

		$this->assertSame( $policy, Policy::get_for_post( self::POST ) );
	}

	/**
	 * A set-mode submitted with nothing selected is refused whichever of the
	 * three it is, and the stored policy stays. Choosing plans in the panel with
	 * no plan ticked is the live case; the other two are hand-crafted.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function set_modes(): array {
		return array(
			'selected plans'        => array( 'selected_tiers' ),
			'segments'              => array( 'segments' ),
			'selected entitlements' => array( 'selected_entitlements' ),
		);
	}

	#[Test]
	#[DataProvider( 'set_modes' )]
	public function a_set_mode_with_nothing_selected_keeps_the_stored_policy( string $mode ): void {
		$policy = array( 'mode' => 'active_member' );
		$this->store( $policy );

		$this->savePanel( array( MetaBox::FIELD_MODE => $mode ) );

		$this->assertSame( $policy, Policy::get_for_post( self::POST ) );
	}

	#[Test]
	public function an_array_valued_mode_is_not_a_mode_and_keeps_the_stored_policy(): void {
		$policy = array( 'mode' => 'active_member' );
		$this->store( $policy );

		$this->savePanel( array( MetaBox::FIELD_MODE => array( 'public' ) ) );

		$this->assertSame( $policy, Policy::get_for_post( self::POST ) );
	}

	// -----------------------------------------------------------------
	// A deliberate choice still replaces it
	// -----------------------------------------------------------------

	/** Clearing a restriction remains possible, by choosing Public. */
	#[Test]
	public function choosing_public_replaces_a_policy_the_panel_cannot_show(): void {
		$this->store( array( 'mode' => 'segments', 'segment_ids' => array( self::SEG ) ) );

		$this->savePanel( array( MetaBox::FIELD_MODE => 'public' ) );

		$this->assertSame( array( 'mode' => 'public' ), Policy::get_for_post( self::POST ) );
	}

	#[Test]
	public function choosing_a_mode_the_panel_offers_replaces_the_stored_policy(): void {
		$this->store( array( 'mode' => 'segments', 'segment_ids' => array( self::SEG ) ) );

		$this->savePanel( array( MetaBox::FIELD_MODE => 'active_member' ) );

		$this->assertSame( array( 'mode' => 'active_member' ), Policy::get_for_post( self::POST ) );
	}

	#[Test]
	public function choosing_plans_stores_them(): void {
		$this->savePanel(
			array(
				MetaBox::FIELD_MODE  => 'selected_tiers',
				MetaBox::FIELD_TIERS => array( self::TIER ),
			)
		);

		$this->assertSame(
			array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER ) ),
			Policy::get_for_post( self::POST )
		);
	}

	// -----------------------------------------------------------------
	// The guards in front of all of it are unchanged
	// -----------------------------------------------------------------

	#[Test]
	public function a_save_without_the_nonce_changes_nothing_even_with_a_mode(): void {
		$policy = array( 'mode' => 'segments', 'segment_ids' => array( self::SEG ) );
		$this->store( $policy );

		$_POST = array( MetaBox::FIELD_MODE => 'public' );
		( new MetaBox() )->save( self::POST, new WP_Post( array( 'ID' => self::POST, 'post_type' => 'page' ) ) );

		$this->assertSame( $policy, Policy::get_for_post( self::POST ) );
	}

	#[Test]
	public function a_save_by_someone_who_cannot_edit_the_post_changes_nothing(): void {
		$policy = array( 'mode' => 'segments', 'segment_ids' => array( self::SEG ) );
		$this->store( $policy );
		$GLOBALS['agend_test_current_user_can']['edit_post'] = false;

		$this->savePanel( array( MetaBox::FIELD_MODE => 'public' ) );

		$this->assertSame( $policy, Policy::get_for_post( self::POST ) );
	}
}

<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Decision as Decision;
use Agend_Content_Access_Meta_Box as MetaBox;
use Agend_Content_Access_Page_Rules as PageRules;
use Agend_Content_Access_Policy as Policy;
use Agend_Content_Access_Rules as Rules;
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
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-attributes.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-access.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-destination.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-page-rules.php';

/**
 * What saving the classic editor's panel does to what a page holds.
 *
 * The panel writes its setting to one hidden input, as JSON, and leaves it EMPTY
 * until an author changes something. A browser submits nothing for a control that
 * is not there, and the panel is not there while its script has not started, on a
 * screen it is not on, or in a request that is not the form (a quick edit, the block
 * editor's own save, a programmatic `wp_update_post`). Reading "nothing submitted" as
 * "the author cleared the setting" turned an ordinary save into the silent
 * publication of a restricted page, so the rule these tests protect is the one the
 * panel has always had: what is stored is only ever REPLACED by a submission that
 * validates, and cleared by one that says so.
 *
 * The tests that were written when the panel was a form of radio buttons stand,
 * ported to the field that replaced them: the cases are the same, a stored setting
 * the panel cannot show, a submission that says nothing, and one that is refused.
 */
#[CoversClass( MetaBox::class )]
final class MetaBoxSaveTest extends TestCase {

	private const POST = 501;
	private const SEG  = '22222222-2222-2222-2222-222222222222';
	private const TIER = '11111111-1111-1111-1111-111111111111';

	private const MEMBER = 'agend_membership:is_member';

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['agend_test_current_user_can']['edit_post'] = true;
		$_POST = array();
	}

	protected function tearDown(): void {
		$_POST = array();

		parent::tearDown();
	}

	private function storePolicy( $policy ): void {
		$GLOBALS['agend_test_post_meta'][ self::POST ][ Policy::META_KEY ] = $policy;
	}

	private function storeRules( array $rules ): void {
		$GLOBALS['agend_test_post_meta'][ self::POST ][ Rules::META_KEY ] = $rules;
	}

	/** @return mixed What the page holds under a meta key, or null when it holds none. */
	private function meta( string $key ) {
		return $GLOBALS['agend_test_post_meta'][ self::POST ][ $key ] ?? null;
	}

	/** The setting the panel would send. */
	private function setting( array $rules = array( self::MEMBER ), array $over = array() ): string {
		return (string) wp_json_encode(
			array_merge(
				array(
					'rules'  => $rules,
					'match'  => 'any',
					'invert' => false,
					'denied' => array( 'action' => 'placeholder', 'target_id' => 0, 'fallback_id' => 0 ),
				),
				$over
			)
		);
	}

	/**
	 * Saves the post as the panel would, with a genuine nonce and whatever fields
	 * the test submits.
	 *
	 * @param array   $fields Extra `$_POST` fields.
	 * @param string  $type   The post's type.
	 */
	private function savePanel( array $fields = array(), string $type = 'page' ): void {
		$_POST = array_merge(
			array(
				MetaBox::NONCE_FIELD => wp_create_nonce( MetaBox::NONCE_ACTION ),
				'post_ID'            => (string) self::POST,
			),
			$fields
		);

		( new MetaBox() )->save( self::POST, new WP_Post( array( 'ID' => self::POST, 'post_type' => $type ) ) );
	}

	private function error(): string {
		return (string) get_transient( MetaBox::ERROR_TRANSIENT . self::POST );
	}

	// -----------------------------------------------------------------
	// A setting the panel cannot show survives a save that says nothing
	// -----------------------------------------------------------------

	/**
	 * Review finding F3, ported. Saving a page that held a segments policy deleted
	 * it, and the page then rendered in full to everyone.
	 */
	#[Test]
	public function saving_the_panel_does_not_delete_a_segments_policy(): void {
		$policy = array( 'mode' => 'segments', 'segment_ids' => array( self::SEG ) );
		$this->storePolicy( $policy );

		$this->savePanel();

		$this->assertSame( $policy, Policy::get_for_post( self::POST ) );

		// And the page is still restricted to a visitor who is not in the segment.
		$GLOBALS['agend_test_current_user_can']['edit_post'] = false;
		Decision::reset_cache();
		$this->assertSame( Decision::STATE_AUTH, Decision::for_post( self::POST )['state'] );
	}

	/**
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public static function stored_settings(): array {
		return array(
			'public'              => array( Policy::META_KEY, array( 'mode' => 'public' ) ),
			'active member'       => array( Policy::META_KEY, array( 'mode' => 'active_member' ) ),
			'selected tiers'      => array( Policy::META_KEY, array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER ) ) ),
			'segments'            => array( Policy::META_KEY, array( 'mode' => 'segments', 'segment_ids' => array( self::SEG ) ) ),
			'entitlements'        => array( Policy::META_KEY, array( 'mode' => 'selected_entitlements', 'entitlement_keys' => array( 'journal' ) ) ),
			'a policy this version cannot read' => array( Policy::META_KEY, array( 'mode' => 'authenticated' ) ),
			'rules'               => array( Rules::META_KEY, array( 'rules' => array( self::MEMBER ), 'match' => 'any', 'invert' => false ) ),
			'rules this version cannot read' => array( Rules::META_KEY, array( 'rules' => array( 7 ), 'match' => 'any', 'invert' => false ) ),
		);
	}

	#[Test]
	#[DataProvider( 'stored_settings' )]
	public function a_save_that_submits_nothing_keeps_whatever_is_stored( string $key, $value ): void {
		$GLOBALS['agend_test_post_meta'][ self::POST ][ $key ] = $value;

		$this->savePanel();

		$this->assertSame( $value, $this->meta( $key ) );
	}

	#[Test]
	#[DataProvider( 'stored_settings' )]
	public function a_save_with_an_empty_input_keeps_whatever_is_stored( string $key, $value ): void {
		$GLOBALS['agend_test_post_meta'][ self::POST ][ $key ] = $value;

		$this->savePanel( array( MetaBox::FIELD_RULES => '' ) );
		$this->assertSame( $value, $this->meta( $key ), 'empty' );

		$this->savePanel( array( MetaBox::FIELD_RULES => "  \n " ) );
		$this->assertSame( $value, $this->meta( $key ), 'spaces' );
	}

	#[Test]
	public function a_post_with_nothing_stored_still_has_nothing_after_a_save_that_submits_nothing(): void {
		$this->savePanel();

		$this->assertSame( array(), $GLOBALS['agend_test_post_meta'][ self::POST ] ?? array() );
	}

	/** The form of the panel that was replaced, posted by a page that was opened before the update. */
	#[Test]
	public function the_old_radio_fields_are_not_a_setting_and_change_nothing(): void {
		$policy = array( 'mode' => 'segments', 'segment_ids' => array( self::SEG ) );
		$this->storePolicy( $policy );

		$this->savePanel( array( 'agend_access_mode' => 'public', 'agend_access_tier_ids' => array( self::TIER ) ) );

		$this->assertSame( $policy, $this->meta( Policy::META_KEY ) );
		$this->assertNull( $this->meta( Rules::META_KEY ) );
	}

	// -----------------------------------------------------------------
	// A submission that is not a usable setting keeps it too
	// -----------------------------------------------------------------

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function unusable_submissions(): array {
		return array(
			'text that is not JSON'     => array( 'everybody_free' ),
			'a broken object'           => array( '{"rules":' ),
			'JSON null'                 => array( 'null' ),
			'a JSON string'             => array( '"public"' ),
			'a JSON number'             => array( '7' ),
			'a JSON list'               => array( '["agend_membership:is_member"]' ),
			'an empty JSON object'      => array( '{}' ),
			'a rule that is not one'    => array( '{"rules":["nonsense"],"match":"any","invert":false}' ),
			'a match that is not one'   => array( '{"rules":["agend_membership:is_member"],"match":"some","invert":false}' ),
			'a redirect with no page'   => array( '{"rules":["agend_membership:is_member"],"denied":{"action":"redirect","target_id":0,"fallback_id":0}}' ),
			'a reversed empty list'     => array( '{"rules":[],"match":"any","invert":true}' ),
		);
	}

	#[Test]
	#[DataProvider( 'unusable_submissions' )]
	public function an_unusable_submission_keeps_the_stored_setting_and_says_why( $submitted ): void {
		$policy = array( 'mode' => 'active_member' );
		$this->storePolicy( $policy );

		$this->savePanel( array( MetaBox::FIELD_RULES => $submitted ) );

		$this->assertSame( $policy, $this->meta( Policy::META_KEY ) );
		$this->assertNull( $this->meta( Rules::META_KEY ) );
		$this->assertNotSame( '', $this->error(), 'carried across the redirect for the editor to read' );
	}

	/** A non-string value (`agend_access_page_rules[]=...`) is not a setting: as good as nothing submitted. */
	#[Test]
	public function an_array_valued_field_is_not_a_setting_and_keeps_the_stored_one(): void {
		$policy = array( 'mode' => 'active_member' );
		$this->storePolicy( $policy );

		$this->savePanel( array( MetaBox::FIELD_RULES => array( '{"rules":[]}' ) ) );

		$this->assertSame( $policy, $this->meta( Policy::META_KEY ) );
		$this->assertNull( $this->meta( Rules::META_KEY ) );
	}

	#[Test]
	public function a_refused_submission_keeps_stored_rules_too(): void {
		$rules = array( 'rules' => array( 'agend_wordpress:logged_in' ), 'match' => 'any', 'invert' => false );
		$this->storeRules( $rules );

		$this->savePanel( array( MetaBox::FIELD_RULES => $this->setting( array( 'nonsense' ) ) ) );

		$this->assertSame( $rules, $this->meta( Rules::META_KEY ) );
	}

	// -----------------------------------------------------------------
	// A deliberate, valid choice replaces it
	// -----------------------------------------------------------------

	#[Test]
	public function choosing_rules_stores_them_and_removes_the_old_policy(): void {
		$this->storePolicy( array( 'mode' => 'segments', 'segment_ids' => array( self::SEG ) ) );

		$this->savePanel( array( MetaBox::FIELD_RULES => $this->setting( array( 'agend_membership:' . self::TIER ) ) ) );

		$this->assertSame( array( 'agend_membership:' . self::TIER ), $this->meta( Rules::META_KEY )['rules'] );
		$this->assertNull( $this->meta( Policy::META_KEY ), 'it would otherwise outlive the rules that replaced it' );
	}

	/** Clearing a restriction is removing every rule, which is the author choosing a page open to everyone. */
	#[Test]
	public function removing_every_rule_clears_the_rules_and_the_old_policy(): void {
		$this->storeRules( array( 'rules' => array( self::MEMBER ), 'match' => 'any', 'invert' => false ) );
		$this->storePolicy( array( 'mode' => 'segments', 'segment_ids' => array( self::SEG ) ) );

		$this->savePanel( array( MetaBox::FIELD_RULES => $this->setting( array() ) ) );

		$this->assertNull( $this->meta( Rules::META_KEY ) );
		$this->assertNull( $this->meta( Policy::META_KEY ) );
		$this->assertTrue( Rules::from_post( self::POST )->is_empty() );
	}

	#[Test]
	public function removing_every_rule_replaces_a_setting_the_panel_could_not_read(): void {
		$this->storePolicy( array( 'mode' => 'authenticated' ) );

		$this->savePanel( array( MetaBox::FIELD_RULES => $this->setting( array() ) ) );

		$this->assertNull( $this->meta( Policy::META_KEY ) );
		$this->assertTrue( Rules::from_post( self::POST )->is_empty() );
	}

	#[Test]
	public function the_denial_setting_is_saved_with_the_rules(): void {
		$this->savePanel(
			array(
				MetaBox::FIELD_RULES => $this->setting(
					array( self::MEMBER ),
					array( 'invert' => true, 'denied' => array( 'action' => 'back', 'target_id' => 0, 'fallback_id' => 0 ) )
				),
			)
		);

		$rules = Rules::from_post( self::POST );

		$this->assertTrue( $rules->is_inverted() );
		$this->assertSame( 'back', $rules->denied()['action'] );
	}

	// -----------------------------------------------------------------
	// The guards in front of all of it
	// -----------------------------------------------------------------

	#[Test]
	public function a_save_without_the_nonce_changes_nothing_even_with_a_setting(): void {
		$policy = array( 'mode' => 'segments', 'segment_ids' => array( self::SEG ) );
		$this->storePolicy( $policy );

		$_POST = array( MetaBox::FIELD_RULES => $this->setting( array() ) );
		( new MetaBox() )->save( self::POST, new WP_Post( array( 'ID' => self::POST, 'post_type' => 'page' ) ) );

		$this->assertSame( $policy, $this->meta( Policy::META_KEY ) );
	}

	#[Test]
	public function a_save_with_a_nonce_for_something_else_changes_nothing(): void {
		$policy = array( 'mode' => 'active_member' );
		$this->storePolicy( $policy );

		$_POST = array(
			MetaBox::NONCE_FIELD => 'not-the-nonce',
			MetaBox::FIELD_RULES => $this->setting( array() ),
		);
		( new MetaBox() )->save( self::POST, new WP_Post( array( 'ID' => self::POST, 'post_type' => 'page' ) ) );

		$this->assertSame( $policy, $this->meta( Policy::META_KEY ) );
	}

	#[Test]
	public function a_save_by_someone_who_cannot_edit_the_post_changes_nothing(): void {
		$policy = array( 'mode' => 'segments', 'segment_ids' => array( self::SEG ) );
		$this->storePolicy( $policy );
		$GLOBALS['agend_test_current_user_can']['edit_post'] = false;

		$this->savePanel( array( MetaBox::FIELD_RULES => $this->setting( array() ) ) );

		$this->assertSame( $policy, $this->meta( Policy::META_KEY ) );
		$this->assertNull( $this->meta( Rules::META_KEY ) );
	}

	/**
	 * `save_post` fires for every post written in a request and `$_POST` is the request's. A second
	 * post saved while the form's own is (a sync, an import, a parent updating its children) was
	 * given the form's setting, nonce and all.
	 */
	#[Test]
	public function a_save_of_another_post_in_the_same_request_is_not_the_forms_save(): void {
		$policy = array( 'mode' => 'segments', 'segment_ids' => array( self::SEG ) );
		$this->storePolicy( $policy );

		// The form is for post 999, and clears every rule. This request also writes post 501.
		$this->savePanel( array( 'post_ID' => '999', MetaBox::FIELD_RULES => $this->setting( array() ) ) );

		$this->assertSame( $policy, $this->meta( Policy::META_KEY ), 'the other post is not cleared' );
		$this->assertNull( $this->meta( Rules::META_KEY ) );

		$this->savePanel( array( 'post_ID' => '999', MetaBox::FIELD_RULES => $this->setting( array( 'agend_wordpress:logged_in' ) ) ) );

		$this->assertNull( $this->meta( Rules::META_KEY ), 'and is not given the form\'s rules' );
	}

	/** @return array<string, array{0: mixed}> */
	public static function notTheFormsId(): array {
		return array(
			'none at all'              => array( null ),
			'an empty string'          => array( '' ),
			'zero'                     => array( '0' ),
			'a different id'           => array( '502' ),
			'the id with junk after'   => array( '501abc' ),
			'the id in an array'       => array( array( '501' ) ),
			'an array that is one'     => array( array( 'x' ) ),
			'a negative id'            => array( '-501' ),
			'the id as a fraction'     => array( '501.5' ),
			'not a number'             => array( 'post' ),
		);
	}

	#[Test]
	#[DataProvider( 'notTheFormsId' )]
	public function a_save_whose_form_is_not_for_this_post_changes_nothing( $form_post ): void {
		$policy = array( 'mode' => 'segments', 'segment_ids' => array( self::SEG ) );
		$this->storePolicy( $policy );

		$fields = array( MetaBox::FIELD_RULES => $this->setting( array() ) );

		if ( null === $form_post ) {
			$_POST = array_merge( array( MetaBox::NONCE_FIELD => wp_create_nonce( MetaBox::NONCE_ACTION ) ), $fields );
			( new MetaBox() )->save( self::POST, new WP_Post( array( 'ID' => self::POST, 'post_type' => 'page' ) ) );
		} else {
			$this->savePanel( array( 'post_ID' => $form_post ) + $fields );
		}

		$this->assertSame( $policy, $this->meta( Policy::META_KEY ) );
		$this->assertNull( $this->meta( Rules::META_KEY ) );
	}

	#[Test]
	public function a_save_of_the_forms_own_post_still_saves_whatever_its_id_is_typed_as(): void {
		$this->savePanel( array( 'post_ID' => '501', MetaBox::FIELD_RULES => $this->setting() ) );

		$this->assertSame( array( self::MEMBER ), $this->meta( Rules::META_KEY )['rules'] );
	}

	#[Test]
	public function a_post_type_the_panel_is_not_offered_on_is_left_alone(): void {
		$this->savePanel( array( MetaBox::FIELD_RULES => $this->setting() ), 'nav_menu_item' );

		$this->assertNull( $this->meta( Rules::META_KEY ) );
	}

	#[Test]
	public function a_successful_save_clears_an_earlier_refusal(): void {
		$this->savePanel( array( MetaBox::FIELD_RULES => 'not json' ) );
		$this->assertNotSame( '', $this->error() );

		$this->savePanel( array( MetaBox::FIELD_RULES => $this->setting() ) );

		$this->assertSame( '', $this->error() );
	}

	/** The meta box and the REST field end in the same method, so neither can accept what the other refuses. */
	#[Test]
	public function the_meta_box_and_the_block_editor_refuse_the_same_value(): void {
		$bad = array( 'rules' => array( 'nonsense' ), 'match' => 'any', 'invert' => false );

		$this->savePanel( array( MetaBox::FIELD_RULES => (string) wp_json_encode( $bad ) ) );

		$this->assertNull( $this->meta( Rules::META_KEY ) );
		$this->assertSame( PageRules::save( self::POST, $bad ), $this->error() );
	}
}

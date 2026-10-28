<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Meta_Box;
use Agend_Content_Access_Policy;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';

/**
 * The panel's save must land on the post the form is for, and on no other.
 *
 * `save_post` fires for every post saved during a request. A translation plugin
 * syncing a second post, or any `wp_update_post()` made while the editor's save is
 * in flight, runs the handler again with the other post's id and the SAME `$_POST`,
 * whose nonce is valid for the form and says nothing about which post it is for.
 * The submitted audience was therefore applied to the second post too, and a
 * submitted "Public" made a restricted post public. The form's own `post_ID` is
 * what ties a save to its post.
 */
#[CoversClass( Agend_Content_Access_Meta_Box::class )]
final class MetaBoxSaveTargetTest extends TestCase {

	private const EDITED = 71;
	private const SYNCED = 72;
	private const TIER_A = '11111111-1111-1111-1111-111111111111';

	protected function setUp(): void {
		parent::setUp();

		$_POST = array();

		foreach ( array( self::EDITED, self::SYNCED ) as $id ) {
			$GLOBALS['agend_test_posts'][ $id ] = array(
				'ID'          => $id,
				'post_status' => 'publish',
				'post_type'   => 'post',
				'post_title'  => 'Post ' . $id,
			);
		}

		// The synced post is restricted to one plan, and the editor is not touching it.
		$GLOBALS['agend_test_post_meta'][ self::SYNCED ][ Agend_Content_Access_Policy::META_KEY ] = array(
			'mode'     => 'selected_tiers',
			'tier_ids' => array( self::TIER_A ),
		);

		$GLOBALS['agend_test_current_user_can']['edit_post'] = true;
	}

	protected function tearDown(): void {
		$_POST = array();

		parent::tearDown();
	}

	/**
	 * Submits the form the way the post screen does, for one post.
	 *
	 * @param array $fields Form fields beyond the nonce and the post it names.
	 * @param mixed $named  What `post_ID` carries, or null to leave it out.
	 */
	private function submitFormFor( array $fields, $named ): void {
		$_POST = array_merge(
			array( Agend_Content_Access_Meta_Box::NONCE_FIELD => wp_create_nonce( Agend_Content_Access_Meta_Box::NONCE_ACTION ) ),
			$fields
		);

		if ( null !== $named ) {
			$_POST['post_ID'] = $named;
		}
	}

	private function saveAs( int $id ): void {
		( new Agend_Content_Access_Meta_Box() )->save( $id, get_post( $id ) );
	}

	private function stored( int $id ) {
		return $GLOBALS['agend_test_post_meta'][ $id ][ Agend_Content_Access_Policy::META_KEY ] ?? null;
	}

	// -----------------------------------------------------------------------
	// The post the form is for is saved, and only that one
	// -----------------------------------------------------------------------

	#[Test]
	public function the_post_the_form_names_takes_the_submitted_audience(): void {
		$this->submitFormFor( array( Agend_Content_Access_Meta_Box::FIELD_MODE => 'active_member' ), (string) self::EDITED );

		$this->saveAs( self::EDITED );

		$this->assertSame( array( 'mode' => 'active_member' ), $this->stored( self::EDITED ) );
	}

	/** The translation sync: the second post is saved in the same request, after the first. */
	#[Test]
	public function a_second_post_saved_in_the_same_request_keeps_its_own_audience(): void {
		$this->submitFormFor( array( Agend_Content_Access_Meta_Box::FIELD_MODE => 'public' ), (string) self::EDITED );

		$this->saveAs( self::EDITED );
		$this->saveAs( self::SYNCED );

		$this->assertSame( array( 'mode' => 'public' ), $this->stored( self::EDITED ), 'the edited post takes what was submitted' );
		$this->assertSame(
			array( 'mode' => 'selected_tiers', 'tier_ids' => array( self::TIER_A ) ),
			$this->stored( self::SYNCED ),
			'the synced post is not made public by a form that was never about it'
		);
	}

	/** Order is not something to rely on: the sync can run before the edited post is saved. */
	#[Test]
	public function the_order_the_posts_are_saved_in_does_not_matter(): void {
		$this->submitFormFor( array( Agend_Content_Access_Meta_Box::FIELD_MODE => 'active_member' ), (string) self::EDITED );

		$this->saveAs( self::SYNCED );
		$this->saveAs( self::EDITED );

		$this->assertSame( array( 'mode' => 'active_member' ), $this->stored( self::EDITED ) );
		$this->assertSame( 'selected_tiers', $this->stored( self::SYNCED )['mode'] );
	}

	/** A submitted plan list is applied to the right post too, not copied across. */
	#[Test]
	public function a_submitted_plan_list_is_not_copied_onto_another_post(): void {
		$GLOBALS['agend_test_post_meta'][ self::SYNCED ][ Agend_Content_Access_Policy::META_KEY ] = array( 'mode' => 'active_member' );

		$this->submitFormFor(
			array(
				Agend_Content_Access_Meta_Box::FIELD_MODE  => 'selected_tiers',
				Agend_Content_Access_Meta_Box::FIELD_TIERS => array( self::TIER_A ),
			),
			(string) self::EDITED
		);

		$this->saveAs( self::SYNCED );

		$this->assertSame( array( 'mode' => 'active_member' ), $this->stored( self::SYNCED ) );
		$this->assertNull( $this->stored( self::EDITED ), 'and nothing is written for a post that was not the target' );
	}

	// -----------------------------------------------------------------------
	// A form that does not name the post is not a save of it
	// -----------------------------------------------------------------------

	/** @return array<string, array{0: mixed}> */
	public static function formsThatDoNotNameThePost(): array {
		return array(
			'no post_ID at all' => array( null ),
			'an empty post_ID'  => array( '' ),
			'another post'      => array( (string) self::SYNCED ),
			'a negative id'     => array( '-' . self::EDITED ),
			'an array'          => array( array( (string) self::EDITED ) ),
			'not a number'      => array( 'abc' ),
		);
	}

	#[Test]
	#[DataProvider( 'formsThatDoNotNameThePost' )]
	public function a_form_that_does_not_name_the_post_changes_nothing( $named ): void {
		$this->submitFormFor( array( Agend_Content_Access_Meta_Box::FIELD_MODE => 'public' ), $named );

		$this->saveAs( self::EDITED );

		$this->assertNull( $this->stored( self::EDITED ) );
	}

	/** The state of the world before this guard: a form with a nonce and a mode, and no post named. */
	#[Test]
	public function a_missing_post_id_leaves_a_restricted_post_restricted(): void {
		$this->submitFormFor( array( Agend_Content_Access_Meta_Box::FIELD_MODE => 'public' ), null );

		$this->saveAs( self::SYNCED );

		$this->assertSame( 'selected_tiers', $this->stored( self::SYNCED )['mode'] );
	}
}

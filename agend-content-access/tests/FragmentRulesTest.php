<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Elementor;
use Agend_Content_Access_Fragment_Rules;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-elementor.php';

/**
 * Which posts hold section-level rules.
 *
 * The raw body of such a post carries the text of every section, restricted ones
 * included, so the answer decides whether it may be searched, excerpted or
 * returned by the REST edit context. Wrongly answering "none" publishes a
 * section; wrongly answering "some" only costs a search result, so every doubt
 * resolves to "some".
 */
#[CoversClass( Agend_Content_Access_Fragment_Rules::class )]
#[CoversClass( Agend_Content_Access_Elementor::class )]
final class FragmentRulesTest extends TestCase {

	private const POST_ID = 77;

	/** @param array<string, mixed> $settings */
	private function node( array $settings = array(), array $children = array(), string $type = 'section' ): array {
		return array(
			'id'       => 'n' . count( $children ) . count( $settings ),
			'elType'   => $type,
			'settings' => $settings,
			'elements' => $children,
		);
	}

	private function store( $value ): void {
		$GLOBALS['agend_test_post_meta'][ self::POST_ID ]['_elementor_data'] = $value;
	}

	// -----------------------------------------------------------------------
	// What counts as carrying rules
	// -----------------------------------------------------------------------

	#[Test]
	public function a_document_with_a_fragment_policy_carries_rules(): void {
		$this->store( (string) wp_json_encode( array( $this->node( array( 'agend_access_mode' => 'active_member' ) ) ) ) );

		$this->assertTrue( Agend_Content_Access_Fragment_Rules::carries( self::POST_ID ) );
	}

	#[Test]
	public function a_document_with_enabled_display_conditions_carries_rules(): void {
		$this->store( (string) wp_json_encode( array( $this->node( array( 'agend_conditions_enabled' => 'yes' ) ) ) ) );

		$this->assertTrue( Agend_Content_Access_Fragment_Rules::carries( self::POST_ID ) );
	}

	#[Test]
	public function a_rule_on_a_deeply_nested_widget_is_found(): void {
		$deep = $this->node(
			array(),
			array(
				$this->node(
					array(),
					array( $this->node( array( 'agend_access_mode' => 'selected_tiers', 'agend_access_tier_ids' => array() ), array(), 'widget' ) ),
					'column'
				),
			)
		);

		$this->store( (string) wp_json_encode( array( $this->node(), $deep ) ) );

		$this->assertTrue( Agend_Content_Access_Fragment_Rules::carries( self::POST_ID ), 'a later top level node, three levels down' );
	}

	#[Test]
	public function an_unrecognised_mode_still_counts_because_it_is_enforced_as_a_restriction(): void {
		$this->store( (string) wp_json_encode( array( $this->node( array( 'agend_access_mode' => 'something_new' ) ) ) ) );

		$this->assertTrue( Agend_Content_Access_Fragment_Rules::carries( self::POST_ID ) );
	}

	/** @return array<string, array{0: mixed}> */
	public static function documentsWithNoRules(): array {
		return array(
			'no document stored'                  => array( '' ),
			'an empty document'                   => array( '[]' ),
			'inherit, which is the explicit opt out' => array(
				(string) wp_json_encode( array( array( 'id' => 'a', 'elType' => 'section', 'settings' => array( 'agend_access_mode' => 'inherit' ), 'elements' => array() ) ) ),
			),
			'conditions switched off'             => array(
				(string) wp_json_encode( array( array( 'id' => 'a', 'elType' => 'section', 'settings' => array( 'agend_conditions_enabled' => '' ), 'elements' => array() ) ) ),
			),
			'already decoded, none'               => array( array( array( 'id' => 'a', 'elType' => 'section', 'settings' => array(), 'elements' => array() ) ) ),
		);
	}

	#[Test]
	#[DataProvider( 'documentsWithNoRules' )]
	public function a_document_with_no_rules_does_not_carry_any( $stored ): void {
		$this->store( $stored );

		$this->assertFalse( Agend_Content_Access_Fragment_Rules::carries( self::POST_ID ) );
	}

	#[Test]
	public function a_post_with_no_document_at_all_carries_none(): void {
		$this->assertFalse( Agend_Content_Access_Fragment_Rules::carries( self::POST_ID ) );
		$this->assertFalse( Agend_Content_Access_Fragment_Rules::carries( 0 ) );
	}

	#[Test]
	public function an_already_decoded_document_is_read_too(): void {
		$this->store( array( $this->node( array( 'agend_access_mode' => 'active_member' ) ) ) );

		$this->assertTrue( Agend_Content_Access_Fragment_Rules::carries( self::POST_ID ) );
	}

	// -----------------------------------------------------------------------
	// Fail closed
	// -----------------------------------------------------------------------

	/** @return array<string, array{0: mixed}> */
	public static function unreadableDocuments(): array {
		return array(
			'truncated json'   => array( '[{"id":"a","elType":"section","settings":{"agend_access_mode":' ),
			'not json at all'  => array( 'not a document' ),
			'a json scalar'    => array( '42' ),
			'json null'        => array( 'null' ),
			'a non array value' => array( 7 ),
		);
	}

	/**
	 * "I could not tell" is not "there are none". A document that is stored but
	 * unreadable is exactly where a rule could be hiding.
	 */
	#[Test]
	#[DataProvider( 'unreadableDocuments' )]
	public function a_stored_document_that_cannot_be_read_is_treated_as_carrying_rules( $stored ): void {
		$this->store( $stored );

		$this->assertTrue( Agend_Content_Access_Fragment_Rules::carries( self::POST_ID ) );
	}

	#[Test]
	public function a_post_object_is_accepted_as_well_as_an_id(): void {
		$GLOBALS['agend_test_posts'][ self::POST_ID ] = array( 'ID' => self::POST_ID, 'post_type' => 'post' );
		$this->store( (string) wp_json_encode( array( $this->node( array( 'agend_access_mode' => 'active_member' ) ) ) ) );

		$this->assertTrue( Agend_Content_Access_Fragment_Rules::carries( get_post( self::POST_ID ) ) );
	}

	// -----------------------------------------------------------------------
	// Other editors
	// -----------------------------------------------------------------------

	#[Test]
	public function another_editors_answer_can_widen_but_the_default_stands_when_there_is_none(): void {
		$this->assertFalse( Agend_Content_Access_Fragment_Rules::carries( self::POST_ID ) );

		Agend_Test_WP::set_filter( 'agend_content_access_post_carries_fragment_rules', true );

		$this->assertTrue( Agend_Content_Access_Fragment_Rules::carries( self::POST_ID ) );
	}

	#[Test]
	public function the_filter_is_handed_the_answer_so_far_and_the_post_id(): void {
		$seen = array();
		Agend_Test_WP::$filters['agend_content_access_post_carries_fragment_rules'] = static function ( $carries, $post_id ) use ( &$seen ) {
			$seen = array( $carries, $post_id );

			return $carries;
		};
		$this->store( (string) wp_json_encode( array( $this->node( array( 'agend_access_mode' => 'active_member' ) ) ) ) );

		Agend_Content_Access_Fragment_Rules::carries( self::POST_ID );

		$this->assertSame( array( true, self::POST_ID ), $seen );
	}
}

<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Block_Editor as BlockEditor;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WP_Block_Type_Registry;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-attributes.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-editor.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-sets.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-access.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/rest/conditions-routes.php';

/**
 * The block editor's attribute, its assets, and the vocabulary route.
 *
 * What these guard is mostly absence. An attribute missing from a block type
 * means the block renderer rejects a restricted dynamic block. An attribute
 * added to the Classic block or the unsupported-block placeholder is one the
 * post then never stores. A script that throws because the build is absent
 * takes the whole editor down. And a route that is open to the public hands out
 * the site's plan, segment and role names.
 */
#[CoversClass( BlockEditor::class )]
#[CoversFunction( 'agend_content_access_conditions_payload' )]
#[CoversFunction( 'agend_content_access_can_read_conditions' )]
final class BlockEditorTest extends TestCase {

	private const HANDLE = 'agend-content-access-block-editor';
	private const URL    = 'https://example.test/wp-content/plugins/agend-content-access/';

	protected function tearDown(): void {
		// Request-shape flags a test flips are put back here, not at the end of
		// the test, so a failed assertion cannot leak one into the next test.
		unset( $GLOBALS['agend_test_is_admin'], $GLOBALS['agend_test_current_user_id'] );

		parent::tearDown();
	}

	private function fixture( string $name ): string {
		return AGEND_TESTS_ROOT . '/agend-content-access/tests/fixtures/' . $name . '/';
	}

	private function editor( string $fixture = 'block-editor' ): BlockEditor {
		return new BlockEditor( $this->fixture( $fixture ), self::URL );
	}

	/** Registers a block type the way a plugin does, so the constructor-time filter runs. */
	private function register( string $name, array $args = array() ): array {
		return WP_Block_Type_Registry::get_instance()->register( $name, $args )->attributes;
	}

	// ---------------------------------------------------------------------
	// The attribute
	// ---------------------------------------------------------------------

	#[Test]
	public function every_registered_block_type_declares_the_attribute_as_an_object_defaulting_to_empty(): void {
		$this->editor();

		$names = array(
			'core/paragraph',
			'core/group',
			'core/query',
			'core/template-part',
			'agend-apps/record-field',
			'acme-blocks/pricing-table',
		);

		foreach ( $names as $name ) {
			$attributes = $this->register(
				$name,
				array(
					'attributes' => array( 'content' => array( 'type' => 'string' ) ),
				)
			);

			$this->assertSame(
				array(
					'type'    => 'object',
					'default' => array(),
				),
				$attributes['agendAccess'] ?? null,
				"{$name} must declare agendAccess"
			);
			$this->assertSame( array( 'type' => 'string' ), $attributes['content'], "{$name} keeps its own attributes" );
		}
	}

	#[Test]
	public function a_dynamic_block_with_no_attributes_of_its_own_gets_the_attribute(): void {
		$this->editor();

		$attributes = $this->register(
			'acme/latest',
			array( 'render_callback' => static fn(): string => '' )
		);

		$this->assertSame( array( 'agendAccess' ), array_keys( $attributes ) );
	}

	#[Test]
	#[DataProvider( 'excludedBlocks' )]
	public function a_block_that_cannot_store_an_attribute_does_not_get_one( string $name ): void {
		$this->editor();

		$attributes = $this->register( $name, array( 'attributes' => array( 'content' => array( 'type' => 'string' ) ) ) );

		$this->assertArrayNotHasKey( 'agendAccess', $attributes );
		$this->assertSame( array( 'content' ), array_keys( $attributes ) );
	}

	public static function excludedBlocks(): array {
		return array(
			'the unsupported block placeholder' => array( 'core/missing' ),
			'the Classic block'                 => array( 'core/freeform' ),
		);
	}

	#[Test]
	public function the_excluded_list_is_exactly_the_two_blocks_that_have_no_comment_delimiter(): void {
		$this->assertSame( array( 'core/missing', 'core/freeform' ), BlockEditor::EXCLUDED_BLOCKS );
	}

	#[Test]
	public function a_block_that_already_declares_the_attribute_keeps_its_own_declaration(): void {
		$this->editor();

		$own = array(
			'type'    => 'string',
			'default' => 'theirs',
		);

		$attributes = $this->register( 'acme/own', array( 'attributes' => array( 'agendAccess' => $own ) ) );

		$this->assertSame( $own, $attributes['agendAccess'] );
	}

	#[Test]
	public function a_declaration_that_is_present_but_null_is_still_not_overridden(): void {
		$result = $this->editor()->add_attribute( array( 'attributes' => array( 'agendAccess' => null ) ), 'acme/null' );

		$this->assertArrayHasKey( 'agendAccess', $result['attributes'] );
		$this->assertNull( $result['attributes']['agendAccess'] );
	}

	#[Test]
	public function arguments_that_are_not_in_the_expected_shape_do_not_break_registration(): void {
		$editor = $this->editor();

		$this->assertSame( 'not an array', $editor->add_attribute( 'not an array', 'acme/odd' ) );

		$result = $editor->add_attribute( array( 'attributes' => 'not an array' ), 'acme/odd' );

		$this->assertSame( array( 'agendAccess' ), array_keys( $result['attributes'] ) );
	}

	#[Test]
	public function it_hooks_the_attribute_on_every_request_and_the_assets_on_the_editor_hooks(): void {
		$this->editor();

		$this->assertArrayHasKey( 'register_block_type_args', Agend_Test_WP::$filters );
		$this->assertArrayHasKey( 'enqueue_block_editor_assets', Agend_Test_WP::$actions );
		$this->assertArrayHasKey( 'enqueue_block_assets', Agend_Test_WP::$actions );
		$this->assertSame( array( 1 ), Agend_Test_WP::$action_priorities['enqueue_block_editor_assets'], 'queued ahead of other editor scripts' );
	}

	// ---------------------------------------------------------------------
	// Assets
	// ---------------------------------------------------------------------

	#[Test]
	public function the_script_is_enqueued_with_the_dependencies_and_version_the_build_wrote(): void {
		$this->editor()->enqueue_script();

		$this->assertSame( array( self::HANDLE ), Agend_Test_WP::$enqueued_scripts );

		$args = Agend_Test_WP::$enqueued_script_args[ self::HANDLE ];

		$this->assertSame( self::URL . 'build/index.js', $args['src'] );
		$this->assertSame( array( 'react-jsx-runtime', 'wp-blocks', 'wp-hooks' ), $args['deps'] );
		$this->assertSame( 'fixture1234567890abcd', $args['ver'] );
		$this->assertFalse( $args['args'], 'in the head, so it runs before the editor registers blocks' );
	}

	#[Test]
	public function the_script_is_made_translatable_under_the_plugin_text_domain(): void {
		$this->editor()->enqueue_script();

		$this->assertSame( 'agend-content-access', Agend_Test_WP::$script_translations[ self::HANDLE ]['domain'] );
	}

	#[Test]
	public function the_stylesheet_is_enqueued_in_the_editor_with_the_same_version_and_an_rtl_twin(): void {
		$GLOBALS['agend_test_is_admin'] = true;

		$this->editor()->enqueue_style();

		$this->assertSame( array( self::HANDLE ), Agend_Test_WP::$enqueued_styles );

		$args = Agend_Test_WP::$enqueued_style_args[ self::HANDLE ];

		$this->assertSame( self::URL . 'build/index.css', $args['src'] );
		$this->assertSame( 'fixture1234567890abcd', $args['ver'] );
		$this->assertSame( 'replace', Agend_Test_WP::$style_data[ self::HANDLE ]['rtl'] );

	}

	#[Test]
	public function the_stylesheet_is_not_enqueued_on_the_front_end(): void {
		$GLOBALS['agend_test_is_admin'] = false;

		$this->editor()->enqueue_style();

		$this->assertSame( array(), Agend_Test_WP::$enqueued_styles );
	}

	#[Test]
	public function an_unbuilt_checkout_enqueues_nothing_and_does_not_fail(): void {
		$GLOBALS['agend_test_is_admin'] = true;

		$editor = new BlockEditor( $this->fixture( 'does-not-exist' ), self::URL );

		$editor->enqueue_script();
		$editor->enqueue_style();

		$this->assertSame( array(), Agend_Test_WP::$enqueued_scripts );
		$this->assertSame( array(), Agend_Test_WP::$enqueued_styles );

	}

	#[Test]
	public function an_asset_file_that_is_not_the_documented_shape_enqueues_nothing(): void {
		$GLOBALS['agend_test_is_admin'] = true;

		$editor = $this->editor( 'block-editor-malformed' );

		$editor->enqueue_script();
		$editor->enqueue_style();

		$this->assertSame( array(), Agend_Test_WP::$enqueued_scripts );
		$this->assertSame( array(), Agend_Test_WP::$enqueued_styles );

	}

	// ---------------------------------------------------------------------
	// The vocabulary route
	// ---------------------------------------------------------------------

	#[Test]
	public function the_route_is_a_get_that_names_the_permission_and_callback(): void {
		$route = agend_content_access_conditions_route();

		$this->assertCount( 1, $route );
		$this->assertSame( 'GET', $route[0]['methods'] );
		$this->assertSame( 'agend_content_access_can_read_conditions', $route[0]['permission_callback'] );
		$this->assertTrue( is_callable( $route[0]['callback'] ) );
		$this->assertTrue( is_callable( $route[0]['permission_callback'] ) );
	}

	#[Test]
	public function the_route_refuses_somebody_who_cannot_edit_posts(): void {
		$GLOBALS['agend_test_current_user_can']['edit_posts'] = false;
		$GLOBALS['agend_test_current_user_id']                = 0;

		$result = agend_content_access_can_read_conditions();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'agend_content_access_forbidden', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'], 'a signed-out request is asked to authenticate' );

		$GLOBALS['agend_test_current_user_id'] = 7;

		$this->assertSame( 403, agend_content_access_can_read_conditions()->get_error_data()['status'], 'a signed-in one is refused' );
	}

	#[Test]
	public function the_route_admits_somebody_who_can_edit_posts(): void {
		$GLOBALS['agend_test_current_user_can']['edit_posts'] = true;

		$this->assertTrue( agend_content_access_can_read_conditions() );
	}

	#[Test]
	public function the_payload_is_lists_with_each_value_already_joined_to_its_provider(): void {
		Agend_Test_WP::set_filter(
			'agend_content_access_condition_sets',
			array(
				'agend_wordpress'  => array(
					'label'      => 'WordPress user',
					'conditions' => array(
						'logged_in' => 'Signed in',
						'role_editor' => 'Role: Editor',
					),
				),
				'agend_membership' => array(
					'label'      => 'Membership',
					// A numeric slug becomes an integer array key in PHP.
					'conditions' => array(
						'is_member' => 'Holds a current membership',
						2024        => 'Plan: Class of 2024',
					),
				),
			)
		);

		$payload = agend_content_access_conditions_payload();

		$this->assertSame( array( 'groups', 'fallbacks' ), array_keys( $payload ) );
		$this->assertSame(
			array(
				array(
					'provider'   => 'agend_wordpress',
					'label'      => 'WordPress user',
					'conditions' => array(
						array( 'value' => 'agend_wordpress:logged_in', 'label' => 'Signed in' ),
						array( 'value' => 'agend_wordpress:role_editor', 'label' => 'Role: Editor' ),
					),
				),
				array(
					'provider'   => 'agend_membership',
					'label'      => 'Membership',
					'conditions' => array(
						array( 'value' => 'agend_membership:is_member', 'label' => 'Holds a current membership' ),
						array( 'value' => 'agend_membership:2024', 'label' => 'Plan: Class of 2024' ),
					),
				),
			),
			$payload['groups']
		);
		$this->assertSame( '[]', json_encode( $payload['fallbacks'] ) );
	}

	#[Test]
	public function a_group_that_could_not_load_is_kept_empty_so_the_panel_can_say_so(): void {
		Agend_Test_WP::set_filter(
			'agend_content_access_condition_sets',
			array(
				'agend_segments' => array(
					'label'      => 'Segments',
					'conditions' => array(),
				),
			)
		);

		$groups = agend_content_access_conditions_payload()['groups'];

		$this->assertCount( 1, $groups );
		$this->assertSame( 'agend_segments', $groups[0]['provider'] );
		$this->assertSame( array(), $groups[0]['conditions'] );
		$this->assertSame( '[]', json_encode( $groups[0]['conditions'] ), 'an empty list, not an empty object' );
	}

	#[Test]
	public function a_filtered_set_that_is_not_the_documented_shape_is_skipped_or_labelled_by_its_key(): void {
		Agend_Test_WP::set_filter(
			'agend_content_access_condition_sets',
			array(
				'broken'    => 'not a set',
				'unlabeled' => array( 'conditions' => array( 'a' => 'A' ) ),
				'noconds'   => array( 'label' => 'No conditions key' ),
			)
		);

		$groups = agend_content_access_conditions_payload()['groups'];

		$this->assertSame( array( 'unlabeled', 'noconds' ), array_column( $groups, 'provider' ) );
		$this->assertSame( 'unlabeled', $groups[0]['label'] );
		$this->assertSame( array(), $groups[1]['conditions'] );
	}

	#[Test]
	public function fallbacks_are_public_pages_as_id_and_title_without_the_nothing_choice(): void {
		Agend_Test_WP::set_filter( 'agend_content_access_condition_sets', array() );

		$GLOBALS['agend_test_posts'] = array(
			12 => (object) array(
				'ID'          => 12,
				'post_status' => 'publish',
				'post_title'  => 'Join us',
				'post_type'   => 'page',
			),
			13 => (object) array(
				'ID'          => 13,
				'post_status' => 'publish',
				'post_title'  => 'Members only',
				'post_type'   => 'page',
			),
		);

		$GLOBALS['agend_test_post_meta'][13] = array( '_agend_access_policy' => array( 'mode' => 'active_member' ) );

		$this->assertSame(
			array( array( 'id' => 12, 'title' => 'Join us' ) ),
			agend_content_access_conditions_payload()['fallbacks'],
			'a restricted page is not offered as replacement content'
		);
	}

	/**
	 * The panel's list is the Elementor picker's list, so a post that renders
	 * without its password prompt is not offered to the block editor either.
	 */
	#[Test]
	public function a_password_protected_page_is_not_offered_as_a_fallback_in_the_block_editor(): void {
		Agend_Test_WP::set_filter( 'agend_content_access_condition_sets', array() );

		foreach ( array( 12 => '', 13 => 'hunter2' ) as $id => $password ) {
			$GLOBALS['agend_test_posts'][ $id ] = (object) array(
				'ID'            => $id,
				'post_status'   => 'publish',
				'post_title'    => 'Page ' . $id,
				'post_type'     => 'page',
				'post_password' => $password,
			);
		}

		$this->assertSame(
			array( array( 'id' => 12, 'title' => 'Page 12' ) ),
			agend_content_access_conditions_payload()['fallbacks']
		);
	}

	#[Test]
	public function the_response_is_the_payload(): void {
		Agend_Test_WP::set_filter( 'agend_content_access_condition_sets', array() );

		$this->assertSame(
			agend_content_access_conditions_payload(),
			agend_content_access_get_conditions()->get_data()
		);
	}
}

<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Records;

use Agend\Tests\TestCase;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Agend Field's custom field picker reads its choices off the editor
 * preview listing, which is two gateway calls on a cache miss. Elementor
 * registers the same control on every front-end render of the widget, so the
 * list may only be resolved for an editor.
 *
 * Runs in separate processes so the directory gateway stub functions never
 * reach another test, which relies on them being absent.
 */
#[RunTestsInSeparateProcesses]
#[CoversFunction( 'agend_apps_records_custom_field_key_options' )]
#[CoversFunction( 'agend_apps_records_is_editor_request' )]
final class CustomFieldKeyOptionsTest extends TestCase {

	private const PLACEHOLDER = 'Not listed (enter a key below)';

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/preview-records.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema/record-field.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/directory-listing-stub.php';

		$GLOBALS['agend_test_directory_requests'] = array();
		$GLOBALS['agend_test_directory_listings'] = array( 'data' => array( array( 'slug' => 'acme' ) ) );
		$GLOBALS['agend_test_directory_listing']  = array(
			'data' => array(
				'slug'          => 'acme',
				'custom_fields' => array(
					array( 'key' => 'education_level', 'label' => 'Education level', 'type' => 'text', 'value' => 'Postgraduate' ),
					array( 'key' => 'region', 'label' => '', 'type' => 'text', 'value' => 'North' ),
					array( 'label' => 'No key', 'type' => 'text', 'value' => 'skipped' ),
				),
			),
		);
	}

	protected function tearDown(): void {
		unset( $_REQUEST['action'], $GLOBALS['wp'] );
		parent::tearDown();
	}

	private function enter_context( string $context ): void {
		switch ( $context ) {
			case 'elementor_edit_mode':
				\Elementor\Plugin::$instance->editor->is_edit_mode = true;
				break;

			case 'elementor_preview_frame':
				\Elementor\Plugin::$instance->preview->is_preview_mode = true;
				break;

			case 'elementor_panel_ajax':
				$GLOBALS['agend_test_doing_ajax'] = true;
				$_REQUEST['action']               = 'elementor_ajax';
				break;

			case 'block_editor_schema_route':
				$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => '/agend-apps/v1/surfaces/record-field/schema' ) );
				break;

			case 'visitor_elementor_ajax':
				$GLOBALS['agend_test_current_user_can']['edit_posts'] = false;
				$GLOBALS['agend_test_doing_ajax']                     = true;
				$_REQUEST['action']                                   = 'elementor_ajax';
				break;

			case 'visitor_schema_route':
				$GLOBALS['agend_test_current_user_can']['edit_posts'] = false;
				$GLOBALS['wp']                                        = (object) array( 'query_vars' => array( 'rest_route' => '/agend-apps/v1/surfaces/record-field/schema' ) );
				break;

			case 'other_ajax_action':
				$GLOBALS['agend_test_doing_ajax'] = true;
				$_REQUEST['action']               = 'heartbeat';
				break;

			case 'signed_in_editor_card_fragment':
				$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => '/agend-apps/v1/cards/listings' ) );
				break;
		}
	}

	/** @return array<string, array{string}> */
	public static function editor_contexts(): array {
		return array(
			'Elementor edit mode'              => array( 'elementor_edit_mode' ),
			'Elementor preview frame'          => array( 'elementor_preview_frame' ),
			'Elementor panel controls request' => array( 'elementor_panel_ajax' ),
			'block editor schema route'        => array( 'block_editor_schema_route' ),
		);
	}

	/** @return array<string, array{string}> */
	public static function visitor_contexts(): array {
		return array(
			'front-end page render'                => array( '' ),
			'elementor_ajax by a visitor'          => array( 'visitor_elementor_ajax' ),
			'schema route by a visitor'            => array( 'visitor_schema_route' ),
			'another ajax action by an editor'     => array( 'other_ajax_action' ),
			'card fragment route for an editor'    => array( 'signed_in_editor_card_fragment' ),
		);
	}

	#[Test]
	#[DataProvider( 'visitor_contexts' )]
	public function should_make_no_gateway_call_and_offer_only_the_placeholder_when_no_editor_is_behind_the_request( string $context ): void {
		$this->enter_context( $context );

		$options = \agend_apps_records_custom_field_key_options();

		self::assertSame( array( '' => self::PLACEHOLDER ), $options );
		self::assertSame( array(), $GLOBALS['agend_test_directory_requests'] );
		self::assertSame( array(), Agend_Test_WP::$transients );
	}

	#[Test]
	#[DataProvider( 'editor_contexts' )]
	public function should_resolve_the_listings_custom_fields_when_an_editor_is_behind_the_request( string $context ): void {
		$this->enter_context( $context );

		$options = \agend_apps_records_custom_field_key_options();

		self::assertSame(
			array(
				''                => self::PLACEHOLDER,
				'education_level' => 'Education level',
				'region'          => 'region',
			),
			$options
		);
		self::assertSame(
			array( array( 'listings', '' ), array( 'listing', 'acme' ) ),
			$GLOBALS['agend_test_directory_requests']
		);
	}
}

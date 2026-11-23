<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Endpoint_Scopes;
use Agend_Apps_Key_Scopes;
use Agend_Apps_Loop_My_Communities_REST_Controller;
use Agend_Apps_Records_Record_Context;
use Agend_Apps_Settings;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use WP_REST_Request;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/pages.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/record-context.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/blocks.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/loop-memberships.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/loop-my-communities.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/loop-my-communities-routes.php';

/**
 * My Communities as a visitor and an editor meet it: the settings, the shell
 * and the sign-in card the page holds, the fragment a member is sent for every
 * state, and the route that sends it.
 */
final class LoopMyCommunitiesRenderTest extends TestCase {

	private const USER = 42;

	protected function setUp(): void {
		parent::setUp();
		Agend_Test_WP::$options['permalink_structure'] = '/%postname%/';
		Agend_Apps_Endpoint_Scopes::set_map( null );
		Agend_Apps_Records_Record_Context::reset();
		update_option( 'agend_apps_member_auth_mode', Agend_Apps_Settings::MEMBER_AUTH_CREDENTIALS );
		update_option( 'agend_apps_account_slug', 'smsf' );
		update_option( 'agend_apps_root_url_for_tests', 'https://api.agend.test' );
		update_user_meta( self::USER, 'imk_membership_number', 'M1001' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['agend_test_current_user_id'], $GLOBALS['agend_test_current_user_can'] );
		Agend_Apps_Endpoint_Scopes::set_map( null );
		Agend_Apps_Records_Record_Context::reset();
		parent::tearDown();
	}

	private function signIn(): void {
		$GLOBALS['agend_test_current_user_id'] = self::USER;
	}

	/**
	 * Surface settings as an adapter passes them: the schema's defaults with
	 * toggles as 'yes' or ''.
	 */
	private function settings( array $overrides = array() ): array {
		$schema   = agend_apps_records_surface_schema( 'loop-my-communities' );
		$defaults = array();

		foreach ( $schema['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				if ( isset( $field['default'] ) ) {
					$defaults[ $field['name'] ] = $field['default'];
				}
			}
		}

		return $overrides + agend_apps_records_settings_from_attributes( $schema, $defaults );
	}

	private function config( array $overrides = array() ): array {
		return agend_apps_records_my_communities_build_config( $this->settings( $overrides ) );
	}

	private function ok( array $names = array( 'Fellows', 'Committee' ), string $url = 'https://api.agend.test/sso/smsf/loop-channel/x' ): array {
		$communities = array();

		foreach ( $names as $name ) {
			$communities[] = array(
				'id'           => 'id-' . $name,
				'name'         => $name,
				'description'  => 'About ' . $name,
				'channel_type' => 'channel',
				'access_url'   => $url,
			);
		}

		return array(
			'state'       => 'ok',
			'communities' => $communities,
			'links'       => '' !== $url,
		);
	}

	private function request( array $body = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/agend-apps/v1/member/communities' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return $request;
	}

	// -----------------------------------------------------------------
	// Registration
	// -----------------------------------------------------------------

	#[Test]
	public function should_be_a_registered_block_surface_with_a_scope_and_a_route(): void {
		$this->assertContains( 'loop-my-communities', AGEND_APPS_RECORDS_BLOCK_SURFACES );
		$this->assertSame( array( 'loop.memberships.read' ), agend_apps_records_surface_map()['loop-my-communities']['scopes'] );
		$this->assertSame( 'loop-my-communities', agend_apps_records_rest_route_surface( '/agend-apps/v1/member/communities' ) );
		$this->assertNotEmpty( agend_apps_records_block_attributes( agend_apps_records_surface_schema( 'loop-my-communities' ) ) );
		$this->assertFileExists( dirname( __DIR__ ) . '/build/blocks/loop-my-communities/block.json' );
	}

	#[Test]
	public function should_carry_editable_headings_and_labels_as_attributes(): void {
		$attributes = agend_apps_records_block_attributes( agend_apps_records_surface_schema( 'loop-my-communities' ) );

		foreach ( array( 'heading_text', 'button_label', 'empty_message', 'unavailable_message', 'signed_out_message', 'sign_in_label' ) as $name ) {
			$this->assertSame( 'string', $attributes[ $name ]['type'], $name );
		}

		$this->assertSame( 'Access community', $attributes['button_label']['default'] );
		$this->assertSame( 'You are not in any communities yet.', $attributes['empty_message']['default'] );
	}

	// -----------------------------------------------------------------
	// The page
	// -----------------------------------------------------------------

	#[Test]
	public function a_visitor_gets_a_sign_in_card_with_no_script_shell_and_no_call(): void {
		$html = agend_apps_records_render_loop_my_communities( $this->settings() );

		$this->assertStringContainsString( 'agend-my-communities--signed-out', $html );
		$this->assertStringContainsString( 'data-agend-mc-state="signed_out"', $html );
		$this->assertStringContainsString( 'Sign in to see the communities you belong to.', $html );
		$this->assertStringContainsString( 'wp-login.php', $html );
		$this->assertStringContainsString( '<h2 class="agend-my-communities__title">My communities</h2>', $html );
		$this->assertStringNotContainsString( 'data-agend-my-communities-config', $html );
		$this->assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function a_visitor_sees_nothing_when_the_editor_hides_the_widget_from_them(): void {
		$this->assertSame( '', agend_apps_records_render_loop_my_communities( $this->settings( array( 'hide_when_signed_out' => 'yes' ) ) ) );
	}

	#[Test]
	public function a_member_gets_a_shell_holding_nothing_about_them(): void {
		$this->signIn();
		update_user_meta( self::USER, 'first_name', 'Priya' );

		$html = agend_apps_records_render_loop_my_communities( $this->settings() );

		$this->assertStringContainsString( 'data-agend-my-communities-config="', $html );
		$this->assertStringContainsString( 'data-agend-mc-body', $html );
		$this->assertStringContainsString( 'aria-busy="true"', $html );
		$this->assertStringContainsString( 'agend-apps\\/v1\\/member\\/communities', html_entity_decode( $html ) );
		$this->assertStringNotContainsString( 'Priya', $html );
		$this->assertStringNotContainsString( 'M1001', $html );
		$this->assertStringNotContainsString( 'loop-channel', $html );
		$this->assertStringNotContainsString( 'agend-mc-card__title', $html );
		$this->assertCount( 0, Agend_Test_WP::$requests, 'the page makes no call; the route does' );
	}

	#[Test]
	public function the_shell_is_the_same_for_every_member(): void {
		$this->signIn();
		$first = agend_apps_records_render_loop_my_communities( $this->settings() );

		update_user_meta( 99, 'imk_membership_number', 'M9' );
		$GLOBALS['agend_test_current_user_id'] = 99;

		$this->assertSame( $first, agend_apps_records_render_loop_my_communities( $this->settings() ) );
	}

	#[Test]
	public function the_shell_hands_back_only_the_content_settings(): void {
		$this->signIn();

		$html   = agend_apps_records_render_loop_my_communities( $this->settings( array( 'button_label' => 'Open it', 'heading_colour' => '#123456' ) ) );
		$client = json_decode( html_entity_decode( (string) preg_replace( '/^.*data-agend-my-communities-config="([^"]*)".*$/s', '$1', $html ) ), true );

		$this->assertSame( 'Open it', $client['settings']['button_label'] );
		$this->assertArrayNotHasKey( 'heading_colour', $client['settings'] );
	}

	#[Test]
	public function the_editor_sees_a_labelled_example_and_no_call_is_made(): void {
		$this->signIn();

		$html = agend_apps_records_render_loop_my_communities( $this->settings(), array( 'preview' => true ) );

		$this->assertStringContainsString( 'agend-my-communities--example', $html );
		$this->assertStringContainsString( 'Example for editing only', $html );
		$this->assertStringContainsString( 'Access community', $html );
		$this->assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function a_key_known_to_lack_the_scope_draws_nothing_for_a_visitor(): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => array( 'crm.tiers.browse' ), 'app_ids' => array() ) ) );

		$this->assertSame( '', agend_apps_records_render_loop_my_communities( $this->settings() ) );
	}

	#[Test]
	public function it_draws_nothing_inside_a_record_template(): void {
		Agend_Apps_Records_Record_Context::push( 'event', array( 'id' => 'e1' ) );

		$this->assertSame( '', agend_apps_records_render_loop_my_communities( $this->settings() ) );
	}

	// -----------------------------------------------------------------
	// The fragment, state by state
	// -----------------------------------------------------------------

	#[Test]
	public function a_list_has_a_card_each_with_name_description_and_a_new_tab_button(): void {
		$html = agend_apps_records_my_communities_render_body( $this->config(), $this->ok() );

		$this->assertSame( 2, substr_count( $html, 'class="agend-mc-card"' ) );
		$this->assertStringContainsString( '<h3 class="agend-mc-card__title">Fellows</h3>', $html );
		$this->assertStringContainsString( 'About Committee', $html );
		$this->assertSame( 2, substr_count( $html, 'href="https://api.agend.test/sso/smsf/loop-channel/x"' ) );
		$this->assertSame( 2, substr_count( $html, 'target="_blank" rel="noopener noreferrer"' ) );
		$this->assertStringContainsString( 'Access community<span class="agend-mc-sr"> (opens in a new tab)</span>', $html );
		$this->assertStringContainsString( 'role="list"', $html );
	}

	#[Test]
	public function the_description_can_be_switched_off_and_the_button_reworded(): void {
		$html = agend_apps_records_my_communities_render_body( $this->config( array( 'show_description' => '', 'button_label' => 'Go to Loop' ) ), $this->ok() );

		$this->assertStringNotContainsString( 'About Fellows', $html );
		$this->assertStringContainsString( 'Go to Loop<span', $html );
	}

	#[Test]
	public function everything_a_channel_says_is_escaped(): void {
		$result = $this->ok( array( '<script>alert(1)</script>' ), 'https://api.agend.test/x?a=1&b="2"' );
		$result['communities'][0]['description'] = '<img src=x onerror=alert(1)>';

		$html = agend_apps_records_my_communities_render_body( $this->config(), $result );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringNotContainsString( '"2"', $html );
	}

	#[Test]
	public function an_empty_list_says_so_in_the_editors_words(): void {
		$result = array( 'state' => 'ok', 'communities' => array(), 'links' => true );

		$this->assertStringContainsString( 'You are not in any communities yet.', agend_apps_records_my_communities_render_body( $this->config(), $result ) );
		$this->assertStringContainsString( 'data-agend-mc-state="empty"', agend_apps_records_my_communities_render_body( $this->config(), $result ) );
		$this->assertStringContainsString( 'Nothing yet', agend_apps_records_my_communities_render_body( $this->config( array( 'empty_message' => 'Nothing yet' ) ), $result ) );
	}

	#[Test]
	public function an_unavailable_answer_gives_the_not_available_state_not_an_error(): void {
		foreach ( array( 'unavailable', 'refused' ) as $state ) {
			$html = agend_apps_records_my_communities_render_body( $this->config(), array( 'state' => $state, 'communities' => array(), 'links' => true ) );

			$this->assertStringContainsString( 'Your communities are not available right now.', $html, $state );
			$this->assertStringContainsString( 'data-agend-mc-state="' . $state . '"', $html, $state );
			$this->assertStringNotContainsString( 'agend-mc-card', $html, $state );
		}
	}

	#[Test]
	public function a_member_with_no_membership_number_is_told_in_plain_words(): void {
		$html = agend_apps_records_my_communities_render_body( $this->config(), array( 'state' => 'no_contact', 'communities' => array(), 'links' => true ) );

		$this->assertStringContainsString( 'could not match your account to a membership', $html );
	}

	#[Test]
	public function a_missing_account_slug_shows_channels_without_buttons_and_tells_only_administrators(): void {
		$result = $this->ok( array( 'Fellows' ), '' );

		$member = agend_apps_records_my_communities_render_body( $this->config(), $result, false );
		$admin  = agend_apps_records_my_communities_render_body( $this->config(), $result, true );

		$this->assertStringContainsString( 'Fellows', $member );
		$this->assertStringNotContainsString( 'agend-mc-button', $member );
		$this->assertStringNotContainsString( 'administrators', $member );
		$this->assertStringContainsString( 'Fellows', $admin );
		$this->assertStringNotContainsString( 'agend-mc-button', $admin );
		$this->assertStringContainsString( 'Only administrators see this note.', $admin );
		$this->assertStringContainsString( 'account slug', $admin );
	}

	#[Test]
	public function administrator_notes_never_reach_a_member(): void {
		foreach ( array( 'unavailable', 'refused', 'no_contact' ) as $state ) {
			$result = array( 'state' => $state, 'communities' => array(), 'links' => true );

			$this->assertStringNotContainsString( 'administrators', agend_apps_records_my_communities_render_body( $this->config(), $result, false ), $state );
			$this->assertStringContainsString( 'Only administrators see this note.', agend_apps_records_my_communities_render_body( $this->config(), $result, true ), $state );
		}
	}

	// -----------------------------------------------------------------
	// The route
	// -----------------------------------------------------------------

	#[Test]
	public function the_route_is_registered_under_the_apps_namespace_for_members_only(): void {
		agend_apps_register_loop_my_communities_routes();

		$routes = array_values( array_filter( Agend_Test_WP::$rest_routes, static fn( array $r ): bool => 'agend-apps/v1' === $r['namespace'] && '/member/communities' === $r['route'] ) );

		$this->assertCount( 1, $routes );
		$this->assertSame( 'POST', $routes[0]['args'][0]['methods'] );
		$this->assertSame( 'permissions_check', $routes[0]['args'][0]['permission_callback'][1] );
	}

	#[Test]
	public function the_route_refuses_a_visitor_and_admits_a_member(): void {
		$controller = new Agend_Apps_Loop_My_Communities_REST_Controller();

		$this->assertFalse( $controller->permissions_check() );

		$this->signIn();
		$this->assertTrue( $controller->permissions_check() );
	}

	#[Test]
	public function the_route_sends_the_members_list_uncached_and_without_a_bearer(): void {
		$this->signIn();
		add_filter( 'agend_apps_bearer_token', static fn() => 'member-token' );
		Agend_Test_WP::queue_response(
			200,
			array(
				'success' => true,
				'data'    => array(
					'resolved' => true,
					'channels' => array(
						array( 'id' => 'a', 'name' => 'Fellows', 'slug' => 'fellows', 'description' => null, 'channel_type' => 'channel', 'unique_id' => 'rule:fellows' ),
					),
				),
			)
		);

		$response = ( new Agend_Apps_Loop_My_Communities_REST_Controller() )->communities( $this->request( array( 'settings' => array( 'button_label' => 'Open it' ) ) ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['state'] );
		$this->assertStringContainsString( 'Fellows', $data['html'] );
		$this->assertStringContainsString( 'Open it', $data['html'] );
		$this->assertStringContainsString( 'https://api.agend.test/sso/smsf/loop-channel/fellows', $data['html'] );
		$this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame( 'Cookie', $response->get_headers()['Vary'] );
		$this->assertArrayNotHasKey( 'Authorization', Agend_Test_WP::$requests[0]['headers'] );
	}

	#[Test]
	public function the_route_always_answers_200_with_a_state_for_the_ordinary_failures(): void {
		$this->signIn();
		Agend_Test_WP::queue_response( 500, array( 'success' => false, 'error' => array( 'code' => 'DATABASE_ERROR', 'message' => 'x' ) ) );

		$response = ( new Agend_Apps_Loop_My_Communities_REST_Controller() )->communities( $this->request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'unavailable', $response->get_data()['state'] );
		$this->assertStringContainsString( 'not available right now', $response->get_data()['html'] );
	}

	#[Test]
	public function the_route_never_trusts_the_settings_it_is_sent(): void {
		$this->signIn();
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'resolved' => true, 'channels' => array() ) ) );

		$response = ( new Agend_Apps_Loop_My_Communities_REST_Controller() )->communities(
			$this->request( array( 'settings' => array( 'empty_message' => '<b onclick="x">None</b>', 'external_id' => 'M9999', 'show_description' => array( 'x' ) ) ) )
		);

		$this->assertStringContainsString( 'None', $response->get_data()['html'] );
		$this->assertStringNotContainsString( '<b', $response->get_data()['html'] );
		$this->assertStringContainsString( 'external_id=M1001', Agend_Test_WP::$requests[0]['url'], 'the member comes from the session alone' );
	}

	#[Test]
	public function the_route_tells_an_administrator_what_a_member_never_hears(): void {
		$this->signIn();
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'resolved' => true ) ) );
		$GLOBALS['agend_test_current_user_can']['manage_options'] = true;

		$admin = ( new Agend_Apps_Loop_My_Communities_REST_Controller() )->communities( $this->request() )->get_data()['html'];

		$this->assertStringContainsString( 'include=channels', $admin );

		update_user_meta( self::USER, 'imk_membership_number', 'M5' );
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'resolved' => true ) ) );
		$GLOBALS['agend_test_current_user_can']['manage_options'] = false;

		$member = ( new Agend_Apps_Loop_My_Communities_REST_Controller() )->communities( $this->request() )->get_data()['html'];

		$this->assertStringNotContainsString( 'include=channels', $member );
	}
}

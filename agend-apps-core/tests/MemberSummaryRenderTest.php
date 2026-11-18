<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Key_Scopes;
use Agend_Apps_Member_Summary_REST_Controller;
use Agend_Apps_Records_Record_Context;
use Agend_Apps_Endpoint_Scopes;
use Agend_Apps_Logger;
use Agend_Apps_API;
use Agend_Test_WP;
use Agend\Tests\AppsCore\MemoryLogStore;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WP_REST_Request;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/pages.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/record-context.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-redactor.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-logger.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-http-batch.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-endpoint-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/blocks.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/member-summary.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/member-summary-routes.php';
require_once __DIR__ . '/fixtures/memory-log-store.php';

/**
 * The member summary as a visitor and an editor meet it: the settings, the
 * shell and the sign-in card the page holds, the cards a member is sent, every
 * state of them, and the route that sends them.
 *
 * The cards are drawn from the pinned gateway shapes
 * (fixtures/member-summary-gateway.json), reduced by the real service, so what
 * is asserted is what a member would read.
 */
final class MemberSummaryRenderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Agend_Test_WP::$options['permalink_structure'] = '/%postname%/';
		Agend_Apps_Logger::set_instance( new Agend_Apps_Logger( new MemoryLogStore() ) );
		Agend_Apps_Endpoint_Scopes::set_map( null );
		Agend_Test_WP::set_filter( 'agend_apps_api_parallel', false );
		agend_apps_member_access_forget();
		Agend_Apps_Records_Record_Context::reset();
		( new \ReflectionProperty( Agend_Apps_API::class, 'identity_memo' ) )->setValue( agend_apps_api(), array() );
	}

	protected function tearDown(): void {
		Agend_Apps_Endpoint_Scopes::set_map( null );
		Agend_Apps_Records_Record_Context::reset();
		parent::tearDown();
	}

	// -----------------------------------------------------------------
	// Scene setting
	// -----------------------------------------------------------------

	private function gateway(): array {
		static $decoded = null;

		if ( null === $decoded ) {
			$decoded = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/member-summary-gateway.json' ), true );
		}

		return $decoded;
	}

	/**
	 * The editor's settings: the schema's defaults in the shape an adapter hands
	 * over, with changes laid over them.
	 *
	 * @param array $overrides Settings to change.
	 * @return array
	 */
	private function settings( array $overrides = array() ): array {
		$schema   = agend_apps_records_surface_schema( 'member-summary' );
		$defaults = array_map( static fn( array $a ) => $a['default'], agend_apps_records_block_attributes( $schema ) );

		return $overrides + agend_apps_records_settings_from_attributes( $schema, $defaults );
	}

	private function config( array $overrides = array() ): array {
		return agend_apps_records_member_summary_build_config( $this->settings( $overrides ) );
	}

	private function signIn( int $id = 42 ): void {
		$GLOBALS['agend_test_current_user_id'] = $id;
	}

	private function withBearer( string $token = 'member-token' ): void {
		Agend_Test_WP::$filters['agend_apps_bearer_token'] = static fn(): string => $token;
	}

	private function know( array $scopes ): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => $scopes, 'app_ids' => array() ) ) );
	}

	private function ready(): array {
		return array( 'state' => 'ready', 'reason' => '', 'mode' => 'credentials', 'signed_in' => true, 'linked' => true, 'action' => 'none', 'retry_after' => 0, 'sign_in_url' => 'https://example.test/wp-login.php', 'initiate_url' => '', 'detail' => '' );
	}

	/**
	 * An access state other than ready.
	 *
	 * @param string $state  State.
	 * @param string $action Action.
	 * @param string $reason Reason.
	 * @param array  $more   Further keys.
	 * @return array
	 */
	private function access( string $state, string $action, string $reason = '', array $more = array() ): array {
		return array( 'state' => $state, 'action' => $action, 'reason' => $reason ) + $more + $this->ready();
	}

	/**
	 * The summary of the pinned member, every part reduced as the service reduces
	 * it, with parts replaced.
	 *
	 * @param array $replace Part name to replacement part.
	 * @return array
	 */
	private function summary( array $replace = array() ): array {
		$g       = $this->gateway();
		$profile = $g['profile']['data'];

		$sections = array(
			'membership'  => agend_apps_member_summary_membership( $g['entitlements'], $g['memberships'], $g['tiers'], 1794960000 ),
			'cpd'         => agend_apps_member_summary_cpd( $profile['lms'] ),
			'events'      => agend_apps_member_summary_events( $profile['events'], 3 ),
			'credentials' => agend_apps_member_summary_credentials( $profile['lms'], 3 ),
			'directory'   => agend_apps_member_summary_directory( $profile['directory'] ),
		);

		return array(
			'access'   => $this->ready(),
			'contact'  => array( 'first_name' => 'Priya' ),
			'sections' => array_replace( $sections, $replace ),
			'reads'    => array(),
		);
	}

	private function body( array $config, array $summary ): string {
		return agend_apps_records_member_summary_render_body( $config, $summary );
	}

	private function setPages(): void {
		foreach ( array( 5, 6 ) as $id ) {
			$GLOBALS['agend_test_posts'][ $id ] = array( 'ID' => $id, 'post_type' => 'page', 'post_status' => 'publish' );
		}

		// Through update_option(): the request's object cache holds what an
		// earlier read found, as in WordPress.
		update_option( AGEND_APPS_RECORDS_EVENTS_PAGE_OPTION, 5 );
		update_option( AGEND_APPS_RECORDS_DIRECTORY_PAGE_OPTION, 6 );
		update_option( 'permalink_structure', '/%postname%/' );
	}

	// -----------------------------------------------------------------
	// Settings
	// -----------------------------------------------------------------

	#[Test]
	public function should_show_every_part_with_three_items_a_list_by_default(): void {
		$config = $this->config();

		self::assertSame( array( 'membership' => true, 'cpd' => true, 'events' => true, 'credentials' => true, 'directory' => true ), $config['sections'] );
		self::assertTrue( $config['greeting'] );
		self::assertSame( array( 'events' => 3, 'certificates' => 3 ), $config['limits'] );
		self::assertFalse( $config['hideWhenSignedOut'] );
		self::assertSame( 'My membership', $config['heading']['title'] );
		self::assertSame( 10, $config['cardRadius'] );
	}

	#[Test]
	public function should_switch_each_part_off_on_its_own(): void {
		foreach ( array( 'membership', 'cpd', 'events', 'credentials', 'directory' ) as $part ) {
			$config = $this->config( array( 'show_' . $part => '' ) );

			foreach ( $config['sections'] as $name => $on ) {
				self::assertSame( $name !== $part, $on, $part . ' off: ' . $name );
			}
		}
	}

	#[Test]
	public function should_hold_the_item_limits_to_their_range(): void {
		self::assertSame( 6, $this->config( array( 'max_events' => 99 ) )['limits']['events'] );
		self::assertSame( 1, $this->config( array( 'max_events' => 0 ) )['limits']['events'] );
		self::assertSame( 1, $this->config( array( 'max_certificates' => -4 ) )['limits']['certificates'] );
		self::assertSame( AGEND_APPS_RECORDS_MEMBER_SUMMARY_MAX_ITEMS, $this->config( array( 'max_certificates' => 1000 ) )['limits']['certificates'] );
	}

	#[Test]
	public function should_read_a_link_from_the_shape_either_editor_stores_it_in(): void {
		$config = $this->config(
			array(
				'join_url'       => array( 'url' => 'https://site.test/join/', 'is_external' => '' ),
				'support_url'    => 'https://site.test/contact/',
				'my_listing_url' => '',
			)
		);

		self::assertSame( 'https://site.test/join/', $config['links']['join'] );
		self::assertSame( 'https://site.test/contact/', $config['links']['support'] );
		self::assertSame( '', $config['links']['my_listing'] );
	}

	#[Test]
	public function should_hand_the_browser_only_the_settings_that_decide_what_the_cards_say(): void {
		$settings = $this->settings(
			array(
				'join_url'      => array( 'url' => 'https://site.test/join/' ),
				'heading_text'  => 'Secret heading',
				'__globals__'   => array( 'accent_colour' => 'globals/colors?id=ab12' ),
				'_element_id'   => 'abc',
				'accent_colour' => '#123456',
				'card_radius'   => 20,
			)
		);

		$client = agend_apps_records_member_summary_client_settings( $settings );

		self::assertSame( 'https://site.test/join/', $client['join_url'] );
		self::assertSame( array(), array_diff( array_keys( $client ), agend_apps_records_member_summary_content_keys() ) );
		self::assertArrayNotHasKey( 'heading_text', $client );
		self::assertArrayNotHasKey( '__globals__', $client );
		self::assertArrayNotHasKey( 'accent_colour', $client );
	}

	#[Test]
	public function should_never_pass_an_array_through_as_a_setting_value(): void {
		$client = agend_apps_records_member_summary_client_settings( array( 'support_url' => array( 'x' => array( 'y' ) ), 'max_events' => array( 1, 2 ), 'show_cpd' => new \stdClass() ) );

		self::assertSame( '', $client['support_url'] );
		self::assertSame( '', $client['max_events'] );
		self::assertSame( '', $client['show_cpd'] );
	}

	#[Test]
	public function should_build_the_same_content_from_what_the_browser_sends_back(): void {
		$settings = $this->settings( array( 'show_events' => '', 'max_certificates' => 5, 'join_url' => array( 'url' => 'https://site.test/join/' ) ) );

		$page    = agend_apps_records_member_summary_content_config( $settings );
		$browser = agend_apps_records_member_summary_content_config( json_decode( (string) wp_json_encode( agend_apps_records_member_summary_client_settings( $settings ) ), true ) );

		self::assertSame( $page, $browser, 'a round trip through the page changes nothing' );
	}

	#[Test]
	public function should_not_trust_what_the_browser_sends_back(): void {
		$config = agend_apps_records_member_summary_content_config( array( 'max_events' => '9999', 'show_cpd' => 'maybe', 'join_url' => 'https://site.test/join/', 'unknown' => 'x' ) );

		self::assertSame( 6, $config['limits']['events'] );
		self::assertFalse( $config['sections']['cpd'], 'anything but a yes is a no' );
		self::assertArrayNotHasKey( 'unknown', $config );
	}

	#[Test]
	public function should_word_every_setting_for_an_association_editor(): void {
		$words = array();

		foreach ( agend_apps_records_surface_schema( 'member-summary' )['sections'] as $section ) {
			$words[] = (string) $section['label'];

			foreach ( $section['fields'] as $field ) {
				$words[] = (string) ( $field['label'] ?? '' );
				$words[] = (string) ( $field['description'] ?? '' );
				$words[] = (string) ( $field['content'] ?? '' );
				$words[] = is_string( $field['default'] ?? null ) ? $field['default'] : '';

				if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
					$words = array_merge( $words, array_map( 'strval', array_values( $field['options'] ) ) );
				}
			}
		}

		$text = implode( "\n", $words );

		self::assertDoesNotMatchRegularExpression( '/\b(requests?|cach(e|ed|ing)|api|slugs?|ids?|endpoints?|gateway|scopes?|bearer|tokens?|rest|json|script)\b/i', $text, 'no developer-speak in what an editor reads' );
		self::assertStringNotContainsString( '—', $text );
		self::assertStringContainsString( 'My membership', $text, 'the default heading' );
	}

	#[Test]
	public function should_derive_the_block_attributes_from_the_schema(): void {
		$attributes = agend_apps_records_block_attributes( agend_apps_records_surface_schema( 'member-summary' ) );

		self::assertSame( array( 'type' => 'boolean', 'default' => true ), $attributes['show_membership'] );
		self::assertSame( array( 'type' => 'boolean', 'default' => false ), $attributes['hide_when_signed_out'] );
		self::assertSame( array( 'type' => 'number', 'default' => 3 ), $attributes['max_events'] );
		self::assertSame( array( 'type' => 'string', 'default' => '' ), $attributes['join_url'] );
		self::assertArrayNotHasKey( 'note_parts', $attributes, 'a note stores nothing' );
	}

	// -----------------------------------------------------------------
	// The page: signed out
	// -----------------------------------------------------------------

	#[Test]
	public function should_give_a_signed_out_visitor_a_sign_in_card_with_no_call_and_no_script_config(): void {
		$html = agend_apps_records_render_member_summary( $this->settings() );

		self::assertStringContainsString( 'agend-member-summary--signed-out', $html );
		self::assertStringContainsString( 'Sign in to see your membership', $html );
		self::assertStringContainsString( 'Your membership, CPD points, events and certificates appear here once you sign in.', $html );
		self::assertStringContainsString( 'wp-login.php?redirect_to=', $html, 'the existing sign-in link' );
		self::assertStringContainsString( '>Sign in</a>', $html );
		self::assertStringNotContainsString( 'data-agend-member-summary-config', $html );
		self::assertCount( 0, Agend_Test_WP::$requests, 'a visitor never costs a gateway call' );
	}

	#[Test]
	public function should_bring_a_signed_out_visitor_back_to_the_page_they_were_on(): void {
		$_SERVER['REQUEST_URI'] = '/my-membership/?tab=cpd';

		$html = agend_apps_records_render_member_summary( $this->settings() );

		self::assertStringContainsString( rawurlencode( rawurlencode( 'https://example.test/my-membership/?tab=cpd' ) ), $html, 'the sign-in link carries the page' );
		unset( $_SERVER['REQUEST_URI'] );
	}

	#[Test]
	public function should_use_the_editors_words_for_the_sign_in_card(): void {
		$html = agend_apps_records_render_member_summary( $this->settings( array( 'signed_out_message' => 'Log in to see your details.', 'sign_in_label' => 'Log in' ) ) );

		self::assertStringContainsString( 'Log in to see your details.', $html );
		self::assertStringContainsString( '>Log in</a>', $html );
	}

	#[Test]
	public function should_draw_nothing_for_a_signed_out_visitor_when_the_editor_says_so(): void {
		self::assertSame( '', agend_apps_records_render_member_summary( $this->settings( array( 'hide_when_signed_out' => 'yes' ) ) ) );
	}

	#[Test]
	public function should_show_the_heading_unless_it_is_switched_off(): void {
		self::assertStringContainsString( '<h2 class="agend-member-summary__title">My membership</h2>', agend_apps_records_render_member_summary( $this->settings() ) );
		self::assertStringNotContainsString( '<h2', agend_apps_records_render_member_summary( $this->settings( array( 'show_heading' => '' ) ) ) );
		self::assertStringContainsString( 'Your membership</h2>', agend_apps_records_render_member_summary( $this->settings( array( 'heading_text' => 'Your membership' ) ) ) );
	}

	// -----------------------------------------------------------------
	// The page: signed in
	// -----------------------------------------------------------------

	#[Test]
	public function should_give_a_signed_in_member_a_shell_with_nothing_about_them_in_it(): void {
		$this->signIn();
		$this->withBearer();

		$html = agend_apps_records_render_member_summary( $this->settings() );

		self::assertStringContainsString( 'data-agend-member-summary-config=', $html );
		self::assertStringContainsString( 'aria-busy="true"', $html );
		self::assertStringContainsString( 'Loading your membership', $html );
		self::assertStringNotContainsString( 'Priya', $html );
		self::assertStringNotContainsString( 'Professional', $html );
		self::assertCount( 0, Agend_Test_WP::$requests, 'the page makes no gateway call; the script does' );
	}

	#[Test]
	public function should_draw_the_same_shell_for_every_member_so_a_page_cache_may_keep_it(): void {
		$this->signIn( 42 );
		$one = agend_apps_records_render_member_summary( $this->settings() );

		$this->signIn( 77 );
		$two = agend_apps_records_render_member_summary( $this->settings() );

		self::assertSame( $one, $two );
	}

	#[Test]
	public function should_put_the_route_and_the_content_settings_in_the_shell_and_nothing_else(): void {
		$this->signIn();

		$html = agend_apps_records_render_member_summary( $this->settings( array( 'join_url' => array( 'url' => 'https://site.test/join/' ) ) ) );

		preg_match( '/data-agend-member-summary-config="([^"]*)"/', $html, $match );
		$client = json_decode( html_entity_decode( $match[1], ENT_QUOTES ), true );

		self::assertSame( array( 'route', 'settings', 'messages' ), array_keys( $client ) );
		self::assertSame( 'https://site.test/join/', $client['settings']['join_url'] );
		self::assertSame( array(), array_diff( array_keys( $client['settings'] ), agend_apps_records_member_summary_content_keys() ) );
		self::assertStringContainsString( 'agend-apps/v1/member/summary', $client['route'] );
	}

	#[Test]
	public function should_say_in_a_noscript_note_that_it_needs_script(): void {
		$this->signIn();

		self::assertStringContainsString( '<noscript>', agend_apps_records_render_member_summary( $this->settings() ) );
	}

	#[Test]
	public function should_set_the_colours_and_corner_as_variables_from_the_palette(): void {
		$this->signIn();

		$html = agend_apps_records_render_member_summary( $this->settings( array( 'card_radius' => 16 ) ) );

		self::assertStringContainsString( '--agend-ms-heading:#1E2A4A;', $html );
		self::assertStringContainsString( '--agend-ms-accent:#FF6B55;', $html );
		self::assertStringContainsString( '--agend-ms-card-radius:16px;', $html );
	}

	#[Test]
	public function should_render_nothing_inside_a_card_template(): void {
		$this->signIn();
		Agend_Apps_Records_Record_Context::push( 'course', array( 'slug' => 'a-course' ) );

		self::assertSame( '', agend_apps_records_render_member_summary( $this->settings() ) );
	}

	#[Test]
	public function should_draw_nothing_for_a_visitor_and_stay_off_the_gateway_when_the_key_lacks_the_permission(): void {
		$this->know( array( 'crm.tiers.browse' ) );
		$this->signIn();

		self::assertSame( '', agend_apps_records_render_member_summary( $this->settings() ) );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_show_an_editor_a_disabled_box_naming_the_permission(): void {
		$this->know( array( 'crm.tiers.browse' ) );

		$html = agend_apps_records_render_member_summary( $this->settings(), array( 'preview' => true ) );

		self::assertStringContainsString( 'agend-surface-gate', $html );
		self::assertStringContainsString( 'crm.contacts.browse', $html );
	}

	// -----------------------------------------------------------------
	// The editor's example
	// -----------------------------------------------------------------

	#[Test]
	public function should_show_an_editor_a_labelled_example_and_call_nothing(): void {
		$this->signIn();

		$html = agend_apps_records_render_member_summary( $this->settings(), array( 'preview' => true ) );

		self::assertStringContainsString( 'Example for editing only. Members see their own details here.', $html );
		self::assertStringContainsString( 'agend-member-summary--example', $html );
		self::assertStringContainsString( 'Hello, Alex.', $html );
		self::assertStringNotContainsString( 'data-agend-member-summary-config', $html, 'no script runs on an example' );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_follow_the_editors_choices_in_the_example(): void {
		$html = agend_apps_records_render_member_summary( $this->settings( array( 'show_events' => '', 'show_directory' => '', 'show_greeting' => '' ) ), array( 'preview' => true ) );

		self::assertStringContainsString( 'CPD points', $html );
		self::assertStringContainsString( 'Certificates and badges', $html );
		self::assertStringNotContainsString( 'Upcoming events', $html );
		self::assertStringNotContainsString( 'Directory listing', $html );
		self::assertStringNotContainsString( 'Hello,', $html );
	}

	#[Test]
	public function should_never_draw_the_example_on_the_front_end(): void {
		foreach ( array( 0, 42 ) as $user ) {
			$this->signIn( $user );
			$html = agend_apps_records_render_member_summary( $this->settings() );

			self::assertStringNotContainsString( 'Example', $html, 'user ' . $user );
			self::assertStringNotContainsString( 'Alex', $html );
			self::assertStringNotContainsString( 'Example Studio', $html );
		}
	}

	#[Test]
	public function should_build_the_example_from_dates_that_never_go_stale(): void {
		$now     = 1794960000;
		$example = agend_apps_records_member_summary_example( $now );

		self::assertSame( gmdate( 'Y-m-d\T00:00:00\Z', $now + 112 * 86400 ), $example['sections']['membership']['date'] );
		self::assertGreaterThan( $now, strtotime( $example['sections']['events']['items'][0]['date'] ) );
	}

	// -----------------------------------------------------------------
	// The cards of a full member
	// -----------------------------------------------------------------

	#[Test]
	public function should_greet_the_member_by_first_name_unless_it_is_off_or_unknown(): void {
		self::assertStringContainsString( '<p class="agend-ms-greeting">Hello, Priya.</p>', $this->body( $this->config(), $this->summary() ) );
		self::assertStringNotContainsString( 'Hello,', $this->body( $this->config( array( 'show_greeting' => '' ) ), $this->summary() ) );

		$nameless            = $this->summary();
		$nameless['contact'] = array( 'first_name' => '' );

		self::assertStringNotContainsString( 'Hello,', $this->body( $this->config(), $nameless ) );
	}

	#[Test]
	public function should_lead_with_the_membership_band(): void {
		$html = $this->body( $this->config(), $this->summary() );

		self::assertStringContainsString( 'agend-ms-band agend-ms-band--active', $html );
		self::assertStringContainsString( '<p class="agend-ms-band__status">Active</p>', $html );
		self::assertStringContainsString( '<h3 class="agend-ms-band__tier">Professional</h3>', $html );
		self::assertStringContainsString( 'Renews on', $html );
		self::assertStringContainsString( '<time datetime="2027-03-01">1 March 2027</time>', $html );
		self::assertStringContainsString( 'in 103 days', $html );
		self::assertLessThan( strpos( $html, 'agend-ms-grid' ), strpos( $html, 'agend-ms-band' ), 'the band is above the cards' );
	}

	#[Test]
	public function should_link_the_band_to_the_editors_membership_page(): void {
		$html = $this->body( $this->config( array( 'membership_url' => array( 'url' => 'https://site.test/renew/' ) ) ), $this->summary() );

		self::assertStringContainsString( 'href="https://site.test/renew/">Manage membership</a>', $html );
	}

	#[Test]
	public function should_draw_no_membership_button_when_there_is_nowhere_to_send_the_member(): void {
		self::assertStringNotContainsString( 'Manage membership', $this->body( $this->config(), $this->summary() ) );
	}

	#[Test]
	public function should_say_so_when_the_membership_comes_through_an_organisation_seat(): void {
		$membership             = $this->summary()['sections']['membership'];
		$membership['via_seat'] = true;

		self::assertMatchesRegularExpression( '/Included through your organisation(&#039;|\')s membership\./', $this->body( $this->config(), $this->summary( array( 'membership' => $membership ) ) ) );
	}

	#[Test]
	public function should_name_a_membership_with_no_known_tier_membership_never_member(): void {
		$membership         = $this->summary()['sections']['membership'];
		$membership['tier'] = '';

		$html = $this->body( $this->config(), $this->summary( array( 'membership' => $membership ) ) );

		self::assertStringContainsString( '<h3 class="agend-ms-band__tier">Membership</h3>', $html );
		self::assertStringNotContainsString( '>Member<', $html );
	}

	#[Test]
	public function should_show_a_pending_and_an_ended_membership_as_what_they_are(): void {
		$base    = $this->summary()['sections']['membership'];
		$pending = array( 'label' => 'pending', 'kind' => 'expires' ) + $base;
		$ended   = array( 'label' => 'ended', 'kind' => 'ended', 'days' => -9 ) + $base;

		$pending_html = $this->body( $this->config( array( 'membership_url' => 'https://site.test/m/' ) ), $this->summary( array( 'membership' => $pending ) ) );
		$ended_html   = $this->body( $this->config( array( 'membership_url' => 'https://site.test/m/' ) ), $this->summary( array( 'membership' => $ended ) ) );

		self::assertStringContainsString( 'agend-ms-band--pending', $pending_html );
		self::assertStringContainsString( '>Pending</p>', $pending_html );
		self::assertStringContainsString( 'View membership', $pending_html );
		self::assertStringContainsString( '>Ended</p>', $ended_html );
		self::assertStringContainsString( 'Ended on', $ended_html );
		self::assertStringContainsString( '9 days ago', $ended_html );
		self::assertStringContainsString( 'Renew membership', $ended_html );
	}

	#[Test]
	public function should_phrase_the_days_to_go_plainly(): void {
		self::assertSame( 'today', agend_apps_records_member_summary_days_phrase( 0 ) );
		self::assertSame( 'tomorrow', agend_apps_records_member_summary_days_phrase( 1 ) );
		self::assertSame( 'in 2 days', agend_apps_records_member_summary_days_phrase( 2 ) );
		self::assertSame( '1 day ago', agend_apps_records_member_summary_days_phrase( -1 ) );
		self::assertSame( '30 days ago', agend_apps_records_member_summary_days_phrase( -30 ) );
	}

	#[Test]
	public function should_show_cpd_against_the_target_with_the_pace_and_the_time_left(): void {
		$html = $this->body( $this->config(), $this->summary() );

		self::assertStringContainsString( '<span class="agend-ms-figure__value">14.5</span>', $html );
		self::assertStringContainsString( 'of 16 points', $html );
		self::assertStringContainsString( '--agend-ms-fill:90.6%', $html );
		self::assertStringContainsString( 'agend-ms-meta__item--pace">On track', $html );
		self::assertStringContainsString( '224 days left', $html );
		self::assertStringContainsString( 'Your target is adjusted: Joined part way through the year.', $html );
	}

	#[Test]
	public function should_hide_the_bar_from_assistive_technology_because_the_figure_says_it(): void {
		self::assertStringContainsString( '<div class="agend-ms-bar" aria-hidden="true">', $this->body( $this->config(), $this->summary() ) );
	}

	#[Test]
	public function should_cap_the_bar_at_full_for_a_member_over_their_target(): void {
		$cpd           = $this->summary()['sections']['cpd'];
		$cpd['earned'] = 30.0;
		$cpd['pace']   = 'met';

		$html = $this->body( $this->config(), $this->summary( array( 'cpd' => $cpd ) ) );

		self::assertStringContainsString( '--agend-ms-fill:100.0%', $html );
		self::assertStringContainsString( 'Target met', $html );
	}

	#[Test]
	public function should_show_the_pace_with_words_for_each_verdict(): void {
		foreach ( array( 'met' => 'Target met', 'on_track' => 'On track', 'behind' => 'Behind pace' ) as $pace => $words ) {
			$cpd = array( 'pace' => $pace ) + $this->summary()['sections']['cpd'];

			self::assertStringContainsString( $words, $this->body( $this->config(), $this->summary( array( 'cpd' => $cpd ) ) ), $pace );
		}
	}

	#[Test]
	public function should_show_only_points_earned_when_there_is_no_target_and_say_why(): void {
		$not_set = array( 'status' => 'ready', 'form' => 'total', 'earned' => 14.5, 'why' => 'not_set' );
		$n_a     = array( 'why' => 'not_applicable' ) + $not_set;

		$html = $this->body( $this->config(), $this->summary( array( 'cpd' => $not_set ) ) );

		self::assertStringContainsString( '>14.5</span>', $html );
		self::assertStringContainsString( 'points earned', $html );
		self::assertStringContainsString( 'No CPD target has been set for you.', $html );
		self::assertStringNotContainsString( 'agend-ms-bar', $html, 'no bar without a target' );
		self::assertStringContainsString( 'No CPD target applies to you.', $this->body( $this->config(), $this->summary( array( 'cpd' => $n_a ) ) ) );
	}

	#[Test]
	public function should_say_nothing_is_recorded_for_a_member_with_no_points_and_no_target(): void {
		$html = $this->body( $this->config(), $this->summary( array( 'cpd' => array( 'status' => 'empty', 'form' => 'total', 'earned' => 0.0, 'why' => 'not_set' ) ) ) );

		self::assertStringContainsString( 'No CPD points are recorded for you yet.', $html );
	}

	#[Test]
	public function should_write_whole_points_without_a_decimal_point(): void {
		self::assertSame( '16', agend_apps_records_member_summary_points( 16.0 ) );
		self::assertSame( '14.5', agend_apps_records_member_summary_points( 14.5 ) );
		self::assertSame( '0', agend_apps_records_member_summary_points( 0.0 ) );
	}

	#[Test]
	public function should_list_upcoming_events_with_their_dates_and_status(): void {
		$html = $this->body( $this->config(), $this->summary() );

		self::assertStringContainsString( 'Upcoming events', $html );
		self::assertLessThan( strpos( $html, 'Summer mixer' ), strpos( $html, 'Pricing your work, a webinar' ), 'soonest first' );
		self::assertStringContainsString( '<span class="agend-ms-date__day">3</span><span class="agend-ms-date__month">Dec</span>', $html );
		self::assertStringContainsString( '<span class="agend-ms-sr">3 December 2026, </span>Thursday, 1:00 am', $html );
		self::assertStringContainsString( 'Registered', $html );
		self::assertStringContainsString( 'Confirmed', $html );
		self::assertStringNotContainsString( 'Regional forum', $html, 'the fourth is beyond the limit of three' );
		self::assertStringContainsString( 'and 1 more.', $html );
	}

	#[Test]
	public function should_link_an_event_to_its_page_on_the_site_when_there_is_one(): void {
		self::assertStringNotContainsString( 'agend-ms-row--link', $this->body( $this->config(), $this->summary( array( 'credentials' => array( 'status' => 'empty' ), 'directory' => array( 'status' => 'hidden' ) ) ) ), 'no Events page, no link to nowhere' );

		$this->setPages();
		$html = $this->body( $this->config(), $this->summary() );

		self::assertStringContainsString( 'href="https://example.test/page-5/event/pricing-your-work/"', $html );
		self::assertStringContainsString( 'agend-ms-row--link', $html );
	}

	#[Test]
	public function should_use_the_editors_words_when_there_are_no_events(): void {
		$empty = array( 'status' => 'empty', 'items' => array(), 'total' => 0 );

		self::assertStringContainsString( 'You are not registered for any upcoming events.', $this->body( $this->config(), $this->summary( array( 'events' => $empty ) ) ) );
		self::assertStringContainsString( 'Nothing booked.', $this->body( $this->config( array( 'empty_events_message' => 'Nothing booked.' ) ), $this->summary( array( 'events' => $empty ) ) ) );
	}

	#[Test]
	public function should_list_certificates_newest_first_with_issue_date_and_points(): void {
		$html = $this->body( $this->config(), $this->summary() );

		self::assertStringContainsString( 'Certificates and badges', $html );
		self::assertLessThan( strpos( $html, 'Accessible design foundations' ), strpos( $html, 'Briefing a client well' ) );
		self::assertStringContainsString( 'Issued 30 Sep 2026', $html );
		self::assertStringContainsString( '4 CPD points', $html );
		self::assertStringContainsString( '6.5 CPD points', $html );
	}

	#[Test]
	public function should_link_a_certificate_to_the_credential_check_page_with_its_code_when_the_editor_sets_one(): void {
		$without = $this->body( $this->config(), $this->summary() );
		$with    = $this->body( $this->config( array( 'credential_check_url' => array( 'url' => 'https://site.test/check/' ) ) ), $this->summary() );

		self::assertStringNotContainsString( 'agend_code', $without );
		self::assertStringContainsString( 'href="https://site.test/check/?agend_code=VER-3fa91c0b7d2e4a65b8c1"', $with );
		self::assertStringContainsString( 'href="https://site.test/check/?agend_code=4D2A9C7E"', $with );
	}

	#[Test]
	public function should_show_badges_as_chips_with_their_own_colour_as_the_mark(): void {
		$html = $this->body( $this->config(), $this->summary() );

		self::assertStringContainsString( 'aria-label="Badges"', $html );
		self::assertStringContainsString( '<li class="agend-ms-badge"><span class="agend-ms-badge__dot" style="--agend-ms-badge:#8A4FD6" aria-hidden="true"></span>Client briefing</li>', $html );
	}

	#[Test]
	public function should_use_the_editors_words_when_there_are_no_certificates_or_badges(): void {
		$empty = array( 'status' => 'empty' );

		self::assertStringContainsString( 'Certificates and badges you earn will appear here.', $this->body( $this->config(), $this->summary( array( 'credentials' => $empty ) ) ) );
		self::assertStringContainsString( 'Soon.', $this->body( $this->config( array( 'empty_credentials_message' => 'Soon.' ) ), $this->summary( array( 'credentials' => $empty ) ) ) );
	}

	#[Test]
	public function should_show_the_directory_listing_with_its_logo_and_a_link_to_its_page(): void {
		$this->setPages();

		$html = $this->body( $this->config(), $this->summary() );

		self::assertStringContainsString( 'Directory listing', $html );
		self::assertStringContainsString( 'Raman Studio', $html );
		self::assertStringContainsString( 'Verified listing', $html );
		self::assertStringContainsString( 'src="https://cdn.example.test/logos/raman-studio.png"', $html );
		self::assertStringContainsString( 'referrerpolicy="no-referrer"', $html );
		self::assertStringContainsString( 'href="https://example.test/page-6/listing/raman-studio/"', $html );
	}

	#[Test]
	public function should_hide_the_directory_tile_when_the_profile_carries_no_listing(): void {
		$html = $this->body( $this->config(), $this->summary( array( 'directory' => array( 'status' => 'hidden' ) ) ) );

		self::assertStringNotContainsString( 'Directory listing', $html );
		self::assertStringNotContainsString( 'not listed', $html, 'null is a failed read as often as it is no listing' );
	}

	#[Test]
	public function should_offer_the_manage_listing_link_in_place_of_the_hidden_tile_when_the_editor_set_one(): void {
		$config = $this->config( array( 'my_listing_url' => array( 'url' => 'https://site.test/my-listing/' ) ) );
		$html   = $this->body( $config, $this->summary( array( 'directory' => array( 'status' => 'hidden' ) ) ) );

		self::assertStringContainsString( 'Directory listing', $html );
		self::assertStringContainsString( 'Add or update your listing in the directory.', $html );
		self::assertStringContainsString( 'href="https://site.test/my-listing/"', $html );
		self::assertStringNotContainsString( 'not listed', $html );
	}

	#[Test]
	public function should_leave_out_a_part_the_editor_switched_off_even_if_it_was_read(): void {
		$config  = $this->config( array( 'show_cpd' => '' ) );
		$summary = $this->summary();
		unset( $summary['sections']['cpd'] );

		self::assertStringNotContainsString( 'agend-ms-card--cpd', $this->body( $config, $summary ) );
	}

	// -----------------------------------------------------------------
	// A member with no membership, and parts that failed
	// -----------------------------------------------------------------

	#[Test]
	public function should_say_there_is_no_membership_with_a_way_to_join_and_to_get_in_touch(): void {
		$config = $this->config( array( 'join_url' => 'https://site.test/join/', 'support_url' => 'https://site.test/contact/' ) );
		$html   = $this->body( $config, $this->summary( array( 'membership' => array( 'status' => 'empty' ) ) ) );

		self::assertStringContainsString( 'There is no membership on this account.', $html );
		self::assertStringContainsString( 'href="https://site.test/join/">Join</a>', $html );
		self::assertStringContainsString( 'Contact us</a>', $html );
		self::assertStringNotContainsString( 'agend-ms-band', $html );
	}

	#[Test]
	public function should_draw_no_dead_buttons_for_a_member_with_no_membership_when_no_links_are_set(): void {
		$html = $this->body( $this->config(), $this->summary( array( 'membership' => array( 'status' => 'empty' ) ) ) );

		self::assertStringContainsString( 'There is no membership on this account.', $html );
		self::assertStringNotContainsString( '>Join</a>', $html );
		self::assertStringNotContainsString( 'Contact us', $html );
	}

	#[Test]
	public function should_say_a_part_could_not_load_and_offer_one_retry_for_all_of_them(): void {
		$html = $this->body(
			$this->config(),
			$this->summary( array( 'cpd' => array( 'status' => 'unavailable' ), 'events' => array( 'status' => 'unavailable' ) ) )
		);

		self::assertSame( 2, substr_count( $html, 'We could not load this just now.' ) );
		self::assertSame( 1, substr_count( $html, 'data-agend-ms-retry' ), 'one button serves every tile' );
		self::assertStringContainsString( 'agend-ms-band', $html, 'the parts that did load are still there' );
		self::assertStringNotContainsString( 'No CPD points', $html, 'a failure is never drawn as nothing' );
		self::assertStringNotContainsString( 'not registered for any', $html );
	}

	#[Test]
	public function should_draw_no_retry_button_when_nothing_failed(): void {
		self::assertStringNotContainsString( 'data-agend-ms-retry', $this->body( $this->config(), $this->summary() ) );
	}

	#[Test]
	public function should_leave_out_a_part_the_key_does_not_allow_and_tell_only_an_administrator(): void {
		$summary = $this->summary( array( 'membership' => array( 'status' => 'no_access' ), 'credentials' => array( 'status' => 'no_access' ) ) );

		$admin = $this->body( $this->config(), $summary );

		self::assertStringNotContainsString( 'agend-ms-band', $admin );
		self::assertStringContainsString( 'Only administrators see this note.', $admin );
		self::assertStringContainsString( 'Membership, Certificates and badges', $admin );

		$GLOBALS['agend_test_current_user_can']['manage_options'] = false;
		$member = $this->body( $this->config(), $summary );

		self::assertStringNotContainsString( 'Only administrators', $member );
		self::assertStringNotContainsString( 'permissions', $member );
		self::assertStringContainsString( 'CPD points', $member, 'the other parts are untouched' );
	}

	#[Test]
	public function should_say_so_when_every_part_is_switched_off_rather_than_draw_a_blank(): void {
		$summary             = $this->summary();
		$summary['sections'] = array();

		self::assertStringContainsString( 'There is nothing to show here yet.', $this->body( $this->config(), $summary ) );
	}

	// -----------------------------------------------------------------
	// Escaping
	// -----------------------------------------------------------------

	#[Test]
	public function should_escape_everything_the_gateway_sends(): void {
		$g                                                     = $this->gateway();
		$g['profile']['data']['events']['upcoming'][0]['event_title'] = '"><img src=x onerror=alert(1)>Gala';
		$g['profile']['data']['lms']['certificates'][0]['course_title'] = '<script>alert(2)</script>Cert';
		$g['profile']['data']['lms']['badges'][0]['badge_name']   = '<b onmouseover=alert(3)>Badge</b>';
		$g['profile']['data']['directory']['listing_name']        = '"><svg onload=alert(4)>';
		$profile                                                  = $g['profile']['data'];

		$summary = $this->summary(
			array(
				'events'      => agend_apps_member_summary_events( $profile['events'], 3 ),
				'credentials' => agend_apps_member_summary_credentials( $profile['lms'], 3 ),
				'directory'   => agend_apps_member_summary_directory( $profile['directory'] ),
			)
		);
		$summary['contact']['first_name'] = '"><script>alert(5)</script>';

		$html = $this->body( $this->config(), $summary );

		self::assertStringNotContainsString( '<script', $html );
		self::assertStringNotContainsString( '<img src=x', $html );
		self::assertStringNotContainsString( '<svg onload', $html );
		self::assertStringNotContainsString( 'onerror=', $html );
		self::assertStringNotContainsString( 'onmouseover=', $html );
		self::assertStringNotContainsString( 'onload=', $html );
	}

	#[Test]
	public function should_use_no_em_dash_or_emoji_in_anything_it_draws(): void {
		$html = $this->body( $this->config(), $this->summary() ) . agend_apps_records_member_summary_render_example( $this->config() ) . agend_apps_records_render_member_summary( $this->settings() );

		self::assertStringNotContainsString( '—', $html );
		self::assertDoesNotMatchRegularExpression( '/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}]/u', $html );
	}

	// -----------------------------------------------------------------
	// States
	// -----------------------------------------------------------------

	private function notice( array $access, array $overrides = array(), array $opts = array() ): string {
		return agend_apps_records_member_summary_render_notice( $this->config( $overrides ), $access, $opts );
	}

	#[Test]
	public function should_tell_a_connecting_member_it_is_connecting_with_no_button_until_it_gives_up(): void {
		$html = $this->notice( $this->access( 'connecting', 'retry', 'link_asserted', array( 'retry_after' => 3 ) ) );

		self::assertStringContainsString( 'Connecting your membership', $html );
		self::assertStringContainsString( 'data-agend-ms-state="connecting"', $html );
		self::assertStringNotContainsString( 'data-agend-ms-retry', $html );
	}

	#[Test]
	public function should_offer_a_retry_once_the_wait_has_run_out(): void {
		$html = $this->notice( $this->access( 'connecting', 'retry', 'link_asserted' ), array(), array( 'settled' => true ) );

		self::assertStringContainsString( 'Still connecting', $html );
		self::assertStringContainsString( 'Try again in a few minutes.', $html );
		self::assertStringContainsString( 'data-agend-ms-retry', $html );
	}

	#[Test]
	public function should_send_a_member_with_no_session_to_sign_out_and_in_again(): void {
		$html = $this->notice( $this->access( 'not_linked', 'sign_in_again', 'session_missing', array( 'sign_in_url' => 'https://site.test/wp-login.php?redirect_to=x' ) ) );

		self::assertStringContainsString( 'Sign in again', $html );
		self::assertStringContainsString( 'Sign out and sign in again</a>', $html );
		self::assertStringContainsString( 'action=logout', $html );
	}

	#[Test]
	public function should_offer_an_sso_member_the_connect_button(): void {
		$html = $this->notice( $this->access( 'not_linked', 'connect', 'sso_unlinked', array( 'initiate_url' => 'https://api.example.test/api/auth/sso/wdaa/initiate?relayState=x' ) ) );

		self::assertStringContainsString( 'Connect your membership', $html );
		self::assertStringContainsString( 'href="https://api.example.test/api/auth/sso/wdaa/initiate?relayState=x">Connect my account</a>', $html );
	}

	#[Test]
	public function should_tell_a_member_to_check_their_email_with_nothing_to_click(): void {
		$html = $this->notice( $this->access( 'not_linked', 'check_email', 'link_pending' ) );

		self::assertStringContainsString( 'Check your email', $html );
		self::assertStringNotContainsString( 'agend-ms-button', $html );
	}

	#[Test]
	public function should_send_a_member_to_the_contact_page_for_a_fault_only_an_administrator_can_clear(): void {
		foreach ( array( array( 'not_linked', 'link_conflict' ), array( 'not_configured', 'link_forbidden' ), array( 'not_configured', 'link_pending_approval' ) ) as $case ) {
			$with    = $this->notice( $this->access( $case[0], 'contact', $case[1] ), array( 'support_url' => 'https://site.test/contact/' ) );
			$without = $this->notice( $this->access( $case[0], 'contact', $case[1] ) );

			self::assertStringContainsString( 'We cannot load your membership right now', $with, $case[1] );
			self::assertStringContainsString( 'Please contact us and we will sort it out.', $with );
			self::assertStringContainsString( 'href="https://site.test/contact/">Contact us</a>', $with );
			self::assertStringNotContainsString( 'agend-ms-button', $without, 'no contact page, no dead button' );
		}
	}

	#[Test]
	public function should_say_no_membership_was_found_with_join_and_contact_and_the_editors_words(): void {
		$access = $this->access( 'no_member_record', 'join', 'contact_not_found' );
		$html   = $this->notice( $access, array( 'join_url' => 'https://site.test/join/', 'support_url' => 'https://site.test/contact/' ) );

		self::assertStringContainsString( 'We could not find a membership for this account', $html );
		self::assertStringContainsString( 'If you are a member, contact us and we will match your account. If not, you can join.', $html );
		self::assertStringContainsString( 'href="https://site.test/join/">Join</a>', $html );
		self::assertStringContainsString( 'Contact us</a>', $html );
		self::assertStringContainsString( 'We will find it.', $this->notice( $access, array( 'no_record_message' => 'We will find it.' ) ) );
	}

	#[Test]
	public function should_say_unavailable_with_a_retry_and_the_editors_words(): void {
		$access = $this->access( 'unavailable', 'retry', 'gateway_error' );

		$html = $this->notice( $access );

		self::assertStringContainsString( 'We cannot load your membership right now', $html );
		self::assertStringContainsString( 'Please try again in a moment.', $html );
		self::assertStringContainsString( 'data-agend-ms-retry', $html );
		self::assertStringContainsString( 'Back soon.', $this->notice( $access, array( 'unavailable_message' => 'Back soon.' ) ) );
		self::assertStringContainsString( 'Back soon.', $this->notice( $this->access( 'not_configured', 'retry', 'missing_scope' ), array( 'unavailable_message' => 'Back soon.' ) ) );
	}

	#[Test]
	public function should_tell_a_member_how_long_to_wait_when_busy(): void {
		$html = $this->notice( $this->access( 'unavailable', 'retry', 'busy', array( 'retry_after' => 30 ) ), array( 'unavailable_message' => 'Back soon.' ) );

		self::assertStringContainsString( 'Please try again in 30 seconds.', $html );
		self::assertStringContainsString( 'We are busy right now', $html );
		self::assertStringNotContainsString( 'Back soon.', $html );
		self::assertStringContainsString( 'in 1 second.', $this->notice( $this->access( 'unavailable', 'retry', 'busy', array( 'retry_after' => 1 ) ) ) );
	}

	/**
	 * Every access state a member can be in.
	 *
	 * @return array<string, array{array}>
	 */
	public static function everyState(): array {
		$cases = array();

		foreach ( array(
			array( 'connecting', 'retry', 'link_asserted' ),
			array( 'not_linked', 'sign_in_again', 'session_missing' ),
			array( 'not_linked', 'sign_in_again', 'session_expired' ),
			array( 'not_linked', 'connect', 'sso_unlinked' ),
			array( 'not_linked', 'check_email', 'link_pending' ),
			array( 'not_linked', 'contact', 'link_conflict' ),
			array( 'not_configured', 'retry', 'missing_scope' ),
			array( 'not_configured', 'contact', 'link_forbidden' ),
			array( 'no_member_record', 'join', 'no_external_id' ),
			array( 'no_member_record', 'join', 'link_no_contact' ),
			array( 'unavailable', 'retry', 'token_unavailable' ),
			array( 'unavailable', 'retry', 'busy' ),
			array( 'unavailable', 'retry', 'gateway_error' ),
		) as $row ) {
			$cases[ $row[0] . ' / ' . $row[2] ] = array( $row );
		}

		return $cases;
	}

	#[Test]
	#[DataProvider( 'everyState' )]
	public function should_never_show_a_member_why_it_failed(array $row): void {
		$GLOBALS['agend_test_current_user_can']['manage_options'] = false;
		$access = $this->access( $row[0], $row[1], $row[2], array( 'detail' => 'The key lacks sso.tokens.create.', 'retry_after' => 5 ) );

		$html = $this->notice( $access, array( 'join_url' => 'https://site.test/join/', 'support_url' => 'https://site.test/contact/' ) );

		self::assertStringNotContainsString( 'sso.tokens.create', $html );
		self::assertStringNotContainsString( 'Only administrators', $html );
		self::assertStringNotContainsString( 'data-agend-ms-reason', $html );
		if ( str_contains( $row[2], '_' ) ) {
			self::assertStringNotContainsString( $row[2], $html, 'the machine reason never reaches the page' );
		}
		self::assertDoesNotMatchRegularExpression( '/\b(scopes?|permissions?|sso|saml|external id|bearer|token|gateway|api|session_|wordpress|credentials)\b/i', wp_strip_all_tags( $html ) );
	}

	#[Test]
	#[DataProvider( 'everyState' )]
	public function should_give_an_administrator_the_reason_in_a_note_that_says_who_sees_it(array $row): void {
		$access = $this->access( $row[0], $row[1], $row[2], array( 'detail' => 'The key lacks sso.tokens.create.' ) );

		$html = $this->notice( $access );

		self::assertStringContainsString( '<p class="agend-ms-admin"><strong>Only administrators see this note.</strong> The key lacks sso.tokens.create.</p>', $html );
	}

	// -----------------------------------------------------------------
	// The route
	// -----------------------------------------------------------------

	private function post( array $body ): array {
		$request = new WP_REST_Request( 'POST', '/agend-apps/v1/member/summary' );
		$request->set_body( (string) wp_json_encode( $body ) );

		$response = ( new Agend_Apps_Member_Summary_REST_Controller() )->summary( $request );

		return array(
			'status'  => $response->get_status(),
			'data'    => $response->get_data(),
			'headers' => $response->get_headers(),
		);
	}

	private function pageSettings( array $overrides = array() ): array {
		return agend_apps_records_member_summary_client_settings( $this->settings( $overrides ) );
	}

	#[Test]
	public function should_register_one_post_route_that_is_open_to_every_visitor(): void {
		( new Agend_Apps_Member_Summary_REST_Controller() )->register_routes();

		$route = Agend_Test_WP::$rest_routes[ count( Agend_Test_WP::$rest_routes ) - 1 ];

		self::assertSame( 'agend-apps/v1', $route['namespace'] );
		self::assertSame( '/member/summary', $route['route'] );
		self::assertSame( 'POST', $route['args'][0]['methods'] );
		self::assertSame( '__return_true', $route['args'][0]['permission_callback'], 'a visitor it does not recognise is answered with the sign-in card' );
	}

	#[Test]
	public function should_answer_every_visit_with_200_never_cached_and_varying_by_cookie(): void {
		$answers = array(
			'signed out' => array(),
			'signed in'  => array( 'user' => 42 ),
		);

		foreach ( $answers as $name => $scene ) {
			$GLOBALS['agend_test_current_user_id'] = $scene['user'] ?? 0;
			$answer                                = $this->post( array( 'settings' => $this->pageSettings() ) );

			self::assertSame( 200, $answer['status'], $name );
			self::assertSame( 'no-store, no-cache, must-revalidate, max-age=0', $answer['headers']['Cache-Control'], $name );
			self::assertSame( 'no-cache', $answer['headers']['Pragma'] );
			self::assertSame( 'Cookie', $answer['headers']['Vary'] );
			self::assertTrue( $answer['data']['success'] );
		}
	}

	#[Test]
	public function should_answer_a_visitor_who_is_not_signed_in_with_the_sign_in_card_and_no_call(): void {
		$answer = $this->post( array( 'settings' => $this->pageSettings( array( 'sign_in_label' => 'Log in' ) ), 'return_url' => 'https://example.test/my-membership/' ) );

		self::assertSame( 'signed_out', $answer['data']['state'] );
		self::assertStringContainsString( 'Sign in to see your membership', $answer['data']['html'] );
		self::assertStringContainsString( '>Log in</a>', $answer['data']['html'] );
		self::assertStringContainsString( 'my-membership', $answer['data']['html'], 'it comes back to the page' );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_answer_nothing_to_a_signed_out_visitor_when_the_editor_hides_the_widget(): void {
		$answer = $this->post( array( 'settings' => $this->pageSettings( array( 'hide_when_signed_out' => 'yes' ) ) ) );

		self::assertSame( '', $answer['data']['html'] );
	}

	#[Test]
	public function should_only_come_back_to_an_address_on_this_site(): void {
		$answer = $this->post( array( 'settings' => $this->pageSettings(), 'return_url' => 'https://evil.example/phish/' ) );

		self::assertStringNotContainsString( 'evil.example', $answer['data']['html'] );
	}

	#[Test]
	public function should_send_the_cards_of_a_signed_in_member_in_one_pass(): void {
		$this->signIn();
		$this->withBearer();
		foreach ( array( 'profile', 'entitlements', 'memberships', 'tiers' ) as $name ) {
			Agend_Test_WP::queue_response( 200, $this->gateway()[ $name ] );
		}

		$answer = $this->post( array( 'settings' => $this->pageSettings( array( 'credential_check_url' => 'https://site.test/check/' ) ) ) );

		self::assertSame( 'ready', $answer['data']['state'] );
		self::assertStringContainsString( 'Hello, Priya.', $answer['data']['html'] );
		self::assertStringContainsString( 'Professional', $answer['data']['html'] );
		self::assertStringContainsString( 'agend_code=VER-3fa91c0b7d2e4a65b8c1', $answer['data']['html'] );
		self::assertCount( 4, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_make_only_the_calls_for_the_parts_the_editor_switched_on(): void {
		$this->signIn();
		$this->withBearer();
		Agend_Test_WP::queue_response( 200, $this->gateway()['profile'] );

		$answer = $this->post( array( 'settings' => $this->pageSettings( array( 'show_membership' => '' ) ) ) );

		self::assertCount( 1, Agend_Test_WP::$requests, 'membership off: one call, not three' );
		self::assertStringNotContainsString( 'agend-ms-band', $answer['data']['html'] );
		self::assertStringContainsString( 'CPD points', $answer['data']['html'] );
	}

	#[Test]
	public function should_list_as_many_events_as_the_page_asked_for_and_no_more_than_the_limit_allows(): void {
		$this->signIn();
		$this->withBearer();
		Agend_Test_WP::queue_response( 200, $this->gateway()['profile'] );

		$two = $this->post( array( 'settings' => $this->pageSettings( array( 'show_membership' => '', 'max_events' => 2 ) ) ) );

		self::assertStringContainsString( 'and 2 more.', $two['data']['html'] );

		agend_apps_member_access_forget();
		( new \ReflectionProperty( Agend_Apps_API::class, 'identity_memo' ) )->setValue( agend_apps_api(), array() );
		Agend_Test_WP::queue_response( 200, $this->gateway()['profile'] );

		$settings               = $this->pageSettings( array( 'show_membership' => '' ) );
		$settings['max_events'] = 4000;
		$all                    = $this->post( array( 'settings' => $settings ) );

		self::assertStringNotContainsString( 'more.', $all['data']['html'], 'the fixture holds four, under the limit of six' );
	}

	#[Test]
	public function should_answer_a_member_who_is_not_connected_with_why_and_no_gateway_call(): void {
		$this->signIn();
		$this->withBearer( '' );

		$answer = $this->post( array( 'settings' => $this->pageSettings() ) );

		self::assertSame( 'not_linked', $answer['data']['state'] );
		self::assertStringContainsString( 'Sign in again', $answer['data']['html'] );
		self::assertCount( 0, Agend_Test_WP::$requests, 'no bearer, no call, and so no count against the member' );
		self::assertSame( array(), preg_grep( '/ms_fetches/', array_keys( Agend_Test_WP::$transients ) ) );
	}

	#[Test]
	public function should_answer_no_member_found_when_agend_has_no_contact(): void {
		$this->signIn();
		$this->withBearer();
		Agend_Test_WP::queue_response( 404, array( 'error' => array( 'code' => 'CONTACT_NOT_FOUND', 'message' => 'x' ) ) );
		Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => array( 'is_member' => false ) ) );
		Agend_Test_WP::queue_response( 404, array( 'error' => array( 'code' => 'CONTACT_NOT_FOUND', 'message' => 'x' ) ) );
		Agend_Test_WP::queue_response( 200, $this->gateway()['tiers'] );

		$answer = $this->post( array( 'settings' => $this->pageSettings( array( 'join_url' => 'https://site.test/join/' ) ) ) );

		self::assertSame( 'no_member_record', $answer['data']['state'] );
		self::assertStringContainsString( 'We could not find a membership for this account', $answer['data']['html'] );
		self::assertStringContainsString( 'href="https://site.test/join/">Join</a>', $answer['data']['html'] );
	}

	#[Test]
	public function should_hold_a_member_who_asks_too_often_and_say_how_long_to_wait(): void {
		$this->signIn();
		$this->withBearer();

		for ( $i = 0; $i < 6; $i++ ) {
			agend_apps_member_summary_guard( 42 );
		}

		$answer = $this->post( array( 'settings' => $this->pageSettings() ) );

		self::assertSame( 'unavailable', $answer['data']['state'] );
		self::assertGreaterThan( 0, $answer['data']['retry_after'] );
		self::assertStringContainsString( 'We are busy right now', $answer['data']['html'] );
		self::assertStringContainsString( 'data-agend-ms-retry', $answer['data']['html'] );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_hold_everyone_when_the_site_keys_minute_is_nearly_spent(): void {
		$this->signIn();
		$this->withBearer();
		set_transient( 'agend_apps_rate_limit_remaining', 4, 60 );

		$answer = $this->post( array( 'settings' => $this->pageSettings() ) );

		self::assertSame( 'unavailable', $answer['data']['state'] );
		self::assertStringContainsString( 'We are busy right now', $answer['data']['html'] );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_tell_the_waiting_page_when_a_connection_has_been_waited_on_long_enough(): void {
		$this->signIn();
		update_option( 'agend_apps_member_auth_mode', 'wordpress' );
		update_user_meta( 42, AGEND_APPS_EXTERNAL_ID_META, 'guid-1' );
		$this->know( array( 'sso.identities.read', 'sso.tokens.create', 'crm.contacts.browse' ) );

		$waiting = $this->post( array( 'settings' => $this->pageSettings() ) );
		$settled = $this->post( array( 'settings' => $this->pageSettings(), 'settled' => true ) );

		self::assertSame( 'connecting', $waiting['data']['state'] );
		self::assertSame( 3, $waiting['data']['retry_after'] );
		self::assertStringContainsString( 'Connecting your membership', $waiting['data']['html'] );
		self::assertStringContainsString( 'Still connecting', $settled['data']['html'] );
		self::assertCount( 0, Agend_Test_WP::$requests, 'connecting is answered from the recorded state, with no call' );
	}

	#[Test]
	public function should_cope_with_a_body_that_is_not_what_it_expects(): void {
		$request = new WP_REST_Request( 'POST', '/agend-apps/v1/member/summary' );
		$request->set_body( 'not json' );

		$response = ( new Agend_Apps_Member_Summary_REST_Controller() )->summary( $request );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'signed_out', $response->get_data()['state'] );
	}

	#[Test]
	public function should_store_nothing_about_a_member_when_answering(): void {
		$this->signIn();
		$this->withBearer();
		foreach ( array( 'profile', 'entitlements', 'memberships', 'tiers' ) as $name ) {
			Agend_Test_WP::queue_response( 200, $this->gateway()[ $name ] );
		}

		$this->post( array( 'settings' => $this->pageSettings() ) );

		$stored = (string) wp_json_encode( Agend_Test_WP::$transients );

		self::assertStringNotContainsString( 'Priya', $stored );
		self::assertStringNotContainsString( 'VER-', $stored );
		self::assertStringNotContainsString( 'member-token', $stored );
	}

	// -----------------------------------------------------------------
	// Links into the portal
	// -----------------------------------------------------------------

	#[Test]
	public function should_build_the_members_screens_under_the_accounts_portal_home(): void {
		self::assertSame(
			array(
				'membership'   => 'https://portal.example.test/home/wdaa/membership',
				'cpd'          => 'https://portal.example.test/home/wdaa/cpd',
				'events'       => 'https://portal.example.test/home/wdaa/events',
				'certificates' => 'https://portal.example.test/home/wdaa/certificates',
				'badges'       => 'https://portal.example.test/home/wdaa/cpd/badges',
			),
			agend_apps_records_member_summary_portal_screens( 'https://portal.example.test/home/wdaa/' )
		);
	}

	#[Test]
	public function should_build_no_portal_link_when_the_portal_home_is_not_known(): void {
		self::assertSame( array( 'membership' => '', 'cpd' => '', 'events' => '', 'certificates' => '', 'badges' => '' ), agend_apps_records_member_summary_portal_screens( '' ) );
	}

	#[Test]
	public function should_let_the_editors_membership_page_win_over_the_portal(): void {
		$links = agend_apps_records_member_summary_links( $this->config( array( 'membership_url' => 'https://site.test/renew/' ) ) );

		self::assertSame( 'https://site.test/renew/', $links['membership'] );
	}

	#[Test]
	public function should_let_a_site_change_where_the_cards_link(): void {
		Agend_Test_WP::set_filter( 'agend_apps_member_summary_links', array( 'membership' => 'https://custom.example/m', 'join' => '' ) + agend_apps_records_member_summary_links( $this->config() ) );

		self::assertSame( 'https://custom.example/m', agend_apps_records_member_summary_links( $this->config() )['membership'] );
	}

	// -----------------------------------------------------------------
	// Notices, block and assets
	// -----------------------------------------------------------------

	#[Test]
	public function should_tell_a_block_editor_how_members_see_the_widget_and_what_a_key_leaves_out(): void {
		$notices = agend_apps_records_member_summary_block_notices( array(), 'member-summary' );

		self::assertCount( 1, $notices );
		self::assertSame( 'info', $notices[0]['status'] );

		$this->know( array( 'crm.contacts.browse' ) );
		$notices = agend_apps_records_member_summary_block_notices( array(), 'member-summary' );

		self::assertCount( 2, $notices );
		self::assertSame( 'warning', $notices[1]['status'] );
		self::assertStringContainsString( 'Membership', $notices[1]['text'] );
		self::assertSame( array( array( 'status' => 'x', 'text' => 'y' ) ), agend_apps_records_member_summary_block_notices( array( array( 'status' => 'x', 'text' => 'y' ) ), 'filter' ), 'only its own surface' );
	}

	#[Test]
	public function should_hold_the_surface_to_the_profile_permission_alone(): void {
		self::assertSame( array( 'crm.contacts.browse' ), agend_apps_records_surface_requirements( 'member-summary' )['scopes'], 'one permission, so a key missing one of the others still shows the widget' );

		$this->know( array( 'crm.tiers.browse' ) );
		self::assertSame( array( 'crm.contacts.browse' ), agend_apps_records_surface_missing( 'member-summary' )['scopes'] );

		$this->know( array( 'crm.contacts.browse' ) );
		self::assertTrue( agend_apps_records_surface_available( 'member-summary' ) );
	}

	#[Test]
	public function should_map_the_route_to_the_surface_so_a_gated_surface_refuses_it(): void {
		self::assertSame( 'member-summary', agend_apps_records_rest_route_surface( '/agend-apps/v1/member/summary' ) );
		self::assertSame( 'member-summary', agend_apps_records_rest_route_surface( '/agend-apps/v1/member/summary/' ) );
		self::assertSame( '', agend_apps_records_rest_route_surface( '/agend-apps/v1/member/summary/extra' ) );
		self::assertSame( '', agend_apps_records_rest_route_surface( '/agend-apps/v1/member/summaries' ) );

		$this->know( array( 'crm.tiers.browse' ) );
		$result = agend_apps_records_surface_gate_rest( null, null, new WP_REST_Request( 'POST', '/agend-apps/v1/member/summary' ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'agend_apps_feature_unavailable', $result->get_error_code() );
		self::assertSame( 'member-summary', $result->get_error_data()['surface'] );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_list_the_surface_as_a_block_in_the_inserter(): void {
		self::assertContains( 'member-summary', AGEND_APPS_RECORDS_BLOCK_SURFACES );
	}

	#[Test]
	public function should_render_the_block_the_same_as_the_core_renderer_at_its_defaults(): void {
		$schema   = agend_apps_records_surface_schema( 'member-summary' );
		$defaults = array_map( static fn( array $a ) => $a['default'], agend_apps_records_block_attributes( $schema ) );

		self::assertSame( agend_apps_records_render_member_summary( agend_apps_records_settings_from_attributes( $schema, $defaults ) ), agend_apps_records_render_block( 'member-summary', $defaults ) );
	}

	#[Test]
	public function should_ship_a_built_block_that_matches_its_source_and_loads_its_script_and_style(): void {
		$src   = json_decode( (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/src/blocks/member-summary/block.json' ), true );
		$build = json_decode( (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/build/blocks/member-summary/block.json' ), true );

		self::assertSame( $src, $build );
		self::assertSame( 'agend-apps/member-summary', $build['name'] );
		self::assertSame( 'Agend Member Summary', $build['title'] );
		self::assertSame( array( 'agend-apps-records-member-summary' ), $build['style'] );
		self::assertSame( array( 'agend-apps-records-member-summary' ), $build['viewScript'] );
		self::assertFileExists( AGEND_TESTS_ROOT . '/agend-apps-core/build/blocks/member-summary/index.js' );
		self::assertFileExists( AGEND_TESTS_ROOT . '/agend-apps-core/assets/css/member-summary.css' );
		self::assertFileExists( AGEND_TESTS_ROOT . '/agend-apps-core/assets/js/member-summary.js' );
	}

	#[Test]
	public function should_read_nothing_from_the_signal_that_is_false_for_wordpress_and_sso_members(): void {
		$script = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/assets/js/member-summary.js' );

		self::assertSame( 0, preg_match( '/\.loggedIn\b/', preg_replace( '#/\*.*?\*/#s', '', $script ) ), 'window.agendApps.loggedIn is never read' );
	}

	#[Test]
	public function should_use_no_shadow_in_the_stylesheet_because_the_theme_removes_them(): void {
		$css = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/assets/css/member-summary.css' );

		self::assertSame( 0, preg_match( '/box-shadow\s*:\s*(?!none)/', preg_replace( '#/\*.*?\*/#s', '', $css ) ) );
		self::assertSame( 0, preg_match( '/—/u', $css ) );
	}
}

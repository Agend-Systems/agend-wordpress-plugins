<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Apps_Log_Redactor;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-redactor.php';

#[CoversClass( Agend_Apps_Log_Redactor::class )]
final class ApiLogRedactorTest extends TestCase {

	private function redactor(): Agend_Apps_Log_Redactor {
		return new Agend_Apps_Log_Redactor( 'test-salt' );
	}

	#[Test]
	public function should_redact_personal_keys_at_every_depth_when_records_are_nested_in_lists(): void {
		$out = $this->redactor()->redact_value(
			array(
				'data' => array(
					array(
						'first_name' => 'Jane',
						'contact'    => array(
							'emailAddress' => 'jane@example.com',
							'addresses'    => array(
								array(
									'address_line_1' => '1 Main St',
									'suburb'         => 'Adelaide',
									'postcode'       => '5000',
									'state'          => 'SA',
								),
							),
						),
					),
				),
			)
		);

		$record = $out['data'][0];
		$this->assertStringStartsWith( '[redacted:name#', $record['first_name'] );
		$this->assertStringStartsWith( '[redacted:email#', $record['contact']['emailAddress'] );
		$this->assertStringStartsWith( '[redacted:address#', $record['contact']['addresses'][0]['address_line_1'] );
		$this->assertStringStartsWith( '[redacted:address#', $record['contact']['addresses'][0]['postcode'] );
		$this->assertStringStartsWith( '[redacted:address#', $record['contact']['addresses'][0]['state'], 'a state inside an address subtree is part of the address' );
	}

	#[Test]
	public function should_keep_catalogue_names_and_titles_when_they_describe_things_not_people(): void {
		$out = $this->redactor()->redact_value(
			array(
				'title'     => 'Annual Gala',
				'tier_name' => 'Gold',
				'country'   => 'AU',
				'state'     => 'SA',
				'status'    => 'active',
				'id'        => '7d2b1f0e-1111-4222-8333-944455556666',
			)
		);

		$this->assertSame( 'Annual Gala', $out['title'] );
		$this->assertSame( 'Gold', $out['tier_name'] );
		$this->assertSame( 'AU', $out['country'] );
		$this->assertSame( 'SA', $out['state'] );
		$this->assertSame( 'active', $out['status'] );
		$this->assertSame( '7d2b1f0e-1111-4222-8333-944455556666', $out['id'] );
	}

	#[Test]
	public function should_catch_an_email_when_it_sits_under_a_key_no_rule_names(): void {
		$out = $this->redactor()->redact_value( array( 'summary_line' => 'Contact jane.doe+events@example.com.au for help' ) );

		$this->assertStringNotContainsString( 'jane.doe', $out['summary_line'] );
		$this->assertStringContainsString( '[redacted:email#', $out['summary_line'] );
		$this->assertStringStartsWith( 'Contact ', $out['summary_line'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function phoneNumbers(): array {
		return array(
			'mobile spaced'      => array( 'Call 0412 345 678 today' ),
			'mobile packed'      => array( 'Call 0412345678 today' ),
			'landline brackets'  => array( 'Call (08) 8123 4567 today' ),
			'international +61'  => array( 'Call +61 412 345 678 today' ),
			'international other' => array( 'Call +44 20 7946 0958 today' ),
			'1300 number'        => array( 'Call 1300 123 456 today' ),
		);
	}

	#[Test]
	#[DataProvider( 'phoneNumbers' )]
	public function should_redact_a_phone_number_when_it_appears_in_free_text( string $text ): void {
		$out = $this->redactor()->redact_text( $text );

		$this->assertMatchesRegularExpression( '/^Call \[redacted:phone#[0-9a-f]{6}\] today$/', $out );
	}

	#[Test]
	public function should_not_mistake_dates_uuids_or_prices_for_phone_numbers(): void {
		$text = 'On 2026-10-02T09:15:00Z order 7d2b1f0e-1111-4222-8333-944455556666 cost 12500 cents';

		$this->assertSame( $text, $this->redactor()->redact_text( $text ) );
	}

	#[Test]
	public function should_redact_tokens_and_api_keys_when_they_appear_in_any_string(): void {
		$jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';
		$out = $this->redactor()->redact_text( "token {$jwt} and Bearer abc.def-ghi and ag_live_ABCDEF123456" );

		$this->assertStringNotContainsString( 'eyJ', $out );
		$this->assertStringNotContainsString( 'abc.def-ghi', $out );
		$this->assertStringNotContainsString( 'ag_live_ABCDEF123456', $out );
	}

	#[Test]
	public function should_redact_a_card_number_only_when_it_passes_the_luhn_check(): void {
		$out = $this->redactor()->redact_text( 'card 4111 1111 1111 1111 ref 1234567890123' );

		$this->assertStringContainsString( '[redacted:card]', $out );
		$this->assertStringNotContainsString( '4111', $out );
		$this->assertStringContainsString( '1234567890123', $out, 'a 13-digit reference failing Luhn is kept' );
	}

	#[Test]
	public function should_redact_every_custom_field_value_when_the_admin_defined_the_field(): void {
		$out = $this->redactor()->redact_value(
			array(
				'custom_fields' => array(
					'date_joined_industry' => '1999-02-14',
					'emergency'            => array( 'who' => 'Sam', 'tel' => 'x' ),
					'opted_in'             => true,
				),
			)
		);

		$this->assertSame( '[redacted:custom]', $out['custom_fields']['date_joined_industry'] );
		$this->assertSame( '[redacted:custom]', $out['custom_fields']['emergency']['who'] );
		$this->assertTrue( $out['custom_fields']['opted_in'], 'booleans carry no personal data' );
	}

	#[Test]
	public function should_keep_the_field_description_but_not_the_answer_when_custom_fields_are_a_list(): void {
		$out = $this->redactor()->redact_value(
			array(
				'custom_fields' => array(
					array( 'key' => 'dob', 'label' => 'Date of birth', 'type' => 'date', 'value' => '1984-07-19' ),
				),
			)
		);

		$this->assertSame(
			array( 'key' => 'dob', 'label' => 'Date of birth', 'type' => 'date', 'value' => '[redacted:custom]' ),
			$out['custom_fields'][0]
		);
	}

	#[Test]
	public function should_keep_a_gateway_error_message_readable_when_it_is_a_diagnostic(): void {
		$out = $this->redactor()->redact_value(
			array(
				'error'   => array(
					'code'    => 'validation_failed',
					'message' => 'Membership with email jane@example.com already exists',
				),
				'message' => 'Hi Jane, welcome to the channel',
			)
		);

		$this->assertSame( 'validation_failed', $out['error']['code'] );
		$this->assertStringStartsWith( 'Membership with email [redacted:email#', $out['error']['message'] );
		$this->assertSame( '[redacted:text, 31 chars]', $out['message'], 'a user-written message is redacted whole' );
	}

	#[Test]
	public function should_give_the_same_value_the_same_marker_when_correlating_rows(): void {
		$a = $this->redactor()->redact_value( array( 'email' => 'Jane@Example.com' ) );
		$b = $this->redactor()->redact_value( array( 'attendee_email' => 'jane@example.com' ) );
		$c = ( new Agend_Apps_Log_Redactor( 'other-salt' ) )->redact_value( array( 'email' => 'jane@example.com' ) );

		$this->assertSame( $a['email'], $b['attendee_email'] );
		$this->assertNotSame( $a['email'], $c['email'], 'the marker depends on the site salt' );
	}

	#[Test]
	public function should_replace_secrets_without_a_hash(): void {
		$out = $this->redactor()->redact_value( array( 'refresh_token' => 'abc', 'password' => 'hunter2', 'api_key' => 'k' ) );

		$this->assertSame( '[redacted:secret]', $out['refresh_token'] );
		$this->assertSame( '[redacted:secret]', $out['password'] );
		$this->assertSame( '[redacted:secret]', $out['api_key'] );
	}

	#[Test]
	public function should_redact_query_values_by_parameter_name_and_by_scanner(): void {
		$out = $this->redactor()->redact_query( 'email=jane%40example.com&filter%5Bfirst_name%5D=Jane&search=bob%40example.com&page=2' );

		$this->assertStringNotContainsString( 'jane', strtolower( rawurldecode( $out ) ) );
		$this->assertStringNotContainsString( 'bob', strtolower( rawurldecode( $out ) ) );
		$this->assertStringContainsString( 'page=2', $out );
	}

	#[Test]
	public function should_redact_an_email_in_a_path_segment_and_template_the_path(): void {
		$redactor = $this->redactor();
		$path     = $redactor->redact_path( '/v1/crm/contacts/by-email/jane%40example.com/memberships/42' );

		$this->assertStringNotContainsString( 'jane', $path );
		$this->assertSame( '/v1/crm/contacts/by-email/:id/memberships/:id', Agend_Apps_Log_Redactor::path_template( $path ) );
		$this->assertSame(
			'/v1/events/:id/registrations',
			Agend_Apps_Log_Redactor::path_template( '/v1/events/7d2b1f0e-1111-4222-8333-944455556666/registrations' )
		);
	}

	#[Test]
	public function should_summarise_a_non_json_body_instead_of_storing_it(): void {
		$out = $this->redactor()->redact_body( "--boundary\r\nContent-Disposition: form-data; name=\"file\"\r\n\r\nJane Citizen\r\n", 'multipart/form-data; boundary=x' );

		$this->assertMatchesRegularExpression( '/^\[non-JSON body: \d+ bytes, multipart\/form-data\]$/', $out );
	}

	#[Test]
	public function should_keep_only_allowlisted_response_headers(): void {
		$out = $this->redactor()->filter_response_headers(
			array(
				'X-Request-Id' => 'req-1',
				'Set-Cookie'   => 'session=abc',
				'Content-Type' => 'application/json',
			)
		);

		$this->assertSame( array( 'x-request-id' => 'req-1', 'content-type' => 'application/json' ), $out );
	}

	#[Test]
	public function should_not_let_a_site_unredact_a_built_in_personal_key(): void {
		$redactor = new Agend_Apps_Log_Redactor( 'test-salt', array( 'favourite_colour' ), array( 'email', 'internal_code' ) );
		$out      = $redactor->redact_value( array( 'email' => 'jane@example.com', 'favourite_colour' => 'teal', 'internal_code' => 'X1' ) );

		$this->assertStringStartsWith( '[redacted:email#', $out['email'] );
		$this->assertStringStartsWith( '[redacted:pii#', $out['favourite_colour'] );
		$this->assertSame( 'X1', $out['internal_code'] );
	}

	#[Test]
	public function should_recognise_credential_endpoints_when_deciding_whether_to_store_bodies(): void {
		$this->assertTrue( Agend_Apps_Log_Redactor::is_body_excluded( '/v1/sso/tokens' ) );
		$this->assertTrue( Agend_Apps_Log_Redactor::is_body_excluded( '/v1/auth/login?x=1' ) );
		$this->assertTrue( Agend_Apps_Log_Redactor::is_body_excluded( '/v1/auth/mfa/verify/' ) );
		$this->assertFalse( Agend_Apps_Log_Redactor::is_body_excluded( '/v1/auth/logout' ) );
		$this->assertFalse( Agend_Apps_Log_Redactor::is_body_excluded( '/v1/crm/me' ) );
		$this->assertTrue( Agend_Apps_Log_Redactor::is_body_excluded( '/v1/crm/me', array( '/crm/me' ) ) );
	}
}

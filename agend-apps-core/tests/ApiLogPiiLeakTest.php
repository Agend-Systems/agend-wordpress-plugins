<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Apps_Log_Redactor;
use Agend_Apps_Logger;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-redactor.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-log-store.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/logging/class-agend-apps-logger.php';

/**
 * The guarantee the API log makes: no personal data reaches a stored row.
 *
 * Each fixture copies the shape of a real gateway exchange and plants one
 * distinctive canary in every personal field. The whole stored row (every
 * column, not just the bodies) is then searched for every canary. A new
 * personal field the redactor misses fails here.
 */
#[CoversClass( Agend_Apps_Log_Redactor::class )]
#[CoversClass( Agend_Apps_Logger::class )]
final class ApiLogPiiLeakTest extends TestCase {

	/**
	 * Distinctive values, one per personal-data class. Each is searched for,
	 * lower-cased, in the serialised row.
	 */
	private const CANARIES = array(
		'first name'        => 'Zelphine',
		'last name'         => 'Quorrimax',
		'email local part'  => 'zq.canary',
		'mobile digits'     => '0491570156',
		'landline digits'   => '0881234567',
		'date of birth'     => '1984-07-19',
		'street'            => 'Wombatcrest',
		'suburb'            => 'Thistlebrook',
		'postcode'          => '5999',
		'latitude'          => '-34.928661',
		'member number'     => 'MBR998877',
		'external id'       => 'ext-7731-canary',
		'abn'               => '51824753556',
		'custom field'      => 'peanutcanary',
		'free text'         => 'secretdiary',
		'avatar'            => 'zelphine-avatar',
		'card'              => '4111111111111111',
		'password'          => 'Hunter2Canary',
		'refresh token'     => 'rt-canary-0099',
		'jwt'               => 'eyJhbGciOiJIUzI1NiJ9',
		'company'           => 'Quorrimax Physio',
		'job title'         => 'Chief Canary Officer',
		'gender'            => 'nonbinarycanary',
		'listing slug'      => 'zelphine-quorrimax-physio',
	);

	private const EMAIL = 'zq.canary@example.com';

	/**
	 * @return array<string, mixed>
	 */
	private static function person(): array {
		return array(
			'id'                => '7d2b1f0e-1111-4222-8333-944455556666',
			'first_name'        => 'Zelphine',
			'last_name'         => 'Quorrimax',
			'display_name'      => 'Zelphine Quorrimax',
			'email'             => self::EMAIL,
			'phone'             => '(08) 8123 4567',
			'mobile'            => '0491 570 156',
			'date_of_birth'     => '1984-07-19',
			'gender'            => 'nonbinarycanary',
			'job_title'         => 'Chief Canary Officer',
			'company_name'      => 'Quorrimax Physio',
			'abn'               => '51 824 753 556',
			'membership_number' => 'MBR998877',
			'external_id'       => 'ext-7731-canary',
			'avatar_url'        => 'https://cdn.example.com/zelphine-avatar.png',
			'linkedin_url'      => 'https://linkedin.com/in/zelphine-avatar',
			'notes'             => 'secretdiary entry',
			'addresses'         => array(
				array(
					'type'           => 'postal',
					'address_line_1' => '17 Wombatcrest Lane',
					'address_line_2' => 'Unit 4',
					'suburb'         => 'Thistlebrook',
					'city'           => 'Thistlebrook',
					'state'          => 'SA',
					'postcode'       => '5999',
					'latitude'       => -34.928661,
					'longitude'      => 138.599961,
				),
			),
			'custom_fields'     => array(
				'allergies'     => 'peanutcanary',
				'date_of_birth' => '1984-07-19',
			),
			'memberships'       => array(
				array(
					'tier_name' => 'Gold',
					'status'    => 'active',
				),
			),
		);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function exchanges(): array {
		$person = self::person();

		return array(
			'crm /me profile read'           => array(
				array(
					'method'        => 'GET',
					'url'           => 'https://api.agend.com.au/v1/crm/me',
					'status'        => 200,
					'response_body' => json_encode( array( 'data' => $person ) ),
				),
			),
			'crm /me profile update failing' => array(
				array(
					'method'        => 'PATCH',
					'url'           => 'https://api.agend.com.au/v1/crm/me',
					'status'        => 422,
					'request_body'  => json_encode( $person ),
					'response_body' => json_encode(
						array(
							'error' => array(
								'code'    => 'validation_failed',
								'message' => 'Email zq.canary@example.com is already used; call 0491 570 156',
								'issues'  => array( array( 'path' => array( 'first_name' ), 'message' => 'Zelphine is too short' ) ),
							),
						)
					),
					'error_message' => 'Email zq.canary@example.com is already used',
				),
			),
			'crm admin contact search'       => array(
				array(
					'method'        => 'GET',
					'url'           => 'https://api.agend.com.au/v1/crm/contacts?search=Zelphine%20Quorrimax&email=zq.canary%40example.com&page=1',
					'status'        => 200,
					'response_body' => json_encode( array( 'data' => array( $person, $person ) ) ),
				),
			),
			'cart attendees and checkout'    => array(
				array(
					'method'        => 'POST',
					'url'           => 'https://api.agend.com.au/v1/cart/checkout',
					'status'        => 402,
					'request_body'  => json_encode(
						array(
							'items'   => array(
								array(
									'event_id'  => 'e1',
									'attendees' => array(
										array(
											'attendee_name'   => 'Zelphine Quorrimax',
											'attendee_email'  => self::EMAIL,
											'attendee_fields' => array( 'dietary' => 'peanutcanary', 'mobile' => '0491570156' ),
										),
									),
								),
							),
							'payment' => array(
								'card_number' => '4111 1111 1111 1111',
								'cvv'         => '123',
							),
						)
					),
					'response_body' => json_encode( array( 'error' => array( 'message' => 'Card 4111111111111111 declined for Zelphine Quorrimax' ) ) ),
				),
			),
			'event registration'             => array(
				array(
					'method'        => 'GET',
					'url'           => 'https://api.agend.com.au/v1/events/annual-gala',
					'status'        => 200,
					'response_body' => json_encode(
						array(
							'data' => array(
								'title'           => 'Annual Gala',
								'my_registration' => array(
									'beneficiary_name'  => 'Zelphine Quorrimax',
									'beneficiary_email' => self::EMAIL,
									'answers'           => array( array( 'question' => 'Diet', 'value' => 'peanutcanary' ) ),
								),
							),
						)
					),
				),
			),
			'loop user sync'                 => array(
				array(
					'method'       => 'POST',
					'url'          => 'https://api.agend.com.au/v1/loop/integration/users/bulk-sync',
					'status'       => 200,
					'request_body' => json_encode(
						array(
							'users' => array(
								array(
									'external_id' => 'ext-7731-canary',
									'email'       => self::EMAIL,
									'displayName' => 'Zelphine Quorrimax',
									'firstName'   => 'Zelphine',
									'lastName'    => 'Quorrimax',
									'avatar'      => 'https://cdn.example.com/zelphine-avatar.png',
									'roles'       => array( 'member' ),
								),
							),
						)
					),
				),
			),
			'directory listing by slug'      => array(
				array(
					'method'        => 'GET',
					'url'           => 'https://api.agend.com.au/v1/directory/listings/zelphine-quorrimax-physio',
					'status'        => 200,
					'response_body' => json_encode(
						array(
							'data' => array(
								'listing_name'     => 'Zelphine Quorrimax Physio',
								'description'      => 'Call 0491 570 156 or write to zq.canary@example.com',
								'contact_email'    => self::EMAIL,
								'website'          => 'https://zelphine-avatar.example.com',
								'primary_location' => array( 'address' => '17 Wombatcrest Lane', 'lat' => -34.928661, 'lng' => 138.599961 ),
								'locations'        => array( array( 'suburb' => 'Thistlebrook', 'postcode' => '5999' ) ),
							),
						)
					),
				),
			),
			'directory geocode lookup'       => array(
				array(
					'method' => 'GET',
					'url'    => 'https://api.agend.com.au/v1/directory/geocode?q=17%20Wombatcrest%20Lane%20Thistlebrook%205999&near=-34.928661%2C138.599961',
					'status' => 200,
				),
			),
			'registration endpoint'          => array(
				array(
					'method'        => 'POST',
					'url'           => 'https://api.agend.com.au/v1/auth/register',
					'status'        => 201,
					'request_body'  => json_encode( $person + array( 'password' => 'Hunter2Canary' ) ),
					'response_body' => json_encode( array( 'refresh_token' => 'rt-canary-0099', 'access_token' => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ4In0.sig' ) ),
				),
			),
			'token refresh in another path'  => array(
				array(
					'method'        => 'POST',
					'url'           => 'https://api.agend.com.au/v1/crm/me/session',
					'status'        => 200,
					'response_body' => json_encode( array( 'session' => array( 'refresh_token' => 'rt-canary-0099', 'jwt' => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ4In0.sig', 'password' => 'Hunter2Canary' ) ) ),
				),
			),
			'membership webhook delivery'    => array(
				array(
					'direction'     => 'inbound',
					'method'        => 'POST',
					'url'           => 'https://site.example/wp-json/agend-apps/v1/webhooks/incoming',
					'status'        => 200,
					'event_type'    => 'crm.membership.lapsed',
					'request_body'  => json_encode(
						array(
							'type' => 'crm.membership.lapsed',
							'data' => array(
								'contact_id'   => '7d2b1f0e-1111-4222-8333-944455556666',
								'contact_name' => 'Zelphine Quorrimax',
								'email'        => self::EMAIL,
								'metadata'     => array( 'note' => 'secretdiary' ),
							),
						)
					),
					'response_body' => '{"ok":true,"synced":true}',
				),
			),
			'transport failure echoing data' => array(
				array(
					'method'        => 'GET',
					'url'           => 'https://api.agend.com.au/v1/crm/contacts/by-email/zq.canary%40example.com',
					'status'        => null,
					'error_code'    => 'http_request_failed',
					'error_message' => 'Could not resolve host for zq.canary@example.com (0491 570 156)',
				),
			),
		);
	}

	#[Test]
	#[DataProvider( 'exchanges' )]
	public function should_store_no_personal_data_in_any_column_when_logging_a_real_exchange_shape( array $entry ): void {
		$GLOBALS['agend_test_current_user_id'] = 0;
		$_SERVER['REQUEST_URI']                = '/directory/zelphine-quorrimax-physio/?email=zq.canary%40example.com';
		$_SERVER['REMOTE_ADDR']                = '203.0.113.77';

		try {
			$row = ( new Agend_Apps_Logger() )->build_row( $entry );
		} finally {
			unset( $_SERVER['REQUEST_URI'], $_SERVER['REMOTE_ADDR'] );
		}

		$haystack = strtolower( rawurldecode( (string) json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
		$digits   = (string) preg_replace( '/\D/', '', $haystack );

		foreach ( self::CANARIES as $class => $canary ) {
			$needle = strtolower( $canary );

			$this->assertStringNotContainsString( $needle, $haystack, "{$class} leaked into the stored row" );

			if ( 1 === preg_match( '/^\d{8,}$/', $canary ) ) {
				$this->assertStringNotContainsString( $canary, $digits, "{$class} leaked as digits into the stored row" );
			}
		}

		$this->assertStringNotContainsString( '203.0.113.77', $haystack, 'the full IP is never stored' );
	}

	/**
	 * Shapes found by the code review that the first redactor stored in
	 * plain text. Each lists the substrings that must not survive.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string[]}>
	 */
	public static function reviewRegressions(): array {
		return array(
			'flat address keys'           => array(
				array( 'url' => 'https://api.x/v1/crm/contacts', 'status' => 500, 'request_body' => '{"address1":"12 Wombatcrest Rd","line1":"9 Thistle St","street_1":"Quorrimax Lane","billing_address_id":"7d2b1f0e-1111-4222-8333-944455556666"}' ),
				array( 'wombatcrest', 'thistle', 'quorrimax' ),
			),
			'session ids'                 => array(
				array( 'url' => 'https://api.x/v1/cart/items', 'status' => 200, 'response_body' => '{"cart_session_id":"cs_abcdefSECRET123","session_id":"sessSECRET99"}' ),
				array( 'secret123', 'sesssecret99' ),
			),
			'free text keys'              => array(
				array( 'url' => 'https://api.x/v1/loop/messages', 'status' => 500, 'request_body' => '{"content":"Hi Bobjones, call me","body":"Dear Mrs Smithers","description":"Run by Dr Quentinblake","answer":"my diagnosis","cancelled_reason":"divorce from Fredrick"}' ),
				array( 'bobjones', 'smithers', 'quentinblake', 'diagnosis', 'fredrick' ),
			),
			'secret links in values'      => array(
				array( 'url' => 'https://api.x/v1/cart/checkout', 'status' => 200, 'response_body' => '{"checkout_url":"https://checkout.stripe.com/c/pay/cs_live_a1B2c3D4e5F6g7","renewal_url":"https://x.example/renew?token=abcdef123456","note_link":"see https://x.example/a?sig=zzsig998877"}' ),
				array( 'cs_live_a1b2c3d4e5f6g7', 'abcdef123456', 'zzsig998877' ),
			),
			'error echoing a path slug'   => array(
				array( 'url' => 'https://api.x/v1/directory/listings/zelphine-quorrimax-physio', 'status' => 404, 'response_body' => '{"error":{"code":"not_found","message":"Listing zelphine-quorrimax-physio not found"}}', 'error_message' => 'Listing zelphine-quorrimax-physio not found' ),
				array( 'zelphine', 'quorrimax' ),
			),
			'error echoing a search term' => array(
				array( 'url' => 'https://api.x/v1/crm/contacts?search=Zelphine%20Quorrimax', 'status' => 400, 'response_body' => '{"error":{"code":"bad","message":"No contact named Zelphine Quorrimax"}}', 'error_message' => 'No contact named Zelphine Quorrimax' ),
				array( 'zelphine', 'quorrimax' ),
			),
			'url without a path'          => array(
				array( 'url' => 'https://api.x?search=Zelphine', 'status' => 200 ),
				array( 'zelphine' ),
			),
			'token in an invitation path' => array(
				array( 'url' => 'https://api.x/v1/crm/me/team/invitations/7d2b1f0e-1111-4222-8333-944455556666/accept', 'status' => 410, 'error_message' => 'Invitation 7d2b1f0e-1111-4222-8333-944455556666 has expired' ),
				array( '7d2b1f0e-1111-4222-8333-944455556666' ),
			),
			'token in a verify path'      => array(
				array( 'url' => 'https://api.x/v1/lms/certificates/verify/CERT-ABC123XYZ', 'status' => 200 ),
				array( 'cert-abc123xyz' ),
			),
			'other phone formats and ids' => array(
				array( 'url' => 'https://api.x/v1/x', 'status' => 500, 'request_body' => '{"phone_numbers":["0412 345 678"],"contact":"call (555) 123-4567 or 0412.345.678","client_ip":"203.0.113.9","user_login":"zquorrlogin"}' ),
				array( '345 678', '123-4567', '0412.345.678', '203.0.113.9', 'zquorrlogin' ),
			),
			'encoded email in a path'     => array(
				array( 'url' => 'https://api.x/v1/crm/contacts/by-email/jane%2Bnews%40example.com', 'status' => 200 ),
				array( 'jane', 'example.com' ),
			),
		);
	}

	#[Test]
	#[DataProvider( 'reviewRegressions' )]
	public function should_not_store_the_value_when_it_arrives_in_a_shape_the_review_found( array $entry, array $forbidden ): void {
		$row      = ( new Agend_Apps_Logger() )->build_row( $entry + array( 'user_id' => 0 ) );
		$haystack = strtolower( rawurldecode( (string) json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );

		foreach ( $forbidden as $needle ) {
			$this->assertStringNotContainsString( strtolower( $needle ), $haystack );
		}
	}

	#[Test]
	public function should_store_no_body_or_claimed_ids_when_a_delivery_is_unverified(): void {
		$row = ( new Agend_Apps_Logger() )->build_row(
			array(
				'direction'     => 'inbound',
				'url'           => 'https://site.example/wp-json/agend-apps/v1/webhooks/incoming',
				'status'        => 400,
				'outcome'       => 'signature_invalid',
				'store_bodies'  => false,
				'request_body'  => str_repeat( '{"email":"zq.canary@example.com"}', 1000 ),
				'response_body' => '{"code":"invalid_signature"}',
			)
		);

		$this->assertSame( '[not stored: unverified delivery]', $row['request_body'] );
		$this->assertSame( 33000, $row['request_bytes'] );
	}

	#[Test]
	public function should_summarise_a_successful_body_when_it_is_too_large_to_redact_quickly(): void {
		$row = ( new Agend_Apps_Logger() )->build_row(
			array(
				'url'           => 'https://api.x/v1/crm/contacts',
				'status'        => 200,
				'response_body' => (string) json_encode( array( 'data' => array_fill( 0, 3000, self::person() ) ) ),
			)
		);

		$this->assertMatchesRegularExpression( '/^\[body not stored: \d+ bytes, over the size limit\]$/', $row['response_body'] );
	}

	#[Test]
	public function should_redact_a_five_hundred_contact_failure_in_well_under_a_second(): void {
		$start = microtime( true );

		( new Agend_Apps_Logger() )->build_row(
			array(
				'url'           => 'https://api.x/v1/crm/contacts',
				'status'        => 500,
				'response_body' => (string) json_encode( array( 'data' => array_fill( 0, 500, self::person() ) ) ),
			)
		);

		$this->assertLessThan( 1.0, microtime( true ) - $start );
	}

	/**
	 * Every key the documented matrix lists is redacted. Removing one from the
	 * redactor fails here, not in production.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function matrixKeys(): array {
		$keys = array(
			'email', 'attendee_email', 'beneficiary_email', 'reviewer_email', 'application_email', 'emailAddress',
			'phone', 'mobile', 'fax', 'work_phone', 'phone_dial_code',
			'dob', 'date_of_birth', 'birthDate', 'birthday', 'gender', 'pronouns', 'salutation',
			'address', 'addresses', 'address_line_1', 'address_line_2', 'street', 'suburb', 'city', 'town', 'postcode', 'zip', 'postal_code',
			'venue_address', 'venue_city', 'venue_postcode', 'location_city', 'latitude', 'longitude', 'lat', 'lng', 'geo_point', 'location', 'primary_location',
			'name', 'first_name', 'last_name', 'full_name', 'display_name', 'legal_name', 'member_name', 'attendee_name', 'beneficiary_name',
			'reviewer_name', 'instructor_name', 'agent_name', 'company_name', 'primary_company_name', 'listing_name', 'nickname', 'job_title', 'organisation', 'company',
			'address1', 'line1', 'street_1', 'content', 'body', 'description', 'answer', 'cancelled_reason', 'cart_session_id', 'session_id', 'phone_numbers', 'client_ip', 'user_login',
			'abn', 'acn', 'tax_id', 'tfn', 'medicare_number', 'passport_number', 'licence_number', 'license_number',
			'membership_number', 'external_id', 'external_user_id', 'member_external_ids', 'chair_external_id', 'secretary_external_id', 'ip_address',
			'bio', 'note', 'notes', 'comment', 'comments', 'message', 'message_preview', 'snippet', 'cancellation_reason', 'search', 'q',
			'avatar', 'avatar_url', 'photo_url', 'linkedin_url', 'facebook_url', 'instagram_url', 'twitter_url', 'youtube_url', 'website', 'company_website',
			'password', 'current_password', 'new_password', 'access_token', 'refresh_token', 'mfa_token', 'invitation_token', 'preview_token',
			'guestSessionToken', 'bootstrap_token', 'secret', 'api_key', 'otp', 'signature', 'x-signature',
			'card_number', 'cvv', 'cvc', 'bsb', 'account_number', 'bank_account',
			'custom_fields', 'custom_field_values', 'external_metadata', 'metadata', 'answers', 'attendee_fields',
		);

		return array_combine( $keys, array_map( static fn( string $key ): array => array( $key ), $keys ) );
	}

	#[Test]
	#[DataProvider( 'matrixKeys' )]
	public function should_redact_the_value_when_the_key_is_in_the_documented_matrix( string $key ): void {
		$out = ( new Agend_Apps_Log_Redactor( 'salt' ) )->redact_value( array( $key => 'plainvalue' ) );

		$this->assertStringNotContainsString( 'plainvalue', (string) json_encode( $out ), "{$key} was not redacted" );
	}
}

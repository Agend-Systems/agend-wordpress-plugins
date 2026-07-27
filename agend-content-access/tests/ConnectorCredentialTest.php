<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Credentials;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-credentials.php';

/**
 * The credential that gates the source connector.
 *
 * It is the whole authorisation for routes that return the full body of every
 * restricted document on the site, so the properties below are the boundary,
 * not conveniences.
 */
#[CoversClass( Agend_Content_Access_Credentials::class )]
final class ConnectorCredentialTest extends TestCase {

	#[Test]
	public function no_credential_exists_until_one_is_issued(): void {
		$this->assertFalse( Agend_Content_Access_Credentials::exists() );
		$this->assertFalse( Agend_Content_Access_Credentials::verify( 'anything' ) );
	}

	#[Test]
	public function an_issued_token_verifies(): void {
		$token = Agend_Content_Access_Credentials::issue();

		$this->assertTrue( Agend_Content_Access_Credentials::exists() );
		$this->assertTrue( Agend_Content_Access_Credentials::verify( $token ) );
	}

	/** A leaked database must give an attacker nothing to replay. */
	#[Test]
	public function the_plaintext_token_is_never_stored(): void {
		$token  = Agend_Content_Access_Credentials::issue();
		$stored = (string) wp_json_encode(
			Agend_Test_WP::$options[ Agend_Content_Access_Credentials::OPTION ]
		);

		$this->assertStringNotContainsString( $token, $stored );
		$this->assertStringContainsString( 'hash', $stored );
	}

	#[Test]
	public function tokens_are_unpredictable_and_long(): void {
		$a = Agend_Content_Access_Credentials::issue();
		$b = Agend_Content_Access_Credentials::issue();

		$this->assertNotSame( $a, $b );
		// 32 bytes rendered hex.
		$this->assertSame( 64, strlen( $a ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $a );
	}

	#[Test]
	public function a_wrong_token_is_rejected(): void {
		Agend_Content_Access_Credentials::issue();

		$this->assertFalse( Agend_Content_Access_Credentials::verify( str_repeat( 'a', 64 ) ) );
		$this->assertFalse( Agend_Content_Access_Credentials::verify( '' ) );
	}

	/**
	 * Rotation is immediate and total. There is no grace window on purpose: a
	 * leaked connector token reads every restricted document on the site, so
	 * "revoked" has to mean revoked.
	 */
	#[Test]
	public function rotating_invalidates_the_previous_token_at_once(): void {
		$old = Agend_Content_Access_Credentials::issue();
		$new = Agend_Content_Access_Credentials::issue();

		$this->assertFalse( Agend_Content_Access_Credentials::verify( $old ) );
		$this->assertTrue( Agend_Content_Access_Credentials::verify( $new ) );
	}

	#[Test]
	public function revoking_stops_every_token_verifying(): void {
		$token = Agend_Content_Access_Credentials::issue();

		Agend_Content_Access_Credentials::revoke();

		$this->assertFalse( Agend_Content_Access_Credentials::exists() );
		$this->assertFalse( Agend_Content_Access_Credentials::verify( $token ) );
	}

	#[Test]
	public function the_issue_time_is_recorded_for_the_admin_screen(): void {
		Agend_Content_Access_Credentials::issue();

		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T/',
			Agend_Content_Access_Credentials::created_at()
		);
	}

	#[Test]
	public function a_corrupt_stored_record_verifies_nothing(): void {
		Agend_Test_WP::$options[ Agend_Content_Access_Credentials::OPTION ] = array( 'hash' => 123 );

		$this->assertFalse( Agend_Content_Access_Credentials::verify( 'anything' ) );

		Agend_Test_WP::$options[ Agend_Content_Access_Credentials::OPTION ] = 'not-an-array';

		$this->assertFalse( Agend_Content_Access_Credentials::verify( 'anything' ) );
	}

	#[Test]
	public function the_header_is_distinct_from_the_member_bearer_header(): void {
		// Core uses Authorization for the OUTBOUND member bearer. Sharing one
		// header name across two credential types in opposite directions is how
		// the wrong one ends up being accepted.
		$this->assertSame( 'X-Agend-Connector-Token', Agend_Content_Access_Credentials::HEADER );
		$this->assertNotSame( 'Authorization', Agend_Content_Access_Credentials::HEADER );
	}
}

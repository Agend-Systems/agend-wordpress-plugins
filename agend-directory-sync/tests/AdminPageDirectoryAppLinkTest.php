<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

use Agend_Directory_Sync_Admin_Page;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-admin-page.php';

/**
 * The directory app link feature's two pure decision points:
 *
 * - link_matches_environment(): the only validation the link gets, checked
 *   both on save and at render time.
 * - token_grants_directory_app(): the JWT-claim helper behind the button's
 *   access gate. No such claim exists on any real token yet (see the
 *   DIRECTORY_APP_ACCESS_CLAIM docblock), so this only exercises the
 *   decoding and lookup logic against constructed tokens.
 */
#[CoversClass( Agend_Directory_Sync_Admin_Page::class )]
final class AdminPageDirectoryAppLinkTest extends TestCase {

	// -----------------------------------------------------------------
	// link_matches_environment()
	// -----------------------------------------------------------------

	#[Test]
	public function production_link_matches_production_root(): void {
		$this->assertTrue(
			Agend_Directory_Sync_Admin_Page::link_matches_environment(
				'https://api.agend.com.au/sso/acme/directory-home?idp=default',
				'https://api.agend.com.au'
			)
		);
	}

	#[Test]
	public function staging_link_matches_staging_root(): void {
		$this->assertTrue(
			Agend_Directory_Sync_Admin_Page::link_matches_environment(
				'https://api.agend.info/sso/acme/directory-home?idp=default',
				'https://api.agend.info'
			)
		);
	}

	#[Test]
	public function local_link_matches_local_root(): void {
		$this->assertTrue(
			Agend_Directory_Sync_Admin_Page::link_matches_environment(
				'http://localhost:3072/sso/acme/directory-home?idp=default',
				'http://localhost:3072'
			)
		);
	}

	#[Test]
	public function custom_root_matches_itself(): void {
		$this->assertTrue(
			Agend_Directory_Sync_Admin_Page::link_matches_environment(
				'https://api.client-example.com/sso/acme/directory-home?idp=default',
				'https://api.client-example.com'
			)
		);
	}

	#[Test]
	public function mismatched_host_is_rejected(): void {
		$this->assertFalse(
			Agend_Directory_Sync_Admin_Page::link_matches_environment(
				'https://api.agend.info/sso/acme/directory-home?idp=default',
				'https://api.agend.com.au'
			)
		);
	}

	#[Test]
	public function mismatched_scheme_is_rejected(): void {
		$this->assertFalse(
			Agend_Directory_Sync_Admin_Page::link_matches_environment(
				'http://api.agend.com.au/sso/acme/directory-home?idp=default',
				'https://api.agend.com.au'
			)
		);
	}

	#[Test]
	public function localhost_3072_does_not_match_3000(): void {
		$this->assertFalse(
			Agend_Directory_Sync_Admin_Page::link_matches_environment(
				'http://localhost:3000/sso/acme/directory-home?idp=default',
				'http://localhost:3072'
			)
		);
	}

	#[Test]
	public function explicit_default_port_matches_implicit_default_port(): void {
		$this->assertTrue(
			Agend_Directory_Sync_Admin_Page::link_matches_environment(
				'https://api.agend.com.au:443/sso/acme/directory-home?idp=default',
				'https://api.agend.com.au'
			)
		);
	}

	#[Test]
	public function http_default_port_80_matches_implicit_default_port(): void {
		$this->assertTrue(
			Agend_Directory_Sync_Admin_Page::link_matches_environment(
				'http://localhost:80/sso/acme/directory-home?idp=default',
				'http://localhost'
			)
		);
	}

	#[Test]
	public function empty_link_is_rejected(): void {
		$this->assertFalse(
			Agend_Directory_Sync_Admin_Page::link_matches_environment( '', 'https://api.agend.com.au' )
		);
	}

	#[Test]
	public function garbage_link_is_rejected(): void {
		$this->assertFalse(
			Agend_Directory_Sync_Admin_Page::link_matches_environment( 'not a url', 'https://api.agend.com.au' )
		);
	}

	#[Test]
	public function empty_api_root_is_rejected(): void {
		$this->assertFalse(
			Agend_Directory_Sync_Admin_Page::link_matches_environment(
				'https://api.agend.com.au/sso/acme/directory-home?idp=default',
				''
			)
		);
	}

	// -----------------------------------------------------------------
	// token_grants_directory_app()
	// -----------------------------------------------------------------

	/**
	 * Builds a JWT-shaped string with the given payload; the header and
	 * signature segments are placeholders since neither is read.
	 *
	 * @param array<string, mixed> $payload
	 */
	private function jwt( array $payload ): string {
		$header_segment  = $this->base64url_encode( '{"alg":"none"}' );
		$payload_segment = $this->base64url_encode( (string) wp_json_encode( $payload ) );

		return $header_segment . '.' . $payload_segment . '.signature';
	}

	private function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	#[Test]
	public function granted_when_the_claim_lists_the_app_for_the_account(): void {
		$token = $this->jwt( array( 'app_access' => array( 'acme' => array( 'directory', 'events' ) ) ) );

		$this->assertTrue(
			Agend_Directory_Sync_Admin_Page::token_grants_directory_app( $token, 'acme' )
		);
	}

	#[Test]
	public function not_granted_for_a_different_account_slug(): void {
		$token = $this->jwt( array( 'app_access' => array( 'other-account' => array( 'directory' ) ) ) );

		$this->assertFalse(
			Agend_Directory_Sync_Admin_Page::token_grants_directory_app( $token, 'acme' )
		);
	}

	#[Test]
	public function not_granted_when_the_claim_is_missing(): void {
		$token = $this->jwt( array( 'sub' => 'user-1' ) );

		$this->assertFalse(
			Agend_Directory_Sync_Admin_Page::token_grants_directory_app( $token, 'acme' )
		);
	}

	#[Test]
	public function not_granted_when_the_claim_is_not_an_object(): void {
		$token = $this->jwt( array( 'app_access' => 'directory' ) );

		$this->assertFalse(
			Agend_Directory_Sync_Admin_Page::token_grants_directory_app( $token, 'acme' )
		);
	}

	#[Test]
	public function not_granted_when_the_accounts_value_is_not_an_array(): void {
		$token = $this->jwt( array( 'app_access' => array( 'acme' => 'directory' ) ) );

		$this->assertFalse(
			Agend_Directory_Sync_Admin_Page::token_grants_directory_app( $token, 'acme' )
		);
	}

	#[Test]
	public function not_granted_when_the_apps_array_does_not_contain_directory(): void {
		$token = $this->jwt( array( 'app_access' => array( 'acme' => array( 'events' ) ) ) );

		$this->assertFalse(
			Agend_Directory_Sync_Admin_Page::token_grants_directory_app( $token, 'acme' )
		);
	}

	#[Test]
	public function malformed_token_with_too_few_segments_is_rejected(): void {
		$this->assertFalse(
			Agend_Directory_Sync_Admin_Page::token_grants_directory_app( 'not-a-jwt', 'acme' )
		);
	}

	#[Test]
	public function empty_token_is_rejected(): void {
		$this->assertFalse(
			Agend_Directory_Sync_Admin_Page::token_grants_directory_app( '', 'acme' )
		);
	}

	#[Test]
	public function payload_segment_with_invalid_base64_is_rejected(): void {
		$this->assertFalse(
			Agend_Directory_Sync_Admin_Page::token_grants_directory_app( 'header.***not-base64***.sig', 'acme' )
		);
	}

	#[Test]
	public function payload_segment_that_is_not_json_is_rejected(): void {
		$bad_payload = $this->base64url_encode( 'not json at all' );

		$this->assertFalse(
			Agend_Directory_Sync_Admin_Page::token_grants_directory_app( 'header.' . $bad_payload . '.sig', 'acme' )
		);
	}

	#[Test]
	public function payload_segment_that_is_a_json_scalar_is_rejected(): void {
		$scalar_payload = $this->base64url_encode( '"just a string"' );

		$this->assertFalse(
			Agend_Directory_Sync_Admin_Page::token_grants_directory_app( 'header.' . $scalar_payload . '.sig', 'acme' )
		);
	}

	#[Test]
	public function empty_account_slug_is_rejected_even_with_a_matching_claim(): void {
		$token = $this->jwt( array( 'app_access' => array( '' => array( 'directory' ) ) ) );

		$this->assertFalse(
			Agend_Directory_Sync_Admin_Page::token_grants_directory_app( $token, '' )
		);
	}
}

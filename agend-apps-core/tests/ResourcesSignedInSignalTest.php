<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * How the three Resources scripts know a WordPress user is signed in.
 *
 * `window.agendApps.loggedIn` is true only when a stored credential session
 * exists, so it is false for every `wordpress` and `sso` member. The scripts
 * read `window.agendApps.signedIn` instead, which the page header sets for any
 * signed-in WordPress user, and leave "is this user a member" to the routes.
 */
final class ResourcesSignedInSignalTest extends TestCase {

	/** @return array<string, array{string}> */
	public static function scripts(): array {
		return array(
			'collections' => array( 'resources-collections.js' ),
			'search'      => array( 'resources-search.js' ),
			'ask'         => array( 'resources-ask.js' ),
		);
	}

	private function code( string $file ): string {
		$script = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/assets/js/' . $file );

		return (string) preg_replace( array( '#/\*.*?\*/#s', '#^\s*//.*$#m' ), '', $script );
	}

	#[Test]
	#[DataProvider( 'scripts' )]
	public function should_read_the_signed_in_signal_and_not_the_credential_session_one( string $file ): void {
		$code = $this->code( $file );

		self::assertStringContainsString( 'window.agendApps.signedIn === true', $code );
		self::assertSame( 0, preg_match( '/\.loggedIn\b/', $code ), 'window.agendApps.loggedIn is never read' );
	}

	#[Test]
	#[DataProvider( 'scripts' )]
	public function should_not_depend_on_the_theme_putting_a_class_on_the_body( string $file ): void {
		self::assertStringNotContainsString( 'logged-in', $this->code( $file ) );
	}

	#[Test]
	public function should_have_the_page_header_say_whether_a_wordpress_user_is_signed_in(): void {
		$core = (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/agend-apps-core.php' );

		self::assertStringContainsString( "'signedIn'       => get_current_user_id() > 0,", $core );
		// The narrower signal is left as it was for the scripts that read it.
		self::assertStringContainsString( "'loggedIn'       => false,", $core );
		self::assertStringContainsString( "\$config['loggedIn']  = true;", $core );
	}
}

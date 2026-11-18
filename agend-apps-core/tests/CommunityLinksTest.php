<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/community-text.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/community.php';

/**
 * Where a Community post or channel sends a visitor: the Loop app's address,
 * worked out from the API root, and the two kinds of link into a channel.
 */
final class CommunityLinksTest extends TestCase {

	private function root( string $url ): void {
		Agend_Test_WP::$options['agend_apps_root_url_for_tests'] = $url;
	}

	private function signInMode( string $mode ): void {
		Agend_Test_WP::$options['agend_apps_member_auth_mode'] = $mode;
	}

	// -----------------------------------------------------------------
	// The Loop address
	// -----------------------------------------------------------------

	/** @return array<string, array{string, string}> */
	public static function roots(): array {
		return array(
			'production'            => array( 'https://api.agend.com.au', 'https://loop.agend.com.au' ),
			'staging'               => array( 'https://api.agend.info', 'https://loop.agend.info' ),
			'local gateway'         => array( 'http://localhost:3072', 'http://localhost:3063' ),
			'a custom api host'     => array( 'https://api.example.org', 'https://loop.example.org' ),
			'a trailing slash'      => array( 'https://api.agend.com.au/', 'https://loop.agend.com.au' ),
			'nothing to swap'       => array( 'https://gateway.example.org', '' ),
			'no root at all'        => array( '', '' ),
		);
	}

	#[Test]
	#[DataProvider( 'roots' )]
	public function should_work_out_the_loop_address_from_the_api_root( string $root, string $expected ): void {
		$this->root( $root );

		self::assertSame( $expected, agend_apps_community_loop_url() );
	}

	#[Test]
	public function should_let_an_explicit_address_win_and_a_filter_win_over_that(): void {
		$this->root( 'https://api.agend.com.au' );
		Agend_Test_WP::$options[ AGEND_APPS_COMMUNITY_OPTION_LOOP_URL ] = ' https://community.example.org/ ';

		self::assertSame( 'https://community.example.org', agend_apps_community_loop_url() );

		add_filter( 'agend_apps_app_url', static fn( string $url, string $app ): string => 'loop' === $app ? 'https://filtered.example.org' : $url, 10, 2 );

		self::assertSame( 'https://filtered.example.org', agend_apps_community_loop_url() );
	}

	// -----------------------------------------------------------------
	// Links
	// -----------------------------------------------------------------

	#[Test]
	public function should_use_the_pretty_sign_in_link_when_this_site_is_the_identity_provider(): void {
		$this->root( 'https://api.agend.com.au' );
		$this->signInMode( 'wordpress' );
		Agend_Test_WP::$options['agend_apps_account_slug'] = 'wdaa';

		self::assertSame( 'https://api.agend.com.au/sso/wdaa/loop-channel/general', agend_apps_community_channel_link( 'general' ) );
		self::assertSame( 'https://api.agend.com.au/sso/wdaa/loop-home', agend_apps_community_home_link() );
	}

	#[Test]
	public function should_use_the_channels_own_address_in_any_other_sign_in_mode(): void {
		$this->root( 'https://api.agend.com.au' );
		Agend_Test_WP::$options['agend_apps_account_slug'] = 'wdaa';

		foreach ( array( 'credentials', 'sso' ) as $mode ) {
			$this->signInMode( $mode );

			self::assertSame( 'https://loop.agend.com.au/home/wdaa/loop/general', agend_apps_community_channel_link( 'general' ), $mode );
			self::assertSame( 'https://loop.agend.com.au/home/wdaa/loop', agend_apps_community_home_link(), $mode );
		}
	}

	#[Test]
	public function should_encode_the_parts_of_a_link(): void {
		$this->root( 'https://api.agend.com.au' );
		$this->signInMode( 'wordpress' );
		Agend_Test_WP::$options['agend_apps_account_slug'] = 'my club';

		self::assertSame( 'https://api.agend.com.au/sso/my%20club/loop-channel/a%2Fb', agend_apps_community_channel_link( 'a/b' ) );
	}

	#[Test]
	public function should_give_no_link_when_the_account_or_the_loop_address_is_not_set_up(): void {
		$this->root( 'https://api.agend.com.au' );
		$this->signInMode( 'wordpress' );

		self::assertSame( '', agend_apps_community_channel_link( 'general' ), 'no account' );
		self::assertSame( '', agend_apps_community_home_link() );

		Agend_Test_WP::$options['agend_apps_account_slug'] = 'wdaa';
		self::assertSame( '', agend_apps_community_channel_link( '' ), 'no channel' );

		$this->signInMode( 'credentials' );
		$this->root( 'https://gateway.example.org' );
		self::assertSame( '', agend_apps_community_channel_link( 'general' ), 'no Loop address to build it on' );
	}

	// -----------------------------------------------------------------
	// Editor notices
	// -----------------------------------------------------------------

	#[Test]
	public function should_tell_the_block_editor_where_to_allow_channels_for_a_community_block_only(): void {
		$notices = agend_apps_community_block_notices( array(), 'community-feed' );

		self::assertCount( 1, $notices );
		self::assertSame( 'warning', $notices[0]['status'] );
		self::assertStringContainsString( 'Settings > Agend Apps > Community', $notices[0]['text'] );
		self::assertSame( array(), agend_apps_community_block_notices( array(), 'learning-paths' ) );

		update_option( AGEND_APPS_COMMUNITY_OPTION_CHANNELS, array( 'chan-1' ) );
		self::assertSame( array(), agend_apps_community_block_notices( array(), 'community-feed' ), 'nothing to say once a channel is allowed' );
	}
}

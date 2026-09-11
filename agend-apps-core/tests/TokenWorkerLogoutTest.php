<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Apps_Token_Worker;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-token-worker.php';

/**
 * docs/PLAN-wordpress-idp-option-b.md section 4.5: `wp_logout` must clear
 * both the minted-token usermeta and the negative-cache transient, since
 * nothing else did before this hook existed and a minted token otherwise
 * outlived the WordPress session until its own expiry. Registered
 * unconditionally by the worker's constructor (see the docblock there),
 * so this also covers `sso` mode, not only the new `wordpress` mode.
 */
final class TokenWorkerLogoutTest extends TestCase {

	#[Test]
	public function should_clear_the_cached_token_and_negative_cache_when_wp_logout_carries_the_user_id(): void {
		new Agend_Apps_Token_Worker();

		update_user_meta( 77, Agend_Apps_Token_Worker::META_KEY, array( 'access_token' => 'stale' ) );
		set_transient( Agend_Apps_Token_Worker::NEGATIVE_PREFIX . 77, 1 );

		do_action( 'wp_logout', 77 );

		$this->assertSame( '', get_user_meta( 77, Agend_Apps_Token_Worker::META_KEY, true ) );
		$this->assertFalse( get_transient( Agend_Apps_Token_Worker::NEGATIVE_PREFIX . 77 ) );
	}

	#[Test]
	public function should_fall_back_to_get_current_user_id_when_wp_logout_fires_with_no_argument(): void {
		new Agend_Apps_Token_Worker();

		$GLOBALS['agend_test_current_user_id'] = 78;

		update_user_meta( 78, Agend_Apps_Token_Worker::META_KEY, array( 'access_token' => 'stale' ) );
		set_transient( Agend_Apps_Token_Worker::NEGATIVE_PREFIX . 78, 1 );

		do_action( 'wp_logout' );

		$this->assertSame( '', get_user_meta( 78, Agend_Apps_Token_Worker::META_KEY, true ) );
		$this->assertFalse( get_transient( Agend_Apps_Token_Worker::NEGATIVE_PREFIX . 78 ) );
	}

	#[Test]
	public function should_do_nothing_when_no_user_id_is_available_at_all(): void {
		new Agend_Apps_Token_Worker();

		$GLOBALS['agend_test_current_user_id'] = 0;

		// Nothing stored, nothing to clear; the call must simply not throw.
		do_action( 'wp_logout' );

		$this->assertTrue( true );
	}
}

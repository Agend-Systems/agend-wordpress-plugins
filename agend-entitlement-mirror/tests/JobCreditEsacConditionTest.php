<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\EntitlementMirror;

use Agend_Apps_Jobs_Credit_Provider;
use Agend_Apps_Jobs_Credits;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/jobs-credits.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/includes/class-entitlement-mirror-settings.php';
require_once AGEND_TESTS_ROOT . '/agend-entitlement-mirror/agend-entitlement-mirror.php';

/**
 * The "Has an unused job credit" Content Personalisation (ESAC) condition
 * passes only for a signed-in member with a credit they can spend now.
 */
final class JobCreditEsacConditionTest extends TestCase {

	/** @var array<int, array>|WP_Error What the provider reports. */
	private $credits = array();

	protected function setUp(): void {
		parent::setUp();
		$test = $this;
		add_filter(
			'agend_apps_jobs_credit_provider',
			static function () use ( $test ) {
				return new class( $test ) implements Agend_Apps_Jobs_Credit_Provider {
					private $test;
					public function __construct( $test ) {
						$this->test = $test;
					}
					public function get_key(): string {
						return 'fake';
					}
					public function is_available(): bool {
						return true;
					}
					public function get_available_credits( int $user_id ) {
						return $this->test->credits_for( $user_id );
					}
					public function spend( int $user_id, string $ref ) {
						return true;
					}
					public function restore( int $user_id, string $ref ) {
						return true;
					}
				};
			}
		);
	}

	public function credits_for( int $user_id ) {
		return $this->credits;
	}

	#[Test]
	public function the_condition_is_offered_only_while_job_credits_are_on(): void {
		$this->assertSame( array(), agend_entitlement_mirror_esac_job_credit_condition_set( array() ) );

		update_option( 'agend_entitlement_mirror_job_credits_enabled', '1' );
		$sets = agend_entitlement_mirror_esac_job_credit_condition_set( array( 'other' => array() ) );

		$this->assertArrayHasKey( 'other', $sets );
		$this->assertSame( array( 'has_job_credit' ), array_keys( $sets['agend_jobs']['conditions'] ) );
	}

	#[Test]
	public function a_member_with_an_unused_credit_passes(): void {
		wp_set_current_user( 7 );
		$this->credits = array( array( 'ref' => 'cr-1', 'expires_at' => null ) );

		$this->assertTrue( agend_entitlement_mirror_esac_check_job_credit( true, 'has_job_credit' ) );
	}

	#[Test]
	public function no_credits_a_failed_read_or_no_member_does_not_pass(): void {
		wp_set_current_user( 7 );
		$this->assertFalse( agend_entitlement_mirror_esac_check_job_credit( true, 'has_job_credit' ) );

		$this->credits = new WP_Error( 'agend_jobs_credit_lookup_failed', 'down' );
		$this->assertFalse( agend_entitlement_mirror_esac_check_job_credit( true, 'has_job_credit' ) );

		$this->credits = array( array( 'ref' => 'cr-1', 'expires_at' => null ) );
		wp_set_current_user( 0 );
		$this->assertFalse( agend_entitlement_mirror_esac_check_job_credit( true, 'has_job_credit' ) );
	}

	#[Test]
	public function a_credit_tied_up_in_a_submission_does_not_count(): void {
		wp_set_current_user( 7 );
		$this->credits = array( array( 'ref' => 'cr-1', 'expires_at' => null ) );
		update_option(
			Agend_Apps_Jobs_Credits::LEDGER_PREFIX . md5( 'cr-1' ),
			array(
				'ref'         => 'cr-1',
				'state'       => Agend_Apps_Jobs_Credits::STATE_RESERVED,
				'user_id'     => 7,
				'reservation' => '0123456789abcdef',
				'job_id'      => '',
				'released'    => array(),
				'updated_at'  => time(),
			)
		);

		$this->assertFalse( agend_entitlement_mirror_esac_check_job_credit( true, 'has_job_credit' ) );
	}

	#[Test]
	public function other_conditions_pass_through(): void {
		$this->assertTrue( agend_entitlement_mirror_esac_check_job_credit( true, 'something_else' ) );
		$this->assertFalse( agend_entitlement_mirror_esac_check_job_credit( false, 'something_else' ) );
	}
}

<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

use Agend_Directory_Sync_Listing_Transformer;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-path-resolver.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-field-map.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-listing-transformer.php';

/**
 * Truthy/falsey coercion of the eligibility / opt-in flag sources
 * (SPEC-DIR-20260731 US-3.3), exercised through `transform_all()`'s status
 * output rather than reaching into the private `truthy()` helper directly.
 */
#[CoversClass( Agend_Directory_Sync_Listing_Transformer::class )]
final class FlagTruthyCoercionTest extends TestCase {

	/**
	 * @param mixed $flag_value
	 */
	private function statusFor( $flag_value ): string {
		$contacts = array(
			array(
				'id'       => '1',
				'eligible' => $flag_value,
			),
		);

		$field_map = array(
			'core' => array(
				'external_id'   => 'id',
				'eligible_flag' => 'eligible',
			),
		);

		$result = Agend_Directory_Sync_Listing_Transformer::transform_all( $contacts, $field_map );

		return (string) $result['listings'][0]['status'];
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function truthyMatrix(): array {
		return array(
			'bool true'               => array( true, Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE ),
			'bool false'              => array( false, Agend_Directory_Sync_Listing_Transformer::STATUS_HIDDEN ),
			'null'                    => array( null, Agend_Directory_Sync_Listing_Transformer::STATUS_HIDDEN ),
			'array'                   => array( array( 'x' ), Agend_Directory_Sync_Listing_Transformer::STATUS_HIDDEN ),
			'string "false"'          => array( 'false', Agend_Directory_Sync_Listing_Transformer::STATUS_HIDDEN ),
			'string "FALSE" upper'    => array( 'FALSE', Agend_Directory_Sync_Listing_Transformer::STATUS_HIDDEN ),
			'string "no"'             => array( 'no', Agend_Directory_Sync_Listing_Transformer::STATUS_HIDDEN ),
			'string "n"'              => array( 'n', Agend_Directory_Sync_Listing_Transformer::STATUS_HIDDEN ),
			'string "off"'            => array( 'off', Agend_Directory_Sync_Listing_Transformer::STATUS_HIDDEN ),
			'string "0"'              => array( '0', Agend_Directory_Sync_Listing_Transformer::STATUS_HIDDEN ),
			'empty string'            => array( '', Agend_Directory_Sync_Listing_Transformer::STATUS_HIDDEN ),
			'whitespace-only string'  => array( '   ', Agend_Directory_Sync_Listing_Transformer::STATUS_HIDDEN ),
			'int 0'                   => array( 0, Agend_Directory_Sync_Listing_Transformer::STATUS_HIDDEN ),
			'float 0.0'               => array( 0.0, Agend_Directory_Sync_Listing_Transformer::STATUS_HIDDEN ),
			'string "0.0"'            => array( '0.0', Agend_Directory_Sync_Listing_Transformer::STATUS_HIDDEN ),
			'int 1'                   => array( 1, Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE ),
			'float 2.5'               => array( 2.5, Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE ),
			'negative number'         => array( -1, Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE ),
			'string "1"'              => array( '1', Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE ),
			'string "true"'           => array( 'true', Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE ),
			'string "TRUE" upper'     => array( 'TRUE', Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE ),
			'string "yes"'            => array( 'yes', Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE ),
			'string "Y"'              => array( 'Y', Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE ),
			'string "on"'             => array( 'on', Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE ),
			'arbitrary non-empty str' => array( 'enabled', Agend_Directory_Sync_Listing_Transformer::STATUS_VISIBLE ),
		);
	}

	#[Test]
	#[DataProvider( 'truthyMatrix' )]
	public function it_coerces_the_flag_value_per_the_truthy_matrix( $flag_value, string $expected_status ): void {
		$this->assertSame( $expected_status, $this->statusFor( $flag_value ) );
	}
}

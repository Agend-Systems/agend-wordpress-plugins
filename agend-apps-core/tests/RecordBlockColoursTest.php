<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Test;

#[CoversFunction( 'agend_apps_records_record_block_colour_overrides' )]
final class RecordBlockColoursTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/record-block.php';
	}

	#[Test]
	public function should_write_nothing_when_no_colour_is_set(): void {
		self::assertSame( '', agend_apps_records_record_block_colour_overrides( array(), 'agend-dir' ) );
	}

	#[Test]
	public function should_override_each_set_role_under_the_record_prefix(): void {
		self::assertSame(
			'--agend-dir-heading:#3E464D;--agend-dir-accent:#0C5998;--agend-dir-border:#DCE3E9;--agend-dir-card-radius:4px;',
			agend_apps_records_record_block_colour_overrides(
				array(
					'heading_colour' => '#3E464D',
					'accent_colour'  => '#0C5998',
					'border_colour'  => '#DCE3E9',
					'body_colour'    => 'red;x:y',
					'panel_radius'   => '4',
				),
				'agend-dir'
			)
		);
	}

	#[Test]
	public function should_ignore_a_radius_the_schema_does_not_offer(): void {
		self::assertSame( '', agend_apps_records_record_block_colour_overrides( array( 'panel_radius' => '5' ), 'agend-ev' ) );
	}
}

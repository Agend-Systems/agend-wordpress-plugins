<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Apps_Records_Record_Context;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/pages.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/record-context.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/filters.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/query.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/rest/fragments-controller.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/deprecated.php';

/**
 * The `agend_elementor_*` shims in deprecated.php: a site, theme, or sibling
 * plugin calling the pre-rename record-layer names for one release after the
 * `agend_apps_records_*` rename.
 */
final class DeprecatedNamesTest extends TestCase {

	#[Test]
	public function should_delegate_the_deprecated_function_to_its_renamed_equivalent(): void {
		$this->assertSame(
			agend_apps_records_ssr_colour_style( 'agend-ev' ),
			agend_elementor_ssr_colour_style( 'agend-ev' )
		);
	}

	#[Test]
	public function should_resolve_the_deprecated_class_alias_to_the_renamed_class(): void {
		$instance = new \Agend_Elementor_Record_Context();

		$this->assertInstanceOf( Agend_Apps_Records_Record_Context::class, $instance );
	}

	#[Test]
	public function should_still_apply_a_filter_registered_on_the_deprecated_hook_name(): void {
		Agend_Test_WP::set_filter( 'agend_elementor_ssr_detail_enabled', true );

		$this->assertTrue( agend_apps_records_ssr_detail_enabled() );
	}
}

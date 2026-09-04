<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Elementor_Templates;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-templates.php';

/**
 * `Agend_Elementor_Templates`: the Card/Detail Template SELECT options,
 * sourced from the `agend_elementor_template_candidate_ids` filter here
 * rather than a real `WP_Query`/`elementor_library` lookup (out of scope for
 * the stub harness; see class docblock).
 */
#[CoversClass( Agend_Elementor_Templates::class )]
final class TemplateOptionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Agend_Elementor_Templates::flush_cache();
	}

	private function seedCandidates( array $candidates ): void {
		Agend_Test_WP::$filters['agend_elementor_template_candidate_ids'] = static function () use ( $candidates ) {
			return $candidates;
		};
	}

	#[Test]
	public function should_list_the_placeholder_first_keyed_by_empty_string(): void {
		$this->seedCandidates( array( 12 => 'Card A' ) );

		$options = Agend_Elementor_Templates::options( 'Custom placeholder' );

		// PHP coerces a numeric-looking string array key back to int, so '12'
		// surfaces as 12 here; that is fine for Elementor's SELECT control,
		// which only ever needs the placeholder's '' key to stay distinct
		// from a real (always-positive) template id, never a literal string
		// type. array_keys() is compared via strval() for that reason.
		$this->assertSame( array( '', '12' ), array_map( 'strval', array_keys( $options ) ) );
		$this->assertSame( 'Custom placeholder', $options[''] );
	}

	#[Test]
	public function should_use_the_translated_default_placeholder_when_none_is_given(): void {
		$this->seedCandidates( array() );

		$options = Agend_Elementor_Templates::options();

		$this->assertSame( 'Default (built-in layout)', $options[''] );
	}

	#[Test]
	public function should_resolve_candidate_ids_by_string_lookup_regardless_of_source_type(): void {
		// The candidate filter may return int-keyed or string-keyed
		// candidates (a real WP_Query loop yields int post ids); either
		// resolves the same way once built.
		$this->seedCandidates( array( 12 => 'Card A', '34' => 'Card B' ) );

		$options = Agend_Elementor_Templates::options();

		$this->assertSame( 'Card A', $options['12'] );
		$this->assertSame( 'Card B', $options['34'] );
	}

	#[Test]
	public function should_preserve_the_candidate_filters_ordering(): void {
		$this->seedCandidates(
			array(
				34 => 'Zeta',
				12 => 'Alpha',
			)
		);

		$options = Agend_Elementor_Templates::options();

		$this->assertSame( array( '', '34', '12' ), array_map( 'strval', array_keys( $options ) ) );
	}

	#[Test]
	public function should_return_the_cached_map_without_reinvoking_the_candidate_filter_on_a_static_cache_hit(): void {
		$calls = 0;
		Agend_Test_WP::$filters['agend_elementor_template_candidate_ids'] = static function () use ( &$calls ) {
			++$calls;
			return array( 12 => 'Card A' );
		};

		$first  = Agend_Elementor_Templates::options();
		$second = Agend_Elementor_Templates::options();

		$this->assertSame( $first, $second );
		$this->assertSame( 1, $calls );
	}

	#[Test]
	public function should_reinvoke_the_candidate_filter_after_flush_cache_clears_the_cache(): void {
		$calls = 0;
		Agend_Test_WP::$filters['agend_elementor_template_candidate_ids'] = static function () use ( &$calls ) {
			++$calls;
			return array( 12 => 'Card A' );
		};

		Agend_Elementor_Templates::options();
		Agend_Elementor_Templates::flush_cache();
		Agend_Elementor_Templates::options();

		$this->assertSame( 2, $calls );
	}
}

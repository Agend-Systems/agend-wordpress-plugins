<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Elementor_Record_Context;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/class-agend-elementor-record-context.php';

/**
 * `Agend_Elementor_Record_Context`: the static stack that lets a field widget
 * nested inside a template know which record it is rendering against.
 */
#[CoversClass( Agend_Elementor_Record_Context::class )]
final class RecordContextTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Agend_Elementor_Record_Context::reset();
	}

	#[Test]
	public function should_return_the_most_recently_pushed_frame_when_nested(): void {
		Agend_Elementor_Record_Context::push( 'event', array( 'slug' => 'outer' ) );
		Agend_Elementor_Record_Context::push( 'course', array( 'slug' => 'inner' ) );

		$this->assertSame( 'course', Agend_Elementor_Record_Context::type() );
		$this->assertSame( array( 'slug' => 'inner' ), Agend_Elementor_Record_Context::record() );
	}

	#[Test]
	public function should_restore_the_outer_frame_when_the_inner_frame_is_popped(): void {
		Agend_Elementor_Record_Context::push( 'event', array( 'slug' => 'outer' ) );
		Agend_Elementor_Record_Context::push( 'course', array( 'slug' => 'inner' ) );

		Agend_Elementor_Record_Context::pop();

		$this->assertSame( 'event', Agend_Elementor_Record_Context::type() );
		$this->assertSame( array( 'slug' => 'outer' ), Agend_Elementor_Record_Context::record() );
	}

	#[Test]
	public function should_report_no_context_when_the_stack_is_empty(): void {
		$this->assertFalse( Agend_Elementor_Record_Context::has() );
		$this->assertNull( Agend_Elementor_Record_Context::current() );
		$this->assertSame( '', Agend_Elementor_Record_Context::type() );
		$this->assertSame( array(), Agend_Elementor_Record_Context::record() );
		$this->assertSame( 0, Agend_Elementor_Record_Context::depth() );
	}

	#[Test]
	public function should_return_the_given_default_when_the_extra_key_is_unset(): void {
		Agend_Elementor_Record_Context::push( 'event', array(), array( 'slug' => 'has-slug' ) );

		$this->assertSame( 'has-slug', Agend_Elementor_Record_Context::extra( 'slug' ) );
		$this->assertNull( Agend_Elementor_Record_Context::extra( 'detail_url' ) );
		$this->assertSame( 'fallback', Agend_Elementor_Record_Context::extra( 'detail_url', 'fallback' ) );
	}

	#[Test]
	public function should_return_the_given_default_for_extra_when_the_stack_is_empty(): void {
		$this->assertSame( 'fallback', Agend_Elementor_Record_Context::extra( 'slug', 'fallback' ) );
	}

	#[Test]
	public function should_track_depth_across_multiple_pushes(): void {
		Agend_Elementor_Record_Context::push( 'event', array() );
		Agend_Elementor_Record_Context::push( 'event', array() );
		Agend_Elementor_Record_Context::push( 'event', array() );

		$this->assertSame( 3, Agend_Elementor_Record_Context::depth() );
	}

	#[Test]
	public function should_clear_the_stack_when_reset_is_called(): void {
		Agend_Elementor_Record_Context::push( 'event', array( 'slug' => 'a' ) );
		Agend_Elementor_Record_Context::push( 'course', array( 'slug' => 'b' ) );

		Agend_Elementor_Record_Context::reset();

		$this->assertSame( 0, Agend_Elementor_Record_Context::depth() );
		$this->assertFalse( Agend_Elementor_Record_Context::has() );
	}

	#[Test]
	public function should_be_a_no_op_when_pop_is_called_on_an_empty_stack(): void {
		Agend_Elementor_Record_Context::pop();

		$this->assertSame( 0, Agend_Elementor_Record_Context::depth() );
		$this->assertFalse( Agend_Elementor_Record_Context::has() );
	}
}

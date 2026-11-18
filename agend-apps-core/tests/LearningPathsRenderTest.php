<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Key_Scopes;
use Agend_Apps_Records_Record_Context;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

/**
 * The learning paths surface: what it asks the gateway for, what it draws, and
 * what it draws when there is nothing to draw. It is read-only and has no
 * script, so the markup is the whole contract.
 *
 * Each case runs in its own process: the fixture declares the gateway wrappers
 * the surface reads, which other tests in this suite assert do NOT exist.
 */
#[RunTestsInSeparateProcesses]
final class LearningPathsRenderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/tests/render-doubles.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/blocks.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/learning-paths.php';
		require_once __DIR__ . '/fixtures/learning-paths-stubs.php';
		agend_render_test_reset();
		agend_lp_test_reset();
		Agend_Apps_Records_Record_Context::reset();
	}

	/**
	 * The settings an editor hands the renderer: the schema's defaults, in the
	 * Elementor shape the renderer takes, with the test's changes over them.
	 *
	 * @param array $overrides Settings to change.
	 * @return array
	 */
	private function settings( array $overrides = array() ): array {
		$schema   = agend_apps_records_surface_schema( 'learning-paths' );
		$defaults = array_map( static fn( array $a ) => $a['default'], agend_apps_records_block_attributes( $schema ) );

		return $overrides + agend_apps_records_settings_from_attributes( $schema, $defaults );
	}

	/**
	 * Renders the surface as an editor would, from the defaults plus changes.
	 *
	 * @param array $overrides Settings to change.
	 * @return string
	 */
	private function render( array $overrides = array() ): string {
		return agend_apps_records_render_learning_paths( $this->settings( $overrides ) );
	}

	/**
	 * Seeds a dedicated Courses page, and the pretty permalinks that give a
	 * course page its `{page}/course/{slug}/` address.
	 */
	private function setCoursesPage(): void {
		$GLOBALS['agend_test_posts'][5]                            = array(
			'ID'          => 5,
			'post_type'   => 'page',
			'post_status' => 'publish',
		);
		Agend_Test_WP::$options['agend_elementor_courses_page_id'] = 5;
		Agend_Test_WP::$options['permalink_structure']             = '/%postname%/';
	}

	/**
	 * A list of two published paths, the first with three courses handed over
	 * out of order, the second with one.
	 */
	private function seedTwoPaths(): void {
		$first  = agend_lp_test_path(
			'p1',
			array(
				'title'                    => 'Web Foundations',
				'difficulty'               => 'beginner',
				'estimated_duration_hours' => 18,
				'is_sequential'            => true,
				'image_url'                => 'https://cdn.example.test/p1.jpg',
			)
		);
		$second = agend_lp_test_path( 'p2', array( 'title' => 'Advanced Delivery', 'difficulty' => 'advanced' ) );

		agend_lp_test_set_list( array( $first, $second ) );
		agend_lp_test_set_detail(
			$first,
			array(
				agend_lp_test_course( 'build-a-site', 2 ),
				agend_lp_test_course( 'html-basics', 0 ),
				agend_lp_test_course( 'css-basics', 1, true ),
			)
		);
		agend_lp_test_set_detail( $second, array( agend_lp_test_course( 'release-pipelines', 0 ) ) );
	}

	/**
	 * Where each needle first appears in the html, in the order given.
	 *
	 * @param string   $html    Markup.
	 * @param string[] $needles Strings that must all be present.
	 * @return int[]
	 */
	private function positions( string $html, array $needles ): array {
		return array_map(
			static function ( string $needle ) use ( $html ): int {
				$at = strpos( $html, $needle );

				return false === $at ? -1 : $at;
			},
			$needles
		);
	}

	private function know( array $scopes ): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => $scopes, 'app_ids' => array() ) ) );
	}

	// -----------------------------------------------------------------
	// Config
	// -----------------------------------------------------------------

	#[Test]
	public function should_default_to_six_newest_paths_in_two_columns_with_courses_listed(): void {
		$config = agend_apps_records_learning_paths_build_config( array() );

		self::assertSame( 'all', $config['source'] );
		self::assertSame( 6, $config['max'] );
		self::assertSame( 2, $config['layout']['desktop'] );
		self::assertSame( 2, $config['layout']['tablet'] );
		self::assertSame( 1, $config['layout']['mobile'], 'one column on a phone, as in the courses catalogue' );
		self::assertTrue( $config['card']['courses'] );
		self::assertTrue( $config['courses']['links'] );
		self::assertFalse( $config['courses']['newTab'] );
		self::assertFalse( $config['empty']['hide'] );
	}

	#[Test]
	public function should_keep_every_number_within_its_bounds(): void {
		$config = agend_apps_records_learning_paths_build_config(
			array(
				'max_paths'       => 500,
				'columns_desktop' => '9',
				'columns_tablet'  => '0',
				'columns_mobile'  => '9',
				'card_radius'     => 99,
				'excerpt_length'  => 5,
			)
		);

		self::assertSame( AGEND_APPS_RECORDS_LEARNING_PATHS_MAX, $config['max'] );
		self::assertSame( 3, $config['layout']['desktop'] );
		self::assertSame( 1, $config['layout']['tablet'] );
		self::assertSame( 2, $config['layout']['mobile'] );
		self::assertSame( 48, $config['layout']['cardRadius'] );
		self::assertSame( 20, $config['card']['excerptLength'] );
		self::assertSame( 1, agend_apps_records_learning_paths_build_config( array( 'max_paths' => 0 ) )['max'] );
		self::assertSame( 1, agend_apps_records_learning_paths_build_config( array( 'columns_mobile' => '0' ) )['layout']['mobile'] );
	}

	#[Test]
	public function should_read_toggles_stored_as_yes_or_empty(): void {
		$config = agend_apps_records_learning_paths_build_config(
			array(
				'show_heading'     => '',
				'show_image'       => '',
				'show_difficulty'  => '',
				'show_duration'    => '',
				'show_description' => '',
				'show_sequence'    => '',
				'show_courses'     => '',
				'links_new_tab'    => 'yes',
				'hide_when_empty'  => 'yes',
				'course_links'     => 'none',
			)
		);

		self::assertFalse( $config['heading']['show'] );
		self::assertFalse( $config['card']['image'] );
		self::assertFalse( $config['card']['difficulty'] );
		self::assertFalse( $config['card']['duration'] );
		self::assertFalse( $config['card']['description'] );
		self::assertFalse( $config['card']['sequence'] );
		self::assertFalse( $config['card']['courses'] );
		self::assertTrue( $config['courses']['newTab'] );
		self::assertTrue( $config['empty']['hide'] );
		self::assertFalse( $config['courses']['links'] );
	}

	#[Test]
	public function should_clean_the_chosen_ids_and_hold_them_to_the_path_limit(): void {
		$config = agend_apps_records_learning_paths_build_config(
			array(
				'path_source'    => 'selected',
				'selected_paths' => array( 'b1', ' b2 ', 'b1', '', 'x<y>z', array( 'nested' ) ),
			)
		);

		self::assertSame( 'selected', $config['source'] );
		self::assertSame( array( 'b1', 'b2', 'xyz' ), $config['paths'] );

		$many = agend_apps_records_learning_paths_build_config(
			array(
				'path_source'    => 'selected',
				'selected_paths' => array_map( static fn( int $n ): string => 'id' . $n, range( 1, 40 ) ),
			)
		);

		self::assertCount( AGEND_APPS_RECORDS_LEARNING_PATHS_MAX, $many['paths'] );
	}

	#[Test]
	public function should_lay_cards_out_automatically_unless_stacked_is_chosen(): void {
		self::assertSame( 'auto', agend_apps_records_learning_paths_build_config( array() )['layout']['card'] );
		self::assertSame( 'auto', agend_apps_records_learning_paths_build_config( array( 'card_layout' => 'sideways' ) )['layout']['card'] );
		self::assertSame( 'stacked', agend_apps_records_learning_paths_build_config( array( 'card_layout' => 'stacked' ) )['layout']['card'] );
	}

	#[Test]
	public function should_add_the_stacked_class_only_when_stacked_is_chosen(): void {
		$this->seedTwoPaths();

		self::assertStringContainsString( '<div class="agend-learning-paths" style=', $this->render( array() ) );
		self::assertStringContainsString( '<div class="agend-learning-paths agend-learning-paths--stacked" style=', $this->render( array( 'card_layout' => 'stacked' ) ) );
	}

	#[Test]
	public function should_fall_back_to_all_paths_for_an_unknown_source(): void {
		self::assertSame( 'all', agend_apps_records_learning_paths_build_config( array( 'path_source' => 'newest' ) )['source'] );
	}

	// -----------------------------------------------------------------
	// What it asks the gateway for
	// -----------------------------------------------------------------

	#[Test]
	public function should_read_the_newest_paths_then_their_courses_as_an_anonymous_visitor_would(): void {
		$this->seedTwoPaths();

		$this->render( array( 'max_paths' => 4 ) );

		$calls = agend_lp_test_calls();

		self::assertCount( 2, $calls );
		self::assertSame( 'list', $calls[0]['kind'] );
		self::assertSame( array( 'limit' => 4 ), $calls[0]['query'] );
		self::assertTrue( $calls[0]['anonymous'], 'a path is the same for every viewer, so it is read from the shared cache' );
		self::assertSame( 'by_id', $calls[1]['kind'] );
		self::assertSame( array( 'p1', 'p2' ), $calls[1]['ids'] );
		self::assertTrue( $calls[1]['anonymous'] );
	}

	#[Test]
	public function should_not_read_the_courses_when_the_card_does_not_list_them(): void {
		$this->seedTwoPaths();

		$html = $this->render( array( 'show_courses' => '' ) );

		self::assertSame( array(), agend_lp_test_calls( 'by_id' ) );
		self::assertStringContainsString( 'Web Foundations', $html );
		self::assertStringNotContainsString( 'agend-learning-paths__courses', $html );
		self::assertStringNotContainsString( 'courses</li>', $html, 'with no course list read there is no count to show' );
	}

	#[Test]
	public function should_read_only_the_chosen_paths_by_id_in_the_order_chosen(): void {
		$this->seedTwoPaths();

		$html = agend_apps_records_render_learning_paths(
			array(
				'path_source'    => 'selected',
				'selected_paths' => array( 'p2', 'p1' ),
			)
		);

		self::assertSame( array(), agend_lp_test_calls( 'list' ), 'a chosen path is read for itself, so it is found past the first page too' );
		self::assertSame( array( array( 'p2', 'p1' ) ), array_column( agend_lp_test_calls( 'by_id' ), 'ids' ) );

		[ $second, $first ] = $this->positions( $html, array( 'Advanced Delivery', 'Web Foundations' ) );

		self::assertGreaterThan( -1, $second );
		self::assertLessThan( $first, $second, 'the chosen order is the order drawn' );
	}

	#[Test]
	public function should_leave_out_a_chosen_path_that_was_unpublished_or_cannot_be_read(): void {
		$this->seedTwoPaths();
		$draft = agend_lp_test_path( 'p3', array( 'title' => 'Draft Path', 'published' => false ) );
		agend_lp_test_set_detail( $draft, array() );

		$html = agend_apps_records_render_learning_paths(
			array(
				'path_source'    => 'selected',
				'selected_paths' => array( 'p1', 'p3', 'gone' ),
			)
		);

		self::assertStringContainsString( 'Web Foundations', $html );
		self::assertStringNotContainsString( 'Draft Path', $html );
		self::assertStringNotContainsString( 'Unable to load', $html, 'one readable path is not a failed load' );
	}

	#[Test]
	public function should_leave_out_an_unpublished_path_from_the_list(): void {
		$live  = agend_lp_test_path( 'p1', array( 'title' => 'Live Path' ) );
		$draft = agend_lp_test_path( 'p2', array( 'title' => 'Draft Path', 'published' => false ) );
		agend_lp_test_set_list( array( $live, $draft ) );

		$html = $this->render( array() );

		self::assertStringContainsString( 'Live Path', $html );
		self::assertStringNotContainsString( 'Draft Path', $html );
	}

	#[Test]
	public function should_show_a_path_without_its_courses_when_only_their_read_failed(): void {
		agend_lp_test_set_list( array( agend_lp_test_path( 'p1', array( 'title' => 'Web Foundations' ) ) ) );
		// No detail is set, so the stub answers with a WP_Error for it.

		$html = $this->render( array() );

		self::assertStringContainsString( 'Web Foundations', $html );
		self::assertStringNotContainsString( 'agend-learning-paths__courses', $html );
		self::assertStringNotContainsString( 'Unable to load', $html );
	}

	// -----------------------------------------------------------------
	// What it draws
	// -----------------------------------------------------------------

	#[Test]
	public function should_draw_each_path_with_its_courses_numbered_in_order(): void {
		$this->seedTwoPaths();

		$html = $this->render( array() );

		self::assertStringContainsString( '<h2 class="agend-learning-paths__title">Learning pathways</h2>', $html );
		self::assertSame( 2, substr_count( $html, '<article class="agend-learning-paths__card">' ) );
		self::assertSame( 2, substr_count( $html, '<ol class="agend-learning-paths__courses' ) );

		// Handed over as build-a-site (2), html-basics (0), css-basics (1).
		$positions = $this->positions( $html, array( 'Html Basics', 'Css Basics', 'Build A Site' ) );

		self::assertNotContains( -1, $positions, 'every course is drawn' );

		$sorted = $positions;
		sort( $sorted );

		self::assertSame( $sorted, $positions, 'order_index decides the order, not the order sent' );
	}

	#[Test]
	public function should_draw_the_path_details_a_card_offers(): void {
		$this->seedTwoPaths();

		$html = $this->render( array() );

		self::assertStringContainsString( '<p class="agend-learning-paths__eyebrow">Beginner</p>', $html );
		self::assertStringContainsString( '<li class="agend-learning-paths__meta-item">18 hours</li>', $html );
		self::assertStringContainsString( '<li class="agend-learning-paths__meta-item">3 courses</li>', $html );
		self::assertStringContainsString( '<li class="agend-learning-paths__meta-item">1 course</li>', $html );
		self::assertStringContainsString( 'Complete the courses in order.', $html );
		self::assertSame( 1, substr_count( $html, 'Complete the courses in order.' ), 'only the sequential path says so' );
		self::assertStringContainsString( 'About path p1.', $html );
		self::assertStringContainsString( '<img class="agend-learning-paths__img" src="https://cdn.example.test/p1.jpg" alt="" loading="lazy" decoding="async">', $html );
		self::assertSame( 1, substr_count( $html, '<img ' ), 'a path with no image draws no frame for one' );
		self::assertSame( 1, substr_count( $html, '>Optional</span>' ) );
	}

	#[Test]
	public function should_split_each_card_into_a_summary_header_and_the_course_steps(): void {
		$this->seedTwoPaths();

		$html = $this->render( array() );

		self::assertSame( 2, substr_count( $html, '<div class="agend-learning-paths__head">' ) );
		self::assertSame( 2, substr_count( $html, '<div class="agend-learning-paths__steps">' ) );

		// The path's own details are in the header, ahead of the steps.
		$positions = $this->positions( $html, array( '>Web Foundations</h3>', 'About path p1.', 'agend-learning-paths__steps', 'Html Basics' ) );

		self::assertNotContains( -1, $positions );

		$sorted = $positions;
		sort( $sorted );

		self::assertSame( $sorted, $positions );
	}

	#[Test]
	public function should_mark_only_a_sequential_path_as_a_stepper_and_say_so_in_words(): void {
		$this->seedTwoPaths();

		$html = $this->render( array() );

		self::assertSame( 1, substr_count( $html, 'agend-learning-paths__courses--sequential' ), 'the connector is drawn for the sequential path only' );
		self::assertSame( 1, substr_count( $html, 'Complete the courses in order.' ), 'the order is stated in text too, not only by the connector' );
	}

	#[Test]
	public function should_mark_an_optional_course_in_the_row_and_in_words(): void {
		$this->seedTwoPaths();
		$this->setCoursesPage();

		$html = $this->render( array() );

		self::assertSame( 1, substr_count( $html, 'agend-learning-paths__course--optional' ) );
		self::assertSame( 1, substr_count( $html, '<span class="agend-learning-paths__optional">Optional</span>' ) );
	}

	#[Test]
	public function should_keep_the_order_note_in_the_header_when_the_course_list_is_off(): void {
		$this->seedTwoPaths();

		$html = $this->render( array( 'show_courses' => '' ) );

		self::assertStringNotContainsString( 'agend-learning-paths__steps', $html, 'no courses, no steps block' );
		self::assertSame( 1, substr_count( $html, 'Complete the courses in order.' ) );

		$head = strpos( $html, 'agend-learning-paths__head-text' );
		$note = strpos( $html, 'Complete the courses in order.' );
		$end  = strpos( $html, '</article>' );

		self::assertNotFalse( $head );
		self::assertTrue( $head < $note && $note < $end );
	}

	#[Test]
	public function should_leave_out_whatever_the_card_settings_switch_off(): void {
		$this->seedTwoPaths();

		$html = agend_apps_records_render_learning_paths(
			array(
				'show_image'       => '',
				'show_difficulty'  => '',
				'show_duration'    => '',
				'show_description' => '',
				'show_sequence'    => '',
				'show_heading'     => '',
			)
		);

		self::assertStringNotContainsString( '<img ', $html );
		self::assertStringNotContainsString( 'agend-learning-paths__eyebrow', $html );
		self::assertStringNotContainsString( '18 hours', $html );
		self::assertStringNotContainsString( 'About path', $html );
		self::assertStringNotContainsString( 'Complete the courses in order.', $html );
		self::assertStringNotContainsString( 'agend-learning-paths__heading', $html );
		self::assertStringContainsString( 'Web Foundations', $html, 'the path itself stays' );
	}

	#[Test]
	public function should_carry_the_layout_and_colours_as_css_variables(): void {
		$this->seedTwoPaths();

		$html = agend_apps_records_render_learning_paths(
			array(
				'inherit_colours' => '',
				'heading_colour'  => '#111111',
				'body_colour'     => '#222222',
				'accent_colour'   => '#abcdef',
				'columns_desktop' => '3',
				'columns_tablet'  => '1',
				'columns_mobile'  => '2',
				'card_radius'     => 4,
			)
		);

		self::assertStringContainsString( '--agend-lp-heading:#111111;--agend-lp-body:#222222;--agend-lp-accent:#abcdef;--agend-lp-card-radius:4px;--agend-lp-cols-desktop:3;--agend-lp-cols-tablet:1;--agend-lp-cols-mobile:2;', $html );
	}

	#[Test]
	public function should_take_its_colours_from_the_site_palette_when_inheriting(): void {
		$this->seedTwoPaths();
		Agend_Test_WP::set_filter(
			'agend_apps_records_site_palette',
			array(
				'heading' => '#010203',
				'body'    => '#040506',
				'accent'  => '#070809',
			)
		);

		$html = $this->render( array() );

		self::assertStringContainsString( '--agend-lp-heading:#010203;--agend-lp-body:#040506;--agend-lp-accent:#070809;', $html );
	}

	#[Test]
	public function should_escape_everything_the_gateway_sends(): void {
		$path = agend_lp_test_path(
			'p1',
			array(
				'title'       => '<script>alert(1)</script>Safe & Sound',
				'description' => '<b onmouseover="x">Bold</b> <script>bad()</script>text',
				'image_url'   => 'javascript:alert(1)',
			)
		);
		agend_lp_test_set_list( array( $path ) );
		agend_lp_test_set_detail( $path, array( array( 'id' => 'c', 'title' => '<img src=x onerror=1>', 'slug' => 'c', 'order_index' => 0 ) ) );

		$html = $this->render( array() );

		self::assertStringNotContainsString( '<script', $html );
		self::assertStringNotContainsString( 'javascript:', $html );
		self::assertStringNotContainsString( '<img src=x', $html );
		self::assertStringNotContainsString( '<b ', $html );
		self::assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;Safe &amp; Sound', $html );
		self::assertStringContainsString( '&lt;img src=x onerror=1&gt;', $html );
	}

	#[Test]
	public function should_use_the_slug_when_a_path_has_no_title(): void {
		agend_lp_test_set_list( array( agend_lp_test_path( 'p1', array( 'title' => '' ) ) ) );

		self::assertStringContainsString( '>path-p1</h3>', $this->render( array( 'show_courses' => '' ) ) );
	}

	// -----------------------------------------------------------------
	// Course links
	// -----------------------------------------------------------------

	#[Test]
	public function should_link_each_course_to_its_page_on_the_courses_page(): void {
		$this->seedTwoPaths();
		$this->setCoursesPage();

		$html = $this->render( array() );

		// The link's text is the course title alone: the step number is drawn by
		// the stylesheet, outside the link, so it is not part of the link's name.
		self::assertStringContainsString( '<a class="agend-learning-paths__course-link exclude-agend-theme-link" href="https://example.test/page-5/course/html-basics/">Html Basics</a>', $html );
		self::assertStringContainsString( 'href="https://example.test/page-5/course/release-pipelines/"', $html );
		self::assertStringNotContainsString( 'target="_blank"', $html );
	}

	#[Test]
	public function should_make_each_linked_row_a_whole_row_target_with_a_decorative_chevron(): void {
		$this->seedTwoPaths();
		$this->setCoursesPage();

		$html = $this->render( array() );

		self::assertSame( 4, substr_count( $html, 'agend-learning-paths__course agend-learning-paths__course--link' ), 'every course row is a linked row' );
		self::assertSame( 4, substr_count( $html, '<svg class="agend-learning-paths__chevron"' ) );
		self::assertSame( 4, substr_count( $html, 'aria-hidden="true" focusable="false"' ), 'the chevron is hidden from screen readers' );
	}

	#[Test]
	public function should_draw_no_chevron_and_no_link_row_without_links(): void {
		$this->seedTwoPaths();

		$html = $this->render( array() );

		self::assertStringNotContainsString( 'agend-learning-paths__chevron', $html );
		self::assertStringNotContainsString( 'agend-learning-paths__course--link', $html );
	}

	#[Test]
	public function should_use_the_query_string_form_without_pretty_permalinks(): void {
		$this->seedTwoPaths();
		$this->setCoursesPage();
		unset( Agend_Test_WP::$options['permalink_structure'] );

		$html = $this->render( array() );

		self::assertStringContainsString( 'href="https://example.test/page-5/?agend_course=html-basics"', $html );
	}

	#[Test]
	public function should_list_courses_without_links_when_no_courses_page_is_set(): void {
		$this->seedTwoPaths();

		$html = $this->render( array() );

		self::assertStringNotContainsString( '<a ', $html, 'this page is not a courses catalogue, so there is nowhere to send a visitor' );
		self::assertStringContainsString( '<span class="agend-learning-paths__course-title">Html Basics</span>', $html );
	}

	#[Test]
	public function should_list_courses_without_links_when_the_links_are_switched_off(): void {
		$this->seedTwoPaths();
		$this->setCoursesPage();

		$html = $this->render( array( 'course_links' => 'none' ) );

		self::assertStringNotContainsString( '<a ', $html );
		self::assertStringContainsString( 'agend-learning-paths__course-title', $html );
	}

	#[Test]
	public function should_open_course_links_in_a_new_tab_safely_and_say_so(): void {
		$this->seedTwoPaths();
		$this->setCoursesPage();

		$html = $this->render( array( 'links_new_tab' => 'yes' ) );

		self::assertStringContainsString( 'target="_blank" rel="noopener"', $html );
		self::assertStringContainsString( '(opens in a new tab)', $html );
	}

	#[Test]
	public function should_leave_a_course_without_a_slug_unlinked(): void {
		$path = agend_lp_test_path( 'p1' );
		agend_lp_test_set_list( array( $path ) );
		agend_lp_test_set_detail( $path, array( array( 'id' => 'c', 'title' => 'No Slug', 'order_index' => 0 ) ) );
		$this->setCoursesPage();

		$html = $this->render( array() );

		self::assertStringContainsString( '<span class="agend-learning-paths__course-title">No Slug</span>', $html );
		self::assertStringNotContainsString( '<a ', $html );
	}

	// -----------------------------------------------------------------
	// Empty and failed states
	// -----------------------------------------------------------------

	#[Test]
	public function should_say_so_when_there_are_no_paths(): void {
		$html = $this->render( array() );

		self::assertStringContainsString( 'role="status"', $html );
		self::assertStringContainsString( 'No learning pathways are available yet.', $html );
		self::assertStringNotContainsString( '<ul', $html );
		self::assertStringContainsString( 'Learning pathways</h2>', $html, 'the heading stays above the message' );
	}

	#[Test]
	public function should_show_the_empty_message_the_author_wrote(): void {
		$html = $this->render( array( 'empty_message' => 'Paths are <coming> soon.' ) );

		self::assertStringContainsString( 'Paths are &lt;coming&gt; soon.', $html );
		self::assertStringNotContainsString( 'No learning pathways are available yet.', $html );
	}

	#[Test]
	public function should_fall_back_to_the_default_message_when_the_author_left_it_blank(): void {
		self::assertStringContainsString( 'No learning pathways are available yet.', $this->render( array( 'empty_message' => '  ' ) ) );
	}

	#[Test]
	public function should_draw_nothing_for_an_empty_list_when_hiding_the_widget(): void {
		self::assertSame( '', $this->render( array( 'hide_when_empty' => 'yes' ) ) );
	}

	#[Test]
	public function should_draw_nothing_for_no_chosen_paths_without_calling_the_gateway(): void {
		$html = agend_apps_records_render_learning_paths(
			array(
				'path_source'     => 'selected',
				'selected_paths'  => array(),
				'hide_when_empty' => 'yes',
			)
		);

		self::assertSame( '', $html );
		self::assertSame( array(), agend_lp_test_calls() );
	}

	#[Test]
	public function should_say_it_could_not_load_when_the_list_read_fails_even_when_hiding_the_empty_widget(): void {
		$GLOBALS['agend_lp_test']['list'] = new WP_Error( 'agend_apps_http_error', 'Down.' );

		$html = $this->render( array( 'hide_when_empty' => 'yes' ) );

		self::assertStringContainsString( 'Unable to load learning pathways.', $html );
		self::assertStringContainsString( 'agend-learning-paths__status--error', $html );
		self::assertStringNotContainsString( 'No learning pathways', $html );
	}

	#[Test]
	public function should_say_it_could_not_load_when_no_chosen_path_can_be_read(): void {
		$html = agend_apps_records_render_learning_paths(
			array(
				'path_source'    => 'selected',
				'selected_paths' => array( 'gone-1', 'gone-2' ),
			)
		);

		self::assertStringContainsString( 'Unable to load learning pathways.', $html );
	}

	// -----------------------------------------------------------------
	// Gate and context
	// -----------------------------------------------------------------

	#[Test]
	public function should_hold_the_surface_to_the_paths_scope(): void {
		self::assertSame( array( 'lms.paths.browse' ), agend_apps_records_surface_requirements( 'learning-paths' )['scopes'] );
		self::assertSame( 'learning-paths', agend_apps_records_rest_route_surface( '/agend-apps/v1/lms/paths' ) );
		self::assertSame( 'learning-paths', agend_apps_records_rest_route_surface( '/agend-apps/v1/lms/paths/abc-123' ) );
		self::assertSame( '', agend_apps_records_rest_route_surface( '/agend-apps/v1/lms/paths/abc-123/progress' ), 'member routes are not the public display' );
		self::assertSame( '', agend_apps_records_rest_route_surface( '/agend-apps/v1/lms/paths/abc-123/enroll' ) );
	}

	#[Test]
	public function should_draw_nothing_and_call_nothing_when_the_key_lacks_the_scope(): void {
		$this->seedTwoPaths();
		$this->know( array( 'lms.courses.browse' ) );
		Agend_Test_WP::$requests = array();

		self::assertSame( '', $this->render( array() ) );
		self::assertSame( array(), agend_lp_test_calls() );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_render_when_the_key_holds_the_scope(): void {
		$this->seedTwoPaths();
		$this->know( array( 'lms.paths.browse' ) );

		self::assertStringContainsString( 'Web Foundations', $this->render( array() ) );
	}

	#[Test]
	public function should_need_no_sign_in(): void {
		$this->seedTwoPaths();
		Agend_Test_WP::$options['agend_apps_member_auth_mode'] = 'wordpress';
		wp_set_current_user( 0 );

		self::assertStringContainsString( 'Web Foundations', $this->render( array() ) );
		self::assertTrue( agend_lp_test_calls()[0]['anonymous'] );
	}

	#[Test]
	public function should_draw_nothing_inside_a_card_template(): void {
		$this->seedTwoPaths();
		Agend_Apps_Records_Record_Context::push( 'course', array( 'slug' => 'a-course' ) );

		self::assertSame( '', $this->render( array() ) );
		self::assertSame( array(), agend_lp_test_calls() );

		Agend_Apps_Records_Record_Context::reset();
	}

	// -----------------------------------------------------------------
	// Excerpt
	// -----------------------------------------------------------------

	/** @return array<string, array{string, int, string}> */
	public static function excerpts(): array {
		return array(
			'short text is kept whole'          => array( 'A short line.', 160, 'A short line.' ),
			'tags and runs of space are dropped' => array( "<p>One\n\n two</p>", 160, 'One two' ),
			'cut at the last whole word'        => array( 'The quick brown fox jumps over', 20, 'The quick brown fox…' ),
			'no cut mid-word when the next is a space' => array( 'The quick brown fox jumps', 19, 'The quick brown fox…' ),
			'a long word is cut where it stands' => array( 'Supercalifragilisticexpialidocious', 10, 'Supercalif…' ),
			'trailing punctuation is trimmed'    => array( 'Plan, build, and ship, then rest', 15, 'Plan, build…' ),
		);
	}

	#[Test]
	#[DataProvider( 'excerpts' )]
	public function should_cut_an_excerpt_to_length_at_a_word( string $text, int $length, string $expected ): void {
		self::assertSame( $expected, agend_apps_records_learning_paths_excerpt( $text, $length ) );
	}

	// -----------------------------------------------------------------
	// Block
	// -----------------------------------------------------------------

	#[Test]
	public function should_offer_the_same_mobile_columns_control_as_the_courses_catalogue(): void {
		$field = $this->field( agend_apps_records_surface_schema( 'learning-paths' ), 'columns_mobile' );
		$other = $this->field( agend_apps_records_surface_schema( 'courses-catalogue' ), 'columns_mobile' );

		self::assertNotNull( $field );
		self::assertSame( $other, $field, 'same name, label, options and default as the courses catalogue' );
		self::assertSame( '1', $field['default'] );
		self::assertSame( array( '1', '2' ), array_map( 'strval', array_keys( $field['options'] ) ) );
	}

	#[Test]
	public function should_tell_an_editor_the_cap_in_plain_words(): void {
		$field = $this->field( agend_apps_records_surface_schema( 'learning-paths' ), 'max_paths' );

		self::assertSame( 'Newest first. Shows up to ' . AGEND_APPS_RECORDS_LEARNING_PATHS_MAX . '.', $field['description'] );
	}

	#[Test]
	public function should_word_every_setting_for_an_association_editor(): void {
		$words = array();

		foreach ( agend_apps_records_surface_schema( 'learning-paths' )['sections'] as $section ) {
			$words[] = (string) $section['label'];

			foreach ( $section['fields'] as $field ) {
				$words[] = (string) ( $field['label'] ?? '' );
				$words[] = (string) ( $field['description'] ?? '' );
				$words[] = is_string( $field['default'] ?? null ) ? $field['default'] : '';

				if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
					$words = array_merge( $words, array_map( 'strval', array_values( $field['options'] ) ) );
				}
			}
		}

		$text = implode( "\n", $words );

		self::assertDoesNotMatchRegularExpression( '/\b(requests?|cach(e|ed|ing)|api|slugs?|ids?|endpoints?|gateway)\b/i', $text, 'no developer-speak in what an editor reads' );
		self::assertDoesNotMatchRegularExpression( '/\bpaths?\b/i', $text, 'the Agend LMS app calls them pathways' );
		self::assertStringContainsString( 'Learning Pathways', $text );
		self::assertStringContainsString( 'Learning pathways', $text, 'the default heading' );
	}

	#[Test]
	public function should_call_the_block_learning_pathways_but_keep_its_name(): void {
		foreach ( array( '/agend-apps-core/src/blocks/learning-paths/block.json', '/agend-apps-core/build/blocks/learning-paths/block.json' ) as $file ) {
			$block = json_decode( (string) file_get_contents( AGEND_TESTS_ROOT . $file ), true );

			self::assertSame( 'agend-apps/learning-paths', $block['name'], $file );
			self::assertSame( 'Agend Learning Pathways', $block['title'], $file );
			self::assertStringContainsString( 'pathways', $block['description'], $file );
		}

		self::assertStringContainsString( 'Agend Learning Pathways', (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/build/blocks/learning-paths/index.js' ), 'the built editor script carries the new label' );
	}

	/**
	 * One field of a surface schema, by name.
	 *
	 * @param array  $schema Surface schema.
	 * @param string $name   Setting name.
	 * @return array|null
	 */
	private function field( array $schema, string $name ): ?array {
		foreach ( $schema['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				if ( $name === $field['name'] ) {
					return $field;
				}
			}
		}

		return null;
	}

	#[Test]
	public function should_derive_the_block_attributes_from_the_schema(): void {
		$attributes = agend_apps_records_block_attributes( agend_apps_records_surface_schema( 'learning-paths' ) );

		self::assertSame( array( 'type' => 'string', 'default' => 'all' ), $attributes['path_source'] );
		self::assertSame( array( 'type' => 'number', 'default' => 6 ), $attributes['max_paths'] );
		self::assertSame( 'array', $attributes['selected_paths']['type'] );
		self::assertSame( array(), $attributes['selected_paths']['default'] );
		self::assertSame( array( 'type' => 'boolean', 'default' => true ), $attributes['show_courses'] );
		self::assertSame( array( 'type' => 'boolean', 'default' => false ), $attributes['links_new_tab'] );
		self::assertSame( array( 'type' => 'string', 'default' => 'detail' ), $attributes['course_links'] );
		self::assertSame( array( 'type' => 'string', 'default' => 'auto' ), $attributes['card_layout'] );
		self::assertSame( array( 'type' => 'string', 'default' => '1' ), $attributes['columns_mobile'] );
	}

	#[Test]
	public function should_render_the_block_the_same_as_the_core_renderer_at_its_defaults(): void {
		$this->seedTwoPaths();
		$schema   = agend_apps_records_surface_schema( 'learning-paths' );
		$defaults = array_map( static fn( array $a ) => $a['default'], agend_apps_records_block_attributes( $schema ) );

		$html = agend_apps_records_render_block( 'learning-paths', $defaults );

		self::assertSame( agend_apps_records_render_learning_paths( agend_apps_records_settings_from_attributes( $schema, $defaults ) ), $html );
		self::assertStringContainsString( 'Web Foundations', $html );
		self::assertStringContainsString( 'Guided sequences of courses, in the order we recommend.', $html );
	}

	#[Test]
	public function should_draw_nothing_from_a_gated_block_and_keep_its_style_off_the_page(): void {
		$this->seedTwoPaths();
		$this->know( array( 'lms.courses.browse' ) );

		self::assertSame( '', agend_apps_records_render_block( 'learning-paths', array() ) );
		self::assertSame( array(), agend_lp_test_calls() );
	}

	#[Test]
	public function should_list_the_surface_as_a_block_in_the_inserter(): void {
		self::assertContains( 'learning-paths', AGEND_APPS_RECORDS_BLOCK_SURFACES );
	}

	#[Test]
	public function should_ship_a_built_block_for_every_block_the_source_declares(): void {
		$src   = json_decode( (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/src/blocks/learning-paths/block.json' ), true );
		$build = json_decode( (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/build/blocks/learning-paths/block.json' ), true );

		self::assertSame( $src, $build );
		self::assertSame( 'agend-apps/learning-paths', $build['name'] );
		self::assertSame( array( 'agend-apps-records-learning-paths' ), $build['style'] );
		self::assertArrayNotHasKey( 'viewScript', $build, 'the surface is server-rendered and ships no script' );
		self::assertFileExists( AGEND_TESTS_ROOT . '/agend-apps-core/build/blocks/learning-paths/index.js' );
		self::assertFileExists( AGEND_TESTS_ROOT . '/agend-apps-core/assets/css/learning-paths.css' );
	}
}

<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use Agend_Test_WP;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

/**
 * The catalogue markup moved out of the Elementor widgets into core. The
 * fixtures were recorded from the widgets BEFORE the move, so equality here
 * is the proof that neither the core renderer nor the delegating widget
 * changed a byte of what a site receives.
 *
 * Each case runs in its own process: the render doubles define the gateway
 * list functions, and other tests in this suite assert those do NOT exist.
 */
#[RunTestsInSeparateProcesses]
final class CatalogueRenderTest extends TestCase {

	private const SURFACES = array(
		'events'      => array( 'Agend_Elementor_Events_Catalogue', 'event' ),
		'courses'     => array( 'Agend_Elementor_Courses_Catalogue', 'course' ),
		'directory'   => array( 'Agend_Elementor_Directory_Catalogue', 'listing' ),
		'memberships' => array( 'Agend_Elementor_Memberships_Catalogue', 'membership' ),
	);

	private const CUSTOM = array(
		'show_heading'          => '',
		'heading_text'          => 'Custom <Heading>',
		'subheading_text'       => 'Sub & text',
		'columns_desktop'       => '4',
		'columns_tablet'        => '1',
		'columns_mobile'        => '2',
		'card_radius'           => 0,
		'show_image'            => '',
		'show_description'      => 'yes',
		'excerpt_length'        => 50,
		'show_search'           => '',
		'multi_category_filter' => 'yes',
		'exclude_categories'    => array( 'c1', '', 'c2' ),
		'exclude_cities'        => array( 'Perth' ),
		'event_timeframe'       => 'past',
		'pagination_style'      => 'load_more',
		'per_page'              => 12,
		'heading_colour'        => '#111111',
		'accent_colour'         => '#222222',
		// Set alongside the manual colour values above (US-1.3): with
		// inherit_colours left at its schema default of 'yes', the resolver
		// would supersede these two values with the resolved site/Agend
		// colours, which is the very behaviour this story adds. A settings
		// map with manual colours and inheritance still on is precisely the
		// state a real UI cannot produce (US-1.2 hides the manual fields
		// while inherit is on), so 'no' here matches what an editor who set
		// these actually did.
		'inherit_colours'       => '',
		'inherit_fonts'         => '',
		'layout_style'          => 'list',
		'featured_only'         => 'yes',
		'show_rating'           => '',
		'course_level'          => 'advanced',
		'heading'               => 'Join us',
		'columns'               => '2',
	);

	/** @return array<string, array{string, string}> */
	public static function scenarios(): array {
		$cases = array();
		foreach ( array_keys( self::SURFACES ) as $surface ) {
			foreach ( array( 'defaults', 'custom', 'templated', 'deeplink_dedicated' ) as $scenario ) {
				$cases[ "$surface/$scenario" ] = array( $surface, $scenario );
			}
		}
		return $cases;
	}

	#[Test]
	#[DataProvider( 'scenarios' )]
	public function should_render_the_recorded_markup_when_core_renders_the_surface_directly( string $surface, string $scenario ): void {
		$this->arrange( $surface, $scenario );
		$render = 'agend_apps_records_render_' . $surface . '_catalogue';

		self::assertSame( $this->expected( $surface, $scenario ), $render( $this->settings( $surface, $scenario ) ) );
	}

	#[Test]
	#[DataProvider( 'scenarios' )]
	public function should_render_the_recorded_markup_when_the_elementor_widget_delegates_to_core( string $surface, string $scenario ): void {
		$this->arrange( $surface, $scenario );
		$class  = self::SURFACES[ $surface ][0];
		$widget = new $class( array(), null, $this->settings( $surface, $scenario ) );

		ob_start();
		( function () {
			$this->render();
		} )->call( $widget );

		self::assertSame( $this->expected( $surface, $scenario ), (string) ob_get_clean() );
	}

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/tests/render-doubles.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		foreach ( array_keys( self::SURFACES ) as $surface ) {
			require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/' . $surface . '-catalogue.php';
			require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-' . $surface . '-catalogue.php';
		}
	}

	private function settings( string $surface, string $scenario ): array {
		switch ( $scenario ) {
			case 'custom':
				return self::CUSTOM;
			case 'templated':
				return array_merge( self::CUSTOM, array( 'show_heading' => 'yes', 'card_template' => '7', 'filter_template' => '8', 'card_link_whole' => '', 'filter_position' => 'left' ) );
			default:
				return array();
		}
	}

	private function arrange( string $surface, string $scenario ): void {
		agend_render_test_reset();
		$type = self::SURFACES[ $surface ][1];

		if ( 'templated' === $scenario ) {
			Agend_Test_WP::$query_vars[ $type ] = 'deep-slug';
		}
		if ( 'deeplink_dedicated' === $scenario ) {
			Agend_Test_WP::$query_vars[ $type ] = 'gala-2026';
			$option = array( 'event' => 'agend_elementor_events_page_id', 'course' => 'agend_elementor_courses_page_id', 'listing' => 'agend_elementor_directory_page_id' )[ $type ] ?? null;
			if ( $option ) {
				Agend_Test_WP::$options[ $option ] = 42;
			}
		}
	}

	private function expected( string $surface, string $scenario ): string {
		$fixtures = json_decode( (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/' . $surface . '-catalogue-render.json' ), true );

		return $fixtures[ $scenario ];
	}
}

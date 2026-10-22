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
			// The widgets ask this class for the surface gate and their asset handles.
			require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-schema-controls.php';
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

	#[Test]
	public function should_render_the_restored_search_when_the_request_carries_a_search_link(): void {
		$config = $this->renderLink( 'rating=4&q=physio&sort=name&utm_source=mail' );

		self::assertSame( array( array( 'page' => 1, 'rating' => '4', 'sortBy' => 'name' ) ), $this->searched( array( 'page', 'rating', 'sortBy' ) ) );
		self::assertSame( array( 'state' => array( 'search' => 'physio', 'rating' => '4', 'sortBy' => 'name' ), 'page' => 1, 'shown' => 1 ), $config['searchLink'] );
	}

	#[Test]
	public function should_render_the_requested_page_when_the_link_carries_pg(): void {
		$config = $this->renderLink( 'rating=4&pg=3' );

		self::assertSame( array( array( 'page' => 3 ) ), $this->searched( array( 'page' ) ) );
		self::assertSame( 3, $config['searchLink']['shown'] );
	}

	#[Test]
	public function should_render_page_1_of_the_restored_search_when_pg_is_past_its_last_page(): void {
		$config = $this->renderLink( 'rating=4&pg=5' );

		self::assertSame( array( array( 'page' => 5, 'rating' => '4' ), array( 'page' => 1, 'rating' => '4' ) ), $this->searched( array( 'page', 'rating' ) ) );
		self::assertSame( array( 5, 1 ), array( $config['searchLink']['page'], $config['searchLink']['shown'] ) );
	}

	#[Test]
	public function should_render_the_default_page_when_no_control_writes_the_linked_value(): void {
		$config = $this->renderLink( 'cf_unknown=x&featured_only=true&utm_source=mail' );

		self::assertSame( array( array( 'page' => 1 ) ), $this->searched( array( 'page', 'custom_fields', 'featured' ) ) );
		self::assertArrayNotHasKey( 'searchLink', $config );
	}

	#[Test]
	public function should_render_the_default_page_when_every_linked_value_is_invalid(): void {
		$config = $this->renderLink( 'rating=9&sort=price&pg=0' );

		self::assertSame( array( array( 'page' => 1, 'sortBy' => 'relevance' ) ), $this->searched( array( 'page', 'rating', 'sortBy' ) ) );
		self::assertArrayNotHasKey( 'searchLink', $config );
	}

	#[Test]
	public function should_ask_one_query_when_two_links_differ_only_in_parameter_order(): void {
		$this->renderLink( 'cf_funding=ndis&rating=4&cf_education_level=masters' );
		$first = $GLOBALS['agend_render_test_directory_search_args'];
		$this->renderLink( 'cf_education_level=masters&rating=4&cf_funding=ndis' );

		self::assertSame( $first, $GLOBALS['agend_render_test_directory_search_args'] );
		self::assertSame( array( 'education_level', 'funding' ), array_keys( $first[0]['custom_fields'] ) );
	}

	#[Test]
	public function should_hand_the_browser_the_unslashed_state_when_the_link_has_a_backslash(): void {
		$config = $this->renderLink( 'location_name=St+Vincent%27s+%5C+Co' );

		self::assertSame( "St Vincent's \\ Co", $config['searchLink']['state']['location_name'] );
		self::assertSame( "St Vincent's \\ Co", $GLOBALS['agend_render_test_directory_search_args'][0]['locationName'] );
	}

	/**
	 * Renders a templated directory catalogue whose filter template draws a
	 * rating, sort, search, Organisation and two custom field filters, for a
	 * request carrying `$query`, and returns the catalogue's client config.
	 */
	private function renderLink( string $query ): array {
		agend_render_test_reset();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/filter.php';
		$adapter = new class() implements \Agend_Apps_Template_Renderer, \Agend_Apps_Template_Source {
			public function is_valid_template( int $template_id ): bool {
				return $template_id > 0 && $template_id < 100;
			}
			public function ensure_styles( int $template_id ): void {}
			public function render( int $template_id, string $type, array $record, array $extra = array(), bool $with_css = false ): string {
				return '<p>' . ( $record['slug'] ?? '' ) . '</p>';
			}
			public function render_plain( int $template_id, bool $with_css = false ): string {
				$html = '';
				foreach ( array( array( 'filter' => 'listing:rating' ), array( 'filter' => 'listing:sort' ), array( 'filter' => 'listing:search' ), array( 'filter' => 'listing:organisation' ), array( 'filter' => 'listing:custom_field', 'custom_field_key' => 'funding', 'control' => 'checkboxes' ), array( 'filter' => 'listing:custom_field', 'custom_field_key' => 'education_level' ) ) as $settings ) {
					$html .= agend_apps_records_render_filter( $settings );
				}
				return $html;
			}
			public function label(): string {
				return 'Test';
			}
			public function templates(): array {
				return array( '7' => 'Card', '8' => 'Filters' );
			}
			public function page_contains_surface( int $page_id, string $surface ): ?bool {
				return null;
			}
		};
		\Agend_Apps_Templates::reset();
		\Agend_Apps_Templates::register_renderer( $adapter );
		\Agend_Apps_Templates::register_source( $adapter );

		parse_str( $query, $params );
		$_GET = array_map( 'addslashes', $params );
		try {
			$html = agend_apps_records_render_directory_catalogue( array( 'card_template' => '7', 'filter_template' => '8' ) );
		} finally {
			$_GET = array();
		}

		self::assertSame( 1, preg_match( '/data-agend-directory-config="([^"]*)"/', $html, $match ) );
		return json_decode( html_entity_decode( $match[1], ENT_QUOTES ), true );
	}

	/**
	 * The named parts of every search the render asked the gateway for.
	 *
	 * @param string[] $keys Query keys to keep.
	 */
	private function searched( array $keys ): array {
		return array_map(
			static function ( array $args ) use ( $keys ): array {
				return array_intersect_key( $args, array_flip( $keys ) );
			},
			$GLOBALS['agend_render_test_directory_search_args']
		);
	}

	private function expected( string $surface, string $scenario ): string {
		$fixtures = json_decode( (string) file_get_contents( AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/' . $surface . '-catalogue-render.json' ), true );

		return $fixtures[ $scenario ];
	}
}

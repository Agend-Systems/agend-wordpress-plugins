<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Apps_Key_Scopes;
use Agend_Elementor_Directory_Catalogue;
use Agend_Elementor_Events_Catalogue;
use Agend_Elementor_Export_Reports;
use Agend_Elementor_Filter;
use Agend_Elementor_Record_Block;
use Agend_Elementor_Record_Field;
use Agend_Elementor_Schema_Controls;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/health.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-key-scopes.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/features.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/surface-gate.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/preview-records.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/directory-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/events-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/export-reports.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/record-field.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/record-block.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/filter.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-schema-controls.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-field-widget-trait.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-events-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-directory-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-export-reports.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-record-field.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-record-block.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/widgets/class-agend-elementor-filter.php';
require_once __DIR__ . '/Content_Tab_Recording_Trait.php';

/**
 * Elementor wiring of the surface gate: a widget whose key lacks the scopes it
 * needs stays registered, shows a disabled box in the editor, renders nothing
 * for a visitor, and loads nothing.
 */
#[CoversClass( Agend_Elementor_Schema_Controls::class )]
final class SurfaceGateWidgetsTest extends TestCase {

	use Content_Tab_Recording_Trait;

	/** Widget classes mapped to the surface they gate on and the scope they need. */
	private const FIXED = array(
		array( Agend_Elementor_Events_Catalogue::class, 'events-catalogue', 'events.events.browse' ),
		array( Agend_Elementor_Directory_Catalogue::class, 'directory-catalogue', 'directory.listings.search' ),
		array( Agend_Elementor_Export_Reports::class, 'export-reports', 'directory.export_reports.run' ),
	);

	protected function setUp(): void {
		parent::setUp();
		// Loaded here rather than at the top of the file: the doubles declare the
		// gateway wrappers, which other suites rely on being absent until a test
		// that wants them asks.
		require_once AGEND_TESTS_ROOT . '/tests/render-doubles.php';
		agend_render_test_reset();
	}

	private function know( array $scopes, array $app_ids = array() ): void {
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => $scopes, 'app_ids' => $app_ids ) ) );
	}

	private function editor( bool $on ): void {
		\Elementor\Plugin::$instance->editor->is_edit_mode = $on;
	}

	/**
	 * Renders a widget the way Elementor does, capturing its output.
	 *
	 * @param class-string         $class    Widget class.
	 * @param array<string, mixed> $settings Settings the widget displays.
	 */
	private function render( string $class, array $settings = array() ): string {
		$widget = new $class( array(), null, $settings );

		ob_start();
		( function () {
			$this->render();
		} )->call( $widget );

		return (string) ob_get_clean();
	}

	/**
	 * @return array<int, array<string, mixed>> The Content-tab sections the widget registers.
	 */
	private function sections( string $class ): array {
		$widget = new $class();
		( function () {
			$this->register_controls();
		} )->call( $widget );

		return $this->contentTabRecordings( $widget->recordings )['sections'];
	}

	#[Test]
	public function should_render_nothing_for_a_visitor_and_make_no_call_when_gated(): void {
		$this->know( array( 'crm.contacts.browse' ) );
		Agend_Test_WP::$requests = array();

		foreach ( self::FIXED as list( $class ) ) {
			self::assertSame( '', $this->render( $class ), $class );
		}

		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_render_a_disabled_box_naming_the_scope_in_the_editor(): void {
		$this->know( array( 'crm.contacts.browse' ) );
		$this->editor( true );

		foreach ( self::FIXED as list( $class, , $scope ) ) {
			$html = $this->render( $class );

			self::assertStringContainsString( 'agend-surface-gate', $html, $class );
			self::assertStringContainsString( $scope, $html, $class );
		}
	}

	#[Test]
	public function should_show_the_box_in_the_preview_frame_too(): void {
		$this->know( array( 'crm.contacts.browse' ) );
		\Elementor\Plugin::$instance->preview->is_preview_mode = true;

		self::assertStringContainsString( 'agend-surface-gate', $this->render( Agend_Elementor_Events_Catalogue::class ) );
	}

	#[Test]
	public function should_render_normally_when_the_key_holds_the_scopes_or_is_unknown(): void {
		// Unknown: fail open.
		self::assertStringContainsString( 'agend-events', $this->render( Agend_Elementor_Events_Catalogue::class ) );

		$this->know( array( 'events.events.browse' ) );
		self::assertStringContainsString( 'agend-events', $this->render( Agend_Elementor_Events_Catalogue::class ) );
		self::assertStringNotContainsString( 'agend-surface-gate', $this->render( Agend_Elementor_Events_Catalogue::class ) );
	}

	#[Test]
	public function should_keep_a_gated_widget_registered_in_the_panel(): void {
		$this->know( array( 'crm.contacts.browse' ) );

		foreach ( self::FIXED as list( $class, $surface ) ) {
			$sections = $this->sections( $class );

			self::assertNotEmpty( $sections, $class . ' still declares its controls' );

			$first = $sections[0]['controls'][0];

			self::assertSame( 'agend_surface_gate_notice', $first['id'], $class );
			self::assertSame( 'raw_html', $first['args']['type'] );
			self::assertStringContainsString( 'API key scope', $first['args']['raw'] );
			self::assertSame( agend_apps_records_surface_notice( $surface ), html_entity_decode( $first['args']['raw'] ) );
		}
	}

	#[Test]
	public function should_lead_only_the_first_content_section_with_the_notice(): void {
		$this->know( array( 'crm.contacts.browse' ) );

		$count = 0;
		foreach ( $this->sections( Agend_Elementor_Events_Catalogue::class ) as $section ) {
			foreach ( $section['controls'] as $control ) {
				$count += 'agend_surface_gate_notice' === $control['id'] ? 1 : 0;
			}
		}

		self::assertSame( 1, $count );
	}

	#[Test]
	public function should_add_no_notice_when_the_surface_is_open(): void {
		$this->know( array( 'events.events.browse' ) );

		self::assertNotSame( 'agend_surface_gate_notice', $this->sections( Agend_Elementor_Events_Catalogue::class )[0]['controls'][0]['id'] );
	}

	#[Test]
	public function should_not_call_the_gateway_for_option_lists_while_gated(): void {
		\Elementor\Plugin::$instance->editor->is_edit_mode = true;

		$options_of = function ( string $name ): array {
			foreach ( $this->sections( Agend_Elementor_Events_Catalogue::class ) as $section ) {
				foreach ( $section['controls'] as $control ) {
					if ( $name === $control['id'] ) {
						return $control['args']['options'];
					}
				}
			}

			return array( 'missing' );
		};

		$this->know( array( 'events.events.browse' ) );
		self::assertSame( array( '1' => 'Conferences', '2' => 'Workshops' ), $options_of( 'exclude_categories' ) );

		$this->know( array( 'crm.contacts.browse' ) );
		self::assertSame( array(), $options_of( 'exclude_categories' ) );
		self::assertSame( array(), $options_of( 'exclude_cities' ) );
	}

	#[Test]
	public function should_load_no_script_or_style_while_gated(): void {
		$widget = new Agend_Elementor_Events_Catalogue();

		self::assertSame( array( 'agend-apps-records-events-catalogue' ), $widget->get_script_depends(), 'open' );

		$this->know( array( 'crm.contacts.browse' ) );

		self::assertSame( array(), $widget->get_script_depends() );
		self::assertSame( array(), $widget->get_style_depends() );

		$directory = new Agend_Elementor_Directory_Catalogue();
		self::assertSame( array(), $directory->get_script_depends() );
	}

	#[Test]
	public function should_leave_other_widgets_assets_alone(): void {
		$this->know( array( 'directory.listings.search' ) );

		self::assertSame( array( 'agend-apps-records-directory-catalogue' ), ( new Agend_Elementor_Directory_Catalogue() )->get_script_depends() );
		self::assertSame( array(), ( new Agend_Elementor_Events_Catalogue() )->get_script_depends() );
	}

	#[Test]
	public function should_enqueue_only_the_surfaces_the_key_can_use(): void {
		require_once AGEND_TESTS_ROOT . '/agend-elementor/agend-elementor.php';
		Agend_Test_WP::$did_action['elementor/loaded'] = 1;
		$this->know( array( 'directory.listings.search' ) );

		agend_elementor_enqueue_scripts();

		self::assertContains( 'agend-apps-records-directory-catalogue', Agend_Test_WP::$enqueued_scripts );
		self::assertContains( 'agend-apps-records-directory-catalogue', Agend_Test_WP::$enqueued_styles );
		self::assertNotContains( 'agend-apps-records-events-catalogue', Agend_Test_WP::$enqueued_scripts );
		self::assertNotContains( 'agend-apps-records-events-catalogue', Agend_Test_WP::$enqueued_styles );
		self::assertNotContains( 'agend-apps-records-courses-catalogue', Agend_Test_WP::$enqueued_scripts );
		self::assertNotContains( 'agend-apps-records-export-reports', Agend_Test_WP::$enqueued_scripts );
		// Shared by the record surfaces, which have no scope of their own.
		self::assertContains( 'agend-apps-records-record-fields', Agend_Test_WP::$enqueued_scripts );
	}

	#[Test]
	public function should_enqueue_every_surface_when_the_scopes_are_unknown(): void {
		require_once AGEND_TESTS_ROOT . '/agend-elementor/agend-elementor.php';
		Agend_Test_WP::$did_action['elementor/loaded'] = 1;

		agend_elementor_enqueue_scripts();

		foreach ( array( 'export-reports', 'events-catalogue', 'courses-catalogue', 'directory-catalogue', 'account-link', 'member-login', 'memberships-catalogue', 'header-auth' ) as $surface ) {
			self::assertContains( 'agend-apps-records-' . $surface, Agend_Test_WP::$enqueued_scripts, $surface );
		}
	}

	// -----------------------------------------------------------------
	// Record widgets and the filter inherit their catalogue
	// -----------------------------------------------------------------

	#[Test]
	public function should_gate_a_record_widget_by_the_catalogue_of_the_type_it_previews(): void {
		$this->know( array( 'directory.listings.search' ) );
		$this->editor( true );

		$html = $this->render( Agend_Elementor_Record_Field::class, array( 'field' => 'event:name' ) );

		self::assertStringContainsString( 'agend-surface-gate', $html );
		self::assertStringContainsString( 'events.events.browse', $html );
		self::assertCount( 0, Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_render_nothing_for_a_visitor_from_a_gated_record_widget(): void {
		$this->know( array( 'crm.contacts.browse' ) );
		\Agend_Apps_Records_Record_Context::push( 'event', array( 'slug' => 'gala', 'name' => 'Gala' ) );

		self::assertSame( '', $this->render( Agend_Elementor_Record_Field::class, array( 'field' => 'event:name' ) ) );

		\Agend_Apps_Records_Record_Context::reset();
	}

	#[Test]
	public function should_hold_the_tickets_panel_to_the_tickets_scope(): void {
		$this->know( array( 'events.events.browse' ) );
		$this->editor( true );

		$html = $this->render( Agend_Elementor_Record_Block::class, array( 'block' => 'event_tickets' ) );

		self::assertStringContainsString( 'agend-surface-gate', $html );
		self::assertStringContainsString( 'events.tickets.browse', $html );
		self::assertStringNotContainsString( 'agend-surface-gate', $this->render( Agend_Elementor_Record_Block::class, array( 'block' => 'event_facts' ) ) );
	}

	#[Test]
	public function should_gate_a_filter_by_the_catalogue_it_belongs_to(): void {
		$this->know( array( 'events.events.browse' ) );
		$this->editor( true );

		$html = $this->render( Agend_Elementor_Filter::class, array( 'filter' => 'listing:category' ) );

		self::assertStringContainsString( 'agend-surface-gate', $html );
		self::assertStringContainsString( 'directory.listings.search', $html );
		self::assertStringNotContainsString( 'agend-surface-gate', $this->render( Agend_Elementor_Filter::class, array( 'filter' => 'event:category' ) ) );
	}
}

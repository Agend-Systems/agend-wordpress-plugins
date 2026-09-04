<?php
/**
 * Doubles for driving a catalogue surface's server render end to end: a
 * template adapter, the gateway list calls, and the request context.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/interface-agend-apps-template-renderer.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/interface-agend-apps-template-source.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/class-agend-apps-templates.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/pages.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/assets.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/record-context.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/filters.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/query.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/cards.php';

/** Renders "<p>slug</p>" per record, and a marker for a plain template; every id from 1 to 99 is valid. */
final class Agend_Render_Test_Adapter implements Agend_Apps_Template_Renderer, Agend_Apps_Template_Source {
	public function is_valid_template( int $template_id ): bool {
		return $template_id > 0 && $template_id < 100;
	}
	public function ensure_styles( int $template_id ): void {}
	public function render( int $template_id, string $type, array $record, array $extra = array(), bool $with_css = false ): string {
		return '<p data-template="' . $template_id . '" data-type="' . $type . '">' . ( $record['slug'] ?? $record['id'] ?? '' ) . '</p>';
	}
	public function render_plain( int $template_id, bool $with_css = false ): string {
		return '<nav data-plain-template="' . $template_id . '"></nav>';
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
}

function agend_render_test_list( string $kind ): array {
	return array(
		'data' => array(
			array( 'id' => 'a1', 'slug' => $kind . '-one', 'name' => ucfirst( $kind ) . ' One', 'title' => ucfirst( $kind ) . ' One' ),
			array( 'id' => 'a2', 'slug' => $kind . '-two', 'name' => ucfirst( $kind ) . ' Two', 'title' => ucfirst( $kind ) . ' Two' ),
		),
		'meta' => array( 'pagination' => array( 'page' => 1, 'total_pages' => 3, 'total' => 25 ) ),
	);
}

if ( ! function_exists( 'agend_apps_events_get_events' ) ) {
	function agend_apps_events_get_events( array $args = array() ) {
		return agend_render_test_list( 'event' );
	}
}
if ( ! function_exists( 'agend_apps_lms_get_courses' ) ) {
	function agend_apps_lms_get_courses( array $args = array() ) {
		return agend_render_test_list( 'course' );
	}
}
if ( ! function_exists( 'agend_apps_directory_search' ) ) {
	function agend_apps_directory_search( string $term = '', array $args = array() ) {
		return agend_render_test_list( 'listing' );
	}
}

/** Resets the registry and request context to the state every render snapshot assumes. */
function agend_render_test_reset(): void {
	Agend_Test_WP::reset();
	Agend_Apps_Templates::reset();
	$adapter = new Agend_Render_Test_Adapter();
	Agend_Apps_Templates::register_renderer( $adapter );
	Agend_Apps_Templates::register_source( $adapter );
	Agend_Test_WP::$queried_object_id = 42;
	Agend_Test_WP::$options['permalink_structure'] = '/%postname%/';
}

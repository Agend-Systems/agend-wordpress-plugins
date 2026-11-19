<?php
/**
 * Test fixtures for the Resources surfaces: the answers the gateway gives for
 * collections, search and Ask, built from the response schemas the gateway
 * publishes (WDAA is not entitled to Ask, so no real answer exists to copy),
 * and the loading of what the surfaces need.
 *
 * The gateway itself is the suite's HTTP double (`Agend_Test_WP::queue_response()`),
 * so the real wrappers run. Global functions, in one file, so the core and
 * Elementor suites can both load it.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

/**
 * Loads everything the Resources surfaces and routes need, once per process.
 */
function agend_res_test_load(): void {
	static $loaded = false;

	if ( $loaded ) {
		return;
	}

	$loaded = true;
	$root   = AGEND_TESTS_ROOT . '/agend-apps-core/';

	require_once AGEND_TESTS_ROOT . '/tests/render-doubles.php';
	require_once $root . 'includes/logging/class-agend-apps-log-redactor.php';
	require_once $root . 'includes/logging/class-agend-apps-log-store.php';
	require_once $root . 'includes/logging/class-agend-apps-logger.php';
	require_once $root . 'includes/api/health.php';
	require_once $root . 'includes/class-agend-apps-http-batch.php';
	require_once $root . 'includes/class-agend-apps-key-scopes.php';
	require_once $root . 'includes/class-agend-apps-endpoint-scopes.php';
	require_once $root . 'includes/account-link-state.php';
	require_once $root . 'includes/api/atlas.php';
	require_once $root . 'includes/records/features.php';
	require_once $root . 'includes/records/palette.php';
	require_once $root . 'includes/records/schema.php';
	require_once $root . 'includes/records/blocks.php';
	require_once $root . 'includes/records/render/resources-collections.php';
	require_once $root . 'includes/records/render/resources-search.php';
	require_once $root . 'includes/records/render/resources-ask.php';
	require_once $root . 'includes/rest/class-agend-apps-rest-controller.php';
	require_once $root . 'includes/rest/resources-routes.php';
	require_once $root . 'tests/fixtures/memory-log-store.php';
	require_once $root . 'tests/fixtures/wp-hash-stub.php';
}

/**
 * Puts the stubs back to a clean site connected to a staging account called
 * `wdaa`, signed out.
 */
function agend_res_test_reset(): void {
	agend_res_test_load();
	agend_render_test_reset();
	Agend_Apps_Logger::set_instance( new Agend_Apps_Logger( new Agend\Tests\AppsCore\MemoryLogStore() ) );
	Agend_Apps_Endpoint_Scopes::set_map( null );
	Agend_Apps_Records_Record_Context::reset();
	// The settings class is a double whose root URL is this option.
	update_option( 'agend_apps_root_url_for_tests', 'https://api.agend.info' );
	update_option( 'agend_apps_account_slug', 'wdaa' );
	// Several answers in a row are sent one after another through the double.
	Agend_Test_WP::set_filter( 'agend_apps_api_parallel', false );
	unset( $_SERVER['REMOTE_ADDR'] );
}

/**
 * Tells the key-scope store which scopes the stored key holds.
 *
 * @param string[] $scopes Scopes.
 */
function agend_res_test_know( array $scopes ): void {
	Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => $scopes, 'app_ids' => array() ) ) );
}

/**
 * A surface's settings as an editor hands the renderer: the schema's defaults,
 * in the Elementor shape, with the test's changes over them.
 *
 * @param string $surface   Surface id.
 * @param array  $overrides Settings to change.
 * @return array
 */
function agend_res_test_settings( string $surface, array $overrides = array() ): array {
	$schema   = agend_apps_records_surface_schema( $surface );
	$defaults = array_map( static fn( array $a ) => $a['default'], agend_apps_records_block_attributes( $schema ) );

	return $overrides + agend_apps_records_settings_from_attributes( $schema, $defaults );
}

/**
 * A collection as `GET /atlas/collections` sends it. The counts and dates are
 * recognisable on purpose, so a test can show they are never printed.
 *
 * @param string $id        Collection id.
 * @param array  $overrides Fields to set over the defaults.
 * @return array
 */
function agend_res_test_collection( string $id, array $overrides = array() ): array {
	return $overrides + array(
		'id'          => $id,
		'title'       => 'Collection ' . $id,
		'description' => 'About collection ' . $id . '.',
		'topics'      => array(),
		'item_count'  => 4721,
		'created_at'  => '2026-03-05T00:00:00.000Z',
		'updated_at'  => '2026-06-01T00:00:00.000Z',
	);
}

/**
 * A topic as the gateway sends it.
 *
 * @param string $name Topic name.
 * @return array
 */
function agend_res_test_topic( string $name ): array {
	return array(
		'id'                 => md5( $name ),
		'slug'               => sanitize_title( $name ),
		'name'               => $name,
		'canonical_topic_id' => null,
	);
}

/**
 * Queues the gateway's answer to the collection list.
 *
 * @param array $collections Collections (see agend_res_test_collection()).
 */
function agend_res_test_queue_collections( array $collections ): void {
	Agend_Test_WP::queue_response(
		200,
		array(
			'success' => true,
			'data'    => $collections,
			'meta'    => array( 'pagination' => array( 'page' => 1, 'total_pages' => 1, 'total' => count( $collections ) ) ),
		)
	);
}

/**
 * A collection as `GET /atlas/me/collections` sends it: no description, the
 * pages a member can open.
 *
 * @param string   $id     Collection id.
 * @param string   $title  Title.
 * @param string[] $pages  Page titles, in order.
 * @return array
 */
function agend_res_test_member_collection( string $id, string $title, array $pages = array() ): array {
	$items = array();

	foreach ( array_values( $pages ) as $position => $page_title ) {
		$items[] = array(
			'page_id'  => '11111111-1111-4111-8111-' . str_pad( (string) ( $position + 1 ), 12, '0', STR_PAD_LEFT ),
			'title'    => $page_title,
			'position' => $position,
		);
	}

	return array(
		'id'          => $id,
		'title'       => $title,
		'description' => null,
		'topics'      => array(),
		'items'       => $items,
	);
}

/**
 * A search hit as `GET /atlas/search` sends it.
 *
 * @param string $id        The page's lineage id (`document_id`).
 * @param string $title     Title.
 * @param string $excerpt   Excerpt.
 * @param array  $overrides Fields to set over the defaults.
 * @return array
 */
function agend_res_test_hit( string $id, string $title, string $excerpt = 'An excerpt.', array $overrides = array() ): array {
	return $overrides + array(
		'chunk_id'            => 'c-' . $id,
		'document_id'         => $id,
		'document_version_id' => 'v-' . $id,
		'document_title'      => $title,
		'document_kind'       => 'page',
		'version_major'       => 1,
		'version_minor'       => 0,
		'last_reviewed_date'  => null,
		'section_id'          => null,
		'page_number'         => null,
		'char_start'          => null,
		'char_end'            => null,
		'excerpt'             => $excerpt,
		'score'               => 0.5,
	);
}

/**
 * Queues a successful gateway answer with the given body under `data`.
 *
 * @param mixed $data The `data` of the response.
 */
function agend_res_test_queue_data( $data ): void {
	Agend_Test_WP::queue_response( 200, array( 'success' => true, 'data' => $data ) );
}

/** A lineage id for the nth page. */
function agend_res_test_id( int $n ): string {
	return '22222222-2222-4222-8222-' . str_pad( (string) $n, 12, '0', STR_PAD_LEFT );
}

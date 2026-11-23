<?php
/**
 * Test fixture: declares the two gateway calls the learning paths surface
 * reads, `agend_apps_lms_get_paths()` and `agend_apps_lms_get_paths_by_id()`,
 * answering from `$GLOBALS['agend_lp_test']` and recording every call in it, so
 * a test can say what was asked for (and that nothing was, for a gated
 * surface) as well as what came back.
 *
 * Function declarations cannot be nested inside a test method, so this lives
 * in its own plain file, required by the test classes that need it. They run in
 * separate processes, so the functions never reach any other test, which relies
 * on them being absent.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

/**
 * Clears the canned answers and the recorded calls.
 */
function agend_lp_test_reset(): void {
	$GLOBALS['agend_lp_test'] = array(
		'list'    => array(
			'data' => array(),
			'meta' => array( 'pagination' => array( 'page' => 1, 'total_pages' => 1, 'total' => 0 ) ),
		),
		'details' => array(),
		'calls'   => array(),
	);
}

/**
 * A path as the gateway's list sends it.
 *
 * @param string $id        Path id.
 * @param array  $overrides Fields to set over the defaults.
 * @return array
 */
function agend_lp_test_path( string $id, array $overrides = array() ): array {
	return $overrides + array(
		'id'                       => $id,
		'title'                    => 'Path ' . $id,
		'slug'                     => 'path-' . $id,
		'description'              => 'About path ' . $id . '.',
		'difficulty'               => 'beginner',
		'is_sequential'            => false,
		'published'                => true,
		'image_url'                => null,
		'estimated_duration_hours' => null,
		'owner_account_id'         => null,
		'created_at'               => '2026-11-01T00:00:00.000Z',
	);
}

/**
 * A course as the gateway's path detail sends it.
 *
 * @param string $slug      Course slug; the title is derived from it.
 * @param int    $order     `order_index`.
 * @param bool   $optional  `is_optional`.
 * @return array
 */
function agend_lp_test_course( string $slug, int $order, bool $optional = false ): array {
	return array(
		'id'          => 'course-' . $slug,
		'title'       => ucwords( str_replace( '-', ' ', $slug ) ),
		'slug'        => $slug,
		'order_index' => $order,
		'is_optional' => $optional,
		'is_unlocked' => true,
		'enrollment'  => null,
	);
}

/**
 * Sets what the single-path read answers for a path: its detail, wrapped in
 * `data` as the gateway sends it.
 *
 * @param array $path    The path (see agend_lp_test_path()).
 * @param array $courses Its courses, in the order the gateway sends them.
 */
function agend_lp_test_set_detail( array $path, array $courses ): void {
	$GLOBALS['agend_lp_test']['details'][ $path['id'] ] = array( 'success' => true, 'data' => $path + array( 'courses' => $courses ) );
}

/**
 * Sets what the list read answers.
 *
 * @param array[] $paths Paths as the list sends them.
 */
function agend_lp_test_set_list( array $paths ): void {
	$GLOBALS['agend_lp_test']['list'] = array(
		'success' => true,
		'data'    => $paths,
		'meta'    => array( 'pagination' => array( 'page' => 1, 'total_pages' => 1, 'total' => count( $paths ) ) ),
	);
}

/**
 * The calls recorded so far, of one kind or all.
 *
 * @param string $kind 'list', 'by_id' or '' for both.
 * @return array[]
 */
function agend_lp_test_calls( string $kind = '' ): array {
	$calls = $GLOBALS['agend_lp_test']['calls'];

	return '' === $kind ? $calls : array_values( array_filter( $calls, static fn( array $call ): bool => $kind === $call['kind'] ) );
}

agend_lp_test_reset();

if ( ! function_exists( 'agend_apps_lms_get_paths' ) ) {
	/**
	 * @param array $query     Query parameters.
	 * @param bool  $anonymous Whether the read is anonymous.
	 * @return array|WP_Error
	 */
	function agend_apps_lms_get_paths( array $query = array(), bool $anonymous = false ) {
		$GLOBALS['agend_lp_test']['calls'][] = array(
			'kind'      => 'list',
			'query'     => $query,
			'anonymous' => $anonymous,
		);

		return $GLOBALS['agend_lp_test']['list'];
	}
}

if ( ! function_exists( 'agend_apps_lms_get_paths_by_id' ) ) {
	/**
	 * @param string[] $ids       Path ids.
	 * @param bool     $anonymous Whether the read is anonymous.
	 * @return array<string, array|WP_Error>
	 */
	function agend_apps_lms_get_paths_by_id( array $ids, bool $anonymous = false ): array {
		$GLOBALS['agend_lp_test']['calls'][] = array(
			'kind'      => 'by_id',
			'ids'       => $ids,
			'anonymous' => $anonymous,
		);

		$results = array();

		foreach ( $ids as $id ) {
			$results[ (string) $id ] = $GLOBALS['agend_lp_test']['details'][ (string) $id ] ?? new WP_Error( 'agend_apps_not_found', 'Not found.', array( 'status' => 404 ) );
		}

		return $results;
	}
}

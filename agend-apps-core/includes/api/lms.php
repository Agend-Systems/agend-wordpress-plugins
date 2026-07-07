<?php
/**
 * LMS API functions.
 *
 * Server-side PHP wrappers for the Agend gateway's `/v1/lms/*` endpoints.
 * Sibling plugins MUST call these helpers rather than building gateway paths
 * or calling `agend_apps_api()` directly, so transport and path knowledge stay
 * centralised here.
 *
 * Public catalogue reads (courses, paths, discovery blocks, single resources)
 * are cached. Identity-specific reads (`me/*`, by-user queries) and every
 * mutation are never cached. Member endpoints rely on the Supabase bearer token
 * resolved by `agend_apps_get_bearer_token()` and attached automatically by
 * the client.
 *
 * Every function returns the decoded response array on success or a WP_Error
 * on failure (transport error, non-2xx, or invalid JSON).
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists courses.
 *
 * Scope: `lms.courses.browse`. Cached.
 *
 * @param array $query Optional. Query parameters (camelCase): `page`, `limit`, `search`, `sortBy`, `sortOrder`. Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_lms_get_courses( array $query = array() ) {
	// Array-valued parameters (the exclusion filters) are repeatable on the
	// gateway; split them out so the client serialises them as repeated bare
	// keys rather than PHP-style brackets.
	$scalar = array();
	$multi  = array();

	foreach ( $query as $key => $value ) {
		if ( is_array( $value ) ) {
			$multi[ $key ] = array_values( $value );
		} else {
			$scalar[ $key ] = $value;
		}
	}

	$request_args = array( 'query' => $scalar );

	if ( $multi ) {
		$request_args['query_multi'] = $multi;
	}

	/**
	 * Filters the courses list request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_lms_get_courses_args',
		$request_args,
		$query
	);

	// A member bearer makes the response IDENTITY-SPECIFIC (Decision 2.7:
	// items carry the member's my_enrollment summary). It must never enter
	// the shared transient cache, where it would leak one member's completion
	// state to every visitor — bypass the cache entirely when a bearer is
	// attached.
	if ( '' !== agend_apps_get_bearer_token() ) {
		$response = agend_apps_api()->request( 'GET', '/lms/courses', $args );
	} else {
		$cache_key = Agend_Apps_Cache::build_key( 'lms_courses', $query );
		$ttl       = Agend_Apps_Settings::get_cache_ttl( 'lms_courses' );

		$response = agend_apps_api()->get_cached( '/lms/courses', $args, $cache_key, $ttl );
	}

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded courses list response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_lms_get_courses_response', $response, $query );
}

/**
 * Retrieves a single course by ID or slug.
 *
 * Scope: `lms.courses.browse`. Cached.
 *
 * @param string $id_or_slug Course ID or slug.
 * @return array|WP_Error Decoded course on success, or WP_Error on failure.
 */
function agend_apps_lms_get_course( string $id_or_slug ) {
	/**
	 * Filters the single-course request args before the request is sent.
	 *
	 * @param array  $args        Request args.
	 * @param string $id_or_slug Course ID or slug.
	 */
	$args = (array) apply_filters( 'agend_apps_lms_get_course_args', array(), $id_or_slug );

	// A member bearer makes the response IDENTITY-SPECIFIC (Decision 2.7: the
	// detail carries the member's my_enrollment progress). It must never enter
	// the shared transient cache — bypass entirely when a bearer is attached.
	if ( '' !== agend_apps_get_bearer_token() ) {
		$response = agend_apps_api()->request( 'GET', '/lms/courses/' . rawurlencode( $id_or_slug ), $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return apply_filters( 'agend_apps_lms_get_course_response', $response, $id_or_slug );
	}

	$cache_key = Agend_Apps_Cache::build_key( 'lms_course_single', array( 'id_or_slug' => $id_or_slug ) );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'lms_course_single' );

	$response = agend_apps_api()->get_cached( '/lms/courses/' . rawurlencode( $id_or_slug ), $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded single-course response before it is returned.
	 *
	 * @param array  $response    Decoded response body.
	 * @param string $id_or_slug Course ID or slug.
	 */
	return apply_filters( 'agend_apps_lms_get_course_response', $response, $id_or_slug );
}

/**
 * Creates a course.
 *
 * Scope: `lms.courses.create`. Requires a member bearer token.
 *
 * @param array $course Course payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded course on success, or WP_Error on failure.
 */
function agend_apps_lms_create_course( array $course ) {
	/**
	 * Filters the create-course request args before the request is sent.
	 *
	 * @param array $args   Request args.
	 * @param array $course Course payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_lms_create_course_args',
		array( 'body' => $course ),
		$course
	);

	$response = agend_apps_api()->request( 'POST', '/lms/courses', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded create-course response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $course   Course payload.
	 */
	return apply_filters( 'agend_apps_lms_create_course_response', $response, $course );
}

/**
 * Updates a course.
 *
 * Scope: `lms.courses.update`.
 *
 * @param string $course_id Course ID.
 * @param array  $course    Updated course payload (snake_case).
 * @return array|WP_Error Decoded course on success, or WP_Error on failure.
 */
function agend_apps_lms_update_course( string $course_id, array $course ) {
	/**
	 * Filters the update-course request args before the request is sent.
	 *
	 * @param array  $args      Request args.
	 * @param string $course_id Course ID.
	 * @param array  $course    Updated course payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_lms_update_course_args',
		array( 'body' => $course ),
		$course_id,
		$course
	);

	$response = agend_apps_api()->request( 'PATCH', '/lms/courses/' . rawurlencode( $course_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded update-course response before it is returned.
	 *
	 * @param array  $response  Decoded response body.
	 * @param string $course_id Course ID.
	 * @param array  $course    Updated course payload.
	 */
	return apply_filters( 'agend_apps_lms_update_course_response', $response, $course_id, $course );
}

/**
 * Deletes a course.
 *
 * Scope: `lms.courses.delete`.
 *
 * @param string $course_id Course ID.
 * @return array|WP_Error Decoded confirmation on success, or WP_Error on failure.
 */
function agend_apps_lms_delete_course( string $course_id ) {
	/**
	 * Filters the delete-course request args before the request is sent.
	 *
	 * @param array  $args      Request args.
	 * @param string $course_id Course ID.
	 */
	$args = (array) apply_filters( 'agend_apps_lms_delete_course_args', array(), $course_id );

	$response = agend_apps_api()->request( 'DELETE', '/lms/courses/' . rawurlencode( $course_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded delete-course response before it is returned.
	 *
	 * @param array  $response  Decoded response body.
	 * @param string $course_id Course ID.
	 */
	return apply_filters( 'agend_apps_lms_delete_course_response', $response, $course_id );
}

/**
 * Lists lessons for a course.
 *
 * Scope: `lms.lessons.browse`. Cached.
 *
 * @param string $course_id Course ID.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_lms_get_course_lessons( string $course_id ) {
	/**
	 * Filters the course-lessons request args before the request is sent.
	 *
	 * @param array  $args      Request args.
	 * @param string $course_id Course ID.
	 */
	$args = (array) apply_filters( 'agend_apps_lms_get_course_lessons_args', array(), $course_id );

	$cache_key = Agend_Apps_Cache::build_key( 'lms_course_lessons', array( 'course_id' => $course_id ) );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'lms_course_lessons' );

	$response = agend_apps_api()->get_cached( '/lms/courses/' . rawurlencode( $course_id ) . '/lessons', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded course-lessons response before it is returned.
	 *
	 * @param array  $response  Decoded response body.
	 * @param string $course_id Course ID.
	 */
	return apply_filters( 'agend_apps_lms_get_course_lessons_response', $response, $course_id );
}

/**
 * Reorders lessons within a course.
 *
 * Scope: `lms.lessons.reorder`.
 *
 * @param string $course_id Course ID.
 * @param array  $payload   Reorder payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded confirmation on success, or WP_Error on failure.
 */
function agend_apps_lms_reorder_course_lessons( string $course_id, array $payload ) {
	/**
	 * Filters the reorder-lessons request args before the request is sent.
	 *
	 * @param array  $args      Request args.
	 * @param string $course_id Course ID.
	 * @param array  $payload   Reorder payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_lms_reorder_course_lessons_args',
		array( 'body' => $payload ),
		$course_id,
		$payload
	);

	$path     = '/lms/courses/' . rawurlencode( $course_id ) . '/lessons/reorder';
	$response = agend_apps_api()->request( 'PATCH', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded reorder-lessons response before it is returned.
	 *
	 * @param array  $response  Decoded response body.
	 * @param string $course_id Course ID.
	 * @param array  $payload   Reorder payload.
	 */
	return apply_filters( 'agend_apps_lms_reorder_course_lessons_response', $response, $course_id, $payload );
}

/**
 * Updates a lesson.
 *
 * Scope: `lms.lessons.update`.
 *
 * @param string $lesson_id Lesson ID.
 * @param array  $lesson    Updated lesson payload (snake_case).
 * @return array|WP_Error Decoded lesson on success, or WP_Error on failure.
 */
function agend_apps_lms_update_lesson( string $lesson_id, array $lesson ) {
	/**
	 * Filters the update-lesson request args before the request is sent.
	 *
	 * @param array  $args      Request args.
	 * @param string $lesson_id Lesson ID.
	 * @param array  $lesson    Updated lesson payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_lms_update_lesson_args',
		array( 'body' => $lesson ),
		$lesson_id,
		$lesson
	);

	$response = agend_apps_api()->request( 'PATCH', '/lms/lessons/' . rawurlencode( $lesson_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded update-lesson response before it is returned.
	 *
	 * @param array  $response  Decoded response body.
	 * @param string $lesson_id Lesson ID.
	 * @param array  $lesson    Updated lesson payload.
	 */
	return apply_filters( 'agend_apps_lms_update_lesson_response', $response, $lesson_id, $lesson );
}

/**
 * Deletes a lesson.
 *
 * Scope: `lms.lessons.delete`.
 *
 * @param string $lesson_id Lesson ID.
 * @return array|WP_Error Decoded confirmation on success, or WP_Error on failure.
 */
function agend_apps_lms_delete_lesson( string $lesson_id ) {
	/**
	 * Filters the delete-lesson request args before the request is sent.
	 *
	 * @param array  $args      Request args.
	 * @param string $lesson_id Lesson ID.
	 */
	$args = (array) apply_filters( 'agend_apps_lms_delete_lesson_args', array(), $lesson_id );

	$response = agend_apps_api()->request( 'DELETE', '/lms/lessons/' . rawurlencode( $lesson_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded delete-lesson response before it is returned.
	 *
	 * @param array  $response  Decoded response body.
	 * @param string $lesson_id Lesson ID.
	 */
	return apply_filters( 'agend_apps_lms_delete_lesson_response', $response, $lesson_id );
}

/**
 * Enrolls a user in a course.
 *
 * Scope: `lms.enrollments.manage`.
 *
 * @param string $course_id Course ID.
 * @param array  $payload   Enrollment payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded enrollment on success, or WP_Error on failure.
 */
function agend_apps_lms_enroll_course( string $course_id, array $payload ) {
	/**
	 * Filters the enroll-course request args before the request is sent.
	 *
	 * @param array  $args      Request args.
	 * @param string $course_id Course ID.
	 * @param array  $payload   Enrollment payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_lms_enroll_course_args',
		array( 'body' => $payload ),
		$course_id,
		$payload
	);

	$response = agend_apps_api()->request( 'POST', '/lms/courses/' . rawurlencode( $course_id ) . '/enroll', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded enroll-course response before it is returned.
	 *
	 * @param array  $response  Decoded response body.
	 * @param string $course_id Course ID.
	 * @param array  $payload   Enrollment payload.
	 */
	return apply_filters( 'agend_apps_lms_enroll_course_response', $response, $course_id, $payload );
}

/**
 * Lists enrollments for a contact.
 *
 * Scope: `lms.enrollments.browse`. Not cached.
 *
 * @param array $query Optional. Query parameters (camelCase): `contactId`, `page`, `limit`. Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_lms_get_enrollments( array $query = array() ) {
	/**
	 * Filters the enrollments list request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_lms_get_enrollments_args',
		array( 'query' => $query ),
		$query
	);

	$response = agend_apps_api()->request( 'GET', '/lms/enrollments', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded enrollments list response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_lms_get_enrollments_response', $response, $query );
}

/**
 * Retrieves the authenticated user's enrollments.
 *
 * Scope: `lms.enrollments.browse`. Requires a member bearer token. Not cached.
 *
 * @param array $query Optional. Query parameters (camelCase): `page`, `limit`, `status`. Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_lms_get_my_enrollments( array $query = array() ) {
	/**
	 * Filters the my-enrollments request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_lms_get_my_enrollments_args',
		array( 'query' => $query ),
		$query
	);

	$response = agend_apps_api()->request( 'GET', '/lms/me/enrollments', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded my-enrollments response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_lms_get_my_enrollments_response', $response, $query );
}

/**
 * Retrieves the authenticated user's enrolment for a single course.
 *
 * Scope: `lms.enrollments.browse`. Requires a member bearer token. Not cached
 * (progress must be live). A 404 means "not enrolled", not an error condition,
 * so callers should translate it rather than surface it.
 *
 * @param string $course_id Course UUID.
 * @return array|WP_Error Decoded enrolment envelope on success, or WP_Error on failure (including 404 when not enrolled).
 */
function agend_apps_lms_get_my_course_enrollment( string $course_id ) {
	/**
	 * Filters the my-course-enrolment request args before the request is sent.
	 *
	 * @param array  $args      Request args.
	 * @param string $course_id Course UUID.
	 */
	$args = (array) apply_filters(
		'agend_apps_lms_get_my_course_enrollment_args',
		array(),
		$course_id
	);

	$response = agend_apps_api()->request( 'GET', '/lms/me/enrollments/' . rawurlencode( $course_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded my-course-enrolment response before it is returned.
	 *
	 * @param array  $response  Decoded response body.
	 * @param string $course_id Course UUID.
	 */
	return apply_filters( 'agend_apps_lms_get_my_course_enrollment_response', $response, $course_id );
}

/**
 * Lists learning paths.
 *
 * Scope: `lms.paths.browse`. Cached.
 *
 * @param array $query Optional. Query parameters (camelCase): `page`, `limit`, `search`, `sortBy`, `sortOrder`. Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_lms_get_paths( array $query = array() ) {
	/**
	 * Filters the paths list request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_lms_get_paths_args',
		array( 'query' => $query ),
		$query
	);

	$cache_key = Agend_Apps_Cache::build_key( 'lms_paths', $query );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'lms_paths' );

	$response = agend_apps_api()->get_cached( '/lms/paths', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded paths list response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_lms_get_paths_response', $response, $query );
}

/**
 * Retrieves a single learning path by ID or slug.
 *
 * Scope: `lms.paths.browse`. Cached.
 *
 * @param string $id_or_slug Path ID or slug.
 * @return array|WP_Error Decoded path on success, or WP_Error on failure.
 */
function agend_apps_lms_get_path( string $id_or_slug ) {
	/**
	 * Filters the single-path request args before the request is sent.
	 *
	 * @param array  $args        Request args.
	 * @param string $id_or_slug Path ID or slug.
	 */
	$args = (array) apply_filters( 'agend_apps_lms_get_path_args', array(), $id_or_slug );

	$cache_key = Agend_Apps_Cache::build_key( 'lms_path_single', array( 'id_or_slug' => $id_or_slug ) );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'lms_path_single' );

	$response = agend_apps_api()->get_cached( '/lms/paths/' . rawurlencode( $id_or_slug ), $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded single-path response before it is returned.
	 *
	 * @param array  $response    Decoded response body.
	 * @param string $id_or_slug Path ID or slug.
	 */
	return apply_filters( 'agend_apps_lms_get_path_response', $response, $id_or_slug );
}

/**
 * Creates a learning path.
 *
 * Scope: `lms.paths.create`. Requires a member bearer token.
 *
 * @param array $path Path payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded path on success, or WP_Error on failure.
 */
function agend_apps_lms_create_path( array $path ) {
	/**
	 * Filters the create-path request args before the request is sent.
	 *
	 * @param array $args Request args.
	 * @param array $path Path payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_lms_create_path_args',
		array( 'body' => $path ),
		$path
	);

	$response = agend_apps_api()->request( 'POST', '/lms/paths', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded create-path response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $path     Path payload.
	 */
	return apply_filters( 'agend_apps_lms_create_path_response', $response, $path );
}

/**
 * Updates a learning path.
 *
 * Scope: `lms.paths.update`.
 *
 * @param string $path_id Path ID.
 * @param array  $path    Updated path payload (snake_case).
 * @return array|WP_Error Decoded path on success, or WP_Error on failure.
 */
function agend_apps_lms_update_path( string $path_id, array $path ) {
	/**
	 * Filters the update-path request args before the request is sent.
	 *
	 * @param array  $args   Request args.
	 * @param string $path_id Path ID.
	 * @param array  $path   Updated path payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_lms_update_path_args',
		array( 'body' => $path ),
		$path_id,
		$path
	);

	$response = agend_apps_api()->request( 'PATCH', '/lms/paths/' . rawurlencode( $path_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded update-path response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $path_id  Path ID.
	 * @param array  $path     Updated path payload.
	 */
	return apply_filters( 'agend_apps_lms_update_path_response', $response, $path_id, $path );
}

/**
 * Deletes a learning path.
 *
 * Scope: `lms.paths.delete`.
 *
 * @param string $path_id Path ID.
 * @return array|WP_Error Decoded confirmation on success, or WP_Error on failure.
 */
function agend_apps_lms_delete_path( string $path_id ) {
	/**
	 * Filters the delete-path request args before the request is sent.
	 *
	 * @param array  $args   Request args.
	 * @param string $path_id Path ID.
	 */
	$args = (array) apply_filters( 'agend_apps_lms_delete_path_args', array(), $path_id );

	$response = agend_apps_api()->request( 'DELETE', '/lms/paths/' . rawurlencode( $path_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded delete-path response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $path_id  Path ID.
	 */
	return apply_filters( 'agend_apps_lms_delete_path_response', $response, $path_id );
}

/**
 * Enrolls a user in a learning path.
 *
 * Scope: `lms.enrollments.manage`.
 *
 * @param string $path_id Path ID.
 * @param array  $payload Enrollment payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded enrollment on success, or WP_Error on failure.
 */
function agend_apps_lms_enroll_path( string $path_id, array $payload ) {
	/**
	 * Filters the enroll-path request args before the request is sent.
	 *
	 * @param array  $args    Request args.
	 * @param string $path_id Path ID.
	 * @param array  $payload Enrollment payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_lms_enroll_path_args',
		array( 'body' => $payload ),
		$path_id,
		$payload
	);

	$response = agend_apps_api()->request( 'POST', '/lms/paths/' . rawurlencode( $path_id ) . '/enroll', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded enroll-path response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $path_id  Path ID.
	 * @param array  $payload  Enrollment payload.
	 */
	return apply_filters( 'agend_apps_lms_enroll_path_response', $response, $path_id, $payload );
}

/**
 * Retrieves the authenticated user's progress on a learning path.
 *
 * Scope: `lms.progress.read`. Requires a member bearer token. Not cached.
 *
 * @param string $path_id Path ID.
 * @return array|WP_Error Decoded progress on success, or WP_Error on failure.
 */
function agend_apps_lms_get_path_progress( string $path_id ) {
	/**
	 * Filters the path-progress request args before the request is sent.
	 *
	 * @param array  $args    Request args.
	 * @param string $path_id Path ID.
	 */
	$args = (array) apply_filters( 'agend_apps_lms_get_path_progress_args', array(), $path_id );

	$response = agend_apps_api()->request( 'GET', '/lms/paths/' . rawurlencode( $path_id ) . '/progress', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded path-progress response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $path_id  Path ID.
	 */
	return apply_filters( 'agend_apps_lms_get_path_progress_response', $response, $path_id );
}

/**
 * Retrieves public discovery blocks for course discovery.
 *
 * Scope: `lms.courses.browse`. Cached.
 *
 * @param array $query Optional. Query parameters (camelCase). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_lms_get_discovery_blocks( array $query = array() ) {
	/**
	 * Filters the discovery-blocks request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_lms_get_discovery_blocks_args',
		array( 'query' => $query ),
		$query
	);

	$cache_key = Agend_Apps_Cache::build_key( 'lms_discovery', $query );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'lms_discovery' );

	$response = agend_apps_api()->get_cached( '/lms/public/blocks/discovery', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded discovery-blocks response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_lms_get_discovery_blocks_response', $response, $query );
}

/**
 * Retrieves CPD summary for a user.
 *
 * Scope: `lms.users.read`. Not cached.
 *
 * @param string $user_id User ID.
 * @return array|WP_Error Decoded response on success, or WP_Error on failure.
 */
function agend_apps_lms_get_user_cpd( string $user_id ) {
	/**
	 * Filters the user-cpd request args before the request is sent.
	 *
	 * @param array  $args    Request args.
	 * @param string $user_id User ID.
	 */
	$args = (array) apply_filters( 'agend_apps_lms_get_user_cpd_args', array(), $user_id );

	$response = agend_apps_api()->request( 'GET', '/lms/users/' . rawurlencode( $user_id ) . '/cpd', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded user-cpd response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $user_id  User ID.
	 */
	return apply_filters( 'agend_apps_lms_get_user_cpd_response', $response, $user_id );
}

/**
 * Retrieves enrollments for a user.
 *
 * Scope: `lms.users.read`. Not cached.
 *
 * @param string $user_id User ID.
 * @param array  $query   Optional. Query parameters (camelCase): `page`, `limit`. Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_lms_get_user_enrollments( string $user_id, array $query = array() ) {
	/**
	 * Filters the user-enrollments request args before the request is sent.
	 *
	 * @param array  $args    Request args.
	 * @param string $user_id User ID.
	 * @param array  $query   Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_lms_get_user_enrollments_args',
		array( 'query' => $query ),
		$user_id,
		$query
	);

	$response = agend_apps_api()->request( 'GET', '/lms/users/' . rawurlencode( $user_id ) . '/enrollments', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded user-enrollments response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $user_id  User ID.
	 * @param array  $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_lms_get_user_enrollments_response', $response, $user_id, $query );
}

/**
 * Retrieves learning progress for a user.
 *
 * Scope: `lms.users.read`. Not cached.
 *
 * @param string $user_id User ID.
 * @param array  $query   Optional. Query parameters (camelCase): `page`, `limit`. Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_lms_get_user_progress( string $user_id, array $query = array() ) {
	/**
	 * Filters the user-progress request args before the request is sent.
	 *
	 * @param array  $args    Request args.
	 * @param string $user_id User ID.
	 * @param array  $query   Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_lms_get_user_progress_args',
		array( 'query' => $query ),
		$user_id,
		$query
	);

	$response = agend_apps_api()->request( 'GET', '/lms/users/' . rawurlencode( $user_id ) . '/progress', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded user-progress response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $user_id  User ID.
	 * @param array  $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_lms_get_user_progress_response', $response, $user_id, $query );
}

/**
 * Verifies a badge by token.
 *
 * Scope: `lms.badges.verify`. Public verification endpoint.
 *
 * @param string $token Badge share token.
 * @return array|WP_Error Decoded verification result on success, or WP_Error on failure.
 */
function agend_apps_lms_verify_badge( string $token ) {
	/**
	 * Filters the badge-verify request args before the request is sent.
	 *
	 * @param array  $args  Request args.
	 * @param string $token Badge share token.
	 */
	$args = (array) apply_filters( 'agend_apps_lms_verify_badge_args', array(), $token );

	$response = agend_apps_api()->request( 'GET', '/lms/badges/verify/' . rawurlencode( $token ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded badge-verify response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $token    Badge share token.
	 */
	return apply_filters( 'agend_apps_lms_verify_badge_response', $response, $token );
}

/**
 * Verifies a certificate by code.
 *
 * Scope: `lms.certificates.verify`. Public verification endpoint.
 *
 * @param string $code Certificate verification code.
 * @return array|WP_Error Decoded verification result on success, or WP_Error on failure.
 */
function agend_apps_lms_verify_certificate( string $code ) {
	/**
	 * Filters the certificate-verify request args before the request is sent.
	 *
	 * @param array  $args Request args.
	 * @param string $code Certificate verification code.
	 */
	$args = (array) apply_filters( 'agend_apps_lms_verify_certificate_args', array(), $code );

	$response = agend_apps_api()->request( 'GET', '/lms/certificates/verify/' . rawurlencode( $code ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded certificate-verify response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $code     Certificate verification code.
	 */
	return apply_filters( 'agend_apps_lms_verify_certificate_response', $response, $code );
}

/**
 * Generates a certificate for a user.
 *
 * Scope: `lms.certificates.generate`.
 *
 * @param string $certificate_id Certificate ID.
 * @param array  $payload        Generation payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded certificate on success, or WP_Error on failure.
 */
function agend_apps_lms_generate_certificate( string $certificate_id, array $payload ) {
	/**
	 * Filters the generate-certificate request args before the request is sent.
	 *
	 * @param array  $args            Request args.
	 * @param string $certificate_id Certificate ID.
	 * @param array  $payload         Generation payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_lms_generate_certificate_args',
		array( 'body' => $payload ),
		$certificate_id,
		$payload
	);

	$path     = '/lms/certificates/' . rawurlencode( $certificate_id ) . '/generate';
	$response = agend_apps_api()->request( 'POST', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded generate-certificate response before it is returned.
	 *
	 * @param array  $response        Decoded response body.
	 * @param string $certificate_id Certificate ID.
	 * @param array  $payload         Generation payload.
	 */
	return apply_filters( 'agend_apps_lms_generate_certificate_response', $response, $certificate_id, $payload );
}

/**
 * Registers a webhook for LMS events.
 *
 * Scope: `lms.webhooks.manage`.
 *
 * @param array $webhook Webhook registration payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded webhook on success, or WP_Error on failure.
 */
function agend_apps_lms_register_webhook( array $webhook ) {
	/**
	 * Filters the register-webhook request args before the request is sent.
	 *
	 * @param array $args    Request args.
	 * @param array $webhook Webhook registration payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_lms_register_webhook_args',
		array( 'body' => $webhook ),
		$webhook
	);

	$response = agend_apps_api()->request( 'POST', '/lms/webhooks/register', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded register-webhook response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $webhook  Webhook registration payload.
	 */
	return apply_filters( 'agend_apps_lms_register_webhook_response', $response, $webhook );
}

/**
 * Unregisters a webhook for LMS events.
 *
 * Scope: `lms.webhooks.manage`.
 *
 * @param array $payload Webhook unregistration payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded confirmation on success, or WP_Error on failure.
 */
function agend_apps_lms_unregister_webhook( array $payload ) {
	/**
	 * Filters the unregister-webhook request args before the request is sent.
	 *
	 * @param array $args    Request args.
	 * @param array $payload Webhook unregistration payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_lms_unregister_webhook_args',
		array( 'body' => $payload ),
		$payload
	);

	$response = agend_apps_api()->request( 'POST', '/lms/webhooks/unregister', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded unregister-webhook response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $payload  Webhook unregistration payload.
	 */
	return apply_filters( 'agend_apps_lms_unregister_webhook_response', $response, $payload );
}

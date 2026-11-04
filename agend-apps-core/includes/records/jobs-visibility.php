<?php
/**
 * Who may see the jobs board (Agend Careers).
 *
 * One site setting, enforced in two places so it is never only cosmetic: the
 * jobs surface's server render (and the script it configures) and the
 * `/agend-apps/v1/jobs` REST proxy the script reads from. A visitor who may
 * not see something gets neither the markup nor the data.
 *
 * - `public`:  everyone sees the board and every job's details (the default).
 * - `teaser`:  everyone sees the list of jobs by title, employer, location and
 *              type; a job's details (its description, how to apply, salary
 *              and the employer's own text) need a signed-in visitor.
 * - `members`: only a signed-in visitor sees the board at all.
 *
 * "Signed in" means a logged-in WordPress user, which on a member site is the
 * member sign-in. A site that must tell members from other accounts narrows it
 * with the `agend_apps_records_jobs_visitor_can_view` filter.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option name storing the jobs board visibility.
 *
 * @var string
 */
const AGEND_APPS_RECORDS_JOBS_VISIBILITY_OPTION = 'agend_apps_jobs_visibility';

/**
 * The visibility choices, keyed by stored value.
 *
 * @return array<string, string>
 */
function agend_apps_records_jobs_visibility_choices(): array {
	return array(
		'public'  => __( 'Public: everyone sees every job', 'agend-apps-core' ),
		'teaser'  => __( 'Titles public: signing in shows a job\'s details', 'agend-apps-core' ),
		'members' => __( 'Members only: signing in shows the board', 'agend-apps-core' ),
	);
}

/**
 * Sanitizes a stored visibility value to a known choice.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function agend_apps_records_sanitize_jobs_visibility( $value ): string {
	$value = is_string( $value ) ? $value : '';
	return array_key_exists( $value, agend_apps_records_jobs_visibility_choices() ) ? $value : 'public';
}

/**
 * The site's jobs board visibility.
 *
 * @return string One of `public`, `teaser`, `members`.
 */
function agend_apps_records_jobs_visibility(): string {
	return agend_apps_records_sanitize_jobs_visibility( get_option( AGEND_APPS_RECORDS_JOBS_VISIBILITY_OPTION, 'public' ) );
}

/**
 * Whether the current visitor may see the job list or a job's details.
 *
 * @param string $what `list` or `detail`.
 * @return bool
 */
function agend_apps_records_jobs_visitor_can_view( string $what ): bool {
	$visibility = agend_apps_records_jobs_visibility();

	$needs_sign_in = 'members' === $visibility
		|| ( 'teaser' === $visibility && 'detail' === $what );

	$can = ! $needs_sign_in || is_user_logged_in();

	/**
	 * Filters whether the current visitor may see the jobs list or a job's
	 * details. A site that must tell members from other signed-in accounts
	 * narrows it here; it is applied to both the rendered board and the REST
	 * proxy, so the two never disagree.
	 *
	 * @param bool   $can        Whether the visitor may see it.
	 * @param string $what       `list` or `detail`.
	 * @param string $visibility The site setting.
	 * @param int    $user_id    The current user id, or 0.
	 */
	return (bool) apply_filters( 'agend_apps_records_jobs_visitor_can_view', $can, $what, $visibility, get_current_user_id() );
}

/**
 * The job fields a visitor who may not see details still receives in the
 * list: enough for a card that names the role and where it is.
 *
 * @return string[]
 */
function agend_apps_records_jobs_teaser_fields(): array {
	return array(
		'id',
		'title',
		'slug',
		'company_name',
		'company_logo_url',
		'location_type',
		'location_city',
		'location_state',
		'location_country',
		'employment_type',
		'experience_level',
		'is_featured',
		'sponsor_level',
		'published_at',
		'expires_at',
		'categories',
		'tags',
	);
}

/**
 * A job reduced to its teaser fields.
 *
 * @param array $job A job as the gateway returns it.
 * @return array
 */
function agend_apps_records_jobs_teaser( array $job ): array {
	return array_intersect_key( $job, array_flip( agend_apps_records_jobs_teaser_fields() ) );
}

/**
 * The sign-in URL that returns the visitor to `$return_url`.
 *
 * @param string $return_url Where to come back to.
 * @return string
 */
function agend_apps_records_jobs_sign_in_url( string $return_url ): string {
	return wp_login_url( $return_url );
}

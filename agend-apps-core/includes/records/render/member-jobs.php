<?php
/**
 * Server render of the Member Jobs surface: a signed-in member's job credits,
 * their own job submissions and the "Post a job" form.
 *
 * Page-builder agnostic: an adapter passes the surface's settings (see
 * `agend_apps_records_surface_schema( 'member-jobs' )`) and echoes the HTML.
 * The list and the form are drawn by assets/js/member-jobs.js from the config
 * embedded here, and every change goes through the `agend-apps/v1/jobs/me`
 * routes, which decide what happens to the member's credit.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../surface-gate.php';
require_once __DIR__ . '/../schema/member-jobs.php';

/**
 * A board term list (categories or tags) as `{ id, slug, name }` rows.
 *
 * @param string $kind `categories` or `tags`.
 * @return array<int, array{id: string, slug: string, name: string}>
 */
function agend_apps_records_member_jobs_terms( string $kind ): array {
	if ( ! function_exists( 'agend_apps_jobs_get_terms' ) ) {
		return array();
	}

	$response = agend_apps_jobs_get_terms( $kind );

	if ( is_wp_error( $response ) || empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
		return array();
	}

	$terms = array();

	foreach ( $response['data'] as $term ) {
		if ( is_array( $term ) && ! empty( $term['id'] ) && ! empty( $term['slug'] ) ) {
			$terms[] = array(
				'id'   => (string) $term['id'],
				'slug' => (string) $term['slug'],
				'name' => (string) ( $term['name'] ?? $term['slug'] ),
			);
		}
	}

	return $terms;
}

/**
 * The configured tag questions with their tags resolved to ids, in the order
 * the editor chose. A question none of whose tags exist any more is dropped.
 *
 * @param mixed $groups The `tag_groups` setting.
 * @param array $tags   The board's tags, from agend_apps_records_member_jobs_terms().
 * @return array<int, array{label: string, style: string, required: bool, tags: array}>
 */
function agend_apps_records_member_jobs_tag_groups( $groups, array $tags ): array {
	$by_slug = array();
	foreach ( $tags as $tag ) {
		$by_slug[ $tag['slug'] ] = $tag;
	}

	$out = array();

	foreach ( is_array( $groups ) ? $groups : array() as $group ) {
		if ( ! is_array( $group ) ) {
			continue;
		}

		$chosen = array();
		foreach ( (array) ( $group['tags'] ?? array() ) as $slug ) {
			$slug = sanitize_title( (string) $slug );
			if ( isset( $by_slug[ $slug ] ) ) {
				$chosen[] = array(
					'id'   => $by_slug[ $slug ]['id'],
					'name' => $by_slug[ $slug ]['name'],
				);
			}
		}

		if ( array() === $chosen ) {
			continue;
		}

		$style = 'checkboxes' === ( $group['style'] ?? '' ) ? 'checkboxes' : 'select';
		$label = trim( (string) ( $group['label'] ?? '' ) );

		$out[] = array(
			'label'    => '' !== $label ? $label : __( 'Tags', 'agend-apps-core' ),
			'style'    => $style,
			'required' => 'select' === $style && in_array( $group['required'] ?? '', array( 'yes', true, '1', 1 ), true ),
			'tags'     => $chosen,
		);
	}

	return $out;
}

/**
 * The fields the form shows as required: the chosen ones that are not hidden.
 *
 * @param array $s Surface settings.
 * @return array{required: string[], hidden: string[]}
 */
function agend_apps_records_member_jobs_field_rules( array $s ): array {
	$known    = array_keys( agend_apps_records_member_jobs_optional_fields() );
	$hidden   = array_values( array_intersect( $known, array_map( 'strval', (array) ( $s['hidden_fields'] ?? array() ) ) ) );
	$required = array_key_exists( 'required_fields', $s ) ? (array) $s['required_fields'] : AGEND_APPS_RECORDS_MEMBER_JOBS_REQUIRED_DEFAULT;
	$required = array_values( array_diff( array_intersect( $known, array_map( 'strval', $required ) ), $hidden ) );

	return array(
		'required' => $required,
		'hidden'   => $hidden,
	);
}

/**
 * Builds the client-side config object from the surface settings.
 *
 * @param array $s Surface settings (Elementor-shaped values: toggles are 'yes'/'').
 * @return array
 */
function agend_apps_records_member_jobs_build_config( array $s ): array {
	$text = static function ( string $key, string $fallback ) use ( $s ): string {
		return array_key_exists( $key, $s ) ? trim( (string) $s[ $key ] ) : $fallback;
	};

	$schema   = agend_apps_records_schema_member_jobs();
	$defaults = array();
	foreach ( $schema['sections'] as $section ) {
		foreach ( $section['fields'] as $field ) {
			if ( isset( $field['default'] ) && is_string( $field['default'] ) ) {
				$defaults[ $field['name'] ] = $field['default'];
			}
		}
	}

	$buy_url = esc_url_raw( agend_apps_records_normalise_url_setting( $s['buy_url'] ?? '' ) );
	$states  = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $s['state_options'] ?? $defaults['state_options'] ) ) ) ) );
	$tags    = agend_apps_records_member_jobs_terms( 'tags' );
	$rules   = agend_apps_records_member_jobs_field_rules( $s );
	$colours = agend_apps_records_resolve_colours(
		array( 'inherit_colours' => (string) ( $s['inherit_colours'] ?? 'yes' ) ) + $s,
		AGEND_APPS_RECORDS_COLOUR_DEFAULTS
	);

	$categories = array_map(
		static function ( array $term ): array {
			return array(
				'id'   => $term['id'],
				'name' => $term['name'],
			);
		},
		agend_apps_records_member_jobs_terms( 'categories' )
	);

	return array(
		'endpoint'    => esc_url_raw( rest_url( 'agend-apps/v1/jobs/me' ) ),
		'jobsPage'    => class_exists( 'Agend_Apps_Records_Pages' ) ? Agend_Apps_Records_Pages::page_url( 'job' ) : '',
		'prettyLinks' => (bool) get_option( 'permalink_structure' ),
		'buyUrl'      => 1 === preg_match( '#^https?://#i', $buy_url ) || 0 === strpos( $buy_url, '/' ) ? $buy_url : '',
		'text'        => array(
			'heading'   => $text( 'heading', $defaults['heading'] ),
			'intro'     => $text( 'intro', $defaults['intro'] ),
			'post'      => $text( 'post_label', $defaults['post_label'] ),
			'buy'       => $text( 'buy_label', $defaults['buy_label'] ),
			'noCredits' => $text( 'no_credits_message', $defaults['no_credits_message'] ),
			'success'   => $text( 'success_message', $defaults['success_message'] ),
		),
		'form'        => array(
			'required'   => $rules['required'],
			'hidden'     => $rules['hidden'],
			'states'     => $states,
			'categories' => $categories,
			'tagGroups'  => agend_apps_records_member_jobs_tag_groups( $s['tag_groups'] ?? array(), $tags ),
		),
		'colours'     => $colours['colours'],
		'theme'       => array(
			'inheritFonts' => 'yes' === ( $s['inherit_fonts'] ?? 'yes' ),
			'colourSource' => $colours['source'],
		),
		'messages'    => agend_apps_records_member_jobs_messages(),
	);
}

/**
 * Every label and message the script draws, so all of it is translatable.
 *
 * @return array
 */
function agend_apps_records_member_jobs_messages(): array {
	return array(
		'loading'        => __( 'Loading your jobs…', 'agend-apps-core' ),
		'loadFailed'     => __( 'Your jobs could not be loaded. Please reload the page to try again.', 'agend-apps-core' ),
		/* translators: %d: number of job credits. */
		'creditsOne'     => __( 'You have %d job credit.', 'agend-apps-core' ),
		/* translators: %d: number of job credits. */
		'creditsMany'    => __( 'You have %d job credits.', 'agend-apps-core' ),
		/* translators: %s: a date. */
		'creditsExpiry'  => __( 'The next one expires on %s.', 'agend-apps-core' ),
		'creditsUnknown' => __( 'Your job credits could not be checked just now.', 'agend-apps-core' ),
		'none'           => __( 'You have not posted any jobs yet.', 'agend-apps-core' ),
		'more'           => __( 'Show more', 'agend-apps-core' ),
		/* translators: %s: a date. */
		'submittedOn'    => __( 'Submitted %s', 'agend-apps-core' ),
		/* translators: %s: a date. */
		'expiresOn'      => __( 'Listed until %s', 'agend-apps-core' ),
		'reason'         => __( 'Reason:', 'agend-apps-core' ),
		'status'         => array(
			'pending'   => __( 'Awaiting review', 'agend-apps-core' ),
			'published' => __( 'Live', 'agend-apps-core' ),
			'expired'   => __( 'Expired', 'agend-apps-core' ),
			'closed'    => __( 'Closed', 'agend-apps-core' ),
			'filled'    => __( 'Filled', 'agend-apps-core' ),
			'rejected'  => __( 'Not approved', 'agend-apps-core' ),
			'withdrawn' => __( 'Withdrawn', 'agend-apps-core' ),
		),
		'actions'        => array(
			'view'            => __( 'View', 'agend-apps-core' ),
			'edit'            => __( 'Edit', 'agend-apps-core' ),
			'withdraw'        => __( 'Withdraw', 'agend-apps-core' ),
			'withdrawConfirm' => __( 'Withdraw this job? Your credit will be returned.', 'agend-apps-core' ),
			'close'           => __( 'Close', 'agend-apps-core' ),
			'closeConfirm'    => __( 'Take this job off the board? Your credit is not returned.', 'agend-apps-core' ),
			'filled'          => __( 'Mark as filled', 'agend-apps-core' ),
			'filledConfirm'   => __( 'Mark this position as filled and take it off the board?', 'agend-apps-core' ),
			'yes'             => __( 'Yes', 'agend-apps-core' ),
			'no'              => __( 'Cancel', 'agend-apps-core' ),
			'withdrawn'       => __( 'The job was withdrawn and your credit returned.', 'agend-apps-core' ),
			'closed'          => __( 'The job has been taken off the board.', 'agend-apps-core' ),
			'saved'           => __( 'Your changes have been saved.', 'agend-apps-core' ),
		),
		'form'           => array(
			'newHeading'   => __( 'Post a job', 'agend-apps-core' ),
			'editHeading'  => __( 'Edit job', 'agend-apps-core' ),
			'submit'       => __( 'Submit for review', 'agend-apps-core' ),
			'submitNote'   => __( 'Submitting uses one job credit. If the job is not approved, or you withdraw it before then, the credit is returned.', 'agend-apps-core' ),
			'save'         => __( 'Save changes', 'agend-apps-core' ),
			'cancel'       => __( 'Cancel', 'agend-apps-core' ),
			'sending'      => __( 'Sending…', 'agend-apps-core' ),
			'saving'       => __( 'Saving…', 'agend-apps-core' ),
			'choose'       => __( 'Choose…', 'agend-apps-core' ),
			'optional'     => __( '(optional)', 'agend-apps-core' ),
			'required'     => __( 'This field is required.', 'agend-apps-core' ),
			'applyNeeded'  => __( 'Please give an application email or a link.', 'agend-apps-core' ),
			'email'        => __( 'Please enter a valid email address.', 'agend-apps-core' ),
			'url'          => __( 'Please enter a full web address, starting with https://.', 'agend-apps-core' ),
			'number'       => __( 'Please enter a whole number.', 'agend-apps-core' ),
			'salaryRange'  => __( 'The maximum must not be less than the minimum.', 'agend-apps-core' ),
			'tooLong'      => __( 'This is too long.', 'agend-apps-core' ),
			'fix'          => __( 'Please check the highlighted fields and try again.', 'agend-apps-core' ),
			'session'      => __( 'Your session has expired. Please reload the page and try again.', 'agend-apps-core' ),
			'failed'       => __( 'Sorry, something went wrong. Please try again later.', 'agend-apps-core' ),
			'sections'     => array(
				'role'     => __( 'The role', 'agend-apps-core' ),
				'employer' => __( 'Employer', 'agend-apps-core' ),
				'location' => __( 'Location', 'agend-apps-core' ),
				'salary'   => __( 'Salary', 'agend-apps-core' ),
				'apply'    => __( 'How to apply', 'agend-apps-core' ),
			),
			'labels'       => array(
				'title'                    => __( 'Job title', 'agend-apps-core' ),
				'reference_number'         => __( 'Reference number', 'agend-apps-core' ),
				'employment_type'          => __( 'Employment type', 'agend-apps-core' ),
				'location_type'            => __( 'Work arrangement', 'agend-apps-core' ),
				'experience_level'         => __( 'Experience level', 'agend-apps-core' ),
				'category'                 => __( 'Category', 'agend-apps-core' ),
				'short_description'        => __( 'Short summary', 'agend-apps-core' ),
				'description'              => __( 'Full description', 'agend-apps-core' ),
				'company_name'             => __( 'Employer name', 'agend-apps-core' ),
				'company_website'          => __( 'Employer website', 'agend-apps-core' ),
				'location_city'            => __( 'Suburb or city', 'agend-apps-core' ),
				'location_state'           => __( 'State', 'agend-apps-core' ),
				'location_postcode'        => __( 'Postcode', 'agend-apps-core' ),
				'location_country'         => __( 'Country', 'agend-apps-core' ),
				'salary_min'               => __( 'Salary from', 'agend-apps-core' ),
				'salary_max'               => __( 'Salary to', 'agend-apps-core' ),
				'salary_period'            => __( 'Paid', 'agend-apps-core' ),
				'show_salary'              => __( 'Show the salary on the listing', 'agend-apps-core' ),
				'application_email'        => __( 'Application email', 'agend-apps-core' ),
				'application_url'          => __( 'Application link', 'agend-apps-core' ),
				'application_instructions' => __( 'Application instructions', 'agend-apps-core' ),
				'contact_name'             => __( 'Contact name', 'agend-apps-core' ),
				'contact_phone'            => __( 'Contact phone', 'agend-apps-core' ),
			),
			'hints'        => array(
				'short_description' => __( 'One or two lines for the job card.', 'agend-apps-core' ),
				'description'       => __( 'Leave a blank line between paragraphs.', 'agend-apps-core' ),
				'application_email' => __( 'Applications are sent to this address.', 'agend-apps-core' ),
				'application_url'   => __( 'Your own application page, if you use one.', 'agend-apps-core' ),
			),
			'options'      => array(
				'employment_type'  => array(
					'full-time'  => __( 'Full time', 'agend-apps-core' ),
					'part-time'  => __( 'Part time', 'agend-apps-core' ),
					'contract'   => __( 'Contract', 'agend-apps-core' ),
					'casual'     => __( 'Casual', 'agend-apps-core' ),
					'internship' => __( 'Internship', 'agend-apps-core' ),
				),
				'location_type'    => array(
					'on-site' => __( 'On site', 'agend-apps-core' ),
					'hybrid'  => __( 'Hybrid', 'agend-apps-core' ),
					'remote'  => __( 'Remote', 'agend-apps-core' ),
				),
				'experience_level' => array(
					'entry'     => __( 'Entry level', 'agend-apps-core' ),
					'mid'       => __( 'Mid level', 'agend-apps-core' ),
					'senior'    => __( 'Senior', 'agend-apps-core' ),
					'executive' => __( 'Executive', 'agend-apps-core' ),
				),
				'salary_period'    => array(
					'annual'  => __( 'Per year', 'agend-apps-core' ),
					'hourly'  => __( 'Per hour', 'agend-apps-core' ),
					'project' => __( 'Per project', 'agend-apps-core' ),
				),
			),
		),
	);
}

/**
 * Renders the Member Jobs surface.
 *
 * A visitor who is not signed in sees the signed-out message and a sign-in
 * link, drawn here so it needs no script. A signed-in member gets the shell
 * the script fills in.
 *
 * @param array $settings Surface settings.
 * @param array $opts     'preview' (bool): drawn in an editor, where the
 *                        script shows sample jobs and calls nothing.
 * @return string
 */
function agend_apps_records_render_member_jobs( array $settings, array $opts = array() ): string {
	$preview = ! empty( $opts['preview'] );
	$gate    = agend_apps_records_surface_gate( 'member-jobs', $preview );
	if ( null !== $gate ) {
		return $gate;
	}

	if ( ! $preview && ! is_user_logged_in() ) {
		$message = array_key_exists( 'logged_out_message', $settings )
			? trim( (string) $settings['logged_out_message'] )
			: __( 'Please sign in to post and manage your jobs.', 'agend-apps-core' );

		if ( '' === $message ) {
			return '';
		}

		$here = home_url( add_query_arg( array() ) );

		return '<div class="agend-member-jobs agend-member-jobs--signed-out"><p>' . esc_html( $message ) . '</p>'
			. '<p><a class="agend-member-jobs__button" href="' . esc_url( wp_login_url( $here ) ) . '">' . esc_html__( 'Sign in', 'agend-apps-core' ) . '</a></p></div>';
	}

	$config = agend_apps_records_member_jobs_build_config( $settings );
	$style  = sprintf(
		'--agend-mj-heading:%1$s;--agend-mj-body:%2$s;--agend-mj-accent:%3$s;--agend-mj-button:%4$s;--agend-mj-button-text:%5$s;',
		esc_attr( $config['colours']['heading'] ),
		esc_attr( $config['colours']['body'] ),
		esc_attr( $config['colours']['accent'] ),
		esc_attr( $config['colours']['button'] ),
		esc_attr( $config['colours']['buttonText'] )
	);

	return '<div class="agend-member-jobs' . ( $config['theme']['inheritFonts'] ? ' agend-member-jobs--inherit-fonts' : '' ) . '"'
		. ' style="' . esc_attr( $style ) . '"'
		. ' data-agend-member-jobs-config="' . esc_attr( (string) wp_json_encode( $config ) ) . '"'
		. ( $preview ? ' data-agend-member-jobs-preview="1"' : '' ) . '>'
		. '<p class="agend-member-jobs__status" role="status">' . esc_html( $config['messages']['loading'] ) . '</p>'
		. '</div>';
}

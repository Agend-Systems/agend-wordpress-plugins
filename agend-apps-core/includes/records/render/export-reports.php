<?php
/**
 * Server render of the export report surface: a button that downloads one
 * nominated report, or a menu whose trigger opens a list of reports where
 * choosing one downloads it.
 *
 * Page-builder agnostic: the same markup and client config whichever editor
 * placed the surface. An adapter passes the surface's settings (see
 * `agend_apps_records_surface_schema()`) and echoes the returned HTML.
 *
 * `reports` and `parameters` are repeater-shaped settings. Elementor stores a
 * repeater as a list of associative arrays keyed by each row control's name;
 * a block is expected to store the same shape, so both are read the same way
 * here without a builder-specific branch.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The parameter vocabulary, the automatic filter pairing and the report
// listing reader all live with the surface's schema, and this file now reads
// all three. Required explicitly rather than relying on the plugin's include
// order, so this file is correct wherever it is loaded from.
require_once __DIR__ . '/../schema/export-reports.php';

/**
 * The parameter mapping rows, normalised for the script.
 *
 * Moved from the Elementor widget's parameter_map() method unchanged, other
 * than tolerating a row that is not itself an array (defensive against a
 * builder-supplied shape this surface has not seen yet).
 *
 * @param array $settings Surface settings.
 * @return array<int, array<string, string>>
 */
function agend_apps_records_export_reports_parameter_map( array $settings ): array {
	$rows = array();
	foreach ( (array) ( $settings['parameters'] ?? array() ) as $row ) {
		$row   = is_array( $row ) ? $row : array();
		$field = agend_apps_records_export_reports_row_field( $row );
		if ( '' === $field ) {
			continue;
		}

		$filter     = (string) ( $row['param_catalogue_filter'] ?? '' );
		$custom_key = trim( (string) ( $row['param_custom_key'] ?? '' ) );

		// Automatic pairing is resolved here rather than in the browser: it
		// depends only on the configured parameter, never on what the visitor
		// has filtered, so there is nothing to defer to click time.
		if ( agend_apps_records_export_reports_filter_is_auto( $filter ) ) {
			$resolved   = agend_apps_records_export_reports_resolve_auto_filter( $field );
			$filter     = $resolved['filter'];
			$custom_key = '' !== $resolved['custom_key'] ? $resolved['custom_key'] : $custom_key;
		}

		$rows[] = array(
			'field'     => $field,
			'source'    => 'catalogue' === ( $row['param_source'] ?? 'manual' ) ? 'catalogue' : 'manual',
			'value'     => (string) ( $row['param_value'] ?? '' ),
			'filter'    => $filter,
			'customKey' => $custom_key,
		);
	}
	return $rows;
}

/**
 * The parameter field a mapping row is configured with.
 *
 * The picker wins when it holds a real key; the free-text box answers
 * otherwise. That order is what lets the picker ship without a data
 * migration: an existing row has no picker value stored, so it keeps
 * resolving through the box exactly as it did before, including a row whose
 * typed key matches nothing.
 *
 * @param array<string, mixed> $row One repeater row.
 * @return string
 */
function agend_apps_records_export_reports_row_field( array $row ): string {
	$pick = trim( (string) ( $row['param_field_pick'] ?? '' ) );

	if ( '' !== $pick && AGEND_APPS_RECORDS_EXPORT_REPORTS_FIELD_OTHER !== $pick ) {
		return $pick;
	}

	return trim( (string) ( $row['param_field'] ?? '' ) );
}

/**
 * Whether a stored "Read from filter" value asks for automatic pairing.
 *
 * A row saved before the automatic option existed stores '', which is the old
 * "nothing chosen" state and must keep meaning that rather than silently
 * acquiring a filter.
 *
 * @param string $filter Stored filter value.
 * @return bool
 */
function agend_apps_records_export_reports_filter_is_auto( string $filter ): bool {
	return AGEND_APPS_RECORDS_EXPORT_REPORTS_FILTER_AUTO === $filter;
}

/**
 * Pair a parameter to its catalogue filter.
 *
 * A `custom.<key>` parameter always reads from the custom field filter, and
 * carries its own key, which is the entry a designer previously had to type a
 * third time. A parameter with no single obvious filter resolves to nothing,
 * which leaves the row contributing no value, exactly as an unchosen filter
 * does today.
 *
 * @param string $field Parameter field key.
 * @return array{filter: string, custom_key: string}
 */
function agend_apps_records_export_reports_resolve_auto_filter( string $field ): array {
	if ( 0 === strpos( $field, 'custom.' ) ) {
		return array(
			'filter'     => 'custom_field',
			'custom_key' => substr( $field, strlen( 'custom.' ) ),
		);
	}

	$map = agend_apps_records_export_reports_auto_filter_map();

	return array(
		'filter'     => (string) ( $map[ $field ] ?? '' ),
		'custom_key' => '',
	);
}

/**
 * The reports a surface instance offers.
 *
 * Moved from the Elementor widget's offered_reports() method unchanged, other
 * than tolerating a non-array `reports` row the same way
 * agend_apps_records_export_reports_parameter_map() does.
 *
 * @param array $settings Surface settings.
 * @return array<int, array<string, string>>
 */
function agend_apps_records_export_reports_offered_reports( array $settings ): array {
	if ( 'dropdown' !== ( $settings['mode'] ?? 'button' ) ) {
		$id = (string) ( $settings['report'] ?? '' );
		return '' === $id ? array() : array( array( 'id' => $id, 'label' => '' ) );
	}

	$reports = array();
	foreach ( (array) ( $settings['reports'] ?? array() ) as $row ) {
		$row = is_array( $row ) ? $row : array();
		$id  = (string) ( $row['report_id'] ?? '' );
		if ( '' === $id ) {
			continue;
		}
		$reports[] = array( 'id' => $id, 'label' => trim( (string) ( $row['report_label'] ?? '' ) ) );
	}
	return $reports;
}

/**
 * Renders the export report surface.
 *
 * Returns '' when the instance offers no report: a button with nothing
 * chosen, or a menu with an empty list. The Elementor widget shows an
 * editor-only notice for that case itself (an editor concern, not this
 * renderer's); it decides whether to show it from the same
 * agend_apps_records_export_reports_offered_reports() call this function
 * makes, so the two never disagree about whether there is anything to offer.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'export-reports' )).
 * @param array $opts     'id' (string, default ''): a caller-supplied element
 *                        id the trigger and menu share (aria-controls/id), so
 *                        several instances on one page never collide. An
 *                        Elementor widget passes its own get_id().
 * @return string The rendered markup, or '' when the instance offers no report.
 */
function agend_apps_records_render_export_reports( array $settings, array $opts = array() ): string {
	$mode    = 'dropdown' === ( $settings['mode'] ?? 'button' ) ? 'dropdown' : 'button';
	$reports = agend_apps_records_export_reports_offered_reports( $settings );

	if ( empty( $reports ) ) {
		return '';
	}

	// TODO: this surface has no URL-typed setting to normalise today --
	// `restBase` below is server-computed, not a setting, and `reports` /
	// `parameters` are repeaters, already the same list-of-associative-arrays
	// shape whichever builder stores them. If a later report parameter
	// source needs one (e.g. reading a URL from elsewhere on the page),
	// switch it to the shared URL-shape normaliser a concurrent change is
	// adding, rather than adding a second one here.
	// The listing already says who each report is for, so a visitor who plainly
	// cannot reach one is offered a sign in rather than a download that the
	// gateway is certain to refuse. Only consulted for a signed-out visitor,
	// so a signed-in one pays nothing for this.
	$sign_in_required = agend_apps_records_export_reports_sign_in_required( $reports );
	$login_url        = agend_apps_records_export_reports_login_url();

	$config = array(
		'restBase'   => esc_url_raw( rest_url( 'agend-apps/v1' ) ),
		'mode'       => $mode,
		'reports'    => $reports,
		'format'     => 'xlsx' === ( $settings['format'] ?? 'csv' ) ? 'xlsx' : 'csv',
		'parameters' => agend_apps_records_export_reports_parameter_map( $settings ),
		// Emitted from the filter registry rather than restated in the client
		// script, so a listing filter added to the registry cannot resolve to
		// nothing until somebody remembers to update a second copy.
		'filterStateKeys' => agend_apps_records_export_reports_filter_state_keys(),
		'loginUrl'   => esc_url_raw( $login_url ),
		// The export reports feature being off is an account configuration
		// problem, not something a visitor can act on, so its hint is shown
		// only to somebody who could actually fix it.
		'canManage'  => current_user_can( 'manage_options' ),
		'labels'     => array(
			'working' => __( 'Preparing…', 'agend-apps-core' ),
			// Refusals are not faults. A visitor who lacks the membership or
			// entitlement a report needs was told to "try again shortly",
			// which sends them round the same loop forever.
			'failed'  => __( 'That report could not be downloaded. Access to some reports depends on your membership or entitlements. Contact the organisation if you believe you should have access.', 'agend-apps-core' ),
			'signIn'  => __( 'Sign in', 'agend-apps-core' ),
			'featureUnavailable' => __( 'This account does not have export reports enabled. Check the account\'s Agend plan, or contact Agend support.', 'agend-apps-core' ),
		),
	);

	$classes = 'agend-export-report agend-export-report--' . $mode;
	if ( 'yes' === ( $settings['full_width'] ?? '' ) ) {
		$classes .= ' agend-export-report--full';
	}

	$trigger_text = (string) ( $settings['button_text'] ?? __( 'Export', 'agend-apps-core' ) );

	ob_start();
	echo '<div class="' . esc_attr( $classes ) . '" data-agend-export-config="' . esc_attr( (string) wp_json_encode( $config ) ) . '">';

	if ( 'button' === $mode ) {
		if ( isset( $sign_in_required[ $reports[0]['id'] ] ) ) {
			echo '<a class="agend-export__submit agend-export__signin" href="' . esc_url( $login_url ) . '">'
				. esc_html__( 'Sign in to download', 'agend-apps-core' )
				. '</a>';
		} else {
			echo '<button type="button" class="agend-export__submit" data-agend-export-submit data-report-id="' . esc_attr( $reports[0]['id'] ) . '">' . esc_html( $trigger_text ) . '</button>';
		}
		echo '</div>';
		return (string) ob_get_clean();
	}

	$menu_id = 'agend-export-menu-' . esc_attr( (string) ( $opts['id'] ?? '' ) );

	echo '<button type="button" class="agend-export__submit agend-export__trigger" data-agend-export-trigger aria-haspopup="true" aria-expanded="false" aria-controls="' . $menu_id . '">';
	echo esc_html( $trigger_text );
	echo '<span class="agend-export__caret" aria-hidden="true"></span>';
	echo '</button>';

	// Choosing a report IS the action, so each entry is a button rather than
	// a value to be confirmed with a second click.
	echo '<ul class="agend-export__menu" id="' . $menu_id . '" data-agend-export-menu hidden>';
	foreach ( $reports as $report ) {
		$label = '' !== $report['label'] ? $report['label'] : $report['id'];

		echo '<li class="agend-export__menu-item">';

		if ( isset( $sign_in_required[ $report['id'] ] ) ) {
			echo '<a class="agend-export__item agend-export__signin" href="' . esc_url( $login_url ) . '">';
			echo esc_html(
				sprintf(
					/* translators: %s: the report's name. */
					__( 'Sign in to download %s', 'agend-apps-core' ),
					$label
				)
			);
			echo '</a></li>';
			continue;
		}

		echo '<button type="button" class="agend-export__item" data-agend-export-submit data-report-id="' . esc_attr( $report['id'] ) . '">';
		// A blank label is filled in with the report's current name at view
		// time, so a rename in Agend does not go stale in a saved template.
		echo esc_html( $label );
		echo '</button></li>';
	}
	echo '</ul>';
	echo '</div>';

	return (string) ob_get_clean();
}

/**
 * Which of the offered reports a signed-out visitor cannot reach.
 *
 * Only a positively known audience counts. The listing a signed-out visitor
 * would receive is itself audience filtered, so a members-only report is
 * simply absent from it, and absence is ambiguous: the report may equally
 * have been unpublished or deleted, which signing in would not fix. The
 * audience is therefore read from the authoring listing, which reports every
 * published report regardless of who is asking, and a report that cannot be
 * found there is left alone to take the ordinary download path.
 *
 * No new disclosure: the widget already names these reports on the page,
 * because a designer put them there.
 *
 * @param array<int, array<string, string>> $reports The offered reports.
 * @return array<string, true> Report ids that need a sign in, keyed by id.
 */
function agend_apps_records_export_reports_sign_in_required( array $reports ): array {
	if ( is_user_logged_in() || ! function_exists( 'agend_apps_directory_get_export_reports' ) ) {
		return array();
	}

	$audiences = array();
	foreach ( agend_apps_records_export_reports_declared_parameters_source() as $report ) {
		if ( ! empty( $report['id'] ) && ! empty( $report['audience'] ) ) {
			$audiences[ (string) $report['id'] ] = (string) $report['audience'];
		}
	}

	if ( empty( $audiences ) ) {
		return array();
	}

	$needs = array();
	foreach ( $reports as $report ) {
		$id       = (string) ( $report['id'] ?? '' );
		$audience = $audiences[ $id ] ?? '';

		if ( 'members_only' === $audience || 'restricted' === $audience ) {
			$needs[ $id ] = true;
		}
	}

	return $needs;
}

/**
 * The raw report listing rows the audience lookup reads.
 *
 * Split out so the lookup above can be exercised without a live gateway.
 *
 * @return array<int, array<string, mixed>>
 */
function agend_apps_records_export_reports_declared_parameters_source(): array {
	if ( ! function_exists( 'agend_apps_records_export_reports_authoring_listing' ) ) {
		return array();
	}

	$response = agend_apps_records_export_reports_authoring_listing();

	if ( is_wp_error( $response ) || empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
		return array();
	}

	return $response['data'];
}

/**
 * Where the sign in link points.
 *
 * `wp_login_url()` rather than a URL of this surface's own: it is the hook
 * every SSO and login plugin on the site already filters, so the visitor
 * lands wherever that site actually signs people in, and comes back here.
 *
 * @return string
 */
function agend_apps_records_export_reports_login_url(): string {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed to home_url(), which builds and escapes the return path.
	$current_url = home_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '' );

	return wp_login_url( $current_url );
}

<?php
/**
 * Surface gate: what each widget, block and fragment needs from the API key
 * before it can do its primary job.
 *
 * A surface (a catalogue, the cart, the login form ...) is only worth placing
 * when the connected API key holds the gateway scope its main call needs. A key
 * without it gets a 403 on every page load, and the visitor gets a broken
 * widget. {@see Agend_Apps_API::request()} already refuses such a call locally;
 * this layer sits above it and stops the surface from being drawn at all, so
 * the page shows nothing (visitors) or a clearly disabled box (an editor), and
 * no gateway call, shell attribute or script is spent on it.
 *
 * Requirements are only the scopes without which the surface's PRIMARY purpose
 * is impossible. Secondary actions (a review form, a checkout button) degrade
 * on their own through the request guard.
 *
 * Every read here comes from the stored scope option, never a lazy refresh: a
 * refresh is a gateway call, and this runs on a front-end render. When the
 * scopes are not known for the current key the surface counts as available and
 * the gateway stays the authority, exactly as the request guard behaves.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The surface requirements map.
 *
 * Each entry: `label` (string), and either `scopes` (string[], every one
 * required) or `feature` (an {@see agend_apps_records_optional_features()} id,
 * whose scopes are used so the two never drift). The app a scope belongs to is
 * its first segment, so an app-restricted key is covered by the same entry.
 *
 * Filterable so a sibling plugin can register its own surface, or a site can
 * relax an entry.
 *
 * @return array<string, array{label: string, scopes?: string[], feature?: string}>
 */
function agend_apps_records_surface_map(): array {
	$map = array(
		'directory-catalogue'       => array(
			'label'  => __( 'Agend Directory', 'agend-apps-core' ),
			'scopes' => array( 'directory.listings.search' ),
		),
		'events-catalogue'          => array(
			'label'  => __( 'Agend Events', 'agend-apps-core' ),
			'scopes' => array( 'events.events.browse' ),
		),
		'courses-catalogue'         => array(
			'label'  => __( 'Agend Courses', 'agend-apps-core' ),
			'scopes' => array( 'lms.courses.browse' ),
		),
		'memberships-catalogue'     => array(
			'label'  => __( 'Agend Memberships', 'agend-apps-core' ),
			'scopes' => array( 'crm.tiers.browse' ),
		),
		'member-login'              => array(
			'label'  => __( 'Agend Member Login', 'agend-apps-core' ),
			'scopes' => array( 'auth.sessions.create' ),
		),
		'header-auth'               => array(
			'label'  => __( 'Agend Login / Portal Link', 'agend-apps-core' ),
			'scopes' => array( 'auth.sessions.delete' ),
		),
		'account-link'              => array(
			'label'   => __( 'Agend Account Link', 'agend-apps-core' ),
			'feature' => 'sso_account_link',
		),
		'export-reports'            => array(
			'label'   => __( 'Agend Export Report', 'agend-apps-core' ),
			'feature' => 'directory_export_reports',
		),
		'add-to-cart'               => array(
			'label'  => __( 'Add to Cart', 'agend-apps-core' ),
			'scopes' => array( 'cart.items.manage' ),
		),
		'cart-header'               => array(
			'label'  => __( 'Cart Header', 'agend-apps-core' ),
			'scopes' => array( 'cart.items.browse' ),
		),
		'cart-view'                 => array(
			'label'  => __( 'Cart', 'agend-apps-core' ),
			'scopes' => array( 'cart.items.browse', 'cart.items.manage' ),
		),
		// Not a surface of its own: the Agend Panel's tickets panel, which lists
		// an event's ticket types and so cannot do its job without this scope
		// even when the events catalogue itself is available.
		'record-block-event-tickets' => array(
			'label'  => __( 'Agend Panel: Event tickets and pricing', 'agend-apps-core' ),
			'scopes' => array( 'events.tickets.browse' ),
		),
	);

	/**
	 * Filters the surface requirements map.
	 *
	 * @param array $map Surface id => requirements.
	 */
	return (array) apply_filters( 'agend_apps_records_surface_map', $map );
}

/**
 * What a surface needs from the API key.
 *
 * @param string $surface Surface id.
 * @return array{label: string, scopes: string[], apps: string[]} Empty `scopes`
 *         and `apps` for a surface the map does not list (nothing to gate).
 */
function agend_apps_records_surface_requirements( string $surface ): array {
	$map   = agend_apps_records_surface_map();
	$entry = isset( $map[ $surface ] ) && is_array( $map[ $surface ] ) ? $map[ $surface ] : array();

	$scopes = array();

	if ( ! empty( $entry['feature'] ) && function_exists( 'agend_apps_records_optional_features' ) ) {
		$features = agend_apps_records_optional_features();
		$scopes   = (array) ( $features[ (string) $entry['feature'] ]['scopes'] ?? array() );
	} elseif ( ! empty( $entry['scopes'] ) ) {
		$scopes = (array) $entry['scopes'];
	}

	$scopes = array_values( array_unique( array_map( 'strval', $scopes ) ) );
	$apps   = array();

	foreach ( $scopes as $scope ) {
		$app = (string) strtok( $scope, '.' );

		if ( '' !== $app && '*' !== $app && ! in_array( $app, $apps, true ) ) {
			$apps[] = $app;
		}
	}

	return array(
		'label'  => isset( $entry['label'] ) ? (string) $entry['label'] : $surface,
		'scopes' => $scopes,
		'apps'   => $apps,
	);
}

/**
 * What the stored key lacks for a surface.
 *
 * Answers from the stored scope option only. Empty (nothing missing) whenever
 * the answer is uncertain: the key's scopes are not known for the configured
 * key, a refresh is in flight, or the site switched the request guard off with
 * the `agend_apps_api_block_unauthorised` filter.
 *
 * @param string $surface Surface id.
 * @return array{scopes: string[], app: string} `scopes` the key lacks; `app`
 *         the first required app the key is restricted away from, or ''.
 */
function agend_apps_records_surface_missing( string $surface ): array {
	$none = array(
		'scopes' => array(),
		'app'    => '',
	);

	$needs = agend_apps_records_surface_requirements( $surface );

	if ( empty( $needs['scopes'] ) || ! class_exists( 'Agend_Apps_Key_Scopes' ) ) {
		return $none;
	}

	if ( Agend_Apps_Key_Scopes::refreshing() || ! Agend_Apps_Key_Scopes::known() ) {
		return $none;
	}

	$held    = Agend_Apps_Key_Scopes::stored_scopes();
	$missing = array();

	foreach ( $needs['scopes'] as $scope ) {
		if ( ! Agend_Apps_Key_Scopes::satisfies( $held, $scope ) ) {
			$missing[] = $scope;
		}
	}

	$app = '';

	foreach ( $needs['apps'] as $candidate ) {
		if ( ! Agend_Apps_Key_Scopes::has_app( $candidate ) ) {
			$app = $candidate;
			break;
		}
	}

	if ( empty( $missing ) && '' === $app ) {
		return $none;
	}

	// The same switch the request guard honours: a site that lets unauthorised
	// calls through to the gateway does not want the widget standing down for
	// the same reason either.
	if ( ! apply_filters( 'agend_apps_api_block_unauthorised', true, 'GET', '/surfaces/' . $surface, $missing ) ) {
		return $none;
	}

	return array(
		'scopes' => $missing,
		'app'    => $app,
	);
}

/**
 * Whether a surface can do its primary job with the stored key.
 *
 * @param string $surface Surface id.
 * @return bool True when nothing is known to be missing.
 */
function agend_apps_records_surface_available( string $surface ): bool {
	$missing = agend_apps_records_surface_missing( $surface );

	return empty( $missing['scopes'] ) && '' === $missing['app'];
}

/**
 * Whether a surface is known to be unusable with the stored key.
 *
 * @param string $surface Surface id.
 * @return bool
 */
function agend_apps_records_surface_gated( string $surface ): bool {
	return ! agend_apps_records_surface_available( $surface );
}

/**
 * The "why is this off" text for a gated surface.
 *
 * @param string $surface Surface id.
 * @return string Plain text naming the missing scopes and/or app, or '' when
 *                the surface is available.
 */
function agend_apps_records_surface_notice( string $surface ): string {
	$missing = agend_apps_records_surface_missing( $surface );

	if ( empty( $missing['scopes'] ) && '' === $missing['app'] ) {
		return '';
	}

	$needs = agend_apps_records_surface_requirements( $surface );
	$parts = array();

	if ( ! empty( $missing['scopes'] ) ) {
		$parts[] = sprintf(
			/* translators: 1: widget name, 2: comma-separated missing scopes. */
			__( '%1$s needs the API key scope(s): %2$s.', 'agend-apps-core' ),
			$needs['label'],
			implode( ', ', $missing['scopes'] )
		);
	}

	if ( '' !== $missing['app'] ) {
		$parts[] = sprintf(
			/* translators: 1: widget name, 2: app slug, e.g. events. */
			__( '%1$s needs the "%2$s" app, which the API key is not allowed to use.', 'agend-apps-core' ),
			$needs['label'],
			$missing['app']
		);
	}

	$parts[] = __( 'Check the scope list on the API key in the Agend dashboard, then use Test connection in Agend Apps settings.', 'agend-apps-core' );

	return implode( ' ', $parts );
}

/**
 * The gate for a surface.
 *
 * @param string $surface Surface id.
 * @param bool   $editor  Whether an editor (Elementor canvas, block editor
 *                        preview) is drawing this rather than a visitor.
 * @return string|null Null when the surface is open and should render as
 *                     normal. When gated: a disabled notice box for an editor,
 *                     and '' for a visitor (render nothing).
 */
function agend_apps_records_surface_gate( string $surface, bool $editor ): ?string {
	// The sign-in surfaces only call the gateway in `credentials` mode. In
	// `wordpress` mode WordPress owns the session and nothing is proxied; in
	// `sso` mode the surface draws nothing at all. Either way there is no key
	// requirement to enforce.
	if ( in_array( $surface, array( 'member-login', 'header-auth' ), true )
		&& class_exists( 'Agend_Apps_Settings' ) && ! Agend_Apps_Settings::credential_login_enabled()
	) {
		return null;
	}

	if ( agend_apps_records_surface_available( $surface ) ) {
		return null;
	}

	if ( ! $editor ) {
		return '';
	}

	$needs = agend_apps_records_surface_requirements( $surface );

	// Inline style: there is no editor stylesheet shared by both page builders,
	// and the box must look the same in the Elementor canvas and the block
	// editor preview.
	return sprintf(
		'<div class="agend-surface-gate" role="note" aria-disabled="true" style="%1$s"><strong style="display:block;margin-bottom:4px;">%2$s</strong><span style="display:block;">%3$s</span><span style="display:block;margin-top:6px;font-style:italic;">%4$s</span></div>',
		esc_attr( 'box-sizing:border-box;border:2px dashed #a7aaad;border-radius:4px;background:#f6f7f7;color:#646970;padding:16px 20px;font:13px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;text-align:left;cursor:not-allowed;' ),
		esc_html(
			sprintf(
				/* translators: %s: widget name. */
				__( '%s (disabled)', 'agend-apps-core' ),
				$needs['label']
			)
		),
		esc_html( agend_apps_records_surface_notice( $surface ) ),
		esc_html__( 'Visitors see nothing in this spot until the key is fixed.', 'agend-apps-core' )
	);
}

/**
 * Whether an editor, not a visitor, is behind this request.
 *
 * For schema option lists that cost a gateway call. Elementor registers a
 * widget's controls on every front-end render of it too, and an `options`
 * callable takes no arguments, so unlike the renderers' `preview` opt the
 * answer cannot be passed in. Elementor is asked for edit mode and the preview
 * frame, plus the `elementor_ajax` request that loads every widget's controls
 * into the panel, which is neither. The block editor is the schema route its
 * inspector reads. Both ajax and REST need `edit_posts`, so a visitor never
 * qualifies.
 *
 * @return bool
 */
function agend_apps_records_is_editor_request(): bool {
	if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->editor, \Elementor\Plugin::$instance->preview ) ) {
		$plugin = \Elementor\Plugin::$instance;

		if ( $plugin->editor->is_edit_mode() || $plugin->preview->is_preview_mode() ) {
			return true;
		}
	}

	if ( ! current_user_can( 'edit_posts' ) ) {
		return false;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Compared with a constant, never stored or output.
	$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';

	if ( wp_doing_ajax() && 'elementor_ajax' === $action ) {
		return true;
	}

	$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';

	return 1 === preg_match( '#^/agend-apps/v1/surfaces/[a-z-]+/schema/?$#', $route );
}

/**
 * The catalogue surface that owns a record type. Record fields, pills, images,
 * links, panels and filters have no gateway call of their own: they draw a
 * record the catalogue fetched, so they inherit its surface.
 *
 * @param string $type 'event', 'course' or 'listing'.
 * @return string Surface id, or '' for an unknown type.
 */
function agend_apps_records_type_surface( string $type ): string {
	$surfaces = array(
		'event'   => 'events-catalogue',
		'course'  => 'courses-catalogue',
		'listing' => 'directory-catalogue',
	);

	return $surfaces[ $type ] ?? '';
}

/**
 * The gate for a templated record surface (field, pills, image, link, panel).
 *
 * The record type in scope is the one a card or detail render pushed, else, for
 * an editor preview, the type the preview would fetch. A panel that lists event
 * tickets is also held to the tickets scope. Outside both, nothing is drawn and
 * nothing is fetched, so there is nothing to gate.
 *
 * @param array $settings Surface settings (`block` is read for the panel).
 * @param array $opts     Render opts; `preview` marks an editor, `preview_type`
 *                        is the type it previews against.
 * @return string|null Null when open; else the gate output (see
 *                     {@see agend_apps_records_surface_gate()}).
 */
function agend_apps_records_record_gate( array $settings, array $opts ): ?string {
	$editor = ! empty( $opts['preview'] );
	$type   = '';

	if ( class_exists( 'Agend_Apps_Records_Record_Context' ) && Agend_Apps_Records_Record_Context::has() ) {
		$current = Agend_Apps_Records_Record_Context::current();
		$type    = (string) ( $current['type'] ?? '' );
	} elseif ( $editor ) {
		$type = '' !== (string) ( $opts['preview_type'] ?? '' ) ? (string) $opts['preview_type'] : 'event';
	}

	$surfaces = array();

	if ( '' !== agend_apps_records_type_surface( $type ) ) {
		$surfaces[] = agend_apps_records_type_surface( $type );
	}

	if ( 'event_tickets' === (string) ( $settings['block'] ?? '' ) && ( '' === $type || 'event' === $type ) ) {
		$surfaces[] = 'record-block-event-tickets';
	}

	foreach ( $surfaces as $surface ) {
		$gate = agend_apps_records_surface_gate( $surface, $editor );

		if ( null !== $gate ) {
			return $gate;
		}
	}

	return null;
}

/**
 * Refuses a proxied gateway route whose owning surface is gated.
 *
 * Hooked on `rest_pre_dispatch`. Only the proxy groups that serve exactly one
 * surface are mapped: `/auth/*`, `/cart*`, `/sites/config` and `/events/{slug}/*`
 * are shared between surfaces (or the whole site), so refusing them for one
 * surface's sake would break another, and the request guard already refuses the
 * individual call there.
 *
 * @param mixed           $result  Response so far (null to continue).
 * @param WP_REST_Server  $server  Server instance.
 * @param WP_REST_Request $request Request.
 * @return mixed A WP_Error (403, `agend_apps_feature_unavailable`) when gated,
 *               else `$result` untouched.
 */
function agend_apps_records_surface_gate_rest( $result, $server, $request ) {
	if ( null !== $result || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
		return $result;
	}

	$surface = agend_apps_records_rest_route_surface( (string) $request->get_route() );

	if ( '' === $surface || ! agend_apps_records_surface_gated( $surface ) ) {
		return $result;
	}

	return new WP_Error(
		'agend_apps_feature_unavailable',
		agend_apps_records_surface_notice( $surface ),
		array(
			'status'  => 403,
			'surface' => $surface,
		)
	);
}
add_filter( 'rest_pre_dispatch', 'agend_apps_records_surface_gate_rest', 10, 3 );

/**
 * The surface a REST route serves exclusively, or '' for a route that is not
 * mapped one-to-one. Covers the proxy under `agend-apps/v1` and the card
 * fragments under both namespaces.
 *
 * @param string $route Request route, e.g. `/agend-apps/v1/directory/search`.
 * @return string Surface id or ''.
 */
function agend_apps_records_rest_route_surface( string $route ): string {
	if ( 1 !== preg_match( '#^/(?:agend-apps/v1|agend-elementor/v1)(/.*)$#', $route, $match ) ) {
		return '';
	}

	$path = rtrim( $match[1], '/' );

	$patterns = array(
		'#^/directory/export-reports(/|$)#'                      => 'export-reports',
		'#^/directory/(search|categories|facets|reviews)$#'      => 'directory-catalogue',
		'#^/directory/listings(/|$)#'                            => 'directory-catalogue',
		'#^/cards/listings$#'                                    => 'directory-catalogue',
		'#^/events(/categories|/venues)?$#'                      => 'events-catalogue',
		'#^/cards/events$#'                                      => 'events-catalogue',
		'#^/lms/courses(/[^/]+)?$#'                              => 'courses-catalogue',
		'#^/cards/courses$#'                                     => 'courses-catalogue',
		'#^/crm/tiers(/|$)#'                                     => 'memberships-catalogue',
		'#^/account-link(/|$)#'                                  => 'account-link',
	);

	foreach ( $patterns as $pattern => $surface ) {
		if ( 1 === preg_match( $pattern, $path ) ) {
			return $surface;
		}
	}

	return '';
}

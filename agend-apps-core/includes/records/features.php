<?php
/**
 * Optional feature registry: features that send a gateway parameter or call an
 * endpoint requiring an API-key scope the connected key may not hold.
 *
 * Several optional plugin features (member LMS achievements on the Directory
 * detail, the Export Report widget, the review submission form, the member
 * "My Listing" self-service, and the SSO account link) each need one or more
 * gateway scopes. A key without the scope gets a 403 from the gateway for
 * that parameter/endpoint; for `include=achievements` on the listing detail
 * request this was severe enough to 404 the whole page (the detail resolver
 * treats any gateway error as "not found").
 *
 * This registry is the single place a feature declares which scope(s) it
 * needs, so every consumer (settings screen, widget controls, runtime) checks
 * availability the same way instead of re-deriving it. Scopes come from
 * {@see Agend_Apps_Key_Scopes}.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The optional-feature registry.
 *
 * Each entry: `label` (string), `scopes` (string[], every one required),
 * `option` (optional option name; when present the feature also needs that
 * option switched on), `description` (optional string).
 *
 * Filterable so a site (or a sibling plugin) can add its own scope-gated
 * feature, or amend an entry's scopes/option.
 *
 * @return array<string, array{label: string, scopes: string[], option?: string, description?: string}>
 */
function agend_apps_records_optional_features(): array {
	$features = array(
		'directory_achievements'  => array(
			'label'       => __( 'Badges & Credentials', 'agend-apps-core' ),
			'scopes'      => array( 'directory.achievements.browse' ),
			'option'      => AGEND_APPS_RECORDS_SHOW_ACHIEVEMENTS_OPTION,
			'description' => __( 'Shows a member\'s LMS badges and certificates on the Directory listing detail view.', 'agend-apps-core' ),
		),
		'directory_export_reports' => array(
			'label'       => __( 'Export Reports', 'agend-apps-core' ),
			'scopes'      => array( 'directory.export_reports.browse' ),
			'description' => __( 'The Agend Export Report widget\'s report list and downloads.', 'agend-apps-core' ),
		),
		'directory_review_form'   => array(
			'label'       => __( 'Review Submission Form', 'agend-apps-core' ),
			'scopes'      => array( 'directory.reviews.manage' ),
			'description' => __( 'Lets visitors submit a Directory listing review from the detail view.', 'agend-apps-core' ),
		),
		'directory_my_listing'    => array(
			'label'       => __( 'Member "My Listing" Self-Service', 'agend-apps-core' ),
			'scopes'      => array( 'directory.listings.self_update' ),
			'description' => __( 'Lets a signed-in member view and edit their own Directory listing.', 'agend-apps-core' ),
		),
		'sso_account_link'        => array(
			'label'       => __( 'SSO Account Link', 'agend-apps-core' ),
			'scopes'      => array( 'sso.identities.read', 'sso.tokens.create' ),
			'description' => __( 'Looks up and mints tokens for a member\'s Agend SSO identity link.', 'agend-apps-core' ),
		),
		'sso_identity_link'       => array(
			'label'       => __( 'SSO Identity Link (WordPress IdP)', 'agend-apps-core' ),
			// Deliberately its own feature, NOT folded into `sso_account_link`
			// above: that feature gates `Agend_Apps_Token_Worker::provide_token()`
			// (mint, `sso.tokens.create`) and the status lookup
			// (`sso.identities.read`). A key can hold either of those without
			// holding `sso.identities.create` (the write scope this feature
			// gates, docs/PLAN-wordpress-idp-option-b.md section 4.2). Folding
			// them together would make a key that can mint tokens for an
			// already-linked member, but cannot create new links, look
			// unavailable for minting too -- wrongly standing down a working
			// feature because of a DIFFERENT, unrelated scope gap.
			// `sso.connections.create` (auto-creating the SSO connection on
			// first link) is deliberately NOT required here: it is optional
			// gateway behaviour the account may not need (the connection can
			// be created ahead of time in the dashboard), so a key lacking it
			// must not be treated as unable to link at all.
			'scopes'      => array( 'sso.identities.create' ),
			'description' => __( 'Links a WordPress member to their Agend identity server-to-server when WordPress is the identity provider.', 'agend-apps-core' ),
		),
	);

	/**
	 * Filters the optional-feature registry.
	 *
	 * @param array $features The registry, keyed by feature id.
	 */
	return (array) apply_filters( 'agend_apps_records_optional_features', $features );
}

/**
 * Whether the connected API key holds every scope a feature needs.
 *
 * A feature id absent from the registry is treated as scope-free (nothing to
 * gate), so an unknown id never blocks unexpectedly.
 *
 * @param string $feature Feature id (a key of {@see agend_apps_records_optional_features()}).
 * @return bool True when every required scope is held (or the feature needs none).
 */
function agend_apps_records_feature_scopes_held( string $feature ): bool {
	$features = agend_apps_records_optional_features();

	if ( ! isset( $features[ $feature ] ) ) {
		return true;
	}

	$scopes = (array) ( $features[ $feature ]['scopes'] ?? array() );

	if ( empty( $scopes ) ) {
		return true;
	}

	if ( ! class_exists( 'Agend_Apps_Key_Scopes' ) ) {
		return false;
	}

	return Agend_Apps_Key_Scopes::has( ...$scopes );
}

/**
 * The scopes a feature needs that the connected API key does not hold.
 *
 * @param string $feature Feature id.
 * @return string[] Missing scopes. Empty when the feature needs none, or holds them all.
 */
function agend_apps_records_feature_missing_scopes( string $feature ): array {
	$features = agend_apps_records_optional_features();

	if ( ! isset( $features[ $feature ] ) ) {
		return array();
	}

	$scopes = (array) ( $features[ $feature ]['scopes'] ?? array() );

	if ( empty( $scopes ) || ! class_exists( 'Agend_Apps_Key_Scopes' ) ) {
		return array();
	}

	$held = Agend_Apps_Key_Scopes::all();

	return array_values( array_diff( $scopes, $held ) );
}

/**
 * Whether the connected key's scopes are not yet known: never fetched, or the
 * last fetch failed and left nothing recorded for the current key.
 *
 * UI should distinguish this from "known to be missing" — "verify the
 * connection" is the correct call to action here, not "this key lacks a
 * scope".
 *
 * @return bool
 */
function agend_apps_records_feature_unknown_scopes(): bool {
	return ! class_exists( 'Agend_Apps_Key_Scopes' ) || ! Agend_Apps_Key_Scopes::known();
}

/**
 * Whether a scope-gated optional feature is available: its option (if any)
 * is switched on, AND the connected key holds every scope it needs.
 *
 * An unfetched/unknown scope list is treated as "not held" for any feature
 * that needs scopes, so a feature never silently sends a param the key turns
 * out to reject.
 *
 * @param string $feature Feature id.
 * @return bool
 */
function agend_apps_records_feature_available( string $feature ): bool {
	$features = agend_apps_records_optional_features();

	if ( ! isset( $features[ $feature ] ) ) {
		return true;
	}

	$entry = $features[ $feature ];

	if ( ! empty( $entry['option'] ) && '1' !== (string) get_option( $entry['option'], '' ) ) {
		return false;
	}

	$scopes = (array) ( $entry['scopes'] ?? array() );

	if ( empty( $scopes ) ) {
		return true;
	}

	if ( agend_apps_records_feature_unknown_scopes() ) {
		return false;
	}

	return agend_apps_records_feature_scopes_held( $feature );
}

/**
 * The user-facing "why is this off" notice for a scope-gated feature.
 *
 * @param string $feature Feature id.
 * @return string The notice text, or '' when the feature needs no scopes.
 */
function agend_apps_records_feature_missing_scope_notice( string $feature ): string {
	$features = agend_apps_records_optional_features();
	$label    = isset( $features[ $feature ]['label'] ) ? (string) $features[ $feature ]['label'] : $feature;
	$scopes   = isset( $features[ $feature ]['scopes'] ) ? (array) $features[ $feature ]['scopes'] : array();

	if ( empty( $scopes ) ) {
		return '';
	}

	if ( agend_apps_records_feature_unknown_scopes() ) {
		return sprintf(
			/* translators: %s: feature label. */
			__( '%s: verify the connection first (Agend Apps settings > Test connection) to check the API key\'s scopes.', 'agend-apps-core' ),
			$label
		);
	}

	$missing = agend_apps_records_feature_missing_scopes( $feature );

	if ( empty( $missing ) ) {
		return '';
	}

	return sprintf(
		/* translators: 1: feature label, 2: comma-separated missing scopes. */
		__( '%1$s requires the API key scope(s): %2$s. The connected key does not hold it/them.', 'agend-apps-core' ),
		$label,
		implode( ', ', $missing )
	);
}

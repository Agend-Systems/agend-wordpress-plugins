<?php
/**
 * CMS API functions.
 *
 * Server-side PHP wrappers for the Agend gateway's `/v1/cms/*` endpoints.
 * Sibling plugins MUST call these helpers rather than building gateway paths
 * or calling `agend_apps_api()` directly, so transport and path knowledge stay
 * centralised here.
 *
 * Public catalogue reads (content list and single content) are cached for
 * ANONYMOUS callers only. When a member bearer is attached the shared
 * transient store is bypassed in both directions, because the gateway
 * projects these responses per member (SPEC-CMS-20260727 US-2.3) and a shared
 * transient would serve one member's projection to the next visitor. The
 * bypass lives in `Agend_Apps_API::get_cached()`, so it applies to every
 * cached endpoint, not only these two.
 *
 * Mutations are never cached.
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
 * Lists CMS content.
 *
 * Scope: `cms.content.browse`. Cached for anonymous callers; bypassed when a
 * member bearer is attached.
 *
 * @param array $query Optional. Query parameters (camelCase): `page`, `limit`, `collection`, `category`, `tag`, `language`, `sortBy`, `sortOrder`, `search`. Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_cms_get_content( array $query = array() ) {
	/**
	 * Filters the CMS content list request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_cms_get_content_args',
		array( 'query' => $query ),
		$query
	);

	$cache_key = Agend_Apps_Cache::build_key( 'cms_content', $query );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'cms_content' );

	$response = agend_apps_api()->get_cached( '/cms/content', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded CMS content list response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_cms_get_content_response', $response, $query );
}

/**
 * Retrieves a single CMS content item by slug.
 *
 * Scope: `cms.content.browse`. Cached for anonymous callers; bypassed when a
 * member bearer is attached.
 *
 * @param string $slug    Content slug.
 * @param array  $query   Optional. Query parameters (camelCase): `collection`, `language`. Default empty.
 * @return array|WP_Error Decoded content item on success, or WP_Error on failure.
 */
function agend_apps_cms_get_content_by_slug( string $slug, array $query = array() ) {
	/**
	 * Filters the single-content request args before the request is sent.
	 *
	 * @param array  $args  Request args.
	 * @param string $slug  Content slug.
	 * @param array  $query Query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_cms_get_content_by_slug_args',
		array( 'query' => $query ),
		$slug,
		$query
	);

	$cache_key = Agend_Apps_Cache::build_key( 'cms_content_single', array( 'slug' => $slug ) );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'cms_content_single' );

	$response = agend_apps_api()->get_cached( '/cms/content/' . rawurlencode( $slug ), $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded single-content response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $slug     Content slug.
	 * @param array  $query    Query parameters.
	 */
	return apply_filters( 'agend_apps_cms_get_content_by_slug_response', $response, $slug, $query );
}

/**
 * Uploads a protected file to Agend's private asset storage.
 *
 * Scope: `cms.assets.create`. Server-to-server: the bytes go from this server
 * to the gateway, never from the browser to Agend storage
 * (SPEC-CMS-20260727 US-5.1 criterion 3).
 *
 * The return carries the opaque asset id and display metadata only. There is
 * deliberately no storage path or bucket in it, so a caller cannot persist a
 * routable reference to the file even by accident: the id is meaningless
 * without the authorising download endpoint, which is what makes it safe to
 * store in WordPress and emit in a page.
 *
 * @param string $file_path Absolute path to a readable local file.
 * @param string $file_name Display name to record.
 * @param string $mime_type Content type to record.
 * @return array|WP_Error Decoded response, or an error.
 */
function agend_apps_cms_upload_asset(
	string $file_path,
	string $file_name,
	string $mime_type
) {
	if ( ! is_readable( $file_path ) ) {
		return new WP_Error(
			'agend_apps_cms_unreadable_file',
			__( 'The file could not be read for upload.', 'agend-apps-core' )
		);
	}

	$contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	if ( false === $contents ) {
		return new WP_Error(
			'agend_apps_cms_unreadable_file',
			__( 'The file could not be read for upload.', 'agend-apps-core' )
		);
	}

	// A fresh boundary per request. Reusing one across requests would let a
	// filename containing the boundary string from an earlier upload split the
	// body of a later one.
	$boundary = 'agend' . bin2hex( random_bytes( 16 ) );

	// The filename is sanitised for the HEADER only, so it cannot inject CRLF
	// and forge additional multipart headers. The DISPLAY name travels
	// separately, as an ordinary form field, where it needs no such surgery.
	$header_name = str_replace( array( "\r", "\n", '"' ), '', $file_name );

	$body  = '--' . $boundary . "\r\n";
	$body .= 'Content-Disposition: form-data; name="file"; filename="' . $header_name . '"' . "\r\n";
	$body .= 'Content-Type: ' . str_replace( array( "\r", "\n" ), '', $mime_type ) . "\r\n\r\n";
	$body .= $contents . "\r\n";
	$body .= '--' . $boundary . "--\r\n";

	$response = agend_apps_api()->request(
		'POST',
		'/cms/assets',
		array(
			'body'    => $body,
			'headers' => array(
				'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
			),
			// Generous: this is bytes over the wire, not a metadata call.
			'timeout' => 120,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded upload response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 */
	return apply_filters( 'agend_apps_cms_upload_asset_response', $response );
}

/**
 * Requests a short-lived signed URL for a protected asset.
 *
 * Scope: `cms.assets.browse`. Requires a member bearer: the gateway
 * re-resolves the caller's membership and re-evaluates the governing content
 * policy on every call, so an anonymous request is refused
 * (SPEC-CMS-20260727 US-5.2).
 *
 * Never cached. The response carries a URL minted for one caller with a very
 * short life, so a shared transient would both hand it to the wrong visitor
 * and outlive it.
 *
 * @param string $asset_id Opaque Agend asset id.
 * @return array|WP_Error Decoded response, or an error.
 */
function agend_apps_cms_get_asset_download( string $asset_id ) {
	$asset_id = trim( $asset_id );

	if ( '' === $asset_id ) {
		return new WP_Error(
			'agend_apps_cms_missing_asset_id',
			__( 'An asset id is required.', 'agend-apps-core' )
		);
	}

	return agend_apps_api()->request(
		'GET',
		'/cms/assets/' . rawurlencode( $asset_id )
	);
}

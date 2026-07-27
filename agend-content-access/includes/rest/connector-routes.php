<?php
/**
 * Source connector: the endpoints Agend reads authored content from.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the connector routes.
 *
 * A namespace of its own, deliberately separate from Core's
 * `/agend-apps/v1/cms/*` catalogue proxy (Decision 2.11). Those routes are
 * publicly readable and read PROJECTED content downstream from Agend; these are
 * credential-gated and serve SOURCE material upstream to it. The two directions
 * must never share a namespace, because a mistake there means either source
 * material behind a public route or projection applied twice.
 */
function agend_content_access_register_connector_routes(): void {
	$namespace = 'agend-content-access/v1';

	register_rest_route(
		$namespace,
		'/connector/documents',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'agend_content_access_connector_list',
				'permission_callback' => 'agend_content_access_connector_permission',
				'args'                => array(
					'page'          => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page'      => array(
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
					'post_type'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'modified_after' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
		)
	);

	register_rest_route(
		$namespace,
		'/connector/documents/(?P<id>\d+)',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'agend_content_access_connector_get',
				'permission_callback' => 'agend_content_access_connector_permission',
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			),
		)
	);
}

/**
 * Gate for every connector route.
 *
 * The ONLY thing that opens these routes is a valid connector credential.
 * Deliberately not `current_user_can( 'manage_options' )` and not
 * `is_user_logged_in()`: a logged-in administrator browsing the site must not
 * be able to pull the full source export of every restricted document just by
 * visiting a URL, and neither should a compromised admin session. The
 * credential is the whole authorisation, which is why it is rotatable and
 * scoped to content sync alone.
 *
 * @param WP_REST_Request $request Request.
 * @return true|WP_Error
 */
function agend_content_access_connector_permission( $request ) {
	if ( Agend_Content_Access_Credentials::verify_request( $request ) ) {
		return true;
	}

	// One message for every failure mode: absent, malformed and wrong all look
	// identical from outside, so a caller cannot probe for whether a credential
	// happens to be configured.
	return new WP_Error(
		'agend_content_access_connector_forbidden',
		__( 'A valid connector credential is required.', 'agend-content-access' ),
		array( 'status' => 401 )
	);
}

/**
 * Lists documents available for sync.
 *
 * Identifiers and revisions only, no bodies. Agend walks this to find what has
 * changed, then fetches the full record per document, so a routine poll does
 * not transfer every protected body on the site.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function agend_content_access_connector_list( $request ) {
	$post_types = Agend_Content_Access_Meta_Box::supported_post_types();
	$requested  = (string) $request->get_param( 'post_type' );

	if ( '' !== $requested ) {
		$post_types = in_array( $requested, $post_types, true )
			? array( $requested )
			: array();
	}

	if ( array() === $post_types ) {
		return rest_ensure_response( array( 'documents' => array(), 'total' => 0 ) );
	}

	$args = array(
		'post_type'      => $post_types,
		// Published only. A draft is not content the association has decided to
		// publish anywhere, and syncing one would surface it through the CMS
		// before an editor intended.
		'post_status'    => 'publish',
		'posts_per_page' => (int) $request->get_param( 'per_page' ),
		'paged'          => (int) $request->get_param( 'page' ),
		'orderby'        => 'modified',
		'order'          => 'ASC',
		'no_found_rows'  => false,
	);

	$modified_after = (string) $request->get_param( 'modified_after' );

	if ( '' !== $modified_after ) {
		$args['date_query'] = array(
			array(
				'column' => 'post_modified_gmt',
				'after'  => $modified_after,
			),
		);
	}

	$query     = new WP_Query( $args );
	$documents = array();

	foreach ( $query->posts as $post ) {
		$documents[] = array(
			'id'          => (string) $post->ID,
			'revision'    => Agend_Content_Access_Exporter::revision_of( $post ),
			'slug'        => (string) $post->post_name,
			'collection'  => (string) $post->post_type,
			'modified_at' => str_replace( ' ', 'T', (string) $post->post_modified_gmt ) . 'Z',
		);
	}

	return rest_ensure_response(
		array(
			'documents' => $documents,
			'total'     => (int) $query->found_posts,
		)
	);
}

/**
 * Returns the full connector record for one document.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function agend_content_access_connector_get( $request ) {
	$post = get_post( (int) $request->get_param( 'id' ) );

	if ( ! $post instanceof WP_Post ) {
		return new WP_Error(
			'agend_content_access_not_found',
			__( 'Document not found.', 'agend-content-access' ),
			array( 'status' => 404 )
		);
	}

	if ( ! in_array( $post->post_type, Agend_Content_Access_Meta_Box::supported_post_types(), true ) ) {
		// Not an exportable type. Reported as not-found rather than forbidden:
		// whether a given id is an attachment or a private CPT is not something
		// the connector needs to learn.
		return new WP_Error(
			'agend_content_access_not_found',
			__( 'Document not found.', 'agend-content-access' ),
			array( 'status' => 404 )
		);
	}

	if ( 'publish' !== $post->post_status ) {
		return new WP_Error(
			'agend_content_access_not_found',
			__( 'Document not found.', 'agend-content-access' ),
			array( 'status' => 404 )
		);
	}

	$response = rest_ensure_response( Agend_Content_Access_Exporter::record( $post ) );

	// Source material is never cacheable by anything in front of WordPress.
	$response->header( 'Cache-Control', 'private, no-store' );

	return $response;
}

<?php
/**
 * Events API functions.
 *
 * Server-side PHP wrappers for the Agend gateway's `/v1/events/*` endpoints.
 * Sibling plugins MUST call these helpers rather than building gateway paths
 * or calling `agend_apps_api()` directly, so transport and path knowledge stay
 * centralised here.
 *
 * Public catalogue reads (events, tickets, categories, venues, embed) are
 * cached. Identity-specific reads (registrations, `my-*`) and every mutation
 * are never cached. Member endpoints rely on the Supabase bearer token resolved
 * by `agend_apps_get_bearer_token()` and attached automatically by the client.
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
 * Lists events.
 *
 * Scope: `events.events.browse`. Cached.
 *
 * @param array $query Optional. Query parameters (camelCase): `page`, `limit`, `search`, `sortBy`, `sortOrder`, date filters. Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_events_get_events( array $query = array() ) {
	/**
	 * Filters the events list request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_events_get_events_args',
		array( 'query' => $query ),
		$query
	);

	$cache_key = Agend_Apps_Cache::build_key( 'events_list', $query );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'events_list' );

	$response = agend_apps_api()->get_cached( '/events', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded events list response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_events_get_events_response', $response, $query );
}

/**
 * Retrieves a single event by slug.
 *
 * Scope: `events.events.browse`. Cached.
 *
 * @param string $slug Event slug.
 * @return array|WP_Error Decoded event on success, or WP_Error on failure.
 */
function agend_apps_events_get_event( string $slug, array $query = array() ) {
	/**
	 * Filters the single-event request args before the request is sent.
	 *
	 * @param array  $args  Request args.
	 * @param string $slug  Event slug.
	 * @param array  $query Query parameters (e.g. include=sponsors).
	 */
	$args = (array) apply_filters(
		'agend_apps_events_get_event_args',
		empty( $query ) ? array() : array( 'query' => $query ),
		$slug,
		$query
	);

	$cache_key = Agend_Apps_Cache::build_key( 'events_single', array( 'slug' => $slug, 'query' => $query ) );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'events_single' );

	$response = agend_apps_api()->get_cached( '/events/' . rawurlencode( $slug ), $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded single-event response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $slug     Event slug.
	 */
	return apply_filters( 'agend_apps_events_get_event_response', $response, $slug );
}

/**
 * Creates an event.
 *
 * Scope: `events.events.create`.
 *
 * @param array $event Event payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded event on success, or WP_Error on failure.
 */
function agend_apps_events_create_event( array $event ) {
	/**
	 * Filters the create-event request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $event Event payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_events_create_event_args',
		array( 'body' => $event ),
		$event
	);

	$response = agend_apps_api()->request( 'POST', '/events', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded create-event response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $event    Event payload.
	 */
	return apply_filters( 'agend_apps_events_create_event_response', $response, $event );
}

/**
 * Updates an event.
 *
 * Scope: `events.events.update`.
 *
 * @param string $slug  Event slug.
 * @param array  $event Updated event payload (snake_case).
 * @return array|WP_Error Decoded event on success, or WP_Error on failure.
 */
function agend_apps_events_update_event( string $slug, array $event ) {
	/**
	 * Filters the update-event request args before the request is sent.
	 *
	 * @param array  $args  Request args.
	 * @param string $slug  Event slug.
	 * @param array  $event Updated event payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_events_update_event_args',
		array( 'body' => $event ),
		$slug,
		$event
	);

	$response = agend_apps_api()->request( 'PATCH', '/events/' . rawurlencode( $slug ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded update-event response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $slug     Event slug.
	 * @param array  $event    Updated event payload.
	 */
	return apply_filters( 'agend_apps_events_update_event_response', $response, $slug, $event );
}

/**
 * Deletes an event.
 *
 * Scope: `events.events.delete`.
 *
 * @param string $slug Event slug.
 * @return array|WP_Error Decoded confirmation on success, or WP_Error on failure.
 */
function agend_apps_events_delete_event( string $slug ) {
	/**
	 * Filters the delete-event request args before the request is sent.
	 *
	 * @param array  $args Request args.
	 * @param string $slug Event slug.
	 */
	$args = (array) apply_filters( 'agend_apps_events_delete_event_args', array(), $slug );

	$response = agend_apps_api()->request( 'DELETE', '/events/' . rawurlencode( $slug ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded delete-event response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $slug     Event slug.
	 */
	return apply_filters( 'agend_apps_events_delete_event_response', $response, $slug );
}

/**
 * Registers an attendee for an event.
 *
 * Scope: `events.registrations.create`. Supports a member bearer token or an
 * anonymous registration.
 *
 * @param string $slug         Event slug.
 * @param array  $registration Registration payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded registration on success, or WP_Error on failure.
 */
function agend_apps_events_register( string $slug, array $registration ) {
	/**
	 * Filters the event-register request args before the request is sent.
	 *
	 * @param array  $args         Request args.
	 * @param string $slug         Event slug.
	 * @param array  $registration Registration payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_events_register_args',
		array( 'body' => $registration ),
		$slug,
		$registration
	);

	$response = agend_apps_api()->request( 'POST', '/events/' . rawurlencode( $slug ) . '/register', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded event-register response before it is returned.
	 *
	 * @param array  $response     Decoded response body.
	 * @param string $slug         Event slug.
	 * @param array  $registration Registration payload.
	 */
	return apply_filters( 'agend_apps_events_register_response', $response, $slug, $registration );
}

/**
 * Lists the ticket types for an event.
 *
 * Scope: `events.tickets.browse`. Cached.
 *
 * @param string $slug Event slug.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_events_get_tickets( string $slug ) {
	/**
	 * Filters the event-tickets request args before the request is sent.
	 *
	 * @param array  $args Request args.
	 * @param string $slug Event slug.
	 */
	$args = (array) apply_filters( 'agend_apps_events_get_tickets_args', array(), $slug );

	$cache_key = Agend_Apps_Cache::build_key( 'events_tickets', array( 'slug' => $slug ) );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'events_tickets' );

	$response = agend_apps_api()->get_cached( '/events/' . rawurlencode( $slug ) . '/tickets', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded event-tickets response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $slug     Event slug.
	 */
	return apply_filters( 'agend_apps_events_get_tickets_response', $response, $slug );
}

/**
 * Creates a ticket type for an event.
 *
 * Scope: `events.tickets.create`.
 *
 * @param string $slug   Event slug.
 * @param array  $ticket Ticket payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded ticket on success, or WP_Error on failure.
 */
function agend_apps_events_create_ticket( string $slug, array $ticket ) {
	/**
	 * Filters the create-ticket request args before the request is sent.
	 *
	 * @param array  $args   Request args.
	 * @param string $slug   Event slug.
	 * @param array  $ticket Ticket payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_events_create_ticket_args',
		array( 'body' => $ticket ),
		$slug,
		$ticket
	);

	$response = agend_apps_api()->request( 'POST', '/events/' . rawurlencode( $slug ) . '/tickets', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded create-ticket response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $slug     Event slug.
	 * @param array  $ticket   Ticket payload.
	 */
	return apply_filters( 'agend_apps_events_create_ticket_response', $response, $slug, $ticket );
}

/**
 * Updates a ticket type for an event.
 *
 * Scope: `events.tickets.update`.
 *
 * @param string $slug      Event slug.
 * @param string $ticket_id Ticket ID.
 * @param array  $ticket    Updated ticket payload (snake_case).
 * @return array|WP_Error Decoded ticket on success, or WP_Error on failure.
 */
function agend_apps_events_update_ticket( string $slug, string $ticket_id, array $ticket ) {
	/**
	 * Filters the update-ticket request args before the request is sent.
	 *
	 * @param array  $args      Request args.
	 * @param string $slug      Event slug.
	 * @param string $ticket_id Ticket ID.
	 * @param array  $ticket    Updated ticket payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_events_update_ticket_args',
		array( 'body' => $ticket ),
		$slug,
		$ticket_id,
		$ticket
	);

	$path     = '/events/' . rawurlencode( $slug ) . '/tickets/' . rawurlencode( $ticket_id );
	$response = agend_apps_api()->request( 'PATCH', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded update-ticket response before it is returned.
	 *
	 * @param array  $response  Decoded response body.
	 * @param string $slug      Event slug.
	 * @param string $ticket_id Ticket ID.
	 * @param array  $ticket    Updated ticket payload.
	 */
	return apply_filters( 'agend_apps_events_update_ticket_response', $response, $slug, $ticket_id, $ticket );
}

/**
 * Deletes a ticket type for an event.
 *
 * Scope: `events.tickets.delete`.
 *
 * @param string $slug      Event slug.
 * @param string $ticket_id Ticket ID.
 * @return array|WP_Error Decoded confirmation on success, or WP_Error on failure.
 */
function agend_apps_events_delete_ticket( string $slug, string $ticket_id ) {
	/**
	 * Filters the delete-ticket request args before the request is sent.
	 *
	 * @param array  $args      Request args.
	 * @param string $slug      Event slug.
	 * @param string $ticket_id Ticket ID.
	 */
	$args = (array) apply_filters( 'agend_apps_events_delete_ticket_args', array(), $slug, $ticket_id );

	$path     = '/events/' . rawurlencode( $slug ) . '/tickets/' . rawurlencode( $ticket_id );
	$response = agend_apps_api()->request( 'DELETE', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded delete-ticket response before it is returned.
	 *
	 * @param array  $response  Decoded response body.
	 * @param string $slug      Event slug.
	 * @param string $ticket_id Ticket ID.
	 */
	return apply_filters( 'agend_apps_events_delete_ticket_response', $response, $slug, $ticket_id );
}

/**
 * Joins the waitlist for an event.
 *
 * Scope: `events.waitlist.create`.
 *
 * @param string $slug    Event slug.
 * @param array  $payload Waitlist payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded waitlist entry on success, or WP_Error on failure.
 */
function agend_apps_events_join_waitlist( string $slug, array $payload ) {
	/**
	 * Filters the join-waitlist request args before the request is sent.
	 *
	 * @param array  $args    Request args.
	 * @param string $slug    Event slug.
	 * @param array  $payload Waitlist payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_events_join_waitlist_args',
		array( 'body' => $payload ),
		$slug,
		$payload
	);

	$response = agend_apps_api()->request( 'POST', '/events/' . rawurlencode( $slug ) . '/waitlist', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded join-waitlist response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $slug     Event slug.
	 * @param array  $payload  Waitlist payload.
	 */
	return apply_filters( 'agend_apps_events_join_waitlist_response', $response, $slug, $payload );
}

/**
 * Lists event categories.
 *
 * Scope: `events.categories.browse`. Cached.
 *
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_events_get_categories() {
	/**
	 * Filters the event-categories request args before the request is sent.
	 *
	 * @param array $args Request args.
	 */
	$args = (array) apply_filters( 'agend_apps_events_get_categories_args', array() );

	$cache_key = Agend_Apps_Cache::build_key( 'events_categories' );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'events_categories' );

	$response = agend_apps_api()->get_cached( '/events/categories', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded event-categories response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 */
	return apply_filters( 'agend_apps_events_get_categories_response', $response );
}

/**
 * Creates an event category.
 *
 * Scope: `events.categories.create`.
 *
 * @param array $category Category payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded category on success, or WP_Error on failure.
 */
function agend_apps_events_create_category( array $category ) {
	/**
	 * Filters the create-category request args before the request is sent.
	 *
	 * @param array $args     Request args.
	 * @param array $category Category payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_events_create_category_args',
		array( 'body' => $category ),
		$category
	);

	$response = agend_apps_api()->request( 'POST', '/events/categories', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded create-category response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $category Category payload.
	 */
	return apply_filters( 'agend_apps_events_create_category_response', $response, $category );
}

/**
 * Updates an event category.
 *
 * Scope: `events.categories.update`.
 *
 * @param string $category_id Category ID.
 * @param array  $category    Updated category payload (snake_case).
 * @return array|WP_Error Decoded category on success, or WP_Error on failure.
 */
function agend_apps_events_update_category( string $category_id, array $category ) {
	/**
	 * Filters the update-category request args before the request is sent.
	 *
	 * @param array  $args        Request args.
	 * @param string $category_id Category ID.
	 * @param array  $category    Updated category payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_events_update_category_args',
		array( 'body' => $category ),
		$category_id,
		$category
	);

	$response = agend_apps_api()->request( 'PATCH', '/events/categories/' . rawurlencode( $category_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded update-category response before it is returned.
	 *
	 * @param array  $response    Decoded response body.
	 * @param string $category_id Category ID.
	 * @param array  $category    Updated category payload.
	 */
	return apply_filters( 'agend_apps_events_update_category_response', $response, $category_id, $category );
}

/**
 * Deletes an event category.
 *
 * Scope: `events.categories.delete`.
 *
 * @param string $category_id Category ID.
 * @return array|WP_Error Decoded confirmation on success, or WP_Error on failure.
 */
function agend_apps_events_delete_category( string $category_id ) {
	/**
	 * Filters the delete-category request args before the request is sent.
	 *
	 * @param array  $args        Request args.
	 * @param string $category_id Category ID.
	 */
	$args = (array) apply_filters( 'agend_apps_events_delete_category_args', array(), $category_id );

	$response = agend_apps_api()->request( 'DELETE', '/events/categories/' . rawurlencode( $category_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded delete-category response before it is returned.
	 *
	 * @param array  $response    Decoded response body.
	 * @param string $category_id Category ID.
	 */
	return apply_filters( 'agend_apps_events_delete_category_response', $response, $category_id );
}

/**
 * Retrieves the embeddable events feed.
 *
 * Scope: `events.events.browse`. Cached.
 *
 * @param array $query Optional. Query parameters (camelCase). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_events_get_embed( array $query = array() ) {
	/**
	 * Filters the events-embed request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_events_get_embed_args',
		array( 'query' => $query ),
		$query
	);

	$cache_key = Agend_Apps_Cache::build_key( 'events_embed', $query );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'events_embed' );

	$response = agend_apps_api()->get_cached( '/events/embed', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded events-embed response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_events_get_embed_response', $response, $query );
}

/**
 * Lists venues.
 *
 * Scope: `events.venues.browse`. Cached.
 *
 * @param array $query Optional. Query parameters (camelCase). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_events_get_venues( array $query = array() ) {
	/**
	 * Filters the venues list request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_events_get_venues_args',
		array( 'query' => $query ),
		$query
	);

	$cache_key = Agend_Apps_Cache::build_key( 'events_venues', $query );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'events_venues' );

	$response = agend_apps_api()->get_cached( '/events/venues', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded venues list response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_events_get_venues_response', $response, $query );
}

/**
 * Creates a venue.
 *
 * Scope: `events.venues.create`.
 *
 * @param array $venue Venue payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded venue on success, or WP_Error on failure.
 */
function agend_apps_events_create_venue( array $venue ) {
	/**
	 * Filters the create-venue request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $venue Venue payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_events_create_venue_args',
		array( 'body' => $venue ),
		$venue
	);

	$response = agend_apps_api()->request( 'POST', '/events/venues', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded create-venue response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $venue    Venue payload.
	 */
	return apply_filters( 'agend_apps_events_create_venue_response', $response, $venue );
}

/**
 * Updates a venue.
 *
 * Scope: `events.venues.update`.
 *
 * @param string $venue_id Venue ID.
 * @param array  $venue    Updated venue payload (snake_case).
 * @return array|WP_Error Decoded venue on success, or WP_Error on failure.
 */
function agend_apps_events_update_venue( string $venue_id, array $venue ) {
	/**
	 * Filters the update-venue request args before the request is sent.
	 *
	 * @param array  $args     Request args.
	 * @param string $venue_id Venue ID.
	 * @param array  $venue    Updated venue payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_events_update_venue_args',
		array( 'body' => $venue ),
		$venue_id,
		$venue
	);

	$response = agend_apps_api()->request( 'PATCH', '/events/venues/' . rawurlencode( $venue_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded update-venue response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $venue_id Venue ID.
	 * @param array  $venue    Updated venue payload.
	 */
	return apply_filters( 'agend_apps_events_update_venue_response', $response, $venue_id, $venue );
}

/**
 * Deletes a venue.
 *
 * Scope: `events.venues.delete`.
 *
 * @param string $venue_id Venue ID.
 * @return array|WP_Error Decoded confirmation on success, or WP_Error on failure.
 */
function agend_apps_events_delete_venue( string $venue_id ) {
	/**
	 * Filters the delete-venue request args before the request is sent.
	 *
	 * @param array  $args     Request args.
	 * @param string $venue_id Venue ID.
	 */
	$args = (array) apply_filters( 'agend_apps_events_delete_venue_args', array(), $venue_id );

	$response = agend_apps_api()->request( 'DELETE', '/events/venues/' . rawurlencode( $venue_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded delete-venue response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $venue_id Venue ID.
	 */
	return apply_filters( 'agend_apps_events_delete_venue_response', $response, $venue_id );
}

/**
 * Lists registrations.
 *
 * Scope: `events.registrations.browse`. Identity-specific; not cached.
 *
 * @param array $query Optional. Query parameters (camelCase). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_events_get_registrations( array $query = array() ) {
	/**
	 * Filters the registrations list request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_events_get_registrations_args',
		array( 'query' => $query ),
		$query
	);

	$response = agend_apps_api()->request( 'GET', '/events/registrations', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded registrations list response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_events_get_registrations_response', $response, $query );
}

/**
 * Retrieves a single registration by ID.
 *
 * Scope: `events.registrations.browse`. Not cached.
 *
 * @param string $registration_id Registration ID.
 * @return array|WP_Error Decoded registration on success, or WP_Error on failure.
 */
function agend_apps_events_get_registration( string $registration_id ) {
	/**
	 * Filters the single-registration request args before the request is sent.
	 *
	 * @param array  $args            Request args.
	 * @param string $registration_id Registration ID.
	 */
	$args = (array) apply_filters( 'agend_apps_events_get_registration_args', array(), $registration_id );

	$response = agend_apps_api()->request( 'GET', '/events/registrations/' . rawurlencode( $registration_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded single-registration response before it is returned.
	 *
	 * @param array  $response        Decoded response body.
	 * @param string $registration_id Registration ID.
	 */
	return apply_filters( 'agend_apps_events_get_registration_response', $response, $registration_id );
}

/**
 * Cancels a registration.
 *
 * Scope: `events.registrations.manage`.
 *
 * @param string $registration_id Registration ID.
 * @return array|WP_Error Decoded confirmation on success, or WP_Error on failure.
 */
function agend_apps_events_cancel_registration( string $registration_id ) {
	/**
	 * Filters the cancel-registration request args before the request is sent.
	 *
	 * @param array  $args            Request args.
	 * @param string $registration_id Registration ID.
	 */
	$args = (array) apply_filters( 'agend_apps_events_cancel_registration_args', array(), $registration_id );

	$response = agend_apps_api()->request( 'DELETE', '/events/registrations/' . rawurlencode( $registration_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded cancel-registration response before it is returned.
	 *
	 * @param array  $response        Decoded response body.
	 * @param string $registration_id Registration ID.
	 */
	return apply_filters( 'agend_apps_events_cancel_registration_response', $response, $registration_id );
}

/**
 * Updates the RSVP status of a registration.
 *
 * Scope: `events.registrations.update`.
 *
 * @param string $registration_id Registration ID.
 * @param array  $payload         RSVP payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded registration on success, or WP_Error on failure.
 */
function agend_apps_events_rsvp_registration( string $registration_id, array $payload ) {
	/**
	 * Filters the rsvp-registration request args before the request is sent.
	 *
	 * @param array  $args            Request args.
	 * @param string $registration_id Registration ID.
	 * @param array  $payload         RSVP payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_events_rsvp_registration_args',
		array( 'body' => $payload ),
		$registration_id,
		$payload
	);

	$path     = '/events/registrations/' . rawurlencode( $registration_id ) . '/rsvp';
	$response = agend_apps_api()->request( 'PATCH', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded rsvp-registration response before it is returned.
	 *
	 * @param array  $response        Decoded response body.
	 * @param string $registration_id Registration ID.
	 * @param array  $payload         RSVP payload.
	 */
	return apply_filters( 'agend_apps_events_rsvp_registration_response', $response, $registration_id, $payload );
}

/**
 * Checks in a registration.
 *
 * Scope: `events.registrations.manage`.
 *
 * @param string $registration_id Registration ID.
 * @return array|WP_Error Decoded registration on success, or WP_Error on failure.
 */
function agend_apps_events_check_in_registration( string $registration_id ) {
	/**
	 * Filters the check-in request args before the request is sent.
	 *
	 * @param array  $args            Request args.
	 * @param string $registration_id Registration ID.
	 */
	$args = (array) apply_filters( 'agend_apps_events_check_in_registration_args', array(), $registration_id );

	$path     = '/events/registrations/' . rawurlencode( $registration_id ) . '/check-in';
	$response = agend_apps_api()->request( 'PATCH', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded check-in response before it is returned.
	 *
	 * @param array  $response        Decoded response body.
	 * @param string $registration_id Registration ID.
	 */
	return apply_filters( 'agend_apps_events_check_in_registration_response', $response, $registration_id );
}

/**
 * Marks a registration as a no-show.
 *
 * Scope: `events.registrations.manage`.
 *
 * @param string $registration_id Registration ID.
 * @return array|WP_Error Decoded registration on success, or WP_Error on failure.
 */
function agend_apps_events_mark_no_show( string $registration_id ) {
	/**
	 * Filters the no-show request args before the request is sent.
	 *
	 * @param array  $args            Request args.
	 * @param string $registration_id Registration ID.
	 */
	$args = (array) apply_filters( 'agend_apps_events_mark_no_show_args', array(), $registration_id );

	$path     = '/events/registrations/' . rawurlencode( $registration_id ) . '/no-show';
	$response = agend_apps_api()->request( 'PATCH', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded no-show response before it is returned.
	 *
	 * @param array  $response        Decoded response body.
	 * @param string $registration_id Registration ID.
	 */
	return apply_filters( 'agend_apps_events_mark_no_show_response', $response, $registration_id );
}

/**
 * Lists the current member's event tickets.
 *
 * Scope: `events.registrations.browse`. Requires a member bearer token.
 *
 * @param array $query Optional. Query parameters (camelCase). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_events_get_my_tickets( array $query = array() ) {
	/**
	 * Filters the my-tickets request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_events_get_my_tickets_args',
		array( 'query' => $query ),
		$query
	);

	$response = agend_apps_api()->request( 'GET', '/events/registrations/my-tickets', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded my-tickets response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_events_get_my_tickets_response', $response, $query );
}

/**
 * Lists the current member's event purchases.
 *
 * Scope: `events.registrations.browse`. Requires a member bearer token.
 *
 * @param array $query Optional. Query parameters (camelCase). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_events_get_my_purchases( array $query = array() ) {
	/**
	 * Filters the my-purchases request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_events_get_my_purchases_args',
		array( 'query' => $query ),
		$query
	);

	$response = agend_apps_api()->request( 'GET', '/events/registrations/my-purchases', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded my-purchases response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_events_get_my_purchases_response', $response, $query );
}

/**
 * Pays for one or more pending registrations.
 *
 * Scope: `events.registrations.pay`. Supports both bearer-attended and
 * unattended (server-to-server) calls.
 *
 * @param array $payload Payment payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded payment result on success, or WP_Error on failure.
 */
function agend_apps_events_pay_registration( array $payload ) {
	/**
	 * Filters the pay-registration request args before the request is sent.
	 *
	 * @param array $args    Request args.
	 * @param array $payload Payment payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_events_pay_registration_args',
		array( 'body' => $payload ),
		$payload
	);

	$response = agend_apps_api()->request( 'POST', '/events/registrations/pay', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded pay-registration response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $payload  Payment payload.
	 */
	return apply_filters( 'agend_apps_events_pay_registration_response', $response, $payload );
}

/**
 * Lists waitlist entries.
 *
 * Scope: `events.waitlist.browse`. Not cached.
 *
 * @param array $query Optional. Query parameters (camelCase). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_events_get_waitlist( array $query = array() ) {
	/**
	 * Filters the waitlist list request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_events_get_waitlist_args',
		array( 'query' => $query ),
		$query
	);

	$response = agend_apps_api()->request( 'GET', '/events/waitlist', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded waitlist list response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_events_get_waitlist_response', $response, $query );
}

/**
 * Removes a waitlist entry.
 *
 * Scope: `events.waitlist.delete`.
 *
 * @param string $waitlist_id Waitlist entry ID.
 * @return array|WP_Error Decoded confirmation on success, or WP_Error on failure.
 */
function agend_apps_events_delete_waitlist_entry( string $waitlist_id ) {
	/**
	 * Filters the delete-waitlist-entry request args before the request is sent.
	 *
	 * @param array  $args        Request args.
	 * @param string $waitlist_id Waitlist entry ID.
	 */
	$args = (array) apply_filters( 'agend_apps_events_delete_waitlist_entry_args', array(), $waitlist_id );

	$response = agend_apps_api()->request( 'DELETE', '/events/waitlist/' . rawurlencode( $waitlist_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded delete-waitlist-entry response before it is returned.
	 *
	 * @param array  $response    Decoded response body.
	 * @param string $waitlist_id Waitlist entry ID.
	 */
	return apply_filters( 'agend_apps_events_delete_waitlist_entry_response', $response, $waitlist_id );
}

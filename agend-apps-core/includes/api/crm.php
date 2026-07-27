<?php
/**
 * CRM API functions.
 *
 * Server-side PHP wrappers for the Agend gateway's `/v1/crm/*` endpoints.
 * Sibling plugins MUST call these helpers rather than building gateway paths
 * or calling `agend_apps_api()` directly, so transport and path knowledge stay
 * centralised here.
 *
 * Public catalogue reads (tiers, types, stages) are cached. Identity-specific
 * reads (contacts, companies, deals, segments, memberships, seats, orders,
 * tasks, dashboard, invitations, and all `/me/*` endpoints) and every mutation
 * are never cached. Member endpoints require the Supabase bearer token resolved
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
 * Retrieves the current member's profile.
 *
 * Scope: `crm.contacts.browse`. Requires a member bearer token.
 *
 * @return array|WP_Error Decoded contact profile on success, or WP_Error on failure.
 */
function agend_apps_crm_get_me() {
	/**
	 * Filters the get-me request args before the request is sent.
	 *
	 * @param array $args Request args.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_get_me_args', array() );

	$response = agend_apps_api()->request( 'GET', '/crm/me', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-me response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 */
	return apply_filters( 'agend_apps_crm_get_me_response', $response );
}

/**
 * Updates the current member's profile.
 *
 * Scope: `crm.contacts.manage`. Requires a member bearer token.
 *
 * @param array $payload Profile payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded contact profile on success, or WP_Error on failure.
 */
function agend_apps_crm_update_me( array $payload ) {
	/**
	 * Filters the update-me request args before the request is sent.
	 *
	 * @param array $args    Request args.
	 * @param array $payload Profile payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_update_me_args',
		array( 'body' => $payload ),
		$payload
	);

	$response = agend_apps_api()->request( 'PATCH', '/crm/me', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded update-me response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $payload  Profile payload.
	 */
	return apply_filters( 'agend_apps_crm_update_me_response', $response, $payload );
}

/**
 * Retrieves the current member's colleagues.
 *
 * Scope: `crm.contacts.browse`. Requires a member bearer token.
 *
 * @param array $query Optional. Query parameters (camelCase). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_crm_get_my_colleagues( array $query = array() ) {
	/**
	 * Filters the get-my-colleagues request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_get_my_colleagues_args',
		array( 'query' => $query ),
		$query
	);

	$response = agend_apps_api()->request( 'GET', '/crm/me/colleagues', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-my-colleagues response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_crm_get_my_colleagues_response', $response, $query );
}

/**
 * Retrieves the current member's connected profile information.
 *
 * Scope: `crm.contacts.browse`. Requires a member bearer token.
 *
 * @return array|WP_Error Decoded profile on success, or WP_Error on failure.
 */
function agend_apps_crm_get_my_connected_profile() {
	/**
	 * Filters the get-my-connected-profile request args before the request is sent.
	 *
	 * @param array $args Request args.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_get_my_connected_profile_args', array() );

	$response = agend_apps_api()->request( 'GET', '/crm/me/connected-profile', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-my-connected-profile response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 */
	return apply_filters( 'agend_apps_crm_get_my_connected_profile_response', $response );
}

/**
 * Retrieves the current member's team.
 *
 * Scope: `crm.contacts.browse`. Requires a member bearer token.
 *
 * @return array|WP_Error Decoded team on success, or WP_Error on failure.
 */
function agend_apps_crm_get_my_team() {
	/**
	 * Filters the get-my-team request args before the request is sent.
	 *
	 * @param array $args Request args.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_get_my_team_args', array() );

	$response = agend_apps_api()->request( 'GET', '/crm/me/team', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-my-team response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 */
	return apply_filters( 'agend_apps_crm_get_my_team_response', $response );
}

/**
 * Updates a team member record for the current member.
 *
 * Scope: `crm.contacts.manage`. Requires a member bearer token.
 *
 * @param string $team_member_id Team member ID.
 * @param array  $payload        Updated member payload (snake_case).
 * @return array|WP_Error Decoded team member on success, or WP_Error on failure.
 */
function agend_apps_crm_update_my_team_member( string $team_member_id, array $payload ) {
	/**
	 * Filters the update-my-team-member request args before the request is sent.
	 *
	 * @param array  $args            Request args.
	 * @param string $team_member_id  Team member ID.
	 * @param array  $payload         Updated member payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_update_my_team_member_args',
		array( 'body' => $payload ),
		$team_member_id,
		$payload
	);

	$path     = '/crm/me/team/' . rawurlencode( $team_member_id );
	$response = agend_apps_api()->request( 'PATCH', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded update-my-team-member response before it is returned.
	 *
	 * @param array  $response       Decoded response body.
	 * @param string $team_member_id Team member ID.
	 * @param array  $payload        Updated member payload.
	 */
	return apply_filters( 'agend_apps_crm_update_my_team_member_response', $response, $team_member_id, $payload );
}

/**
 * Removes the current member from a team.
 *
 * Scope: `crm.contacts.manage`. Requires a member bearer token.
 *
 * @param string $team_member_id Team member ID.
 * @return array|WP_Error Decoded confirmation on success, or WP_Error on failure.
 */
function agend_apps_crm_delete_my_team_member( string $team_member_id ) {
	/**
	 * Filters the delete-my-team-member request args before the request is sent.
	 *
	 * @param array  $args           Request args.
	 * @param string $team_member_id Team member ID.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_delete_my_team_member_args', array(), $team_member_id );

	$path     = '/crm/me/team/' . rawurlencode( $team_member_id );
	$response = agend_apps_api()->request( 'DELETE', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded delete-my-team-member response before it is returned.
	 *
	 * @param array  $response       Decoded response body.
	 * @param string $team_member_id Team member ID.
	 */
	return apply_filters( 'agend_apps_crm_delete_my_team_member_response', $response, $team_member_id );
}

/**
 * Resends an invite to a team member.
 *
 * Scope: `crm.contacts.manage`. Requires a member bearer token.
 *
 * @param string $team_member_id Team member ID.
 * @return array|WP_Error Decoded confirmation on success, or WP_Error on failure.
 */
function agend_apps_crm_resend_my_team_invite( string $team_member_id ) {
	/**
	 * Filters the resend-my-team-invite request args before the request is sent.
	 *
	 * @param array  $args           Request args.
	 * @param string $team_member_id Team member ID.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_resend_my_team_invite_args', array(), $team_member_id );

	$path     = '/crm/me/team/' . rawurlencode( $team_member_id ) . '/resend-invite';
	$response = agend_apps_api()->request( 'POST', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded resend-my-team-invite response before it is returned.
	 *
	 * @param array  $response       Decoded response body.
	 * @param string $team_member_id Team member ID.
	 */
	return apply_filters( 'agend_apps_crm_resend_my_team_invite_response', $response, $team_member_id );
}

/**
 * Creates a team invitation.
 *
 * Scope: `crm.contacts.manage`. Requires a member bearer token.
 *
 * @param array $payload Invitation payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded invitation on success, or WP_Error on failure.
 */
function agend_apps_crm_create_my_team_invitation( array $payload ) {
	/**
	 * Filters the create-my-team-invitation request args before the request is sent.
	 *
	 * @param array $args    Request args.
	 * @param array $payload Invitation payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_create_my_team_invitation_args',
		array( 'body' => $payload ),
		$payload
	);

	$response = agend_apps_api()->request( 'POST', '/crm/me/team/invitations', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded create-my-team-invitation response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $payload  Invitation payload.
	 */
	return apply_filters( 'agend_apps_crm_create_my_team_invitation_response', $response, $payload );
}

/**
 * Accepts a team invitation.
 *
 * Scope: `crm.invitations.accept`. Requires a member bearer token.
 *
 * @param string $token Invitation token.
 * @return array|WP_Error Decoded confirmation on success, or WP_Error on failure.
 */
function agend_apps_crm_accept_team_invitation( string $token ) {
	/**
	 * Filters the accept-team-invitation request args before the request is sent.
	 *
	 * @param array  $args  Request args.
	 * @param string $token Invitation token.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_accept_team_invitation_args', array(), $token );

	$path     = '/crm/team/invitations/' . rawurlencode( $token ) . '/accept';
	$response = agend_apps_api()->request( 'POST', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded accept-team-invitation response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $token    Invitation token.
	 */
	return apply_filters( 'agend_apps_crm_accept_team_invitation_response', $response, $token );
}

/**
 * Verifies a team invitation token.
 *
 * Scope: `crm.invitations.verify`.
 *
 * @param string $token Invitation token.
 * @return array|WP_Error Decoded invitation on success, or WP_Error on failure.
 */
function agend_apps_crm_verify_team_invitation( string $token ) {
	/**
	 * Filters the verify-team-invitation request args before the request is sent.
	 *
	 * @param array  $args  Request args.
	 * @param string $token Invitation token.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_verify_team_invitation_args', array(), $token );

	$path     = '/crm/team/invitations/' . rawurlencode( $token );
	$response = agend_apps_api()->request( 'GET', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded verify-team-invitation response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $token    Invitation token.
	 */
	return apply_filters( 'agend_apps_crm_verify_team_invitation_response', $response, $token );
}

/**
 * Retrieves the current member's RESOLVED standing.
 *
 * Returns the membership tier ids the caller currently holds, computed by
 * Agend's single shared member definition: an active individual membership OR
 * an active corporate seat on an active corporate membership, within each
 * tier's grace period.
 *
 * Use this, NOT `agend_apps_crm_get_my_memberships()`, to decide access. That
 * function returns raw individual membership rows: it omits corporate seat
 * holders entirely and leaves grace-period arithmetic to the caller, so
 * deciding access from it means reimplementing the member definition in PHP and
 * getting a different answer from the rest of the platform.
 *
 * Never cached: standing changes the moment a membership lapses or a seat is
 * revoked, and the answer is specific to the caller.
 *
 * Scope: `crm.memberships.browse`. Requires a member bearer token.
 *
 * @return array|WP_Error Decoded response with `tier_ids` and `is_member`, or WP_Error on failure.
 */
function agend_apps_crm_get_my_entitlements() {
	/**
	 * Filters the get-my-entitlements request args before the request is sent.
	 *
	 * @param array $args Request args.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_get_my_entitlements_args',
		array()
	);

	$response = agend_apps_api()->request( 'GET', '/crm/me/entitlements', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded entitlements response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 */
	return apply_filters( 'agend_apps_crm_get_my_entitlements_response', $response );
}

/**
 * Lists the current member's memberships.
 *
 * Scope: `crm.memberships.browse`. Requires a member bearer token.
 *
 * @param array $query Optional. Query parameters (camelCase). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_crm_get_my_memberships( array $query = array() ) {
	/**
	 * Filters the get-my-memberships request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_get_my_memberships_args',
		array( 'query' => $query ),
		$query
	);

	$response = agend_apps_api()->request( 'GET', '/crm/me/memberships', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-my-memberships response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_crm_get_my_memberships_response', $response, $query );
}

/**
 * Purchases a membership for the current member.
 *
 * Scope: `crm.memberships.purchase`. Requires a member bearer token.
 *
 * @param array $payload Membership purchase payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded membership on success, or WP_Error on failure.
 */
function agend_apps_crm_purchase_my_membership( array $payload ) {
	/**
	 * Filters the purchase-my-membership request args before the request is sent.
	 *
	 * @param array $args    Request args.
	 * @param array $payload Membership purchase payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_purchase_my_membership_args',
		array( 'body' => $payload ),
		$payload
	);

	$response = agend_apps_api()->request( 'POST', '/crm/me/memberships', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded purchase-my-membership response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $payload  Membership purchase payload.
	 */
	return apply_filters( 'agend_apps_crm_purchase_my_membership_response', $response, $payload );
}

/**
 * Lists the current member's transactions.
 *
 * Scope: `crm.transactions.browse`. Requires a member bearer token.
 *
 * @param array $query Optional. Query parameters (camelCase). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_crm_get_my_transactions( array $query = array() ) {
	/**
	 * Filters the get-my-transactions request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_get_my_transactions_args',
		array( 'query' => $query ),
		$query
	);

	$response = agend_apps_api()->request( 'GET', '/crm/me/transactions', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-my-transactions response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_crm_get_my_transactions_response', $response, $query );
}

/**
 * Retrieves a hosted invoice URL for a transaction.
 *
 * Scope: `crm.transactions.browse`. Requires a member bearer token.
 *
 * @param string $transaction_id Transaction ID.
 * @return array|WP_Error Decoded response containing invoice URL on success, or WP_Error on failure.
 */
function agend_apps_crm_get_my_transaction_invoice( string $transaction_id ) {
	/**
	 * Filters the get-my-transaction-invoice request args before the request is sent.
	 *
	 * @param array  $args             Request args.
	 * @param string $transaction_id   Transaction ID.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_get_my_transaction_invoice_args', array(), $transaction_id );

	$path     = '/crm/me/transactions/' . rawurlencode( $transaction_id ) . '/invoice';
	$response = agend_apps_api()->request( 'GET', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-my-transaction-invoice response before it is returned.
	 *
	 * @param array  $response        Decoded response body.
	 * @param string $transaction_id  Transaction ID.
	 */
	return apply_filters( 'agend_apps_crm_get_my_transaction_invoice_response', $response, $transaction_id );
}

/**
 * Generates a transaction statement PDF for the current member.
 *
 * Scope: `crm.transactions.browse`. Requires a member bearer token.
 *
 * @param array $payload Statement generation payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded response containing statement URL on success, or WP_Error on failure.
 */
function agend_apps_crm_generate_my_statement( array $payload ) {
	/**
	 * Filters the generate-my-statement request args before the request is sent.
	 *
	 * @param array $args    Request args.
	 * @param array $payload Statement generation payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_generate_my_statement_args',
		array( 'body' => $payload ),
		$payload
	);

	$response = agend_apps_api()->request( 'POST', '/crm/me/statements', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded generate-my-statement response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $payload  Statement generation payload.
	 */
	return apply_filters( 'agend_apps_crm_generate_my_statement_response', $response, $payload );
}

/**
 * Records a CPD export event for the current member.
 *
 * Scope: `crm.contacts.manage`. Requires a member bearer token.
 *
 * @param array $payload CPD export payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded export log on success, or WP_Error on failure.
 */
function agend_apps_crm_log_my_cpd_export( array $payload ) {
	/**
	 * Filters the log-my-cpd-export request args before the request is sent.
	 *
	 * @param array $args    Request args.
	 * @param array $payload CPD export payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_log_my_cpd_export_args',
		array( 'body' => $payload ),
		$payload
	);

	$response = agend_apps_api()->request( 'POST', '/crm/me/cpd-exports', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded log-my-cpd-export response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $payload  CPD export payload.
	 */
	return apply_filters( 'agend_apps_crm_log_my_cpd_export_response', $response, $payload );
}

/**
 * Purchases a seat for the current member's team.
 *
 * Scope: `crm.seats.manage`. Requires a member bearer token.
 *
 * @param array $payload Seat purchase payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded seat on success, or WP_Error on failure.
 */
function agend_apps_crm_purchase_my_team_seat( array $payload ) {
	/**
	 * Filters the purchase-my-team-seat request args before the request is sent.
	 *
	 * @param array $args    Request args.
	 * @param array $payload Seat purchase payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_purchase_my_team_seat_args',
		array( 'body' => $payload ),
		$payload
	);

	$response = agend_apps_api()->request( 'POST', '/crm/me/team/seats/purchase', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded purchase-my-team-seat response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $payload  Seat purchase payload.
	 */
	return apply_filters( 'agend_apps_crm_purchase_my_team_seat_response', $response, $payload );
}

/**
 * Creates a seat for the current member's team.
 *
 * Scope: `crm.seats.manage`. Supports both bearer-attended and unattended
 * (server-to-server) calls.
 *
 * @param array $payload Seat payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded seat on success, or WP_Error on failure.
 */
function agend_apps_crm_create_my_team_seat( array $payload ) {
	/**
	 * Filters the create-my-team-seat request args before the request is sent.
	 *
	 * @param array $args    Request args.
	 * @param array $payload Seat payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_create_my_team_seat_args',
		array( 'body' => $payload ),
		$payload
	);

	$response = agend_apps_api()->request( 'POST', '/crm/me/team/seats', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded create-my-team-seat response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $payload  Seat payload.
	 */
	return apply_filters( 'agend_apps_crm_create_my_team_seat_response', $response, $payload );
}

/**
 * Pays for a pending order for the current member.
 *
 * Scope: `crm.orders.pay`. Requires a member bearer token.
 *
 * @param string $order_id Order ID.
 * @return array|WP_Error Decoded payment result on success, or WP_Error on failure.
 */
function agend_apps_crm_pay_my_order( string $order_id ) {
	/**
	 * Filters the pay-my-order request args before the request is sent.
	 *
	 * @param array  $args     Request args.
	 * @param string $order_id Order ID.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_pay_my_order_args', array(), $order_id );

	$path     = '/crm/me/orders/' . rawurlencode( $order_id ) . '/pay';
	$response = agend_apps_api()->request( 'POST', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded pay-my-order response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $order_id Order ID.
	 */
	return apply_filters( 'agend_apps_crm_pay_my_order_response', $response, $order_id );
}

/**
 * Lists contacts.
 *
 * Scope: `crm.contacts.browse`. Not cached.
 *
 * @param array $query Optional. Query parameters (camelCase). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_crm_get_contacts( array $query = array() ) {
	/**
	 * Filters the get-contacts request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_get_contacts_args',
		array( 'query' => $query ),
		$query
	);

	$response = agend_apps_api()->request( 'GET', '/crm/contacts', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-contacts response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_crm_get_contacts_response', $response, $query );
}

/**
 * Creates a contact.
 *
 * Scope: `crm.contacts.create`.
 *
 * @param array $contact Contact payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded contact on success, or WP_Error on failure.
 */
function agend_apps_crm_create_contact( array $contact ) {
	/**
	 * Filters the create-contact request args before the request is sent.
	 *
	 * @param array $args    Request args.
	 * @param array $contact Contact payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_create_contact_args',
		array( 'body' => $contact ),
		$contact
	);

	$response = agend_apps_api()->request( 'POST', '/crm/contacts', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded create-contact response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $contact  Contact payload.
	 */
	return apply_filters( 'agend_apps_crm_create_contact_response', $response, $contact );
}

/**
 * Searches for contacts.
 *
 * Scope: `crm.contacts.browse`.
 *
 * @param array $query Optional. Query parameters (camelCase). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_crm_search_contacts( array $query = array() ) {
	/**
	 * Filters the search-contacts request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_search_contacts_args',
		array( 'query' => $query ),
		$query
	);

	$response = agend_apps_api()->request( 'GET', '/crm/contacts/search', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded search-contacts response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_crm_search_contacts_response', $response, $query );
}

/**
 * Retrieves a single contact by ID.
 *
 * Scope: `crm.contacts.browse`.
 *
 * @param string $contact_id Contact ID.
 * @return array|WP_Error Decoded contact on success, or WP_Error on failure.
 */
function agend_apps_crm_get_contact( string $contact_id ) {
	/**
	 * Filters the get-contact request args before the request is sent.
	 *
	 * @param array  $args       Request args.
	 * @param string $contact_id Contact ID.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_get_contact_args', array(), $contact_id );

	$response = agend_apps_api()->request( 'GET', '/crm/contacts/' . rawurlencode( $contact_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-contact response before it is returned.
	 *
	 * @param array  $response   Decoded response body.
	 * @param string $contact_id Contact ID.
	 */
	return apply_filters( 'agend_apps_crm_get_contact_response', $response, $contact_id );
}

/**
 * Updates a contact.
 *
 * Scope: `crm.contacts.manage`. Supports both bearer-attended and unattended
 * (server-to-server) calls with unattendedScope `crm.contacts.update`.
 *
 * @param string $contact_id Contact ID.
 * @param array  $contact    Updated contact payload (snake_case).
 * @return array|WP_Error Decoded contact on success, or WP_Error on failure.
 */
function agend_apps_crm_update_contact( string $contact_id, array $contact ) {
	/**
	 * Filters the update-contact request args before the request is sent.
	 *
	 * @param array  $args       Request args.
	 * @param string $contact_id Contact ID.
	 * @param array  $contact    Updated contact payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_update_contact_args',
		array( 'body' => $contact ),
		$contact_id,
		$contact
	);

	$response = agend_apps_api()->request( 'PATCH', '/crm/contacts/' . rawurlencode( $contact_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded update-contact response before it is returned.
	 *
	 * @param array  $response   Decoded response body.
	 * @param string $contact_id Contact ID.
	 * @param array  $contact    Updated contact payload.
	 */
	return apply_filters( 'agend_apps_crm_update_contact_response', $response, $contact_id, $contact );
}

/**
 * Deletes a contact.
 *
 * Scope: `crm.contacts.delete`.
 *
 * @param string $contact_id Contact ID.
 * @return array|WP_Error Decoded confirmation on success, or WP_Error on failure.
 */
function agend_apps_crm_delete_contact( string $contact_id ) {
	/**
	 * Filters the delete-contact request args before the request is sent.
	 *
	 * @param array  $args       Request args.
	 * @param string $contact_id Contact ID.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_delete_contact_args', array(), $contact_id );

	$response = agend_apps_api()->request( 'DELETE', '/crm/contacts/' . rawurlencode( $contact_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded delete-contact response before it is returned.
	 *
	 * @param array  $response   Decoded response body.
	 * @param string $contact_id Contact ID.
	 */
	return apply_filters( 'agend_apps_crm_delete_contact_response', $response, $contact_id );
}

/**
 * Retrieves a contact's team.
 *
 * Scope: `crm.contacts.browse`.
 *
 * @param string $contact_id Contact ID.
 * @return array|WP_Error Decoded team on success, or WP_Error on failure.
 */
function agend_apps_crm_get_contact_team( string $contact_id ) {
	/**
	 * Filters the get-contact-team request args before the request is sent.
	 *
	 * @param array  $args       Request args.
	 * @param string $contact_id Contact ID.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_get_contact_team_args', array(), $contact_id );

	$path     = '/crm/contacts/' . rawurlencode( $contact_id ) . '/team';
	$response = agend_apps_api()->request( 'GET', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-contact-team response before it is returned.
	 *
	 * @param array  $response   Decoded response body.
	 * @param string $contact_id Contact ID.
	 */
	return apply_filters( 'agend_apps_crm_get_contact_team_response', $response, $contact_id );
}

/**
 * Performs bulk operations on contacts.
 *
 * Scope: `crm.contacts.manage`.
 *
 * @param array $operations Bulk operations payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_crm_bulk_contacts( array $operations ) {
	/**
	 * Filters the bulk-contacts request args before the request is sent.
	 *
	 * @param array $args        Request args.
	 * @param array $operations  Bulk operations payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_bulk_contacts_args',
		array( 'body' => $operations ),
		$operations
	);

	$response = agend_apps_api()->request( 'POST', '/crm/contacts/bulk', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded bulk-contacts response before it is returned.
	 *
	 * @param array $response    Decoded response body.
	 * @param array $operations  Bulk operations payload.
	 */
	return apply_filters( 'agend_apps_crm_bulk_contacts_response', $response, $operations );
}

/**
 * Lists companies.
 *
 * Scope: `crm.companies.browse`.
 *
 * @param array $query Optional. Query parameters (camelCase). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_crm_get_companies( array $query = array() ) {
	/**
	 * Filters the get-companies request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_get_companies_args',
		array( 'query' => $query ),
		$query
	);

	$response = agend_apps_api()->request( 'GET', '/crm/companies', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-companies response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_crm_get_companies_response', $response, $query );
}

/**
 * Creates a company.
 *
 * Scope: `crm.companies.create`.
 *
 * @param array $company Company payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded company on success, or WP_Error on failure.
 */
function agend_apps_crm_create_company( array $company ) {
	/**
	 * Filters the create-company request args before the request is sent.
	 *
	 * @param array $args    Request args.
	 * @param array $company Company payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_create_company_args',
		array( 'body' => $company ),
		$company
	);

	$response = agend_apps_api()->request( 'POST', '/crm/companies', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded create-company response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $company  Company payload.
	 */
	return apply_filters( 'agend_apps_crm_create_company_response', $response, $company );
}

/**
 * Retrieves a single company by ID.
 *
 * Scope: `crm.companies.browse`.
 *
 * @param string $company_id Company ID.
 * @return array|WP_Error Decoded company on success, or WP_Error on failure.
 */
function agend_apps_crm_get_company( string $company_id ) {
	/**
	 * Filters the get-company request args before the request is sent.
	 *
	 * @param array  $args       Request args.
	 * @param string $company_id Company ID.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_get_company_args', array(), $company_id );

	$response = agend_apps_api()->request( 'GET', '/crm/companies/' . rawurlencode( $company_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-company response before it is returned.
	 *
	 * @param array  $response   Decoded response body.
	 * @param string $company_id Company ID.
	 */
	return apply_filters( 'agend_apps_crm_get_company_response', $response, $company_id );
}

/**
 * Updates a company.
 *
 * Scope: `crm.companies.update`.
 *
 * @param string $company_id Company ID.
 * @param array  $company    Updated company payload (snake_case).
 * @return array|WP_Error Decoded company on success, or WP_Error on failure.
 */
function agend_apps_crm_update_company( string $company_id, array $company ) {
	/**
	 * Filters the update-company request args before the request is sent.
	 *
	 * @param array  $args       Request args.
	 * @param string $company_id Company ID.
	 * @param array  $company    Updated company payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_update_company_args',
		array( 'body' => $company ),
		$company_id,
		$company
	);

	$response = agend_apps_api()->request( 'PATCH', '/crm/companies/' . rawurlencode( $company_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded update-company response before it is returned.
	 *
	 * @param array  $response   Decoded response body.
	 * @param string $company_id Company ID.
	 * @param array  $company    Updated company payload.
	 */
	return apply_filters( 'agend_apps_crm_update_company_response', $response, $company_id, $company );
}

/**
 * Deletes a company.
 *
 * Scope: `crm.companies.delete`.
 *
 * @param string $company_id Company ID.
 * @return array|WP_Error Decoded confirmation on success, or WP_Error on failure.
 */
function agend_apps_crm_delete_company( string $company_id ) {
	/**
	 * Filters the delete-company request args before the request is sent.
	 *
	 * @param array  $args       Request args.
	 * @param string $company_id Company ID.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_delete_company_args', array(), $company_id );

	$response = agend_apps_api()->request( 'DELETE', '/crm/companies/' . rawurlencode( $company_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded delete-company response before it is returned.
	 *
	 * @param array  $response   Decoded response body.
	 * @param string $company_id Company ID.
	 */
	return apply_filters( 'agend_apps_crm_delete_company_response', $response, $company_id );
}

/**
 * Retrieves the CRM dashboard.
 *
 * Scope: `crm.deals.browse`.
 *
 * @return array|WP_Error Decoded dashboard on success, or WP_Error on failure.
 */
function agend_apps_crm_get_dashboard() {
	/**
	 * Filters the get-dashboard request args before the request is sent.
	 *
	 * @param array $args Request args.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_get_dashboard_args', array() );

	$response = agend_apps_api()->request( 'GET', '/crm/dashboard', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-dashboard response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 */
	return apply_filters( 'agend_apps_crm_get_dashboard_response', $response );
}

/**
 * Lists deals.
 *
 * Scope: `crm.deals.browse`.
 *
 * @param array $query Optional. Query parameters (camelCase). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_crm_get_deals( array $query = array() ) {
	/**
	 * Filters the get-deals request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_get_deals_args',
		array( 'query' => $query ),
		$query
	);

	$response = agend_apps_api()->request( 'GET', '/crm/deals', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-deals response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_crm_get_deals_response', $response, $query );
}

/**
 * Creates a deal.
 *
 * Scope: `crm.deals.create`.
 *
 * @param array $deal Deal payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded deal on success, or WP_Error on failure.
 */
function agend_apps_crm_create_deal( array $deal ) {
	/**
	 * Filters the create-deal request args before the request is sent.
	 *
	 * @param array $args Request args.
	 * @param array $deal Deal payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_create_deal_args',
		array( 'body' => $deal ),
		$deal
	);

	$response = agend_apps_api()->request( 'POST', '/crm/deals', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded create-deal response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $deal     Deal payload.
	 */
	return apply_filters( 'agend_apps_crm_create_deal_response', $response, $deal );
}

/**
 * Performs bulk operations on deals.
 *
 * Scope: `crm.deals.create`, `crm.deals.update`, `crm.deals.delete`.
 * Process up to 100 create / update / delete operations sequentially.
 *
 * @param array $operations Bulk operations payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_crm_bulk_deals( array $operations ) {
	/**
	 * Filters the bulk-deals request args before the request is sent.
	 *
	 * @param array $args        Request args.
	 * @param array $operations  Bulk operations payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_bulk_deals_args',
		array( 'body' => $operations ),
		$operations
	);

	$response = agend_apps_api()->request( 'POST', '/crm/deals/bulk', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded bulk-deals response before it is returned.
	 *
	 * @param array $response    Decoded response body.
	 * @param array $operations  Bulk operations payload.
	 */
	return apply_filters( 'agend_apps_crm_bulk_deals_response', $response, $operations );
}

/**
 * Retrieves a single deal by ID.
 *
 * Scope: `crm.deals.browse`.
 *
 * @param string $deal_id Deal ID.
 * @return array|WP_Error Decoded deal on success, or WP_Error on failure.
 */
function agend_apps_crm_get_deal( string $deal_id ) {
	/**
	 * Filters the get-deal request args before the request is sent.
	 *
	 * @param array  $args    Request args.
	 * @param string $deal_id Deal ID.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_get_deal_args', array(), $deal_id );

	$response = agend_apps_api()->request( 'GET', '/crm/deals/' . rawurlencode( $deal_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-deal response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $deal_id  Deal ID.
	 */
	return apply_filters( 'agend_apps_crm_get_deal_response', $response, $deal_id );
}

/**
 * Updates a deal.
 *
 * Scope: `crm.deals.update`.
 *
 * @param string $deal_id Deal ID.
 * @param array  $deal    Updated deal payload (snake_case).
 * @return array|WP_Error Decoded deal on success, or WP_Error on failure.
 */
function agend_apps_crm_update_deal( string $deal_id, array $deal ) {
	/**
	 * Filters the update-deal request args before the request is sent.
	 *
	 * @param array  $args    Request args.
	 * @param string $deal_id Deal ID.
	 * @param array  $deal    Updated deal payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_update_deal_args',
		array( 'body' => $deal ),
		$deal_id,
		$deal
	);

	$response = agend_apps_api()->request( 'PATCH', '/crm/deals/' . rawurlencode( $deal_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded update-deal response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $deal_id  Deal ID.
	 * @param array  $deal     Updated deal payload.
	 */
	return apply_filters( 'agend_apps_crm_update_deal_response', $response, $deal_id, $deal );
}

/**
 * Deletes a deal.
 *
 * Scope: `crm.deals.delete`.
 *
 * @param string $deal_id Deal ID.
 * @return array|WP_Error Decoded confirmation on success, or WP_Error on failure.
 */
function agend_apps_crm_delete_deal( string $deal_id ) {
	/**
	 * Filters the delete-deal request args before the request is sent.
	 *
	 * @param array  $args    Request args.
	 * @param string $deal_id Deal ID.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_delete_deal_args', array(), $deal_id );

	$response = agend_apps_api()->request( 'DELETE', '/crm/deals/' . rawurlencode( $deal_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded delete-deal response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $deal_id  Deal ID.
	 */
	return apply_filters( 'agend_apps_crm_delete_deal_response', $response, $deal_id );
}

/**
 * Moves a deal to another stage.
 *
 * Scope: `crm.deals.update`.
 *
 * @param string $deal_id Deal ID.
 * @param array  $payload Move payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded deal on success, or WP_Error on failure.
 */
function agend_apps_crm_move_deal( string $deal_id, array $payload ) {
	/**
	 * Filters the move-deal request args before the request is sent.
	 *
	 * @param array  $args    Request args.
	 * @param string $deal_id Deal ID.
	 * @param array  $payload Move payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_move_deal_args',
		array( 'body' => $payload ),
		$deal_id,
		$payload
	);

	$path     = '/crm/deals/' . rawurlencode( $deal_id ) . '/move';
	$response = agend_apps_api()->request( 'POST', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded move-deal response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $deal_id  Deal ID.
	 * @param array  $payload  Move payload.
	 */
	return apply_filters( 'agend_apps_crm_move_deal_response', $response, $deal_id, $payload );
}

/**
 * Closes a deal.
 *
 * Scope: `crm.deals.update`.
 *
 * @param string $deal_id Deal ID.
 * @param array  $payload Close payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded deal on success, or WP_Error on failure.
 */
function agend_apps_crm_close_deal( string $deal_id, array $payload ) {
	/**
	 * Filters the close-deal request args before the request is sent.
	 *
	 * @param array  $args    Request args.
	 * @param string $deal_id Deal ID.
	 * @param array  $payload Close payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_close_deal_args',
		array( 'body' => $payload ),
		$deal_id,
		$payload
	);

	$path     = '/crm/deals/' . rawurlencode( $deal_id ) . '/close';
	$response = agend_apps_api()->request( 'POST', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded close-deal response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $deal_id  Deal ID.
	 * @param array  $payload  Close payload.
	 */
	return apply_filters( 'agend_apps_crm_close_deal_response', $response, $deal_id, $payload );
}

/**
 * Lists activities for a deal.
 *
 * Scope: `crm.deals.browse`.
 *
 * @param string $deal_id Deal ID.
 * @param array  $query   Optional. Query parameters (camelCase). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_crm_get_deal_activities( string $deal_id, array $query = array() ) {
	/**
	 * Filters the get-deal-activities request args before the request is sent.
	 *
	 * @param array  $args    Request args.
	 * @param string $deal_id Deal ID.
	 * @param array  $query   Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_get_deal_activities_args',
		array( 'query' => $query ),
		$deal_id,
		$query
	);

	$path     = '/crm/deals/' . rawurlencode( $deal_id ) . '/activities';
	$response = agend_apps_api()->request( 'GET', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-deal-activities response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $deal_id  Deal ID.
	 * @param array  $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_crm_get_deal_activities_response', $response, $deal_id, $query );
}

/**
 * Creates an activity for a deal.
 *
 * Scope: `crm.deals.update`.
 *
 * @param string $deal_id  Deal ID.
 * @param array  $activity Activity payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded activity on success, or WP_Error on failure.
 */
function agend_apps_crm_create_deal_activity( string $deal_id, array $activity ) {
	/**
	 * Filters the create-deal-activity request args before the request is sent.
	 *
	 * @param array  $args     Request args.
	 * @param string $deal_id  Deal ID.
	 * @param array  $activity Activity payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_create_deal_activity_args',
		array( 'body' => $activity ),
		$deal_id,
		$activity
	);

	$path     = '/crm/deals/' . rawurlencode( $deal_id ) . '/activities';
	$response = agend_apps_api()->request( 'POST', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded create-deal-activity response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $deal_id  Deal ID.
	 * @param array  $activity Activity payload.
	 */
	return apply_filters( 'agend_apps_crm_create_deal_activity_response', $response, $deal_id, $activity );
}

/**
 * Lists tasks for a deal.
 *
 * Scope: `crm.tasks.browse`.
 *
 * @param string $deal_id Deal ID.
 * @param array  $query   Optional. Query parameters (camelCase). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_crm_get_deal_tasks( string $deal_id, array $query = array() ) {
	/**
	 * Filters the get-deal-tasks request args before the request is sent.
	 *
	 * @param array  $args    Request args.
	 * @param string $deal_id Deal ID.
	 * @param array  $query   Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_get_deal_tasks_args',
		array( 'query' => $query ),
		$deal_id,
		$query
	);

	$path     = '/crm/deals/' . rawurlencode( $deal_id ) . '/tasks';
	$response = agend_apps_api()->request( 'GET', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-deal-tasks response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $deal_id  Deal ID.
	 * @param array  $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_crm_get_deal_tasks_response', $response, $deal_id, $query );
}

/**
 * Creates a task for a deal.
 *
 * Scope: `crm.tasks.create`.
 *
 * @param string $deal_id Deal ID.
 * @param array  $task    Task payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded task on success, or WP_Error on failure.
 */
function agend_apps_crm_create_deal_task( string $deal_id, array $task ) {
	/**
	 * Filters the create-deal-task request args before the request is sent.
	 *
	 * @param array  $args    Request args.
	 * @param string $deal_id Deal ID.
	 * @param array  $task    Task payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_create_deal_task_args',
		array( 'body' => $task ),
		$deal_id,
		$task
	);

	$path     = '/crm/deals/' . rawurlencode( $deal_id ) . '/tasks';
	$response = agend_apps_api()->request( 'POST', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded create-deal-task response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $deal_id  Deal ID.
	 * @param array  $task     Task payload.
	 */
	return apply_filters( 'agend_apps_crm_create_deal_task_response', $response, $deal_id, $task );
}

/**
 * Lists tasks.
 *
 * Scope: `crm.tasks.browse`.
 *
 * @param array $query Optional. Query parameters (camelCase). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_crm_get_tasks( array $query = array() ) {
	/**
	 * Filters the get-tasks request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_get_tasks_args',
		array( 'query' => $query ),
		$query
	);

	$response = agend_apps_api()->request( 'GET', '/crm/tasks', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-tasks response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_crm_get_tasks_response', $response, $query );
}

/**
 * Creates a task.
 *
 * Scope: `crm.tasks.create`.
 *
 * @param array $task Task payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded task on success, or WP_Error on failure.
 */
function agend_apps_crm_create_task( array $task ) {
	/**
	 * Filters the create-task request args before the request is sent.
	 *
	 * @param array $args Request args.
	 * @param array $task Task payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_create_task_args',
		array( 'body' => $task ),
		$task
	);

	$response = agend_apps_api()->request( 'POST', '/crm/tasks', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded create-task response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $task     Task payload.
	 */
	return apply_filters( 'agend_apps_crm_create_task_response', $response, $task );
}

/**
 * Retrieves a single task by ID.
 *
 * Scope: `crm.tasks.browse`.
 *
 * @param string $task_id Task ID.
 * @return array|WP_Error Decoded task on success, or WP_Error on failure.
 */
function agend_apps_crm_get_task( string $task_id ) {
	/**
	 * Filters the get-task request args before the request is sent.
	 *
	 * @param array  $args    Request args.
	 * @param string $task_id Task ID.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_get_task_args', array(), $task_id );

	$response = agend_apps_api()->request( 'GET', '/crm/tasks/' . rawurlencode( $task_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-task response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $task_id  Task ID.
	 */
	return apply_filters( 'agend_apps_crm_get_task_response', $response, $task_id );
}

/**
 * Updates a task.
 *
 * Scope: `crm.tasks.update`.
 *
 * @param string $task_id Task ID.
 * @param array  $task    Updated task payload (snake_case).
 * @return array|WP_Error Decoded task on success, or WP_Error on failure.
 */
function agend_apps_crm_update_task( string $task_id, array $task ) {
	/**
	 * Filters the update-task request args before the request is sent.
	 *
	 * @param array  $args    Request args.
	 * @param string $task_id Task ID.
	 * @param array  $task    Updated task payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_update_task_args',
		array( 'body' => $task ),
		$task_id,
		$task
	);

	$response = agend_apps_api()->request( 'PATCH', '/crm/tasks/' . rawurlencode( $task_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded update-task response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $task_id  Task ID.
	 * @param array  $task     Updated task payload.
	 */
	return apply_filters( 'agend_apps_crm_update_task_response', $response, $task_id, $task );
}

/**
 * Deletes a task.
 *
 * Scope: `crm.tasks.delete`.
 *
 * @param string $task_id Task ID.
 * @return array|WP_Error Decoded confirmation on success, or WP_Error on failure.
 */
function agend_apps_crm_delete_task( string $task_id ) {
	/**
	 * Filters the delete-task request args before the request is sent.
	 *
	 * @param array  $args    Request args.
	 * @param string $task_id Task ID.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_delete_task_args', array(), $task_id );

	$response = agend_apps_api()->request( 'DELETE', '/crm/tasks/' . rawurlencode( $task_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded delete-task response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $task_id  Task ID.
	 */
	return apply_filters( 'agend_apps_crm_delete_task_response', $response, $task_id );
}

/**
 * Lists membership tiers.
 *
 * Scope: `crm.tiers.browse`. Cached.
 *
 * @param array $query Optional. Query parameters (camelCase). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_crm_get_tiers( array $query = array() ) {
	/**
	 * Filters the get-tiers request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_get_tiers_args',
		array( 'query' => $query ),
		$query
	);

	$cache_key = Agend_Apps_Cache::build_key( 'crm_tiers', $query );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'crm_tiers' );

	$response = agend_apps_api()->get_cached( '/crm/tiers', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-tiers response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_crm_get_tiers_response', $response, $query );
}

/**
 * Retrieves the public membership-signup field definitions for an entity type.
 *
 * Returns custom-field DEFINITIONS an association marked for its public signup
 * form (is_active AND display_on_signup); never stored member values.
 *
 * Scope: `crm.fields.browse`.
 *
 * @param array $query Query parameters (e.g. `entityType`) forwarded to the gateway.
 * @return array|WP_Error Decoded field definitions on success, or WP_Error on failure.
 */
function agend_apps_crm_get_fields( array $query = array() ) {
	/**
	 * Filters the get-fields request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_get_fields_args',
		array( 'query' => $query ),
		$query
	);

	$response = agend_apps_api()->request( 'GET', '/crm/fields', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-fields response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_crm_get_fields_response', $response, $query );
}

/**
 * Creates a membership tier.
 *
 * Scope: `crm.tiers.create`.
 *
 * @param array $tier Tier payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded tier on success, or WP_Error on failure.
 */
function agend_apps_crm_create_tier( array $tier ) {
	/**
	 * Filters the create-tier request args before the request is sent.
	 *
	 * @param array $args Request args.
	 * @param array $tier Tier payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_create_tier_args',
		array( 'body' => $tier ),
		$tier
	);

	$response = agend_apps_api()->request( 'POST', '/crm/tiers', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded create-tier response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $tier     Tier payload.
	 */
	return apply_filters( 'agend_apps_crm_create_tier_response', $response, $tier );
}

/**
 * Retrieves a single membership tier by ID.
 *
 * Scope: `crm.tiers.browse`. Cached.
 *
 * @param string $tier_id Tier ID.
 * @return array|WP_Error Decoded tier on success, or WP_Error on failure.
 */
function agend_apps_crm_get_tier( string $tier_id ) {
	/**
	 * Filters the get-tier request args before the request is sent.
	 *
	 * @param array  $args    Request args.
	 * @param string $tier_id Tier ID.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_get_tier_args', array(), $tier_id );

	$cache_key = Agend_Apps_Cache::build_key( 'crm_tier_single', array( 'id' => $tier_id ) );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'crm_tier_single' );

	$response = agend_apps_api()->get_cached( '/crm/tiers/' . rawurlencode( $tier_id ), $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-tier response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $tier_id  Tier ID.
	 */
	return apply_filters( 'agend_apps_crm_get_tier_response', $response, $tier_id );
}

/**
 * Updates a membership tier.
 *
 * Scope: `crm.tiers.update`.
 *
 * @param string $tier_id Tier ID.
 * @param array  $tier    Updated tier payload (snake_case).
 * @return array|WP_Error Decoded tier on success, or WP_Error on failure.
 */
function agend_apps_crm_update_tier( string $tier_id, array $tier ) {
	/**
	 * Filters the update-tier request args before the request is sent.
	 *
	 * @param array  $args    Request args.
	 * @param string $tier_id Tier ID.
	 * @param array  $tier    Updated tier payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_update_tier_args',
		array( 'body' => $tier ),
		$tier_id,
		$tier
	);

	$response = agend_apps_api()->request( 'PATCH', '/crm/tiers/' . rawurlencode( $tier_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded update-tier response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $tier_id  Tier ID.
	 * @param array  $tier     Updated tier payload.
	 */
	return apply_filters( 'agend_apps_crm_update_tier_response', $response, $tier_id, $tier );
}

/**
 * Deletes a membership tier.
 *
 * Scope: `crm.tiers.delete`.
 *
 * @param string $tier_id Tier ID.
 * @return array|WP_Error Decoded confirmation on success, or WP_Error on failure.
 */
function agend_apps_crm_delete_tier( string $tier_id ) {
	/**
	 * Filters the delete-tier request args before the request is sent.
	 *
	 * @param array  $args    Request args.
	 * @param string $tier_id Tier ID.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_delete_tier_args', array(), $tier_id );

	$response = agend_apps_api()->request( 'DELETE', '/crm/tiers/' . rawurlencode( $tier_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded delete-tier response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $tier_id  Tier ID.
	 */
	return apply_filters( 'agend_apps_crm_delete_tier_response', $response, $tier_id );
}

/**
 * Lists memberships.
 *
 * Scope: `crm.memberships.browse`.
 *
 * @param array $query Optional. Query parameters (camelCase). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_crm_get_memberships( array $query = array() ) {
	/**
	 * Filters the get-memberships request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_get_memberships_args',
		array( 'query' => $query ),
		$query
	);

	$response = agend_apps_api()->request( 'GET', '/crm/memberships', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-memberships response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_crm_get_memberships_response', $response, $query );
}

/**
 * Creates a membership.
 *
 * Scope: `crm.memberships.create`.
 *
 * @param array $membership Membership payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded membership on success, or WP_Error on failure.
 */
function agend_apps_crm_create_membership( array $membership ) {
	/**
	 * Filters the create-membership request args before the request is sent.
	 *
	 * @param array $args        Request args.
	 * @param array $membership  Membership payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_create_membership_args',
		array( 'body' => $membership ),
		$membership
	);

	$response = agend_apps_api()->request( 'POST', '/crm/memberships', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded create-membership response before it is returned.
	 *
	 * @param array $response    Decoded response body.
	 * @param array $membership  Membership payload.
	 */
	return apply_filters( 'agend_apps_crm_create_membership_response', $response, $membership );
}

/**
 * Purchases a membership.
 *
 * Scope: `crm.memberships.purchase`.
 *
 * @param array $payload Membership purchase payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded membership on success, or WP_Error on failure.
 */
function agend_apps_crm_purchase_membership( array $payload ) {
	/**
	 * Filters the purchase-membership request args before the request is sent.
	 *
	 * @param array $args    Request args.
	 * @param array $payload Membership purchase payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_purchase_membership_args',
		array( 'body' => $payload ),
		$payload
	);

	$response = agend_apps_api()->request( 'POST', '/crm/memberships/purchase', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded purchase-membership response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $payload  Membership purchase payload.
	 */
	return apply_filters( 'agend_apps_crm_purchase_membership_response', $response, $payload );
}

/**
 * Retrieves a single membership by ID.
 *
 * Scope: `crm.memberships.browse`.
 *
 * @param string $membership_id Membership ID.
 * @return array|WP_Error Decoded membership on success, or WP_Error on failure.
 */
function agend_apps_crm_get_membership( string $membership_id ) {
	/**
	 * Filters the get-membership request args before the request is sent.
	 *
	 * @param array  $args           Request args.
	 * @param string $membership_id  Membership ID.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_get_membership_args', array(), $membership_id );

	$response = agend_apps_api()->request( 'GET', '/crm/memberships/' . rawurlencode( $membership_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-membership response before it is returned.
	 *
	 * @param array  $response       Decoded response body.
	 * @param string $membership_id  Membership ID.
	 */
	return apply_filters( 'agend_apps_crm_get_membership_response', $response, $membership_id );
}

/**
 * Deletes a membership.
 *
 * Scope: `crm.memberships.delete`.
 *
 * @param string $membership_id Membership ID.
 * @return array|WP_Error Decoded confirmation on success, or WP_Error on failure.
 */
function agend_apps_crm_delete_membership( string $membership_id ) {
	/**
	 * Filters the delete-membership request args before the request is sent.
	 *
	 * @param array  $args           Request args.
	 * @param string $membership_id  Membership ID.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_delete_membership_args', array(), $membership_id );

	$response = agend_apps_api()->request( 'DELETE', '/crm/memberships/' . rawurlencode( $membership_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded delete-membership response before it is returned.
	 *
	 * @param array  $response       Decoded response body.
	 * @param string $membership_id  Membership ID.
	 */
	return apply_filters( 'agend_apps_crm_delete_membership_response', $response, $membership_id );
}

/**
 * Lists seats.
 *
 * Scope: `crm.seats.browse`.
 *
 * @param array $query Optional. Query parameters (camelCase). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_crm_get_seats( array $query = array() ) {
	/**
	 * Filters the get-seats request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_get_seats_args',
		array( 'query' => $query ),
		$query
	);

	$response = agend_apps_api()->request( 'GET', '/crm/seats', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-seats response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_crm_get_seats_response', $response, $query );
}

/**
 * Creates a seat.
 *
 * Scope: `crm.seats.create`.
 *
 * @param array $seat Seat payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded seat on success, or WP_Error on failure.
 */
function agend_apps_crm_create_seat( array $seat ) {
	/**
	 * Filters the create-seat request args before the request is sent.
	 *
	 * @param array $args Request args.
	 * @param array $seat Seat payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_create_seat_args',
		array( 'body' => $seat ),
		$seat
	);

	$response = agend_apps_api()->request( 'POST', '/crm/seats', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded create-seat response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $seat     Seat payload.
	 */
	return apply_filters( 'agend_apps_crm_create_seat_response', $response, $seat );
}

/**
 * Retrieves a single seat by ID.
 *
 * Scope: `crm.seats.browse`.
 *
 * @param string $seat_id Seat ID.
 * @return array|WP_Error Decoded seat on success, or WP_Error on failure.
 */
function agend_apps_crm_get_seat( string $seat_id ) {
	/**
	 * Filters the get-seat request args before the request is sent.
	 *
	 * @param array  $args    Request args.
	 * @param string $seat_id Seat ID.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_get_seat_args', array(), $seat_id );

	$response = agend_apps_api()->request( 'GET', '/crm/seats/' . rawurlencode( $seat_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-seat response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $seat_id  Seat ID.
	 */
	return apply_filters( 'agend_apps_crm_get_seat_response', $response, $seat_id );
}

/**
 * Updates a seat.
 *
 * Scope: `crm.seats.update`.
 *
 * @param string $seat_id Seat ID.
 * @param array  $seat    Updated seat payload (snake_case).
 * @return array|WP_Error Decoded seat on success, or WP_Error on failure.
 */
function agend_apps_crm_update_seat( string $seat_id, array $seat ) {
	/**
	 * Filters the update-seat request args before the request is sent.
	 *
	 * @param array  $args    Request args.
	 * @param string $seat_id Seat ID.
	 * @param array  $seat    Updated seat payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_update_seat_args',
		array( 'body' => $seat ),
		$seat_id,
		$seat
	);

	$response = agend_apps_api()->request( 'PATCH', '/crm/seats/' . rawurlencode( $seat_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded update-seat response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $seat_id  Seat ID.
	 * @param array  $seat     Updated seat payload.
	 */
	return apply_filters( 'agend_apps_crm_update_seat_response', $response, $seat_id, $seat );
}

/**
 * Deletes a seat.
 *
 * Scope: `crm.seats.delete`.
 *
 * @param string $seat_id Seat ID.
 * @return array|WP_Error Decoded confirmation on success, or WP_Error on failure.
 */
function agend_apps_crm_delete_seat( string $seat_id ) {
	/**
	 * Filters the delete-seat request args before the request is sent.
	 *
	 * @param array  $args    Request args.
	 * @param string $seat_id Seat ID.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_delete_seat_args', array(), $seat_id );

	$response = agend_apps_api()->request( 'DELETE', '/crm/seats/' . rawurlencode( $seat_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded delete-seat response before it is returned.
	 *
	 * @param array  $response Decoded response body.
	 * @param string $seat_id  Seat ID.
	 */
	return apply_filters( 'agend_apps_crm_delete_seat_response', $response, $seat_id );
}

/**
 * Lists orders.
 *
 * Scope: `crm.orders.manage`.
 *
 * @param array $payload Order payload (snake_case) forwarded as the request body.
 * @return array|WP_Error Decoded order on success, or WP_Error on failure.
 */
function agend_apps_crm_create_order( array $payload ) {
	/**
	 * Filters the create-order request args before the request is sent.
	 *
	 * @param array $args    Request args.
	 * @param array $payload Order payload.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_create_order_args',
		array( 'body' => $payload ),
		$payload
	);

	$response = agend_apps_api()->request( 'POST', '/crm/orders', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded create-order response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $payload  Order payload.
	 */
	return apply_filters( 'agend_apps_crm_create_order_response', $response, $payload );
}

/**
 * Lists deal types.
 *
 * Scope: `crm.deals.browse`. Cached.
 *
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_crm_get_types() {
	/**
	 * Filters the get-types request args before the request is sent.
	 *
	 * @param array $args Request args.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_get_types_args', array() );

	$cache_key = Agend_Apps_Cache::build_key( 'crm_types' );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'crm_types' );

	$response = agend_apps_api()->get_cached( '/crm/types', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-types response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 */
	return apply_filters( 'agend_apps_crm_get_types_response', $response );
}

/**
 * Lists deal pipeline stages.
 *
 * Scope: `crm.deals.browse`. Cached.
 *
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_crm_get_stages() {
	/**
	 * Filters the get-stages request args before the request is sent.
	 *
	 * @param array $args Request args.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_get_stages_args', array() );

	$cache_key = Agend_Apps_Cache::build_key( 'crm_stages' );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'crm_stages' );

	$response = agend_apps_api()->get_cached( '/crm/stages', $args, $cache_key, $ttl );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-stages response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 */
	return apply_filters( 'agend_apps_crm_get_stages_response', $response );
}

/**
 * Lists segments.
 *
 * Scope: `crm.segments.browse`.
 *
 * @param array $query Optional. Query parameters (camelCase). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_crm_get_segments( array $query = array() ) {
	/**
	 * Filters the get-segments request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_get_segments_args',
		array( 'query' => $query ),
		$query
	);

	$response = agend_apps_api()->request( 'GET', '/crm/segments', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-segments response before it is returned.
	 *
	 * @param array $response Decoded response body.
	 * @param array $query    Original query parameters.
	 */
	return apply_filters( 'agend_apps_crm_get_segments_response', $response, $query );
}

/**
 * Retrieves a single segment by ID.
 *
 * Scope: `crm.segments.browse`.
 *
 * @param string $segment_id Segment ID.
 * @return array|WP_Error Decoded segment on success, or WP_Error on failure.
 */
function agend_apps_crm_get_segment( string $segment_id ) {
	/**
	 * Filters the get-segment request args before the request is sent.
	 *
	 * @param array  $args       Request args.
	 * @param string $segment_id Segment ID.
	 */
	$args = (array) apply_filters( 'agend_apps_crm_get_segment_args', array(), $segment_id );

	$response = agend_apps_api()->request( 'GET', '/crm/segments/' . rawurlencode( $segment_id ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-segment response before it is returned.
	 *
	 * @param array  $response   Decoded response body.
	 * @param string $segment_id Segment ID.
	 */
	return apply_filters( 'agend_apps_crm_get_segment_response', $response, $segment_id );
}

/**
 * Lists contacts within a segment.
 *
 * Scope: `crm.segments.browse`.
 *
 * @param string $segment_id Segment ID.
 * @param array  $query      Optional. Query parameters (camelCase). Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_crm_get_segment_contacts( string $segment_id, array $query = array() ) {
	/**
	 * Filters the get-segment-contacts request args before the request is sent.
	 *
	 * @param array  $args       Request args.
	 * @param string $segment_id Segment ID.
	 * @param array  $query      Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_crm_get_segment_contacts_args',
		array( 'query' => $query ),
		$segment_id,
		$query
	);

	$path     = '/crm/segments/' . rawurlencode( $segment_id ) . '/contacts';
	$response = agend_apps_api()->request( 'GET', $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/**
	 * Filters the decoded get-segment-contacts response before it is returned.
	 *
	 * @param array  $response   Decoded response body.
	 * @param string $segment_id Segment ID.
	 * @param array  $query      Original query parameters.
	 */
	return apply_filters( 'agend_apps_crm_get_segment_contacts_response', $response, $segment_id, $query );
}

<?php
/**
 * REST route receiving Agend platform webhooks.
 *
 * Ingests `crm.membership.*` events from the Agend unified webhook system so
 * a member's membership snapshot usermeta stays fresh WHILE a session is
 * active (content restrictions react to renewals, lapses, and reinstatements
 * without waiting for the next login). The association subscribes its
 * account's webhook to this URL from the Agend dashboard and stores the
 * subscription's signing secret in the plugin settings.
 *
 * Security model: the route is necessarily unauthenticated at the WordPress
 * layer (a server-to-server POST carries no nonce or cookie); authentication
 * is the Agend signature — a Stripe-style timestamped HMAC
 * (`X-Agend-Signature: t=<unix>,v1=<hmac-sha256 of "t.body">`) verified in
 * constant time against the stored secret, with a five-minute replay window.
 * Deliveries are at-least-once, so the `X-Agend-Event-Id` header (stable
 * across retries) is deduplicated via a transient. The event payload is only
 * ever a TRIGGER: the member's standing is re-read from the gateway with
 * their own bearer, never trusted from the request body.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the webhook receiver REST routes.
 *
 * Called from `rest_api_init` via the main plugin bootstrap.
 */
function agend_apps_register_webhook_receiver_routes(): void {
	$controller = new Agend_Apps_Webhook_Receiver_REST_Controller();
	$controller->register_routes();
}

/**
 * REST controller for the incoming Agend webhook endpoint.
 *
 * Exposes `POST agend-apps/v1/webhooks/incoming`.
 */
class Agend_Apps_Webhook_Receiver_REST_Controller extends Agend_Apps_REST_Controller {

	/**
	 * Resource base for the receiver route.
	 *
	 * @var string
	 */
	protected $rest_base = 'webhooks';

	/**
	 * Maximum accepted age (and future skew) of a signature timestamp, in
	 * seconds. Mirrors the Agend webhook replay-window guidance.
	 *
	 * @var int
	 */
	const SIGNATURE_TOLERANCE = 5 * MINUTE_IN_SECONDS;

	/**
	 * How long a delivery id is remembered for deduplication, in seconds.
	 *
	 * @var int
	 */
	const DEDUPE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Registers the REST routes for this controller.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/incoming',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'ingest' ),
					// Signature verification inside the handler IS the
					// authentication: a server-to-server delivery cannot carry
					// a WordPress nonce or cookie, and rejecting here would
					// hide the 400/503 diagnostics from the dispatcher log.
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Ingests a signed Agend webhook delivery.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function ingest( WP_REST_Request $request ): WP_REST_Response {
		$secret = (string) get_option( 'agend_apps_webhook_secret', '' );

		if ( '' === $secret ) {
			return new WP_REST_Response(
				array(
					'code'    => 'receiver_not_configured',
					'message' => __( 'No webhook signing secret is configured.', 'agend-apps-core' ),
				),
				503
			);
		}

		$body = (string) $request->get_body();

		if ( ! $this->signature_valid( (string) $request->get_header( 'X-Agend-Signature' ), $body, $secret ) ) {
			return new WP_REST_Response(
				array(
					'code'    => 'invalid_signature',
					'message' => __( 'The webhook signature could not be verified.', 'agend-apps-core' ),
				),
				400
			);
		}

		// At-least-once delivery: the delivery id is stable across retries, so
		// a repeat within the window is acknowledged without re-processing.
		$event_id = (string) $request->get_header( 'X-Agend-Event-Id' );

		if ( '' !== $event_id ) {
			$dedupe_key = 'agend_apps_wh_' . md5( $event_id );

			if ( false !== get_transient( $dedupe_key ) ) {
				return new WP_REST_Response(
					array(
						'ok'        => true,
						'duplicate' => true,
					),
					200
				);
			}

			set_transient( $dedupe_key, 1, self::DEDUPE_TTL );
		}

		$envelope = json_decode( $body, true );

		if ( ! is_array( $envelope ) ) {
			return new WP_REST_Response(
				array(
					'code'    => 'invalid_payload',
					'message' => __( 'The webhook body is not valid JSON.', 'agend-apps-core' ),
				),
				400
			);
		}

		$type   = isset( $envelope['type'] ) ? (string) $envelope['type'] : '';
		$synced = false;

		if ( 0 === strpos( $type, 'crm.membership.' ) ) {
			$synced = $this->handle_membership_event( $envelope );
		}

		/**
		 * Fires for every verified Agend webhook delivery, after built-in
		 * handling, so sibling plugins can react to additional event types.
		 *
		 * @param string $type     Event type, e.g. `crm.membership.lapsed`.
		 * @param array  $envelope Full decoded delivery envelope.
		 */
		do_action( 'agend_apps_webhook_received', $type, $envelope );

		return new WP_REST_Response(
			array(
				'ok'     => true,
				'synced' => $synced,
			),
			200
		);
	}

	/**
	 * Refreshes the membership snapshot for the member a membership event
	 * belongs to, when they have an active session on this site.
	 *
	 * The payload's contact id is used only to LOCATE the WordPress user (via
	 * the `_agend_apps_contact_id` meta written at login); their standing is
	 * re-fetched from the gateway with their own stored bearer, so a forged or
	 * stale payload can never assert a standing directly. A member without an
	 * active session is skipped — their snapshot refreshes at next login.
	 *
	 * @param array $envelope Decoded delivery envelope.
	 * @return bool True when a member's snapshot was refreshed.
	 */
	private function handle_membership_event( array $envelope ): bool {
		$data       = ( isset( $envelope['data'] ) && is_array( $envelope['data'] ) ) ? $envelope['data'] : array();
		$contact_id = isset( $data['contact_id'] ) ? (string) $data['contact_id'] : '';

		if ( '' === $contact_id ) {
			return false;
		}

		$user_ids = get_users(
			array(
				'meta_key'    => '_agend_apps_contact_id',
				'meta_value'  => $contact_id,
				'number'      => 1,
				'fields'      => 'ID',
				'count_total' => false,
			)
		);

		if ( empty( $user_ids ) ) {
			return false;
		}

		$user_id = (int) $user_ids[0];

		if ( ! Agend_Apps_Member_Session::has_session( $user_id ) ) {
			return false;
		}

		return agend_apps_member_sync_membership_meta( $user_id );
	}

	/**
	 * Verifies a Stripe-style timestamped HMAC signature.
	 *
	 * Format: `t=<unix>,v1=<hex>` where hex is HMAC-SHA256 of "<t>.<body>"
	 * keyed by the subscription secret. The timestamp must be within the
	 * replay tolerance; the digest comparison is constant-time.
	 *
	 * @param string $header Raw X-Agend-Signature header value.
	 * @param string $body   Raw request body exactly as received.
	 * @param string $secret Subscription signing secret.
	 * @return bool True when the signature is valid and fresh.
	 */
	private function signature_valid( string $header, string $body, string $secret ): bool {
		if ( '' === $header ) {
			return false;
		}

		$timestamp = '';
		$digest    = '';

		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );

			if ( 2 !== count( $pair ) ) {
				continue;
			}

			if ( 't' === $pair[0] ) {
				$timestamp = $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$digest = $pair[1];
			}
		}

		if ( '' === $timestamp || '' === $digest || ! ctype_digit( $timestamp ) ) {
			return false;
		}

		if ( abs( time() - (int) $timestamp ) > self::SIGNATURE_TOLERANCE ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );

		return hash_equals( $expected, strtolower( $digest ) );
	}
}

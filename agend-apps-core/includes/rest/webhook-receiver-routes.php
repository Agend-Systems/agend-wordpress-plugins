<?php
/**
 * REST route receiving Agend platform webhooks.
 *
 * Ingests `crm.membership.*` and `crm.seat.*` events from the Agend unified
 * webhook system so a member's membership snapshot usermeta stays fresh
 * WHILE a session is active (content restrictions react to renewals, lapses,
 * reinstatements, and corporate-seat changes without waiting for the next
 * login). The association subscribes its account's webhook to this URL from
 * the Agend dashboard and stores the subscription's signing secret in the
 * plugin settings.
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
	 * Ingests a signed Agend webhook delivery and records it in the API log.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response REST response.
	 */
	public function ingest( WP_REST_Request $request ): WP_REST_Response {
		$started = microtime( true );

		list( $response, $outcome ) = $this->process( $request );

		if ( function_exists( 'agend_apps_logger' ) ) {
			$body     = (string) $request->get_body();
			$data     = $response->get_data();
			$verified = ! in_array( $outcome, array( 'not_configured', 'signature_invalid' ), true );
			$envelope = $verified ? json_decode( $body, true ) : null;

			agend_apps_logger()->record(
				array(
					'source'             => 'agend-apps-core',
					'direction'          => 'inbound',
					'method'             => 'POST',
					'url'                => rest_url( $this->namespace . '/' . $this->rest_base . '/incoming' ),
					'status'             => $response->get_status(),
					'outcome'            => $outcome,
					'duration_ms'        => (int) round( ( microtime( true ) - $started ) * 1000 ),
					// The signature header itself is never passed in.
					'auth_mode'          => 'signature',
					// Anyone can POST here: nothing an unverified delivery
					// claims about itself is recorded, and its body is never
					// processed, so a forged flood costs one small row each.
					'store_bodies'       => $verified,
					'gateway_request_id' => $verified ? (string) $request->get_header( 'X-Agend-Event-Id' ) : '',
					'event_type'         => is_array( $envelope ) && isset( $envelope['type'] ) && is_string( $envelope['type'] ) ? $envelope['type'] : '',
					'request_body'       => $body,
					'response_body'      => (string) wp_json_encode( $data ),
					'error_code'         => 'success' === $outcome || 'duplicate' === $outcome ? '' : ( is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : $outcome ),
					'error_message'      => is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : '',
					// Server-to-server: there is no signed-in WordPress user.
					'user_id'            => 0,
				)
			);
		}

		return $response;
	}

	/**
	 * Verifies, deduplicates and handles one delivery.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return array{0: WP_REST_Response, 1: string} Response and API log outcome.
	 */
	private function process( WP_REST_Request $request ): array {
		$secret = (string) get_option( 'agend_apps_webhook_secret', '' );

		if ( '' === $secret ) {
			return array( new WP_REST_Response(
				array(
					'code'    => 'receiver_not_configured',
					'message' => __( 'No webhook signing secret is configured.', 'agend-apps-core' ),
				),
				503
			), 'not_configured' );
		}

		$body = (string) $request->get_body();

		if ( ! $this->signature_valid( (string) $request->get_header( 'X-Agend-Signature' ), $body, $secret ) ) {
			return array( new WP_REST_Response(
				array(
					'code'    => 'invalid_signature',
					'message' => __( 'The webhook signature could not be verified.', 'agend-apps-core' ),
				),
				400
			), 'signature_invalid' );
		}

		// At-least-once delivery: the delivery id is stable across retries, so
		// a repeat within the window is acknowledged without re-processing.
		$event_id = (string) $request->get_header( 'X-Agend-Event-Id' );

		if ( '' !== $event_id ) {
			$dedupe_key = 'agend_apps_wh_' . md5( $event_id );

			if ( false !== get_transient( $dedupe_key ) ) {
				return array( new WP_REST_Response(
					array(
						'ok'        => true,
						'duplicate' => true,
					),
					200
				), 'duplicate' );
			}

			set_transient( $dedupe_key, 1, self::DEDUPE_TTL );
		}

		$envelope = json_decode( $body, true );

		if ( ! is_array( $envelope ) ) {
			return array( new WP_REST_Response(
				array(
					'code'    => 'invalid_payload',
					'message' => __( 'The webhook body is not valid JSON.', 'agend-apps-core' ),
				),
				400
			), 'invalid_payload' );
		}

		$type   = isset( $envelope['type'] ) ? (string) $envelope['type'] : '';
		$synced = false;

		// Membership lifecycle AND corporate-seat events both change a
		// member's standing; each carries the affected contact_id.
		if (
			0 === strpos( $type, 'crm.membership.' ) ||
			0 === strpos( $type, 'crm.seat.' )
		) {
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

		return array( new WP_REST_Response(
			array(
				'ok'     => true,
				'synced' => $synced,
			),
			200
		), 'success' );
	}

	/**
	 * Refreshes the membership snapshot for the member a membership event
	 * belongs to, when their WordPress identity resolves to a gateway bearer.
	 *
	 * The payload's contact id is used only to LOCATE the WordPress user (via
	 * the `_agend_apps_contact_id` meta written at login); their standing is
	 * always re-fetched from the gateway with that user's own bearer, so a
	 * forged or stale payload can never assert a standing directly.
	 * `agend_apps_member_sync_membership_meta()` resolves that bearer itself
	 * (whichever provider is active) and returns false without a gateway call
	 * when none resolves, which is what skips a member with no live identity
	 * on this site — their snapshot refreshes at next login instead.
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

		return agend_apps_member_sync_membership_meta( (int) $user_ids[0] );
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

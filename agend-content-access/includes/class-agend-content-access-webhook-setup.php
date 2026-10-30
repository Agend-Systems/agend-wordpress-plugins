<?php
/**
 * One-click subscription of Agend webhooks to this site's receiver.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates, completes and checks the Agend webhook subscription for this site.
 *
 * WHICH RECEIVER. Core's receiver, `agend-apps/v1/webhooks/incoming`. This plugin
 * adds no route and no secret of its own; it only helps the operator point Agend
 * at the receiver Core already has and put the signing secret where Core reads it
 * (`agend_apps_webhook_secret`).
 *
 * NEVER A SECOND SUBSCRIPTION. Every action starts from the account's real list of
 * subscriptions and matches on the receiver URL. A subscription that already
 * points here is completed or left alone, never duplicated, and more than one
 * match (made by hand, or by an older run) stops every action until a person
 * removes the extras: this class never deletes a subscription it did not just
 * create. A short lock stops a double click racing two creates.
 *
 * THE SECRET IS SHOWN ONCE. The gateway returns a signing secret only when a
 * subscription is created or its secret rotated. It is written straight to the
 * option Core verifies deliveries against and is never returned to the caller,
 * logged, or placed in a result, so it cannot reach a page or a log line. A
 * stored secret is not replaced unless the caller says so, because it may be the
 * working secret of a subscription this site still relies on.
 *
 * EVERYTHING IS A RESULT. No method throws for a gateway outcome. Each returns a
 * plain array whose `code` says what happened, and the admin screen turns codes
 * into sentences. When the connected key may not manage subscriptions, or Core is
 * too old to, the screen falls back to a written guide for doing it by hand.
 */
class Agend_Content_Access_Webhook_Setup {

	/**
	 * The route Core's receiver listens on, under the REST root.
	 *
	 * @var string
	 */
	const RECEIVER_ROUTE = 'agend-apps/v1/webhooks/incoming';

	/**
	 * The option Core's receiver verifies deliveries against.
	 *
	 * @var string
	 */
	const SECRET_OPTION = 'agend_apps_webhook_secret';

	/**
	 * Whether the subscription should also carry the entitlement and segment
	 * events this plugin acts on. Stored so a later completion repeats the choice.
	 *
	 * @var string
	 */
	const ACCESS_OPTION = 'agend_content_access_webhook_access_events';

	/**
	 * Name of the short lock held while a setup action runs.
	 *
	 * @var string
	 */
	const LOCK = 'agend_content_access_webhook_setup_lock';

	/**
	 * How long that lock lasts, in seconds.
	 *
	 * @var int
	 */
	const LOCK_TTL = 30;

	/**
	 * The description the gateway stores on the subscription.
	 *
	 * @var string
	 */
	const DESCRIPTION = 'Agend Content Access (member lifecycle)';

	/**
	 * The member lifecycle events. Always subscribed.
	 *
	 * @var string[]
	 */
	const LIFECYCLE_EVENTS = array(
		'crm.membership.created',
		'crm.membership.updated',
		'crm.membership.cancelled',
		'crm.membership.renewed',
		'crm.membership.reinstated',
		'crm.membership.lapsed',
		'crm.seat.added',
		'crm.seat.updated',
		'crm.seat.removed',
	);

	/**
	 * The entitlement, contact and segment events this plugin also acts on.
	 * Optional, and dropped if the gateway does not know them yet.
	 *
	 * @var string[]
	 */
	const ACCESS_EVENTS = array(
		'crm.entitlement.granted',
		'crm.entitlement.revoked',
		'crm.contact.attributes_changed',
		'crm.contact.bulk_updated',
		'crm.segment.updated',
		'crm.benefit.changed',
	);

	/**
	 * The gateway.
	 *
	 * @var Agend_Content_Access_Webhook_Gateway
	 */
	private $gateway;

	/**
	 * Builds the setup over a gateway; Core's own by default.
	 *
	 * @param Agend_Content_Access_Webhook_Gateway|null $gateway Gateway to use.
	 */
	public function __construct( ?Agend_Content_Access_Webhook_Gateway $gateway = null ) {
		$this->gateway = $gateway ?? new Agend_Content_Access_Core_Webhook_Gateway();
	}

	/**
	 * The URL Agend should deliver to.
	 *
	 * @return string
	 */
	public function receiver_url(): string {
		return rest_url( self::RECEIVER_ROUTE );
	}

	/**
	 * Whether the entitlement and segment events are wanted. On unless turned off.
	 *
	 * @return bool
	 */
	public function include_access_events(): bool {
		return '0' !== (string) get_option( self::ACCESS_OPTION, '1' );
	}

	/**
	 * The events a complete subscription carries.
	 *
	 * @param bool $include_access Whether to include the entitlement and segment events.
	 * @return string[]
	 */
	public function wanted_events( bool $include_access ): array {
		return $include_access
			? array_merge( self::LIFECYCLE_EVENTS, self::ACCESS_EVENTS )
			: self::LIFECYCLE_EVENTS;
	}

	/**
	 * Whether two URLs name the same receiver.
	 *
	 * Compared by host, the site's own base path and the REST route, ignoring the
	 * scheme, the default port, a trailing slash and the two spellings WordPress
	 * has for the same route (`/wp-json/...` and `?rest_route=/...`), so a
	 * subscription made by hand for the pretty form is still found when the site
	 * now reports the plain form.
	 *
	 * @param string $a One URL.
	 * @param string $b The other.
	 * @return bool
	 */
	public static function same_receiver( string $a, string $b ): bool {
		$key_a = self::receiver_key( $a );

		return '' !== $key_a && $key_a === self::receiver_key( $b );
	}

	/**
	 * What the account has, whether it is complete, and what can be done about it.
	 *
	 * `state` is one of:
	 * - `unavailable`      Core cannot manage subscriptions.
	 * - `cannot_manage`    The connected key is known not to hold the scope.
	 * - `error`            The list could not be read.
	 * - `not_subscribed`   Nothing points at this site.
	 * - `incomplete`       One subscription points here but lacks events or is paused.
	 * - `subscribed`       One complete, active subscription points here.
	 * - `duplicates`       More than one subscription points here.
	 *
	 * @return array{state: string, receiver_url: string, subscription: array|null, missing_events: string[], duplicates: string[], secret_stored: bool, message: string}
	 */
	public function status(): array {
		$status = array(
			'state'          => 'unavailable',
			'receiver_url'   => $this->receiver_url(),
			'subscription'   => null,
			'missing_events' => array(),
			'duplicates'     => array(),
			'secret_stored'  => $this->secret_stored(),
			'message'        => '',
		);

		if ( ! $this->gateway->available() ) {
			return $status;
		}

		if ( false === $this->gateway->can_manage() ) {
			$status['state'] = 'cannot_manage';
			return $status;
		}

		$response = $this->gateway->list_subscriptions();

		if ( is_wp_error( $response ) ) {
			$status['state']   = 'error';
			$status['message'] = $this->error_message( $response );
			return $status;
		}

		$matches = $this->matching( $this->rows( $response ) );

		if ( array() === $matches ) {
			$status['state'] = 'not_subscribed';
			return $status;
		}

		if ( count( $matches ) > 1 ) {
			$status['state']      = 'duplicates';
			$status['duplicates'] = array_values(
				array_filter(
					array_map(
						static function ( $row ) {
							return isset( $row['id'] ) ? (string) $row['id'] : '';
						},
						$matches
					),
					'strlen'
				)
			);
			return $status;
		}

		$subscription           = $matches[0];
		$status['subscription'] = array(
			'id'     => isset( $subscription['id'] ) ? (string) $subscription['id'] : '',
			'url'    => isset( $subscription['url'] ) ? (string) $subscription['url'] : '',
			'events' => $this->event_ids( $subscription ),
			'active' => ! ( isset( $subscription['active'] ) && false === $subscription['active'] ),
		);

		$status['missing_events'] = array_values(
			array_diff(
				$this->wanted_events( $this->include_access_events() ),
				$status['subscription']['events']
			)
		);

		$status['state'] = ( array() === $status['missing_events'] && $status['subscription']['active'] )
			? 'subscribed'
			: 'incomplete';

		return $status;
	}

	/**
	 * Creates the subscription, once, and stores its signing secret.
	 *
	 * @param bool $include_access  Whether to include the entitlement and segment events.
	 * @param bool $replace_secret  Whether a secret already stored may be overwritten.
	 * @return array{ok: bool, code: string, message: string, events: string[], omitted: string[]}
	 */
	public function subscribe( bool $include_access = true, bool $replace_secret = false ): array {
		return $this->locked(
			function () use ( $include_access, $replace_secret ) {
				$status = $this->status();

				if ( 'not_subscribed' !== $status['state'] ) {
					return $this->refusal( $this->refusal_code( $status['state'] ), $status['message'] );
				}

				if ( $status['secret_stored'] && ! $replace_secret ) {
					return $this->refusal( 'secret_stored' );
				}

				$events  = $this->wanted_events( $include_access );
				$omitted = array();

				$response = $this->gateway->create( $this->create_payload( $events ) );

				if ( is_wp_error( $response ) && $include_access && $this->is_rejection( $response ) ) {
					$omitted  = self::ACCESS_EVENTS;
					$events   = self::LIFECYCLE_EVENTS;
					$response = $this->gateway->create( $this->create_payload( $events ) );
				}

				if ( is_wp_error( $response ) ) {
					return $this->refusal( 'create_failed', $this->error_message( $response ) );
				}

				$stored = $this->store_secret_from( $response );

				update_option( self::ACCESS_OPTION, $include_access ? '1' : '0' );

				if ( ! $stored ) {
					return $this->refusal( 'secret_missing' );
				}

				return $this->success( 'created', $events, $omitted );
			}
		);
	}

	/**
	 * Adds the events a subscription lacks, and switches it on if it was paused.
	 *
	 * Events already on the subscription are kept: it may carry events this plugin
	 * does not know, and removing them would silence another consumer.
	 *
	 * @param bool|null $include_access Whether to include the entitlement and segment events; null keeps the stored choice.
	 * @return array{ok: bool, code: string, message: string, events: string[], omitted: string[]}
	 */
	public function complete( ?bool $include_access = null ): array {
		return $this->locked(
			function () use ( $include_access ) {
				if ( null !== $include_access ) {
					update_option( self::ACCESS_OPTION, $include_access ? '1' : '0' );
				}

				$status = $this->status();

				if ( 'incomplete' !== $status['state'] ) {
					return $this->refusal( 'subscribed' === $status['state'] ? 'nothing_to_add' : $this->refusal_code( $status['state'] ), $status['message'] );
				}

				$subscription = $status['subscription'];
				$wanted       = $this->wanted_events( $this->include_access_events() );
				$events       = array_values( array_unique( array_merge( $subscription['events'], $wanted ) ) );
				$omitted      = array();

				$payload  = array(
					'events' => $events,
					'active' => true,
				);
				$response = $this->gateway->update( $subscription['id'], $payload );

				if ( is_wp_error( $response ) && $this->is_rejection( $response ) && array() !== array_intersect( self::ACCESS_EVENTS, $status['missing_events'] ) ) {
					$omitted            = array_values( array_intersect( self::ACCESS_EVENTS, $status['missing_events'] ) );
					$payload['events']  = array_values( array_diff( $events, $omitted ) );
					$response           = $this->gateway->update( $subscription['id'], $payload );
				}

				if ( is_wp_error( $response ) ) {
					return $this->refusal( 'update_failed', $this->error_message( $response ) );
				}

				return $this->success( 'updated', $payload['events'], $omitted );
			}
		);
	}

	/**
	 * Replaces the subscription's signing secret and stores the new one.
	 *
	 * The old secret stops working at once, so this is for a subscription whose
	 * secret this site does not hold. Needs exactly one subscription pointing here.
	 *
	 * @return array{ok: bool, code: string, message: string, events: string[], omitted: string[]}
	 */
	public function rotate_secret(): array {
		return $this->locked(
			function () {
				$status = $this->status();

				if ( null === $status['subscription'] ) {
					return $this->refusal( $this->refusal_code( $status['state'] ), $status['message'] );
				}

				$response = $this->gateway->rotate( $status['subscription']['id'] );

				if ( is_wp_error( $response ) ) {
					return $this->refusal( 'rotate_failed', $this->error_message( $response ) );
				}

				if ( ! $this->store_secret_from( $response ) ) {
					return $this->refusal( 'secret_missing' );
				}

				return $this->success( 'rotated', $status['subscription']['events'], array() );
			}
		);
	}

	/**
	 * Asks Agend to send a test delivery to this site.
	 *
	 * @return array{ok: bool, code: string, message: string, events: string[], omitted: string[]}
	 */
	public function send_test(): array {
		return $this->locked(
			function () {
				$status = $this->status();

				if ( null === $status['subscription'] ) {
					return $this->refusal( $this->refusal_code( $status['state'] ), $status['message'] );
				}

				$response = $this->gateway->test( $status['subscription']['id'] );

				if ( is_wp_error( $response ) ) {
					return $this->refusal( 'test_failed', $this->error_message( $response ) );
				}

				return $this->success( 'tested', array(), array() );
			}
		);
	}

	/**
	 * Whether a signing secret is stored. The secret itself is never read out.
	 *
	 * @return bool
	 */
	private function secret_stored(): bool {
		return '' !== (string) get_option( self::SECRET_OPTION, '' );
	}

	/**
	 * Stores the secret a response carries, and nothing else from it.
	 *
	 * @param mixed $response Decoded create or rotate response.
	 * @return bool Whether a secret was found and stored.
	 */
	private function store_secret_from( $response ): bool {
		$data   = $this->unwrap( $response );
		$secret = isset( $data['secret'] ) && is_string( $data['secret'] ) ? trim( $data['secret'] ) : '';

		if ( '' === $secret ) {
			return false;
		}

		update_option( self::SECRET_OPTION, $secret );

		return true;
	}

	/**
	 * The create request body.
	 *
	 * @param string[] $events Event ids.
	 * @return array
	 */
	private function create_payload( array $events ): array {
		return array(
			'url'         => $this->receiver_url(),
			'description' => self::DESCRIPTION,
			'events'      => array_values( $events ),
		);
	}

	/**
	 * Runs an action under the short lock so two clicks cannot race two creates.
	 *
	 * @param callable $action Action returning a result array.
	 * @return array
	 */
	private function locked( callable $action ): array {
		if ( false !== get_transient( self::LOCK ) ) {
			return $this->refusal( 'busy' );
		}

		set_transient( self::LOCK, '1', self::LOCK_TTL );

		try {
			return $action();
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/**
	 * Subscriptions whose URL names this site's receiver.
	 *
	 * @param array $rows Subscription rows.
	 * @return array[]
	 */
	private function matching( array $rows ): array {
		$receiver = $this->receiver_url();
		$matches  = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['url'] ) && is_string( $row['url'] ) && self::same_receiver( $row['url'], $receiver ) ) {
				$matches[] = $row;
			}
		}

		return $matches;
	}

	/**
	 * The subscription rows in a list response.
	 *
	 * @param mixed $response Decoded list response.
	 * @return array
	 */
	private function rows( $response ): array {
		$data = $this->unwrap( $response );

		return array_values(
			array_filter(
				$data,
				static function ( $row ) {
					return is_array( $row );
				}
			)
		);
	}

	/**
	 * The payload of a response: its `data` when it has one, else the response.
	 *
	 * @param mixed $response Decoded response.
	 * @return array
	 */
	private function unwrap( $response ): array {
		if ( ! is_array( $response ) ) {
			return array();
		}

		return ( isset( $response['data'] ) && is_array( $response['data'] ) ) ? $response['data'] : $response;
	}

	/**
	 * The event ids on a subscription row.
	 *
	 * @param array $row Subscription row.
	 * @return string[]
	 */
	private function event_ids( array $row ): array {
		if ( ! isset( $row['events'] ) || ! is_array( $row['events'] ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'strval', array_filter( $row['events'], 'is_scalar' ) ),
				'strlen'
			)
		);
	}

	/**
	 * Whether the gateway rejected the request itself (an event id it does not
	 * know, say) rather than failing to answer it.
	 *
	 * @param WP_Error $error Error from a gateway call.
	 * @return bool
	 */
	private function is_rejection( WP_Error $error ): bool {
		return in_array( $this->http_status( $error ), array( 400, 422 ), true );
	}

	/**
	 * The HTTP status a gateway error carries, or 0.
	 *
	 * @param WP_Error $error Error from a gateway call.
	 * @return int
	 */
	private function http_status( WP_Error $error ): int {
		$data = $error->get_error_data();

		return ( is_array( $data ) && isset( $data['status_code'] ) ) ? (int) $data['status_code'] : 0;
	}

	/**
	 * A message safe to show: the gateway's text, tags stripped, with its status.
	 *
	 * @param WP_Error $error Error from a gateway call.
	 * @return string
	 */
	private function error_message( WP_Error $error ): string {
		$text   = trim( wp_strip_all_tags( $error->get_error_message() ) );
		$status = $this->http_status( $error );

		return trim( $text . ( $status > 0 ? ' (HTTP ' . $status . ')' : '' ) );
	}

	/**
	 * The refusal code for a status state an action cannot proceed from.
	 *
	 * @param string $state A `status()` state.
	 * @return string
	 */
	private function refusal_code( string $state ): string {
		switch ( $state ) {
			case 'unavailable':
			case 'cannot_manage':
			case 'error':
			case 'duplicates':
			case 'not_subscribed':
				return $state;
			default:
				return 'already_subscribed';
		}
	}

	/**
	 * A failed result.
	 *
	 * @param string $code    What went wrong.
	 * @param string $message The gateway's own text, when there is one.
	 * @return array{ok: bool, code: string, message: string, events: string[], omitted: string[]}
	 */
	private function refusal( string $code, string $message = '' ): array {
		return array(
			'ok'      => false,
			'code'    => $code,
			'message' => $message,
			'events'  => array(),
			'omitted' => array(),
		);
	}

	/**
	 * A successful result.
	 *
	 * @param string   $code    What was done.
	 * @param string[] $events  The events the subscription now carries.
	 * @param string[] $omitted Events left out because the gateway rejected them.
	 * @return array{ok: bool, code: string, message: string, events: string[], omitted: string[]}
	 */
	private function success( string $code, array $events, array $omitted ): array {
		return array(
			'ok'      => true,
			'code'    => $code,
			'message' => '',
			'events'  => array_values( $events ),
			'omitted' => array_values( $omitted ),
		);
	}

	/**
	 * The comparison key for a receiver URL: host, base path and route.
	 *
	 * @param string $url A receiver URL.
	 * @return string Empty when the URL has no host.
	 */
	private static function receiver_key( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$host = strtolower( (string) $parts['host'] );
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$base = '';
		$route = '';

		$query = array();
		if ( isset( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $query );
		}

		if ( isset( $query['rest_route'] ) && is_string( $query['rest_route'] ) ) {
			$route = $query['rest_route'];
			$base  = (string) preg_replace( '#/index\.php$#', '', $path );
		} elseif ( false !== strpos( $path, '/wp-json/' ) ) {
			$position = (int) strpos( $path, '/wp-json/' );
			$base     = substr( $path, 0, $position );
			$route    = substr( $path, $position + strlen( '/wp-json' ) );
		} else {
			$base = $path;
		}

		$authority = $host . ( ( $port > 0 && 80 !== $port && 443 !== $port ) ? ':' . $port : '' );

		return $authority . rtrim( $base, '/' ) . '|/' . trim( $route, '/' );
	}
}

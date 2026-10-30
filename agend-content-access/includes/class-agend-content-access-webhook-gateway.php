<?php
/**
 * The webhook subscription calls Content Access makes, behind one seam.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the webhook setup needs from the gateway.
 *
 * Each call returns the decoded response array or a `WP_Error`, exactly as
 * Core's `agend_apps_webhooks_*` wrappers do. The setup logic depends on this
 * interface rather than on Core's functions, so it can be driven without a
 * gateway.
 */
interface Agend_Content_Access_Webhook_Gateway {

	/**
	 * Whether this Core can manage subscriptions at all (older Core cannot).
	 *
	 * @return bool
	 */
	public function available(): bool;

	/**
	 * Whether the connected key may manage subscriptions.
	 *
	 * @return bool|null True when it holds the scope, false when it is known not
	 *                   to, null when its scopes have not been read yet.
	 */
	public function can_manage(): ?bool;

	/**
	 * Lists one page of the account's subscriptions.
	 *
	 * The gateway paginates the list, so the caller follows `meta.pagination`
	 * until it has them all; reading only the first page would miss a
	 * subscription on a later one.
	 *
	 * @param int $page  Page number, from 1.
	 * @param int $limit Subscriptions per page.
	 * @return array|WP_Error
	 */
	public function list_subscriptions( int $page = 1, int $limit = 100 );

	/**
	 * Creates a subscription. The response carries its signing secret once.
	 *
	 * @param array $payload `url`, `events`, `description`.
	 * @return array|WP_Error
	 */
	public function create( array $payload );

	/**
	 * Updates a subscription.
	 *
	 * @param string $id      Subscription id.
	 * @param array  $payload Fields to change.
	 * @return array|WP_Error
	 */
	public function update( string $id, array $payload );

	/**
	 * Rotates a subscription's signing secret. The response carries the new one.
	 *
	 * @param string $id Subscription id.
	 * @return array|WP_Error
	 */
	public function rotate( string $id );

	/**
	 * Sends a test delivery to a subscription.
	 *
	 * @param string $id Subscription id.
	 * @return array|WP_Error
	 */
	public function test( string $id );
}

/**
 * The gateway as Agend Apps Core exposes it.
 *
 * Core owns transport, the API key and the scope cache. This adds nothing to
 * them: it only calls their published functions and reads their scope cache.
 */
class Agend_Content_Access_Core_Webhook_Gateway implements Agend_Content_Access_Webhook_Gateway {

	/**
	 * The scope every write below needs.
	 *
	 * @var string
	 */
	const MANAGE_SCOPE = 'webhooks.subscriptions.manage';

	/**
	 * {@inheritdoc}
	 */
	public function available(): bool {
		$functions = array(
			'agend_apps_webhooks_list_subscriptions',
			'agend_apps_webhooks_create_subscription',
			'agend_apps_webhooks_update_subscription',
			'agend_apps_webhooks_rotate_subscription_secret',
			'agend_apps_webhooks_test_subscription',
		);

		foreach ( $functions as $function ) {
			if ( ! function_exists( $function ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function can_manage(): ?bool {
		if (
			! class_exists( 'Agend_Apps_Key_Scopes' )
			|| ! method_exists( 'Agend_Apps_Key_Scopes', 'known' )
			|| ! method_exists( 'Agend_Apps_Key_Scopes', 'has' )
		) {
			return null;
		}

		if ( ! Agend_Apps_Key_Scopes::known() ) {
			return null;
		}

		return Agend_Apps_Key_Scopes::has( self::MANAGE_SCOPE );
	}

	/**
	 * {@inheritdoc}
	 */
	public function list_subscriptions( int $page = 1, int $limit = 100 ) {
		// Core's wrapper takes no arguments of its own; its documented extension
		// point is this filter, so the page rides on it for exactly one call.
		$paging = static function ( $args ) use ( $page, $limit ) {
			$args          = is_array( $args ) ? $args : array();
			$args['query'] = array_merge(
				isset( $args['query'] ) && is_array( $args['query'] ) ? $args['query'] : array(),
				array(
					'page'  => $page,
					'limit' => $limit,
				)
			);

			return $args;
		};

		add_filter( 'agend_apps_webhooks_list_subscriptions_args', $paging );

		try {
			return agend_apps_webhooks_list_subscriptions();
		} finally {
			remove_filter( 'agend_apps_webhooks_list_subscriptions_args', $paging );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function create( array $payload ) {
		return agend_apps_webhooks_create_subscription( $payload );
	}

	/**
	 * {@inheritdoc}
	 */
	public function update( string $id, array $payload ) {
		return agend_apps_webhooks_update_subscription( $id, $payload );
	}

	/**
	 * {@inheritdoc}
	 */
	public function rotate( string $id ) {
		return agend_apps_webhooks_rotate_subscription_secret( $id );
	}

	/**
	 * {@inheritdoc}
	 */
	public function test( string $id ) {
		return agend_apps_webhooks_test_subscription( $id );
	}
}

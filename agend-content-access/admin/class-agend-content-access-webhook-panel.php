<?php
/**
 * The webhook setup panel on the Agend Content Access settings screen.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows whether Agend is connected to this site's webhook receiver, and lets an
 * administrator connect it in one click.
 *
 * All decisions are made by `Agend_Content_Access_Webhook_Setup`; this class only
 * checks who is asking, runs one action, and prints the outcome. The signing
 * secret is never printed: the panel can say whether one is stored and nothing
 * more.
 */
class Agend_Content_Access_Webhook_Panel {

	const NONCE = 'agend_content_access_webhooks';
	const FIELD = 'agend_content_access_webhook_action';

	/**
	 * The setup logic.
	 *
	 * @var Agend_Content_Access_Webhook_Setup
	 */
	private $setup;

	/**
	 * The outcome of the action run on this request, if any.
	 *
	 * @var array|null
	 */
	private $result = null;

	/**
	 * Registers the form handler.
	 *
	 * @param Agend_Content_Access_Webhook_Setup|null $setup Setup to use; the default talks to Core.
	 */
	public function __construct( ?Agend_Content_Access_Webhook_Setup $setup = null ) {
		$this->setup = $setup ?? new Agend_Content_Access_Webhook_Setup();

		add_action( 'admin_init', array( $this, 'handle_action' ) );
	}

	/**
	 * Runs the action a form submitted.
	 *
	 * Capability first, then nonce, as the credential form does: the nonce proves
	 * the request came from this form, the capability proves the person may change
	 * where Agend sends member data.
	 */
	public function handle_action(): void {
		if ( ! isset( $_POST[ self::FIELD ] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		$action  = sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) );
		$access  = isset( $_POST['agend_content_access_include_access'] );
		$replace = isset( $_POST['agend_content_access_replace_secret'] );
		$confirm = isset( $_POST['agend_content_access_confirm_rotate'] );

		if ( 'subscribe' === $action ) {
			$this->result = $this->setup->subscribe( $access, $replace );
		} elseif ( 'complete' === $action ) {
			$this->result = $this->setup->complete( $access );
		} elseif ( 'rotate' === $action ) {
			$this->result = $confirm
				? $this->setup->rotate_secret()
				: array(
					'ok'      => false,
					'code'    => 'confirm_required',
					'message' => '',
					'events'  => array(),
					'omitted' => array(),
				);
		} elseif ( 'test' === $action ) {
			$this->result = $this->setup->send_test();
		}
	}

	/**
	 * Prints the panel.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$setup          = $this->setup;
		$status         = $setup->status();
		$result         = $this->result;
		$include_access = $setup->include_access_events();
		$wanted         = $setup->wanted_events( $include_access );
		$panel          = $this;

		require AGEND_CONTENT_ACCESS_DIR . 'admin/views/webhook-panel.php';
	}

	/**
	 * The sentence for an action's outcome.
	 *
	 * @param array $result A `Agend_Content_Access_Webhook_Setup` result.
	 * @return string Plain text; the caller escapes it.
	 */
	public static function message( array $result ): string {
		$code    = isset( $result['code'] ) ? (string) $result['code'] : '';
		$detail  = isset( $result['message'] ) ? (string) $result['message'] : '';
		$omitted = isset( $result['omitted'] ) && is_array( $result['omitted'] ) ? $result['omitted'] : array();
		$left    = array() === $omitted
			? ''
			: ' ' . sprintf(
				/* translators: %s: comma separated event ids. */
				__( 'Agend did not recognise these events yet, so they were left out: %s. Use "Add missing events" once Agend supports them.', 'agend-content-access' ),
				implode( ', ', $omitted )
			);

		switch ( $code ) {
			case 'created':
				return __( 'The webhook was created and its signing secret was stored. Agend will now send member events to this site.', 'agend-content-access' ) . $left;
			case 'updated':
				return __( 'The webhook was updated with the missing events.', 'agend-content-access' ) . $left;
			case 'rotated':
				return __( 'The signing secret was replaced and stored. Deliveries signed with the old secret will now be refused.', 'agend-content-access' );
			case 'tested':
				return __( 'A test delivery was requested. It appears in the Agend Apps log once it arrives.', 'agend-content-access' );
			case 'unavailable':
				return __( 'This version of Agend Apps Core cannot manage webhook subscriptions. Update Core, or follow the manual steps below.', 'agend-content-access' );
			case 'cannot_manage':
				return __( 'The connected API key does not hold the webhooks.subscriptions.manage scope. Add it to the key in the Agend dashboard, or follow the manual steps below.', 'agend-content-access' );
			case 'error':
				/* translators: %s: error text from Agend. */
				return sprintf( __( 'Agend could not be asked about this site\'s webhooks: %s', 'agend-content-access' ), $detail );
			case 'duplicates':
				return __( 'More than one webhook already points at this site. Nothing was changed. Remove the extras in the Agend dashboard, then try again.', 'agend-content-access' );
			case 'already_subscribed':
				return __( 'A webhook already points at this site, so a second one was not created. Use "Add missing events" if it lacks any.', 'agend-content-access' );
			case 'nothing_to_add':
				return __( 'Nothing to add: the webhook already carries every event.', 'agend-content-access' );
			case 'not_subscribed':
				return __( 'No webhook points at this site yet.', 'agend-content-access' );
			case 'secret_stored':
				return __( 'A signing secret is already stored for this site. Creating a webhook replaces it, which would break any webhook still using it. Tick "Replace the stored signing secret" to go ahead.', 'agend-content-access' );
			case 'secret_missing':
				return __( 'The webhook exists but Agend did not return its signing secret. Use "Replace signing secret" to get one.', 'agend-content-access' );
			case 'busy':
				return __( 'Another setup action is still running. Wait a few seconds and try again.', 'agend-content-access' );
			case 'confirm_required':
				return __( 'Tick the box to confirm replacing the signing secret.', 'agend-content-access' );
			case 'create_failed':
				/* translators: %s: error text from Agend. */
				return sprintf( __( 'The webhook could not be created: %s', 'agend-content-access' ), $detail );
			case 'update_failed':
				/* translators: %s: error text from Agend. */
				return sprintf( __( 'The webhook could not be updated: %s', 'agend-content-access' ), $detail );
			case 'rotate_failed':
				/* translators: %s: error text from Agend. */
				return sprintf( __( 'The signing secret could not be replaced: %s', 'agend-content-access' ), $detail );
			case 'test_failed':
				/* translators: %s: error text from Agend. */
				return sprintf( __( 'The test delivery could not be requested: %s', 'agend-content-access' ), $detail );
			default:
				return __( 'The request could not be completed.', 'agend-content-access' );
		}
	}
}

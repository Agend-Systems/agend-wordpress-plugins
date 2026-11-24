<?php
/**
 * User sync.
 *
 * @package Agend_Loop_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pushes a WordPress user's identity and roles to Loop when they log in or
 * when their roles or profile change. Failures are logged and never block the
 * originating WordPress flow (e.g. login).
 */
class Agend_Loop_Sync_User_Sync {

	/**
	 * Provisioning outcomes the gateway reports.
	 *
	 * @var string[]
	 */
	const PROVISIONING_OUTCOMES = array( 'provisioned', 'linked', 'already_linked', 'skipped' );

	/**
	 * Reasons the gateway gives for skipping a member. Anything else is read
	 * as 'unknown'.
	 *
	 * @var string[]
	 */
	const SKIP_REASONS = array( 'connection_not_usable', 'provisioning_not_allowed', 'restricted_role', 'consent_required', 'rate_limited', 'conflict', 'provisioning_failed' );

	/**
	 * Members per bulk request; the gateway's limit.
	 */
	const BATCH_SIZE = 100;

	/**
	 * Per-request guard so a user is synced at most once per request, even when
	 * several triggering hooks (e.g. profile_update + set_user_role) fire in the
	 * same request.
	 *
	 * @var array<int, bool>
	 */
	private static $synced_this_request = array();

	/**
	 * Users whose login sync is queued for the shutdown hook.
	 *
	 * @var array<int, WP_User>
	 */
	private static $pending_logins = array();

	/**
	 * Clears the per-request guards. For tests and long-running CLI.
	 *
	 * @return void
	 */
	public static function reset_request_state() {
		self::$synced_this_request = array();
		self::$pending_logins      = array();
	}

	/**
	 * Registers the WordPress hooks that trigger a user sync.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_login', array( $this, 'on_login' ), 20, 2 );
		add_action( 'profile_update', array( $this, 'on_profile_update' ), 20, 1 );
		add_action( 'set_user_role', array( $this, 'on_set_role' ), 20, 1 );

		// Event-driven: re-sync a member when iugo-membership-kiosk reports their
		// data changed upstream (Upbeat), so identity / role changes made outside
		// WordPress propagate to Loop without waiting for a scheduled run.
		add_action( 'iugo_membership_kiosk_invalidate_member_caches_complete', array( $this, 'on_member_changed' ), 10, 2 );
	}

	/**
	 * Re-syncs a member when iugo-membership-kiosk invalidates their caches
	 * (an upstream create / update / delete). Resolves the membership number to
	 * a local WordPress user and pushes the current identity + roles.
	 *
	 * @param string|int $membership_number The Upbeat membership number.
	 * @param string     $event_name        The invalidation event name (unused).
	 * @return void
	 */
	public function on_member_changed( $membership_number, $event_name = '' ) {
		unset( $event_name );

		if ( ! Agend_Loop_Sync_Settings::is_enabled() || ! Agend_Loop_Sync_Settings::is_user_sync_enabled() ) {
			return;
		}

		$wp_id = Agend_Loop_Sync_Contact_Resolver::resolve_wp_id( (string) $membership_number );
		if ( null === $wp_id ) {
			return;
		}

		$user = get_userdata( $wp_id );
		if ( $user instanceof WP_User ) {
			$this->sync_user( $user );
		}
	}

	/**
	 * Syncs the user on login.
	 *
	 * @param string  $user_login The user login (unused).
	 * @param WP_User $user       The authenticated user.
	 * @return void
	 */
	public function on_login( $user_login, $user ) {
		unset( $user_login );
		if ( ! $user instanceof WP_User ) {
			return;
		}

		// The gateway call is a blocking HTTP request; queue it for shutdown so
		// a slow gateway does not hold up the member's login redirect.
		if ( array() === self::$pending_logins ) {
			add_action( 'shutdown', array( $this, 'flush_pending_logins' ) );
		}
		self::$pending_logins[ (int) $user->ID ] = $user;
	}

	/**
	 * Syncs users queued by on_login(), after the response has been sent where
	 * the server supports it.
	 *
	 * @return void
	 */
	public function flush_pending_logins() {
		$pending               = self::$pending_logins;
		self::$pending_logins = array();

		if ( array() === $pending ) {
			return;
		}

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		foreach ( $pending as $user ) {
			$this->sync_user( $user );
		}
	}

	/**
	 * Syncs the user after a profile update.
	 *
	 * @param int $user_id The user id.
	 * @return void
	 */
	public function on_profile_update( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( $user instanceof WP_User ) {
			$this->sync_user( $user );
		}
	}

	/**
	 * Syncs the user after a role change.
	 *
	 * @param int $user_id The user id.
	 * @return void
	 */
	public function on_set_role( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( $user instanceof WP_User ) {
			$this->sync_user( $user );
		}
	}

	/**
	 * Builds the payload and syncs a single user to Loop.
	 *
	 * @param WP_User $user The user to sync.
	 * @return bool True on success or dry-run; false on failure or when disabled.
	 */
	public function sync_user( WP_User $user ): bool {
		if ( ! Agend_Loop_Sync_Settings::is_enabled() || ! Agend_Loop_Sync_Settings::is_user_sync_enabled() ) {
			return false;
		}

		if ( ! function_exists( 'agend_apps_loop_sync_user' ) ) {
			Agend_Loop_Sync_Logger::error( 'agend-apps-core is not available; cannot sync user', array( 'wp_id' => $user->ID ) );
			return false;
		}

		// external_id is the subject the SSO assertion's NameID carries: for this
		// site the Upbeat membership number, not the WordPress user id. A user
		// with no membership number cannot be matched to a Loop user, so skip
		// rather than send an invalid id.
		$membership_number = trim(
			(string) get_user_meta( $user->ID, 'imk_membership_number', true )
		);
		if ( '' === $membership_number ) {
			Agend_Loop_Sync_Logger::warning(
				'User has no membership number; skipping Loop user sync',
				array( 'wp_id' => (int) $user->ID )
			);
			return false;
		}

		$external_id = Agend_Loop_Sync_Contact_Resolver::to_external_id( $membership_number );
		if ( null === $external_id || ! is_email( $user->user_email ) ) {
			Agend_Loop_Sync_Logger::warning(
				'User has a non-numeric membership number or invalid email; skipping Loop user sync',
				array( 'wp_id' => (int) $user->ID )
			);
			return false;
		}

		// De-duplicate within a single request: profile_update and set_user_role
		// can both fire for the same user in one request, and the event-driven
		// hook may overlap with a WordPress hook. One gateway call per user is
		// enough.
		if ( isset( self::$synced_this_request[ (int) $user->ID ] ) ) {
			return true;
		}
		self::$synced_this_request[ (int) $user->ID ] = true;

		$payload = array(
			'idp_entity_id' => Agend_Loop_Sync_Settings::idp_entity_id(),
			'external_id'   => $external_id,
			'email'         => (string) $user->user_email,
			'display_name'  => (string) $user->display_name,
			'roles'         => array_values( (array) $user->roles ),
		);

		$provision = Agend_Loop_Sync_Settings::should_provision();
		if ( $provision ) {
			$payload['provision'] = true;
		}

		if ( Agend_Loop_Sync_Settings::is_dry_run() ) {
			Agend_Loop_Sync_Logger::info(
				'Dry run: would sync user',
				array(
					'wp_id'       => (int) $user->ID,
					'external_id' => $external_id,
					'roles'       => $payload['roles'],
					'provision'   => $provision,
				)
			);
			return true;
		}

		$response = agend_apps_loop_sync_user( $payload );

		// The gateway refuses the whole request when the key lacks the
		// provisioning scope. Note it, so later syncs stop asking, and sync the
		// member without it rather than not at all.
		if ( $provision && is_wp_error( $response ) && self::is_scope_refusal( $response ) ) {
			Agend_Loop_Sync_Settings::note_provision_refused();
			Agend_Loop_Sync_Logger::error(
				'Loop account creation was refused: the site key lacks loop.users.provision; synced without it',
				array( 'wp_id' => (int) $user->ID )
			);
			unset( $payload['provision'] );
			$provision = false;
			$response  = agend_apps_loop_sync_user( $payload );
		}

		if ( is_wp_error( $response ) ) {
			return $this->handle_sync_error( $user, $response, $provision );
		}

		$data    = is_array( $response ) ? ( $response['data'] ?? $response ) : array();
		$outcome = $provision ? self::provisioning_outcome( is_array( $data ) ? $data : array() ) : array();

		if ( $provision ) {
			Agend_Loop_Sync_Provision_Retry::remove( array( (int) $user->ID ) );
		}

		$context = array( 'wp_id' => (int) $user->ID );
		if ( array() !== $outcome ) {
			$context['provisioning'] = $outcome['outcome'];
			if ( isset( $outcome['reason'] ) ) {
				$context['reason'] = $outcome['reason'];
			}
		}
		Agend_Loop_Sync_Logger::info( 'User synced', $context );

		return true;
	}

	/**
	 * Logs a failed single-user sync without the member's email, and, when the
	 * sync asked for provisioning, explains why the account was not created and
	 * queues a retry where waiting will help.
	 *
	 * @param WP_User  $user      The user.
	 * @param WP_Error $error     The gateway error.
	 * @param bool     $provision Whether the request asked for provisioning.
	 * @return bool Always false.
	 */
	private function handle_sync_error( WP_User $user, WP_Error $error, bool $provision ): bool {
		$reason = $provision ? self::skip_reason_from_error( $error ) : '';
		if ( '' !== $reason ) {
			Agend_Loop_Sync_Logger::warning(
				'Loop account not created',
				array(
					'wp_id'  => (int) $user->ID,
					'reason' => $reason,
				)
			);
			if ( Agend_Loop_Sync_Provision_Retry::is_retryable( $reason ) ) {
				Agend_Loop_Sync_Provision_Retry::add( array( (int) $user->ID => $reason ) );
			} else {
				// Waiting will not help, so a retry already queued would only
				// repeat this answer.
				Agend_Loop_Sync_Provision_Retry::remove( array( (int) $user->ID ) );
			}
			return false;
		}

		Agend_Loop_Sync_Logger::error( 'User sync failed', array( 'wp_id' => (int) $user->ID ) + self::error_context( $error ) );

		return false;
	}

	/**
	 * Reads the `provisioning` block of a synced user or bulk result item.
	 *
	 * @param array<string, mixed> $item The synced user, or one bulk result item.
	 * @return array{outcome?: string, reason?: string} Empty when the gateway sent none.
	 */
	public static function provisioning_outcome( array $item ): array {
		$block = $item['provisioning'] ?? ( $item['user']['provisioning'] ?? null );
		if ( ! is_array( $block ) || ! isset( $block['outcome'] ) || ! is_scalar( $block['outcome'] ) ) {
			return array();
		}

		$outcome = (string) $block['outcome'];
		if ( ! in_array( $outcome, self::PROVISIONING_OUTCOMES, true ) ) {
			$outcome = 'skipped';
		}

		$read = array( 'outcome' => $outcome );
		if ( 'skipped' === $outcome ) {
			$reason         = isset( $block['reason'] ) && is_scalar( $block['reason'] ) ? (string) $block['reason'] : '';
			$read['reason'] = in_array( $reason, self::SKIP_REASONS, true ) ? $reason : 'unknown';
		}

		return $read;
	}

	/**
	 * The gateway reports a single member it could not provision as a 404 whose
	 * message ends "provisioning was skipped: <reason>." Reads the reason, and
	 * nothing else from the message, which also carries the member's email.
	 *
	 * @param WP_Error $error The gateway error.
	 * @return string The skip reason, or '' when the error is something else.
	 */
	public static function skip_reason_from_error( WP_Error $error ): string {
		if ( ! preg_match( '/provisioning was skipped: ([a-z_]+)/', $error->get_error_message(), $match ) ) {
			return '';
		}

		return in_array( $match[1], self::SKIP_REASONS, true ) ? $match[1] : 'unknown';
	}

	/**
	 * Whether a failed request is worth sending again later: no answer at all
	 * (a transport error), a server error, or a rate limit. A request the
	 * gateway rejected as invalid or unauthorised would fail the same way.
	 *
	 * @param WP_Error $error The error.
	 * @return bool
	 */
	public static function is_passing_failure( WP_Error $error ): bool {
		if ( 'agend_apps_rate_limited' === $error->get_error_code() ) {
			return true;
		}

		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status_code'] ) ? (int) $data['status_code'] : 0;

		if ( 'agend_api_error' !== $error->get_error_code() || 0 === $status ) {
			return true;
		}

		return 429 === $status || $status >= 500;
	}

	/**
	 * Whether the gateway refused the request for a missing scope.
	 *
	 * @param WP_Error $error The gateway error.
	 * @return bool
	 */
	public static function is_scope_refusal( WP_Error $error ): bool {
		$data = $error->get_error_data();
		$code = is_array( $data ) ? ( $data['body']['error']['code'] ?? '' ) : '';

		return 'INSUFFICIENT_SCOPES' === $code;
	}

	/**
	 * What to log about a gateway error: the HTTP status, the gateway's code and
	 * the message with any email address masked, since a not-found message
	 * names the member's address.
	 *
	 * @param WP_Error $error The gateway error.
	 * @return array<string, mixed>
	 */
	private static function error_context( WP_Error $error ): array {
		$data    = $error->get_error_data();
		$context = array(
			'error' => preg_replace( '/[^\s()<>@,;:"]+@[^\s()<>@,;:"]+/', '[email]', $error->get_error_message() ),
		);

		if ( is_array( $data ) ) {
			if ( isset( $data['status_code'] ) ) {
				$context['status'] = (int) $data['status_code'];
			}
			$code = $data['body']['error']['code'] ?? '';
			if ( is_scalar( $code ) && '' !== (string) $code ) {
				$context['code'] = (string) $code;
			}
		}

		return $context;
	}

	/**
	 * Backfills every local member into Loop, using the bulk-sync endpoint in
	 * batches of 100. Intended for the initial rollout. Honours dry run.
	 *
	 * Without provisioning it refreshes display names and role-based channels on
	 * existing Loop accounts only, and a member who has never signed in to Loop
	 * comes back as failed. With provisioning on (see
	 * Agend_Loop_Sync_Settings::should_provision()) Agend first creates or links
	 * the account of each member who has none, so they are in Loop before their
	 * first sign-in. Members it cannot create yet for a passing reason are
	 * queued for a retry an hour later (Agend_Loop_Sync_Provision_Retry).
	 *
	 * @return array<string, mixed> A summary of the backfill.
	 */
	public static function backfill_all(): array {
		if ( ! Agend_Loop_Sync_Settings::is_enabled() || ! Agend_Loop_Sync_Settings::is_user_sync_enabled() ) {
			$summary             = self::empty_summary( false );
			$summary['errors'][] = 'User sync is disabled in settings';
			return $summary;
		}

		$users = get_users(
			array(
				'meta_key'     => 'imk_membership_number', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
				'orderby'      => 'ID',
				'order'        => 'ASC',
			)
		);

		$summary = self::sync_users( $users, Agend_Loop_Sync_Settings::should_provision() );

		Agend_Loop_Sync_Logger::info( 'User backfill complete', self::loggable_summary( $summary ) );

		return $summary;
	}

	/**
	 * Bulk-syncs the given users in batches of 100, with or without
	 * provisioning, and keeps the provisioning retry queue in step: a member
	 * skipped for a passing reason is queued, and a member whose account now
	 * exists is taken off.
	 *
	 * Provisioning stops early in two cases. A refusal for a missing scope ends
	 * the run, since every later batch would be refused too. A member skipped
	 * for the hourly creation limit means the rest of the run would be too, so
	 * the remaining members are queued for the retry without being sent.
	 *
	 * @param WP_User[] $users     Users to sync.
	 * @param bool      $provision Whether to ask Agend to create missing accounts.
	 * @return array<string, mixed> The summary: counts, `provisioning` counts when provisioning, `errors`, and `attempted_ids` (users whose batch was sent).
	 */
	public static function sync_users( array $users, bool $provision ): array {
		$summary          = self::empty_summary( $provision );
		$summary['total'] = count( $users );

		if ( ! function_exists( 'agend_apps_loop_bulk_sync_users' ) ) {
			$summary['errors'][] = 'agend-apps-core is not available';
			return $summary;
		}

		$items = array();
		foreach ( $users as $user ) {
			$item = self::bulk_item( $user, $summary );
			if ( null === $item ) {
				$summary['unsendable_ids'][] = (int) $user->ID;
			} else {
				$items[ (int) $user->ID ] = $item;
			}
		}

		$idp      = Agend_Loop_Sync_Settings::idp_entity_id();
		$retry    = array();
		$done     = array();
		$deferred = false;

		$chunks = array_chunk( $items, self::BATCH_SIZE, true );
		$count  = count( $chunks );
		for ( $index = 0; $index < $count; $index++ ) {
			$chunk = $chunks[ $index ];
			if ( $deferred ) {
				foreach ( array_keys( $chunk ) as $wp_id ) {
					$retry[ $wp_id ] = 'rate_limited';
				}
				$summary['provisioning']['deferred'] += count( $chunk );
				continue;
			}

			++$summary['batches'];

			if ( Agend_Loop_Sync_Settings::is_dry_run() ) {
				Agend_Loop_Sync_Logger::info(
					'Dry run: would bulk-sync users',
					array(
						'count'     => count( $chunk ),
						'provision' => $provision,
					)
				);
				$summary['sent'] += count( $chunk );
				continue;
			}

			$response = agend_apps_loop_bulk_sync_users( array_values( $chunk ), $idp, $provision );

			// Counted whether or not the request succeeds, so a member whose
			// batch keeps failing still reaches the retry limit.
			$summary['attempted_ids'] = array_merge( $summary['attempted_ids'], array_keys( $chunk ) );

			if ( is_wp_error( $response ) ) {
				// A missing scope refuses every batch. Note it, then send this
				// batch and the rest without provisioning so members are still
				// refreshed.
				if ( $provision && self::is_scope_refusal( $response ) ) {
					Agend_Loop_Sync_Settings::note_provision_refused();
					$summary['provisioning']['refused'] = true;
					$summary['errors'][]                = 'Agend refused to create Loop accounts: the site key does not hold loop.users.provision. Members were synced without creating accounts. Ask Agend to grant the scope, or turn off "Create Loop accounts".';
					Agend_Loop_Sync_Logger::error( 'Loop account creation was refused: the site key lacks loop.users.provision; syncing without it' );
					$provision = false;
					--$summary['batches'];
					--$index;
					continue;
				}

				$summary['errors'][] = (string) self::error_context( $response )['error'];
				Agend_Loop_Sync_Logger::error( 'User backfill batch failed', self::error_context( $response ) );
				if ( $provision && self::is_passing_failure( $response ) ) {
					foreach ( array_keys( $chunk ) as $wp_id ) {
						$retry[ $wp_id ] = 'request_failed';
					}
				}
				continue;
			}

			$data                  = is_array( $response ) ? ( $response['data'] ?? $response ) : array();
			$summary['sent']      += count( $chunk );
			$summary['succeeded'] += (int) ( $data['succeeded'] ?? 0 );
			$summary['failed']    += (int) ( $data['failed'] ?? 0 );

			if ( ! $provision ) {
				continue;
			}

			$by_external_id = array();
			foreach ( $chunk as $wp_id => $item ) {
				$by_external_id[ (int) $item['external_id'] ][] = $wp_id;
			}

			foreach ( (array) ( $data['results'] ?? array() ) as $result ) {
				if ( ! is_array( $result ) || ! isset( $result['external_id'] ) ) {
					continue;
				}
				$outcome = self::provisioning_outcome( $result );
				if ( array() === $outcome ) {
					continue;
				}

				$wp_ids = $by_external_id[ (int) $result['external_id'] ] ?? array();
				++$summary['provisioning'][ $outcome['outcome'] ];

				if ( 'skipped' !== $outcome['outcome'] ) {
					$done = array_merge( $done, $wp_ids );
					continue;
				}

				$reason = $outcome['reason'];

				$summary['provisioning']['reasons'][ $reason ] = ( $summary['provisioning']['reasons'][ $reason ] ?? 0 ) + 1;

				foreach ( $wp_ids as $wp_id ) {
					Agend_Loop_Sync_Logger::warning(
						'Loop account not created',
						array(
							'wp_id'  => (int) $wp_id,
							'reason' => $reason,
						)
					);
					if ( Agend_Loop_Sync_Provision_Retry::is_retryable( $reason ) ) {
						$retry[ $wp_id ] = $reason;
					} else {
						$done[] = $wp_id;
					}
				}

				if ( 'rate_limited' === $reason ) {
					$deferred = true;
				}
			}
		}

		// Keyed on what the run set out to do: a scope refusal part way turns
		// $provision off, but the members it already queued or settled count.
		if ( $summary['provision'] ) {
			Agend_Loop_Sync_Provision_Retry::remove( $done );
			Agend_Loop_Sync_Provision_Retry::add( $retry );
			$summary['provisioning']['queued'] = count( $retry );
		}

		$summary['attempted_ids'] = array_values( array_unique( $summary['attempted_ids'] ) );

		return $summary;
	}

	/**
	 * One member's bulk-sync item, or null (counted in the summary) when the
	 * member has no usable membership number or email.
	 *
	 * @param WP_User              $user    The user.
	 * @param array<string, mixed> $summary The run summary, by reference.
	 * @return array<string, mixed>|null
	 */
	private static function bulk_item( WP_User $user, array &$summary ): ?array {
		$number = trim( (string) get_user_meta( $user->ID, 'imk_membership_number', true ) );
		if ( '' === $number ) {
			++$summary['skipped_no_number'];
			return null;
		}

		$external_id = Agend_Loop_Sync_Contact_Resolver::to_external_id( $number );
		if ( null === $external_id || ! is_email( $user->user_email ) ) {
			++$summary['skipped_invalid'];
			return null;
		}

		return array(
			'external_id'  => $external_id,
			'email'        => (string) $user->user_email,
			'display_name' => (string) $user->display_name,
			'roles'        => array_values( (array) $user->roles ),
		);
	}

	/**
	 * A summary with every count at zero.
	 *
	 * @param bool $provision Whether the run provisions.
	 * @return array<string, mixed>
	 */
	private static function empty_summary( bool $provision ): array {
		$summary = array(
			'total'             => 0,
			'sent'              => 0,
			'succeeded'         => 0,
			'failed'            => 0,
			'skipped_no_number' => 0,
			'skipped_invalid'   => 0,
			'batches'           => 0,
			'dry_run'           => Agend_Loop_Sync_Settings::is_dry_run(),
			'provision'         => $provision,
			'errors'            => array(),
			'attempted_ids'     => array(),
			'unsendable_ids'    => array(),
		);

		if ( $provision ) {
			$summary['provisioning'] = array(
				'provisioned'    => 0,
				'linked'         => 0,
				'already_linked' => 0,
				'skipped'        => 0,
				'reasons'        => array(),
				'queued'         => 0,
				'deferred'       => 0,
				'refused'        => false,
			);
		}

		return $summary;
	}

	/**
	 * The summary without the per-user id list, for the log.
	 *
	 * @param array<string, mixed> $summary The run summary.
	 * @return array<string, mixed>
	 */
	public static function loggable_summary( array $summary ): array {
		unset( $summary['attempted_ids'], $summary['unsendable_ids'] );
		return $summary;
	}
}

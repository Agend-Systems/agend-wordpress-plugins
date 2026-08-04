<?php
/**
 * Entitlement sync.
 *
 * SPEC-AMS-20260804-upbeat-entitlement-mirror US-2.2/US-2.3/US-2.4. Listens on
 * the kiosk's existing webhook actions and the WordPress login hook, and
 * pushes the affected member's FULL current entitlement state to Agend
 * (Decision 2.3 -- never a delta). Also owns the entitlement-type catalogue
 * sync (US-2.4) that keeps `POST /v1/crm/entitlements/catalogue` current, so a
 * brand-new entitlement type gets its segment before (or with) the first
 * contact carrying it (US-2.2 AC5).
 *
 * The kiosk plugin itself is never modified (Decision 2.5) -- this class only
 * subscribes to hooks the kiosk already fires.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Entitlement_Sync' ) ) :

	/**
	 * Listens on the Upbeat webhook actions and WordPress login, and syncs a
	 * member's full current entitlement state to Agend.
	 */
	class Agend_Entitlement_Sync {

		/**
		 * Per-member coalesce lock TTL in seconds (US-2.2 AC4). A webhook storm
		 * for one member within this window skips repeat syncs; at-least-once
		 * delivery with full-state writes makes duplicates safe, so this guard
		 * is about load, not correctness.
		 *
		 * @var int
		 */
		const COALESCE_LOCK_TTL = 30;

		/**
		 * Delay, in seconds, before the single WP-Cron retry after a gateway
		 * write failure (US-2.2 AC3).
		 *
		 * @var int
		 */
		const RETRY_DELAY = 300; // 5 minutes.

		/**
		 * WP-Cron hook name for the single retry.
		 *
		 * @var string
		 */
		const RETRY_HOOK = 'agend_entitlement_mirror_retry_sync_member';

		/**
		 * Option holding the cached catalogue slugs known to this WordPress
		 * install, refreshed on every successful catalogue sync (US-2.4 AC3).
		 *
		 * @var string
		 */
		const KNOWN_CATALOGUE_OPTION = 'agend_entitlement_mirror_known_catalogue';

		/**
		 * Option holding the outcome of the last catalogue sync, for the admin
		 * status panel (US-2.4 AC1).
		 *
		 * @var string
		 */
		const LAST_CATALOGUE_SYNC_OPTION = 'agend_entitlement_mirror_last_catalogue_sync';

		/**
		 * Option holding the outcome of the last per-member sync, for the admin
		 * status panel.
		 *
		 * @var string
		 */
		const LAST_SYNC_OPTION = 'agend_entitlement_mirror_last_sync';

		/**
		 * Option holding the last write failure, for the admin status panel
		 * (US-2.2 AC3: visible in the WordPress admin).
		 *
		 * @var string
		 */
		const LAST_ERROR_OPTION = 'agend_entitlement_mirror_last_error';

		/**
		 * Registers the webhook listeners, the login hook, and the retry hook.
		 *
		 * Guarded behind the enable toggle AND kiosk availability, degrading
		 * silently (no notices spam) when either is absent -- the module is only
		 * meaningful on a site running the kiosk, and only when an operator has
		 * opted in.
		 */
		public static function register(): void {
			if ( ! Agend_Apps_Settings::is_entitlement_mirror_enabled() ) {
				return;
			}

			if ( ! class_exists( 'Iugo_Membership_Kiosk_API' ) ) {
				return;
			}

			add_action( 'agend_webhook_entitlement_created', array( __CLASS__, 'handle_entitlement_webhook' ), 10, 2 );
			add_action( 'agend_webhook_entitlement_updated', array( __CLASS__, 'handle_entitlement_webhook' ), 10, 2 );
			add_action( 'agend_webhook_contact_updated', array( __CLASS__, 'handle_contact_webhook' ), 10, 2 );
			add_action( 'wp_login', array( __CLASS__, 'handle_login' ), 10, 2 );
			add_action( self::RETRY_HOOK, array( __CLASS__, 'handle_retry' ), 10, 1 );
		}

		/**
		 * Handles the kiosk's `agend_webhook_entitlement_created` /
		 * `agend_webhook_entitlement_updated` actions.
		 *
		 * The webhook params carry EITHER `member_id` OR `account_id` (see
		 * `iugo-membership-kiosk/includes/class-webhooks.php`). There is no
		 * kiosk API to enumerate the members under an account, so an
		 * account-scoped event (no `member_id`) is logged and left to the
		 * nightly sweep (US-2.5) -- a documented scope decision, not a silent
		 * drop: Decision 2.3's full-state, self-healing sync means the sweep
		 * will reconcile it.
		 *
		 * @param array  $params      Sanitised webhook parameters (guid, id, member_id, account_id).
		 * @param string $request_uri The request URI.
		 */
		public static function handle_entitlement_webhook( array $params, string $request_uri ): void {
			unset( $request_uri );

			$member_id = '' !== ( $params['member_id'] ?? '' ) ? (string) $params['member_id'] : (string) ( $params['id'] ?? '' );

			if ( '' === $member_id ) {
				$account_id = (string) ( $params['account_id'] ?? '' );

				if ( '' !== $account_id ) {
					self::log(
						'Entitlement webhook fired at account scope with no member_id; no kiosk API enumerates account members, deferring to the nightly sweep.',
						array( 'account_id' => $account_id )
					);
				}

				return;
			}

			self::sync_member( $member_id );
		}

		/**
		 * Handles the kiosk's `agend_webhook_contact_updated` action. The
		 * identifying param for this action is `id` (see the URL examples in
		 * `class-webhooks.php`).
		 *
		 * @param array  $params      Sanitised webhook parameters (guid, id, member_id, account_id).
		 * @param string $request_uri The request URI.
		 */
		public static function handle_contact_webhook( array $params, string $request_uri ): void {
			unset( $request_uri );

			$member_id = (string) ( $params['id'] ?? '' );

			if ( '' === $member_id ) {
				return;
			}

			self::sync_member( $member_id );
		}

		/**
		 * Handles WordPress login (US-2.3): a safety-net reconciliation over the
		 * webhooks, throttled per member so an SSO burst does not restorm the
		 * gateway (AC1, AC3). Never blocks or breaks login/SSO on failure (AC2).
		 *
		 * @param string  $user_login Unused; required by the `wp_login` hook signature.
		 * @param WP_User $user       The user who just logged in.
		 */
		public static function handle_login( string $user_login, WP_User $user ): void {
			unset( $user_login );

			$member_id = (string) get_user_meta( $user->ID, agend_apps_external_id_meta_key(), true );

			if ( '' === $member_id ) {
				return;
			}

			$throttle_key = 'agend_ent_mirror_login_' . md5( $member_id );

			if ( false !== get_transient( $throttle_key ) ) {
				return;
			}

			set_transient( $throttle_key, 1, Agend_Apps_Settings::get_entitlement_mirror_login_throttle() );

			// Non-blocking (AC2): any Throwable is caught and logged here so a
			// mirror failure can never delay or break authentication or the SSO
			// redirect flow.
			try {
				self::sync_member( $member_id );
			} catch ( Throwable $e ) {
				self::log( 'Login reconciliation failed: ' . $e->getMessage(), array( 'member_id' => $member_id ) );
			}
		}

		/**
		 * WP-Cron callback for the single retry after a gateway write failure.
		 *
		 * @param string $member_id Kiosk membership number.
		 */
		public static function handle_retry( string $member_id ): void {
			self::sync_member( $member_id );
		}

		/**
		 * Runs a full-state sync for one member: collect -> maybe sync the
		 * catalogue for new slugs -> resolve/create the contact -> write the
		 * full value list (Decision 2.3).
		 *
		 * @param string $member_id Kiosk membership number.
		 * @return bool True when the sync completed (including a coalesced skip), false on failure.
		 */
		public static function sync_member( string $member_id ): bool {
			$lock_key = 'agend_ent_mirror_lock_' . md5( $member_id );

			if ( false !== get_transient( $lock_key ) ) {
				self::log( 'Sync coalesced: already run for this member within the lock window.', array( 'member_id' => $member_id ) );
				return true;
			}

			set_transient( $lock_key, 1, self::COALESCE_LOCK_TTL );

			try {
				$entries = Agend_Entitlement_Collector::collect( $member_id );
			} catch ( Throwable $e ) {
				self::log( 'Collector failed: ' . $e->getMessage(), array( 'member_id' => $member_id ) );
				return false;
			}

			self::maybe_sync_catalogue_for_new_slugs( $entries );

			$slugs   = Agend_Entitlement_Collector::slugs_only( $entries );
			$profile = self::member_profile( $member_id );

			$resolution = self::with_suppression(
				function () use ( $member_id, $profile, $slugs ) {
					return Agend_Entitlement_Contact_Resolver::resolve_or_create( $member_id, $profile, $slugs );
				}
			);

			if ( is_wp_error( $resolution ) ) {
				self::handle_write_failure( $member_id, $resolution );
				return false;
			}

			// A newly created contact already carries the flag values from the
			// same create call (Decision 2.4); only an EXISTING contact needs
			// the separate full-state PATCH.
			if ( ! $resolution['created'] ) {
				$patch = self::with_suppression(
					function () use ( $resolution, $slugs ) {
						return agend_apps_crm_update_contact(
							$resolution['contact_id'],
							array(
								'custom_fields' => array(
									Agend_Apps_Settings::get_entitlement_mirror_field_key() => $slugs,
								),
							)
						);
					}
				);

				if ( is_wp_error( $patch ) ) {
					self::handle_write_failure( $member_id, $patch );
					return false;
				}
			}

			update_option(
				self::LAST_SYNC_OPTION,
				array(
					'member_id'  => $member_id,
					'contact_id' => $resolution['contact_id'],
					'created'    => $resolution['created'],
					'slugs'      => $slugs,
					'at'         => gmdate( 'c' ),
				),
				false
			);

			return true;
		}

		/**
		 * Runs `$callback` with the webhook-suppression header temporarily
		 * attached to `agend_apps_crm_create_contact()` /
		 * `agend_apps_crm_update_contact()` calls made inside it, when the
		 * setting is enabled (US-2.2 business rule: OFF by default, opt-in per
		 * install so other subscribers still receive `contact_updated` events
		 * unless this install explicitly wants to suppress them).
		 *
		 * The create/update helpers in `includes/api/crm.php` only accept
		 * `(id, payload)` -- there is no third "request args" parameter to pass
		 * headers through directly. Each helper does expose an `*_args` filter
		 * before it sends the request, so this method attaches a scoped filter
		 * for the duration of the call and always removes it afterwards, even
		 * if the callback throws.
		 *
		 * @param callable $callback Zero-arg callback making the create/update-contact call.
		 * @return mixed The callback's return value.
		 */
		private static function with_suppression( callable $callback ) {
			$suppress = Agend_Apps_Settings::is_entitlement_mirror_webhook_suppression_enabled();

			if ( $suppress ) {
				add_filter( 'agend_apps_crm_create_contact_args', array( __CLASS__, 'inject_suppression_header' ) );
				add_filter( 'agend_apps_crm_update_contact_args', array( __CLASS__, 'inject_suppression_header' ) );
			}

			try {
				return $callback();
			} finally {
				if ( $suppress ) {
					remove_filter( 'agend_apps_crm_create_contact_args', array( __CLASS__, 'inject_suppression_header' ) );
					remove_filter( 'agend_apps_crm_update_contact_args', array( __CLASS__, 'inject_suppression_header' ) );
				}
			}
		}

		/**
		 * Filter callback: merges the webhook-suppression header into a
		 * create/update-contact request args array.
		 *
		 * @param array $args Request args.
		 * @return array
		 */
		public static function inject_suppression_header( array $args ): array {
			$args['headers'] = array_merge(
				is_array( $args['headers'] ?? null ) ? $args['headers'] : array(),
				array( 'X-Agend-Suppress-Webhooks' => 'true' )
			);

			return $args;
		}

		/**
		 * Handles a gateway write failure: logs member id + HTTP status only
		 * (never the API key or payload PII beyond the membership number, per
		 * AC3), records it for the admin status panel, and schedules exactly one
		 * WP-Cron retry.
		 *
		 * @param string   $member_id Kiosk membership number.
		 * @param WP_Error $error     The failure.
		 */
		private static function handle_write_failure( string $member_id, WP_Error $error ): void {
			$data        = $error->get_error_data();
			$status_code = ( is_array( $data ) && isset( $data['status_code'] ) ) ? (int) $data['status_code'] : 0;

			self::log(
				sprintf( 'Gateway write failed (status %d).', $status_code ),
				array(
					'member_id'   => $member_id,
					'status_code' => $status_code,
				)
			);

			update_option(
				self::LAST_ERROR_OPTION,
				array(
					'member_id'   => $member_id,
					'status_code' => $status_code,
					'at'          => gmdate( 'c' ),
				),
				false
			);

			// ONE retry via WP-Cron (AC3). A second failure is left to the login
			// hook and the nightly sweep to reconcile.
			if ( ! wp_next_scheduled( self::RETRY_HOOK, array( $member_id ) ) ) {
				wp_schedule_single_event( time() + self::RETRY_DELAY, self::RETRY_HOOK, array( $member_id ) );
			}
		}

		/**
		 * Resolves a member's profile (email, first/last name) from the kiosk,
		 * for use only if a contact must be created.
		 *
		 * @param string $member_id Kiosk membership number.
		 * @return array{email: string, first_name: string, last_name: string}
		 */
		public static function member_profile( string $member_id ): array {
			$profile = array(
				'email'      => '',
				'first_name' => '',
				'last_name'  => '',
			);

			if ( ! class_exists( 'Iugo_Membership_Kiosk_API' ) ) {
				return $profile;
			}

			$member = Iugo_Membership_Kiosk_API::instance()->get_member_details_by_id( $member_id );

			if ( ! ( $member instanceof Iugo_Membership_Kiosk_API_MemberDetail ) ) {
				return $profile;
			}

			$profile['email']      = (string) $member->get_email();
			$profile['first_name'] = (string) $member->get_first_name();
			$profile['last_name']  = (string) $member->get_last_name();

			return $profile;
		}

		/**
		 * Triggers a catalogue sync when the collector observes a slug not yet
		 * in the cached known-catalogue option (US-2.2 AC5).
		 *
		 * @param array<int, array{slug: string, label: string}> $entries Collector output for one member.
		 */
		private static function maybe_sync_catalogue_for_new_slugs( array $entries ): void {
			$known = (array) get_option( self::KNOWN_CATALOGUE_OPTION, array() );

			foreach ( $entries as $entry ) {
				if ( ! in_array( $entry['slug'], $known, true ) ) {
					// Sync the discovered catalogue MERGED with this member's
					// own observed entries. A live grant can reference a type
					// the kiosk's get_entitlement_types() does not list (found
					// against the PCA sandbox, 2026-08-04: Electronic Downloads
					// grants whose types are absent from the types endpoint) --
					// syncing discovery alone would never create those
					// segments AND would re-fire this check on every sync
					// because the slugs stay unknown. The endpoint is
					// idempotent (US-1.2 AC5), so the merge costs nothing.
					$discovered = self::discover_catalogue_entries();
					$by_slug    = array();
					foreach ( array_merge( $discovered, $entries ) as $candidate ) {
						$by_slug[ $candidate['slug'] ] = $candidate;
					}
					self::sync_catalogue( array_values( $by_slug ) );
					return;
				}
			}
		}

		/**
		 * Discovers the current mirrorable entitlement-type catalogue from the
		 * kiosk: `get_entitlement_types()` filtered to the configured category
		 * allow-list, shaped and deduplicated with the same rules as the
		 * per-member collector (US-2.4 AC1).
		 *
		 * @return array<int, array{slug: string, label: string}>
		 */
		public static function discover_catalogue_entries(): array {
			if ( ! class_exists( 'Iugo_Membership_Kiosk_API' ) ) {
				return array();
			}

			$types = Iugo_Membership_Kiosk_API::instance()->get_entitlement_types();

			if ( ! is_array( $types ) ) {
				return array();
			}

			$allowed_categories = Agend_Apps_Settings::get_entitlement_mirror_categories();
			$rows                = array();

			foreach ( $types as $type ) {
				if ( ! $type instanceof Iugo_Membership_Kiosk_API_Entitlement_Type ) {
					continue;
				}

				$category = (string) $type->get_category();

				if ( ! Agend_Entitlement_Collector::category_allowed( $category, $allowed_categories ) ) {
					continue;
				}

				$type_name = (string) $type->get_type();

				$category_slug = Agend_Entitlement_Collector::slugify( $category );
				$type_slug     = Agend_Entitlement_Collector::slugify( $type_name );

				if ( '' === $category_slug || '' === $type_slug ) {
					continue;
				}

				// The type catalogue carries no display name (unlike a
				// per-member entitlement grant) -- fall back to the raw type
				// name, matching the collector's own fallback (US-2.1 AC3).
				$rows[] = array(
					'slug'  => $category_slug . '/' . $type_slug,
					'label' => $type_name,
				);
			}

			return Agend_Entitlement_Collector::deduplicate_and_sort( $rows );
		}

		/**
		 * Pushes the entitlement-type catalogue to the gateway (US-2.4 AC2) and
		 * refreshes the cached known-slugs option on success (AC3).
		 *
		 * @param array<int, array{slug: string, label: string}>|null $entries Optional. Defaults to `discover_catalogue_entries()`.
		 * @return array|WP_Error Decoded gateway response, or WP_Error on failure.
		 */
		public static function sync_catalogue( ?array $entries = null ) {
			if ( null === $entries ) {
				$entries = self::discover_catalogue_entries();
			}

			if ( empty( $entries ) ) {
				return new WP_Error(
					'agend_entitlement_mirror_no_entries',
					__( 'No mirrorable entitlement types were found to sync.', 'agend-apps-core' )
				);
			}

			// The gateway caps a catalogue request at 200 entries. A large
			// category (the PCA sandbox's Electronic Downloads is a 335-type
			// document library) must chunk, or the whole sync 400s and no
			// segment is ever created (found live, 2026-08-04). Each chunk is
			// independently idempotent, so partial failure leaves earlier
			// chunks correct and the next sync retries the remainder.
			if ( count( $entries ) > 200 ) {
				$last = null;
				foreach ( array_chunk( $entries, 200 ) as $chunk ) {
					$last = self::sync_catalogue( $chunk );
					if ( is_wp_error( $last ) ) {
						return $last;
					}
				}
				// The per-chunk recursion has already cached each chunk's
				// slugs; re-cache the FULL list so the known-catalogue check
				// sees every slug.
				update_option( self::KNOWN_CATALOGUE_OPTION, wp_list_pluck( $entries, 'slug' ), false );
				return $last;
			}

			$payload = array(
				'field_key' => Agend_Apps_Settings::get_entitlement_mirror_field_key(),
				'entries'   => array_map(
					function ( array $entry ) {
						return array(
							'slug'  => $entry['slug'],
							'label' => $entry['label'],
						);
					},
					$entries
				),
			);

			$response = agend_apps_crm_sync_entitlement_catalogue( $payload );

			if ( is_wp_error( $response ) ) {
				update_option(
					self::LAST_CATALOGUE_SYNC_OPTION,
					array(
						'at'      => gmdate( 'c' ),
						'success' => false,
						'error'   => $response->get_error_message(),
					),
					false
				);

				self::log( 'Catalogue sync failed: ' . $response->get_error_message() );

				return $response;
			}

			update_option( self::KNOWN_CATALOGUE_OPTION, wp_list_pluck( $entries, 'slug' ), false );

			$results = isset( $response['data']['entries'] ) && is_array( $response['data']['entries'] )
				? $response['data']['entries']
				: array();

			update_option(
				self::LAST_CATALOGUE_SYNC_OPTION,
				array(
					'at'      => gmdate( 'c' ),
					'success' => true,
					'results' => $results,
				),
				false
			);

			return $response;
		}

		/**
		 * Logs a mirror event. Never logs the API key or payload PII beyond the
		 * membership number (US-2.2 AC3).
		 *
		 * @param string $message Log message.
		 * @param array  $context Structured context (member_id, status_code, etc.).
		 */
		private static function log( string $message, array $context = array() ): void {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[Agend Entitlement Mirror] ' . $message . ' ' . wp_json_encode( $context ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}

			/**
			 * Fires on every entitlement mirror log event, for sites that want to
			 * route it to their own logging/monitoring.
			 *
			 * @param string $message Log message.
			 * @param array  $context Structured context.
			 */
			do_action( 'agend_entitlement_mirror_log', $message, $context );
		}
	}

endif;

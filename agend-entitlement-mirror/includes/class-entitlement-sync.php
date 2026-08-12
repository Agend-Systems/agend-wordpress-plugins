<?php
/**
 * Entitlement sync.
 *
 * SPEC-AMS-20260804-upbeat-entitlement-mirror US-2.2/US-2.3/US-2.4 /
 * SPEC-CRM-20260805-member-entitlement-grants US-5.1. Listens on the kiosk's
 * existing webhook actions and the WordPress login hook, and pushes the
 * affected member's FULL current entitlement state to Agend (Decision 2.3 --
 * never a delta) via the platform's entitlement-types + entitlement-grants
 * endpoints. Also owns the entitlement-type declaration sync (US-2.4/US-5.1)
 * that keeps `POST /v1/crm/entitlements/types` current, so a brand-new
 * entitlement type gets its `gate_key` before (or with) the first grant
 * carrying it (US-2.2 AC5).
 *
 * The kiosk plugin itself is never modified (Decision 2.5) -- this class only
 * subscribes to hooks the kiosk already fires.
 *
 * @package Agend_Entitlement_Mirror
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
		 * Option holding the cached `gate_key`s known to this WordPress install,
		 * refreshed on every successful types sync (US-2.4 AC3 / US-5.1).
		 *
		 * @var string
		 */
		const KNOWN_TYPES_OPTION = 'agend_entitlement_mirror_known_types';

		/**
		 * Option holding the outcome of the last entitlement-types sync, for the
		 * admin status panel (US-2.4 AC1 / US-5.1).
		 *
		 * @var string
		 */
		const LAST_TYPES_SYNC_OPTION = 'agend_entitlement_mirror_last_types_sync';

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
			if ( ! Agend_Entitlement_Mirror_Settings::is_entitlement_mirror_enabled() ) {
				return;
			}

			if ( ! class_exists( 'Iugo_Membership_Kiosk_API' ) ) {
				return;
			}

			add_action( 'agend_webhook_entitlement_created', array( __CLASS__, 'handle_entitlement_webhook' ), 10, 2 );
			add_action( 'agend_webhook_entitlement_updated', array( __CLASS__, 'handle_entitlement_webhook' ), 10, 2 );
			add_action( 'agend_webhook_contact_updated', array( __CLASS__, 'handle_contact_webhook' ), 10, 2 );
			add_action( 'wp_login', array( __CLASS__, 'handle_login' ), 10, 2 );
			add_filter( 'wp_saml_idp_user_attributes_lightsaml', array( __CLASS__, 'handle_sso_attributes' ), 10, 3 );
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

			set_transient( $throttle_key, 1, Agend_Entitlement_Mirror_Settings::get_entitlement_mirror_login_throttle() );

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
		 * Handles agend-saml-idp's `wp_saml_idp_user_attributes_lightsaml` filter
		 * (the sole path every SAML response is built through), fired
		 * immediately before the assertion is signed and sent.
		 *
		 * A member already logged into WordPress who follows an Agend SSO link
		 * gets JIT-provisioned in Agend with no prior mirror run: `wp_login`
		 * never fires for that session, so the login safety-net reconcile never
		 * runs either, and the member's grants only appear after a subsequent
		 * WordPress logout/login. Running the reconcile here -- synchronously,
		 * before the assertion leaves -- means a freshly-provisioned contact's
		 * grants exist before the member's first Agend page load.
		 *
		 * Non-blocking, mirroring {@see handle_login()}: any Throwable is caught
		 * and logged so a mirror failure can never delay or break the SSO
		 * response. Deliberately skips the `wp_login` throttle transient -- SSO
		 * is exactly the moment freshness matters most, and {@see sync_member()}'s
		 * own coalesce lock already de-dupes a login immediately followed by SSO.
		 *
		 * Always returns `$attributes` unchanged: this filter is used purely for
		 * its side effect.
		 *
		 * @param array   $attributes   The attributes agend-saml-idp is about to sign and send.
		 * @param WP_User $user         The user the assertion is being issued for.
		 * @param string  $sp_entity_id Unused; required by the filter's signature.
		 * @return array The unchanged `$attributes`.
		 */
		public static function handle_sso_attributes( array $attributes, WP_User $user, string $sp_entity_id ): array {
			unset( $sp_entity_id );

			$member_id = (string) get_user_meta( $user->ID, agend_apps_external_id_meta_key(), true );

			if ( '' === $member_id ) {
				return $attributes;
			}

			// Non-blocking (mirrors handle_login()'s AC2 contract): any Throwable
			// is caught and logged here so a mirror failure can never delay or
			// break the SAML response.
			try {
				self::sync_member( $member_id );
			} catch ( Throwable $e ) {
				self::log( 'SSO-time reconciliation failed: ' . $e->getMessage(), array( 'member_id' => $member_id ) );
			}

			return $attributes;
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
		 * Runs a full-state sync for one member: collect -> reconcile -> record
		 * the outcome for the admin status panel.
		 *
		 * @param string $member_id Kiosk membership number.
		 * @return bool True when the sync completed (including a coalesced skip or a deliberate no-op skip), false on failure.
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

			$result = self::reconcile_member( $member_id, $entries, self::member_profile( $member_id ) );

			if ( is_wp_error( $result ) ) {
				self::handle_write_failure( $member_id, $result );
				return false;
			}

			if ( true === $result ) {
				return true;
			}

			$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();

			update_option(
				self::LAST_SYNC_OPTION,
				array(
					'member_id'       => $member_id,
					'contact_id'      => (string) ( $data['contact_id'] ?? '' ),
					'contact_created' => ! empty( $data['contact_created'] ),
					'granted'         => count( (array) ( $data['granted'] ?? array() ) ),
					'refreshed'       => count( (array) ( $data['refreshed'] ?? array() ) ),
					'unchanged'       => count( (array) ( $data['unchanged'] ?? array() ) ),
					'revoked'         => count( (array) ( $data['revoked'] ?? array() ) ),
					'at'              => gmdate( 'c' ),
				),
				false
			);

			return true;
		}

		/**
		 * Reconciles one member's entitlement grants: declares any newly
		 * observed `gate_key`s, skips the reconcile when there is genuinely
		 * nothing to do, and otherwise calls the grants endpoint with the
		 * create-on-miss identity fields the gateway schema actually accepts.
		 *
		 * Shared by {@see sync_member()} (webhook/login/retry) and the CLI
		 * sweep's real-run path, so a payload-shape fix (empty-profile-field
		 * omission, the empty-entries skip, the types pre-declaration) lands
		 * once for every caller rather than being re-derived per caller.
		 *
		 * @param string                                                                                                                                        $member_id Kiosk membership number.
		 * @param array<int, array{gate_key: string, name: string, starts_at: string|null, expires_at: string|null, quantity_allowed: int|null, quantity_remaining: int|null}> $entries   Collector output for this member.
		 * @param array{email: string, first_name: string, last_name: string}                                                                                  $profile   Create-on-miss identity fields; empty strings are omitted from the payload.
		 * @return array|true|WP_Error Decoded gateway response on a real call, `true` for a deliberate skip (nothing to grant and no contact exists), or WP_Error on failure.
		 */
		public static function reconcile_member( string $member_id, array $entries, array $profile ) {
			self::maybe_sync_types_for_new_keys( $entries );

			$external_source = Agend_Entitlement_Mirror_Settings::get_entitlement_mirror_external_source();

			// A member with nothing to grant AND no Agend contact needs no
			// reconcile: there is nothing to revoke, and the endpoint's
			// create-on-miss would otherwise materialise a placeholder contact
			// for every unentitled member the nightly sweep touches. A lookup
			// failure aborts rather than proceeds, so a transport blip can
			// never fall through to a contact-creating call.
			if ( empty( $entries ) ) {
				$existing = self::find_contact_id( $external_source, $member_id );

				if ( is_wp_error( $existing ) ) {
					return $existing;
				}

				if ( null === $existing ) {
					self::log( 'Skipping reconcile: member holds nothing and no contact exists.', array( 'member_id' => $member_id ) );
					return true;
				}
			}

			$payload = array(
				'external_source' => $external_source,
				'external_id'     => $member_id,
				'source_key'      => Agend_Entitlement_Mirror_Settings::get_entitlement_mirror_source_key(),
				// An empty array here is the positively-established "this source
				// now grants this member nothing" state (AC12): the caller's
				// collector failing is what must (and does) abort before
				// reaching this call, never an empty result reaching it by
				// mistake.
				'entries'         => self::to_grant_entries( $entries ),
			);

			// Create-on-miss identity, used server-side only when no contact
			// matches the external pair. The gateway rejects empty strings
			// (first_name/last_name min length 1, email must parse), so absent
			// profile fields are omitted rather than sent empty.
			foreach ( $profile as $field => $value ) {
				if ( '' !== $value ) {
					$payload[ $field ] = $value;
				}
			}

			return agend_apps_crm_reconcile_entitlement_grants( $payload );
		}

		/**
		 * Maps collector entries onto the grants-endpoint entry shape, omitting
		 * any key whose value is null rather than sending an explicit null.
		 *
		 * @param array<int, array{gate_key: string, starts_at: string|null, expires_at: string|null, quantity_allowed: int|null, quantity_remaining: int|null}> $entries Collector output.
		 * @return array<int, array{gate_key: string}>
		 */
		public static function to_grant_entries( array $entries ): array {
			return array_map(
				function ( array $entry ): array {
					$row = array( 'gate_key' => $entry['gate_key'] );

					foreach ( array( 'starts_at', 'expires_at', 'quantity_allowed', 'quantity_remaining' ) as $key ) {
						if ( null !== ( $entry[ $key ] ?? null ) ) {
							$row[ $key ] = $entry[ $key ];
						}
					}

					return $row;
				},
				$entries
			);
		}

		/**
		 * Looks up a contact by `(external_source, external_id)`, for the
		 * empty-entries skip: reconciling nothing against a member with no
		 * contact would only exercise the endpoint's create-on-miss.
		 *
		 * @param string $external_source Configured external identity source.
		 * @param string $member_id       Kiosk membership number.
		 * @return string|null|WP_Error Contact id, null when not found, or WP_Error on transport failure.
		 */
		private static function find_contact_id( string $external_source, string $member_id ) {
			$response = agend_apps_crm_get_contacts(
				array(
					'externalSource' => $external_source,
					'externalId'     => $member_id,
					'limit'          => 1,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$rows = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();

			return isset( $rows[0]['id'] ) ? (string) $rows[0]['id'] : null;
		}

		/**
		 * Handles a gateway write failure: logs member id + HTTP status only
		 * (never the API key or payload PII beyond the membership number, per
		 * AC3), records it for the admin status panel, and schedules a WP-Cron
		 * retry -- except for a 401/403, which is a configuration error (bad or
		 * under-scoped API key), not a transient one, and retrying it on the
		 * same broken credential would just repeat the failure every 5 minutes
		 * until an operator intervenes anyway (SPEC-CRM-20260805-member-entitlement-grants
		 * US-5.1 AC7).
		 *
		 * @param string   $member_id Kiosk membership number.
		 * @param WP_Error $error     The failure.
		 */
		private static function handle_write_failure( string $member_id, WP_Error $error ): void {
			$data        = $error->get_error_data();
			$status_code = ( is_array( $data ) && isset( $data['status_code'] ) ) ? (int) $data['status_code'] : 0;
			$is_config   = in_array( $status_code, array( 401, 403 ), true );
			$kind        = $is_config ? 'configuration' : 'transient';

			if ( $is_config ) {
				self::log(
					sprintf( 'Gateway write failed (status %d): configuration error, not retrying.', $status_code ),
					array(
						'member_id'   => $member_id,
						'status_code' => $status_code,
					)
				);
			} else {
				self::log(
					sprintf( 'Gateway write failed (status %d).', $status_code ),
					array(
						'member_id'   => $member_id,
						'status_code' => $status_code,
					)
				);
			}

			update_option(
				self::LAST_ERROR_OPTION,
				array(
					'member_id'   => $member_id,
					'status_code' => $status_code,
					'kind'        => $kind,
					'message'     => $is_config
						? __( 'Check that the Agend API key configured for this site is valid and has been granted the crm.entitlements.sync scope. This scope is not implied by any other CRM scope and must be granted explicitly.', 'agend-entitlement-mirror' )
						: '',
					'at'          => gmdate( 'c' ),
				),
				false
			);

			if ( $is_config ) {
				return;
			}

			// ONE retry via WP-Cron (AC3). A second failure is left to the login
			// hook and the nightly sweep to reconcile.
			if ( ! wp_next_scheduled( self::RETRY_HOOK, array( $member_id ) ) ) {
				wp_schedule_single_event( time() + self::RETRY_DELAY, self::RETRY_HOOK, array( $member_id ) );
			}
		}

		/**
		 * Resolves a member's profile (email, first/last name) from the kiosk,
		 * used only if a contact must be created by the grants endpoint's
		 * create-on-miss path.
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
		 * Triggers a types sync when the collector observes a `gate_key` not yet
		 * in the cached known-types option (US-2.2 AC5 / US-5.1).
		 *
		 * @param array<int, array{gate_key: string, name: string}> $entries Collector output for one member.
		 */
		private static function maybe_sync_types_for_new_keys( array $entries ): void {
			$known = (array) get_option( self::KNOWN_TYPES_OPTION, array() );

			foreach ( $entries as $entry ) {
				if ( ! in_array( $entry['gate_key'], $known, true ) ) {
					// Sync the discovered types MERGED with this member's own
					// observed entries. A live grant can reference a type the
					// kiosk's get_entitlement_types() does not list (found
					// against the PCA sandbox, 2026-08-04: Electronic Downloads
					// grants whose types are absent from the types endpoint) --
					// syncing discovery alone would never declare those types
					// AND would re-fire this check on every sync because the
					// keys stay unknown. The endpoint is idempotent (US-5.1),
					// so the merge costs nothing.
					$discovered = self::discover_type_entries();
					$by_key     = array();
					foreach ( array_merge( $discovered, self::to_type_entries( $entries ) ) as $candidate ) {
						$by_key[ $candidate['gate_key'] ] = $candidate;
					}
					self::sync_types( array_values( $by_key ) );
					return;
				}
			}
		}

		/**
		 * Maps collector entries onto the `{ gate_key, name }` shape the types
		 * endpoint takes.
		 *
		 * @param array<int, array{gate_key: string, name: string}> $entries Collector output.
		 * @return array<int, array{gate_key: string, name: string}>
		 */
		private static function to_type_entries( array $entries ): array {
			return array_map(
				function ( array $entry ): array {
					return array(
						'gate_key' => $entry['gate_key'],
						'name'     => $entry['name'],
					);
				},
				$entries
			);
		}

		/**
		 * Discovers the current mirrorable entitlement-type declarations from
		 * the kiosk: `get_entitlement_types()` filtered to the configured
		 * category allow-list, converted to `gate_key`s and deduplicated with the
		 * same rules as the per-member collector (US-2.4 AC1 / US-5.1).
		 *
		 * @return array<int, array{gate_key: string, name: string}>
		 */
		public static function discover_type_entries(): array {
			if ( ! class_exists( 'Iugo_Membership_Kiosk_API' ) ) {
				return array();
			}

			$types = Iugo_Membership_Kiosk_API::instance()->get_entitlement_types();

			if ( ! is_array( $types ) ) {
				return array();
			}

			$allowed_categories = Agend_Entitlement_Mirror_Settings::get_entitlement_mirror_categories();
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

				$gate_key = Agend_Entitlement_Collector::gate_key( $category, $type_name );

				if ( '' === $gate_key ) {
					continue;
				}

				$rows[] = array(
					'gate_key' => $gate_key,
					'name'     => $type_name,
				);
			}

			return Agend_Entitlement_Collector::deduplicate_and_sort( $rows );
		}

		/**
		 * Pushes the entitlement-type declarations to the gateway (US-2.4 AC2 /
		 * US-5.1) and refreshes the cached known-keys option on success (AC3).
		 *
		 * @param array<int, array{gate_key: string, name: string}>|null $entries Optional. Defaults to `discover_type_entries()`.
		 * @return array|WP_Error Decoded gateway response, or WP_Error on failure.
		 */
		public static function sync_types( ?array $entries = null ) {
			if ( null === $entries ) {
				$entries = self::discover_type_entries();
			}

			if ( empty( $entries ) ) {
				return new WP_Error(
					'agend_entitlement_mirror_no_entries',
					__( 'No mirrorable entitlement types were found to sync.', 'agend-entitlement-mirror' )
				);
			}

			// The gateway caps a types request at 200 entries. A large category
			// (the PCA sandbox's Electronic Downloads is a 335-type document
			// library) must chunk, or the whole sync 400s and no type is ever
			// declared (found live, 2026-08-04). Each chunk is independently
			// idempotent, so partial failure leaves earlier chunks correct and
			// the next sync retries the remainder.
			if ( count( $entries ) > 200 ) {
				$last = null;
				foreach ( array_chunk( $entries, 200 ) as $chunk ) {
					$last = self::sync_types( $chunk );
					if ( is_wp_error( $last ) ) {
						return $last;
					}
				}
				// The per-chunk recursion has already cached each chunk's keys;
				// re-cache the FULL list so the known-types check sees every key.
				update_option( self::KNOWN_TYPES_OPTION, wp_list_pluck( $entries, 'gate_key' ), false );
				return $last;
			}

			$payload = array(
				'source_key' => Agend_Entitlement_Mirror_Settings::get_entitlement_mirror_source_key(),
				'entries'    => array_map(
					function ( array $entry ) {
						return array(
							'gate_key' => $entry['gate_key'],
							'name'     => $entry['name'],
						);
					},
					$entries
				),
			);

			$response = agend_apps_crm_sync_entitlement_types( $payload );

			if ( is_wp_error( $response ) ) {
				update_option(
					self::LAST_TYPES_SYNC_OPTION,
					array(
						'at'      => gmdate( 'c' ),
						'success' => false,
						'error'   => $response->get_error_message(),
					),
					false
				);

				self::log( 'Types sync failed: ' . $response->get_error_message() );

				return $response;
			}

			update_option( self::KNOWN_TYPES_OPTION, wp_list_pluck( $entries, 'gate_key' ), false );

			$results = isset( $response['data']['types'] ) && is_array( $response['data']['types'] )
				? $response['data']['types']
				: array();

			update_option(
				self::LAST_TYPES_SYNC_OPTION,
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

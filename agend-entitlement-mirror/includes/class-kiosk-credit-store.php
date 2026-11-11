<?php
/**
 * Upbeat entitlement reads and writes for job credits, through the kiosk.
 *
 * Kept apart from the provider so the provider's rules can be tested without
 * the kiosk, and so every kiosk quirk lives in one place:
 *
 * - The kiosk's own entitlement reads turn a failed request into an empty
 *   list and cache it for up to 15 minutes, which would show a CRM outage as
 *   "no credits" and is too stale to decide whether a credit is unused. So
 *   reads go straight to the entitlements endpoint for the member and each
 *   account their entitlements are inherited from (the same ids the kiosk
 *   uses), uncached, and any failed request fails the whole read.
 * - After a write, the member's and their accounts' kiosk entitlement caches
 *   are cleared so the rest of the site sees the change.
 * - Only the record's unique id and `downloadCount` are sent on a write, the
 *   same partial update the kiosk's support ticket credits use.
 * - Neither the kiosk nor this store follows the endpoint's further pages, so
 *   an account with more records than one page holds shows only the first.
 *
 * @package Agend_Entitlement_Mirror
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'Agend_Entitlement_Mirror_Credit_Store' ) ) :

	/**
	 * Reads and writes a member's Upbeat entitlement records.
	 */
	interface Agend_Entitlement_Mirror_Credit_Store {

		/**
		 * Whether the CRM connection is present.
		 *
		 * @return bool
		 */
		public function is_available(): bool;

		/**
		 * The member's current entitlement records, read fresh from Upbeat.
		 * Includes records inherited from the member's account, as the kiosk
		 * does everywhere else.
		 *
		 * @param string $member_id Upbeat membership number.
		 * @return array<int, array{ref: string, category: string, type: string, used: bool, starts_at: ?DateTimeInterface, expires_at: ?DateTimeInterface}>|WP_Error
		 */
		public function member_entitlements( string $member_id );

		/**
		 * Marks one entitlement record as used or unused.
		 *
		 * @param string $member_id Upbeat membership number.
		 * @param string $ref       Entitlement unique id.
		 * @param bool   $used      True to mark used, false to mark unused.
		 * @return true|WP_Error
		 */
		public function set_used( string $member_id, string $ref, bool $used );
	}

endif;

if ( ! class_exists( 'Agend_Entitlement_Mirror_Kiosk_Credit_Store' ) ) :

	/**
	 * Credit store backed by the iugo-membership-kiosk API.
	 */
	final class Agend_Entitlement_Mirror_Kiosk_Credit_Store implements Agend_Entitlement_Mirror_Credit_Store {

		/**
		 * Kiosk API, or null to use Iugo_Membership_Kiosk_API::instance().
		 *
		 * @var object|null
		 */
		private $api;

		/**
		 * @param object|null $api Kiosk API; injectable for tests.
		 */
		public function __construct( $api = null ) {
			$this->api = $api;
		}

		/**
		 * {@inheritDoc}
		 */
		public function is_available(): bool {
			return null !== $this->api || ( class_exists( 'Iugo_Membership_Kiosk_API' ) && class_exists( 'Iugo_Membership_Kiosk_API_Entitlement' ) );
		}

		/**
		 * {@inheritDoc}
		 */
		public function member_entitlements( string $member_id ) {
			if ( ! $this->is_available() ) {
				return $this->unavailable();
			}

			try {
				$api    = $this->api();
				$member = $api->get_member_details_by_id( $member_id );

				if ( ! $this->is_member( $member ) ) {
					return $this->lookup_failed();
				}

				$rows = array();

				foreach ( $this->entitlement_ids( $member_id, $member ) as $id ) {
					$records = $api->get_objects( 'get', $api::get_entitlements_for_id_api_url( $id ), null, array(), 'Iugo_Membership_Kiosk_API_Entitlement' );

					if ( ! is_array( $records ) ) {
						return $this->lookup_failed();
					}

					foreach ( $records as $entitlement ) {
						if ( ! ( $entitlement instanceof Iugo_Membership_Kiosk_API_Entitlement ) || ! $entitlement->is_current() ) {
							continue;
						}

						$rows[] = array(
							'ref'        => (string) $entitlement->get_unique_id(),
							'category'   => (string) $entitlement->get_entitlement_category(),
							'type'       => (string) $entitlement->get_entitlement_type(),
							'used'       => (int) $entitlement->get_download_count() > 0,
							'starts_at'  => $entitlement->get_the_start_date(),
							'expires_at' => $entitlement->get_the_end_date(),
						);
					}
				}
			} catch ( Throwable $e ) {
				return $this->lookup_failed();
			}

			return $rows;
		}

		/**
		 * {@inheritDoc}
		 */
		public function set_used( string $member_id, string $ref, bool $used ) {
			if ( ! $this->is_available() ) {
				return $this->unavailable();
			}

			try {
				$api    = $this->api();
				$member = $api->get_member_details_by_id( $member_id );

				if ( ! $this->is_member( $member ) ) {
					return $this->lookup_failed();
				}

				$update = new Iugo_Membership_Kiosk_API_Entitlement();
				$update->set_unique_id( $ref );
				$update->set_download_count( $used ? 1 : 0 );

				$result = $api->update_entitlement( $update, $member );
				$this->forget( $api, $member_id, $member );
			} catch ( Throwable $e ) {
				return $this->write_failed();
			}

			if ( ! is_object( $result ) ) {
				return $this->write_failed();
			}

			return true;
		}

		/**
		 * The ids whose entitlements the member draws on: their own, and their
		 * account's and ultimate parent account's where the kiosk is set to
		 * inherit them, matching get_all_member_entitlements().
		 *
		 * @param string $member_id Upbeat membership number.
		 * @param object $member    Member details.
		 * @return string[]
		 */
		private function entitlement_ids( string $member_id, $member ): array {
			$ids = array( $member_id );

			if ( function_exists( 'agend_should_fetch_entitlements_from_account' ) && agend_should_fetch_entitlements_from_account() ) {
				$account = $member->get_account();

				if ( is_object( $account ) && method_exists( $account, 'get_membership_number' ) ) {
					$ids[] = (string) $account->get_membership_number();
				}
			}

			if ( function_exists( 'agend_should_fetch_entitlements_from_ultimate_parent_account' ) && agend_should_fetch_entitlements_from_ultimate_parent_account() ) {
				$ids[] = (string) $member->get_ultimate_parent_account_id();
			}

			return array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) );
		}

		/**
		 * Clears the member's entitlement caches, and those of the accounts
		 * their entitlements are inherited from.
		 *
		 * @param object $api       Kiosk API.
		 * @param string $member_id Upbeat membership number.
		 * @param object $member    Member details.
		 * @return void
		 */
		private function forget( $api, string $member_id, $member ): void {
			$api->erase_entitlements_cache_for_member_id( $member_id );

			foreach ( $this->entitlement_ids( $member_id, $member ) as $id ) {
				if ( $id !== $member_id ) {
					$api->erase_entitlements_cache_for_account_id( $id );
				}
			}
		}

		/**
		 * Whether a kiosk member lookup found the member.
		 *
		 * @param mixed $member Lookup result.
		 * @return bool
		 */
		private function is_member( $member ): bool {
			return is_object( $member )
				&& method_exists( $member, 'get_account' )
				&& ( ! method_exists( $member, 'exists' ) || $member->exists() );
		}

		/**
		 * The kiosk API.
		 *
		 * @return object
		 */
		private function api() {
			return $this->api ?? Iugo_Membership_Kiosk_API::instance();
		}

		/**
		 * The error returned when a read fails.
		 *
		 * @return WP_Error
		 */
		private function lookup_failed(): WP_Error {
			return new WP_Error( 'agend_jobs_credit_lookup_failed', __( 'Your job posting credits could not be checked. Please try again shortly.', 'agend-entitlement-mirror' ), array( 'status' => 503 ) );
		}

		/**
		 * The error returned when a write fails.
		 *
		 * @return WP_Error
		 */
		private function write_failed(): WP_Error {
			return new WP_Error( 'agend_jobs_credit_write_failed', __( 'Your job posting credit could not be updated. Please try again shortly.', 'agend-entitlement-mirror' ), array( 'status' => 503 ) );
		}

		/**
		 * The error returned when the kiosk is not active.
		 *
		 * @return WP_Error
		 */
		private function unavailable(): WP_Error {
			return new WP_Error( 'agend_jobs_credit_unavailable', __( 'Job posting credits are not available on this site.', 'agend-entitlement-mirror' ), array( 'status' => 503 ) );
		}
	}

endif;

<?php
/**
 * Upbeat entitlement reads and writes for job credits, through the kiosk.
 *
 * Kept apart from the provider so the provider's rules can be tested without
 * the kiosk, and so every kiosk quirk lives in one place:
 *
 * - Entitlement lists are cached for 15 minutes, which is far too stale to
 *   decide whether a credit is still unused, so every read clears the
 *   member's (and their account's) entitlement caches first.
 * - `update_entitlement()` stores the single updated record under the cache
 *   key of the member's whole entitlement list, so the caches are cleared
 *   again after every write.
 * - Only the record's unique id and `downloadCount` are sent on a write, the
 *   same partial update the kiosk's support ticket credits use.
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
		 * {@inheritDoc}
		 */
		public function is_available(): bool {
			return class_exists( 'Iugo_Membership_Kiosk_API' ) && class_exists( 'Iugo_Membership_Kiosk_API_Entitlement' );
		}

		/**
		 * {@inheritDoc}
		 */
		public function member_entitlements( string $member_id ) {
			if ( ! $this->is_available() ) {
				return $this->unavailable();
			}

			try {
				$api = Iugo_Membership_Kiosk_API::instance();
				$this->forget( $api, $member_id );

				$entitlements = $api->get_all_member_entitlements( $member_id );
			} catch ( Throwable $e ) {
				return new WP_Error( 'agend_jobs_credit_lookup_failed', __( 'Your job posting credits could not be checked. Please try again shortly.', 'agend-entitlement-mirror' ), array( 'status' => 503 ) );
			}

			if ( ! is_array( $entitlements ) ) {
				return new WP_Error( 'agend_jobs_credit_lookup_failed', __( 'Your job posting credits could not be checked. Please try again shortly.', 'agend-entitlement-mirror' ), array( 'status' => 503 ) );
			}

			$rows = array();

			foreach ( $entitlements as $entitlement ) {
				if ( ! ( $entitlement instanceof Iugo_Membership_Kiosk_API_Entitlement ) ) {
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
				$api    = Iugo_Membership_Kiosk_API::instance();
				$member = $api->get_member_details_by_id( $member_id );

				if ( ! ( $member instanceof Iugo_Membership_Kiosk_API_MemberDetail ) ) {
					return new WP_Error( 'agend_jobs_credit_no_member', __( 'Your membership record could not be found.', 'agend-entitlement-mirror' ), array( 'status' => 404 ) );
				}

				$update = new Iugo_Membership_Kiosk_API_Entitlement();
				$update->set_unique_id( $ref );
				$update->set_download_count( $used ? 1 : 0 );

				$result = $api->update_entitlement( $update, $member );
				$this->forget( $api, $member_id );
			} catch ( Throwable $e ) {
				return new WP_Error( 'agend_jobs_credit_write_failed', __( 'Your job posting credit could not be updated. Please try again shortly.', 'agend-entitlement-mirror' ), array( 'status' => 503 ) );
			}

			if ( false === $result || null === $result ) {
				return new WP_Error( 'agend_jobs_credit_write_failed', __( 'Your job posting credit could not be updated. Please try again shortly.', 'agend-entitlement-mirror' ), array( 'status' => 503 ) );
			}

			return true;
		}

		/**
		 * Clears the member's entitlement caches, and those of the accounts
		 * their entitlements are inherited from.
		 *
		 * @param Iugo_Membership_Kiosk_API $api       Kiosk API.
		 * @param string                    $member_id Upbeat membership number.
		 * @return void
		 */
		private function forget( $api, string $member_id ): void {
			$api->erase_entitlements_cache_for_member_id( $member_id );

			$member = $api->get_member_details_by_id( $member_id );

			if ( ! ( $member instanceof Iugo_Membership_Kiosk_API_MemberDetail ) ) {
				return;
			}

			$account_ids = array();
			$account     = $member->get_account();

			if ( is_object( $account ) && method_exists( $account, 'get_membership_number' ) ) {
				$account_ids[] = (string) $account->get_membership_number();
			}

			$account_ids[] = (string) $member->get_ultimate_parent_account_id();

			foreach ( array_unique( array_filter( $account_ids ) ) as $account_id ) {
				$api->erase_entitlements_cache_for_account_id( $account_id );
			}
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

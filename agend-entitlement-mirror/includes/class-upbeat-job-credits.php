<?php
/**
 * Upbeat entitlements as member job posting credits.
 *
 * One Upbeat entitlement record is one credit: a member who buys a pack of
 * five holds five records of the configured entitlement type. A record's
 * `downloadCount` marks it used (1) or unused (0), the same convention the
 * kiosk's support ticket credits use, so Upbeat stays the record of how many
 * credits a member has and when each expires.
 *
 * Registered as agend-apps-core's `agend_apps_jobs_credit_provider` when Job
 * credits are switched on in the Entitlement Mirror settings. Only loaded when
 * agend-apps-core's provider interface exists.
 *
 * @package Agend_Entitlement_Mirror
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Entitlement_Mirror_Upbeat_Job_Credits' ) && interface_exists( 'Agend_Apps_Jobs_Credit_Provider' ) ) :

	/**
	 * Job credit provider over a member's Upbeat entitlement records.
	 */
	final class Agend_Entitlement_Mirror_Upbeat_Job_Credits implements Agend_Apps_Jobs_Credit_Provider {

		/**
		 * Entitlement records.
		 *
		 * @var Agend_Entitlement_Mirror_Credit_Store
		 */
		private $store;

		/**
		 * Upbeat entitlement type of a credit.
		 *
		 * @var string
		 */
		private $type;

		/**
		 * Upbeat entitlement category of a credit, or '' for any.
		 *
		 * @var string
		 */
		private $category;

		/**
		 * @param Agend_Entitlement_Mirror_Credit_Store $store    Entitlement records.
		 * @param string                                $type     Upbeat entitlement type of a credit.
		 * @param string                                $category Upbeat entitlement category, or '' for any.
		 */
		public function __construct( Agend_Entitlement_Mirror_Credit_Store $store, string $type, string $category = '' ) {
			$this->store    = $store;
			$this->type     = trim( $type );
			$this->category = trim( $category );
		}

		/**
		 * {@inheritDoc}
		 */
		public function get_key(): string {
			return 'upbeat';
		}

		/**
		 * {@inheritDoc}
		 */
		public function is_available(): bool {
			return '' !== $this->type && $this->store->is_available();
		}

		/**
		 * {@inheritDoc}
		 */
		public function get_available_credits( int $user_id ) {
			$member_id = $this->member_id( $user_id );

			if ( '' === $member_id ) {
				return array();
			}

			$credits = $this->credits( $member_id );

			if ( is_wp_error( $credits ) ) {
				return $credits;
			}

			$available = array_values(
				array_filter(
					$credits,
					static function ( array $credit ): bool {
						return ! $credit['used'];
					}
				)
			);

			usort( $available, array( __CLASS__, 'compare' ) );

			return array_map(
				static function ( array $credit ): array {
					return array(
						'ref'        => $credit['ref'],
						'expires_at' => $credit['expires_at'] instanceof DateTimeInterface ? $credit['expires_at']->format( 'c' ) : null,
					);
				},
				$available
			);
		}

		/**
		 * {@inheritDoc}
		 */
		public function spend( int $user_id, string $ref ) {
			$member_id = $this->member_id( $user_id );
			$credit    = $this->find( $member_id, $ref );

			if ( is_wp_error( $credit ) ) {
				// Nothing was written: the re-check itself failed.
				return self::not_applied( $credit );
			}

			if ( null === $credit || $credit['used'] ) {
				return new WP_Error(
					'agend_jobs_credit_not_available',
					__( 'That job posting credit has already been used or has expired.', 'agend-entitlement-mirror' ),
					array(
						'status'  => 409,
						'applied' => false,
					)
				);
			}

			return $this->store->set_used( $member_id, $ref, true );
		}

		/**
		 * {@inheritDoc}
		 */
		public function restore( int $user_id, string $ref ) {
			$member_id = $this->member_id( $user_id );
			$credit    = $this->find( $member_id, $ref );

			if ( is_wp_error( $credit ) ) {
				return $credit;
			}

			if ( null === $credit ) {
				return new WP_Error(
					'agend_jobs_credit_expired',
					__( 'The job posting credit has expired, so it could not be given back.', 'agend-entitlement-mirror' ),
					array(
						'status' => 410,
						'gone'   => true,
					)
				);
			}

			if ( ! $credit['used'] ) {
				return true;
			}

			return $this->store->set_used( $member_id, $ref, false );
		}

		/**
		 * Copies an error, marking that nothing was changed.
		 *
		 * @param WP_Error $error Error.
		 * @return WP_Error
		 */
		private static function not_applied( WP_Error $error ): WP_Error {
			$data            = $error->get_error_data();
			$data            = is_array( $data ) ? $data : array();
			$data['applied'] = false;

			return new WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
		}

		/**
		 * Orders credits soonest to expire first (no expiry last), then oldest
		 * first, then by reference so the order is stable.
		 *
		 * @param array $a Credit.
		 * @param array $b Credit.
		 * @return int
		 */
		public static function compare( array $a, array $b ): int {
			$a_end = $a['expires_at'] instanceof DateTimeInterface ? $a['expires_at']->getTimestamp() : PHP_INT_MAX;
			$b_end = $b['expires_at'] instanceof DateTimeInterface ? $b['expires_at']->getTimestamp() : PHP_INT_MAX;

			if ( $a_end !== $b_end ) {
				return $a_end <=> $b_end;
			}

			$a_start = $a['starts_at'] instanceof DateTimeInterface ? $a['starts_at']->getTimestamp() : 0;
			$b_start = $b['starts_at'] instanceof DateTimeInterface ? $b['starts_at']->getTimestamp() : 0;

			if ( $a_start !== $b_start ) {
				return $a_start <=> $b_start;
			}

			return strcmp( $a['ref'], $b['ref'] );
		}

		/**
		 * The member's current records of the credit type, used or not.
		 *
		 * @param string $member_id Upbeat membership number.
		 * @return array<int, array>|WP_Error
		 */
		private function credits( string $member_id ) {
			$rows = $this->store->member_entitlements( $member_id );

			if ( is_wp_error( $rows ) ) {
				return $rows;
			}

			$credits = array();

			foreach ( (array) $rows as $row ) {
				if ( '' === (string) ( $row['ref'] ?? '' ) || ! $this->is_credit( $row ) ) {
					continue;
				}

				$credits[ (string) $row['ref'] ] = array(
					'ref'        => (string) $row['ref'],
					'used'       => ! empty( $row['used'] ),
					'starts_at'  => $row['starts_at'] ?? null,
					'expires_at' => $row['expires_at'] ?? null,
				);
			}

			return array_values( $credits );
		}

		/**
		 * One of the member's current credit records, or null.
		 *
		 * @param string $member_id Upbeat membership number.
		 * @param string $ref       Entitlement unique id.
		 * @return array|null|WP_Error
		 */
		private function find( string $member_id, string $ref ) {
			if ( '' === $member_id ) {
				return new WP_Error( 'agend_jobs_credit_no_member', __( 'Your membership record could not be found.', 'agend-entitlement-mirror' ), array( 'status' => 404 ) );
			}

			$credits = $this->credits( $member_id );

			if ( is_wp_error( $credits ) ) {
				return $credits;
			}

			foreach ( $credits as $credit ) {
				if ( $credit['ref'] === $ref ) {
					return $credit;
				}
			}

			return null;
		}

		/**
		 * Whether an entitlement record is a job credit.
		 *
		 * @param array $row Entitlement record.
		 * @return bool
		 */
		private function is_credit( array $row ): bool {
			if ( 0 !== strcasecmp( trim( (string) ( $row['type'] ?? '' ) ), $this->type ) ) {
				return false;
			}

			return '' === $this->category || 0 === strcasecmp( trim( (string) ( $row['category'] ?? '' ) ), $this->category );
		}

		/**
		 * The member's Upbeat membership number, from the user meta core's
		 * identity settings name.
		 *
		 * @param int $user_id WordPress user id.
		 * @return string
		 */
		private function member_id( int $user_id ): string {
			if ( $user_id <= 0 || ! function_exists( 'agend_apps_external_id_meta_key' ) ) {
				return '';
			}

			return trim( (string) get_user_meta( $user_id, agend_apps_external_id_meta_key(), true ) );
		}
	}

endif;

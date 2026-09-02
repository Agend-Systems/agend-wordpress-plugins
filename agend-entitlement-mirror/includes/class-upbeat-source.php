<?php
/**
 * Upbeat source: reads member entitlements, member profiles, the entitlement
 * type catalogue, and the member list from the kiosk plugin's API class.
 *
 * Delegates auth, base URI resolution, pagination, inheritance (member ->
 * account -> ultimate-parent) and validity resolution entirely to the kiosk
 * plugin's API class -- this class never reimplements any of that (mirrors
 * the collector's existing Decision 2.5 contract). It only converts the
 * kiosk's typed value objects
 * (Iugo_Membership_Kiosk_API_Entitlement / _MemberDetail / _Entitlement_Type)
 * into the plain-array shapes Agend_Entitlement_Mirror_Source declares, so
 * the rest of the plugin (collector, sync, CLI) is source-neutral.
 *
 * Implements Agend_Entitlement_Mirror_Source (key `upbeat`) so the collector
 * / sync / CLI resolve it via the registry rather than calling
 * Iugo_Membership_Kiosk_API directly.
 *
 * @package Agend_Entitlement_Mirror
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Entitlement_Mirror_Upbeat_Source' ) ) :
	final class Agend_Entitlement_Mirror_Upbeat_Source implements Agend_Entitlement_Mirror_Source {

		/**
		 * Stable registry key for this source.
		 */
		public const SOURCE_KEY = 'upbeat';

		/**
		 * Page size used when enumerating kiosk members (matches the previous
		 * hardcoded value in the CLI command).
		 */
		public const MEMBER_PAGE_SIZE = 100;

		public function get_key(): string {
			return self::SOURCE_KEY;
		}

		public function get_label(): string {
			return __( 'Upbeat (membership kiosk)', 'agend-entitlement-mirror' );
		}

		public function is_available(): bool {
			return '' === $this->get_unavailable_reason();
		}

		public function get_unavailable_reason(): string {
			if ( ! class_exists( 'Iugo_Membership_Kiosk_API' ) ) {
				return __( 'The iugo-membership-kiosk plugin is not available.', 'agend-entitlement-mirror' );
			}
			return '';
		}

		/**
		 * @param string $member_id Kiosk membership number.
		 *
		 * @return array<int, array{category: string, type: string, name: string, starts_at: ?DateTime, expires_at: ?DateTime, quantity_allowed: ?string, quantity_remaining: ?string}>
		 *
		 * @throws RuntimeException When the source is unavailable or the underlying API call fails.
		 */
		public function fetch_member_entitlements( string $member_id ): array {
			if ( ! $this->is_available() ) {
				throw new RuntimeException( $this->get_unavailable_reason() );
			}

			try {
				$entitlements = Iugo_Membership_Kiosk_API::instance()->get_all_member_entitlements( $member_id );
			} catch ( Throwable $e ) {
				// get_all_member_entitlements() has no try/catch around its own
				// entitlement-fetch loop: a genuine API failure there
				// (get_entitlements_for_id() returning false into array_merge())
				// surfaces as an uncaught Throwable. "Member not found" returns
				// array() cleanly and never reaches this catch -- that stays a
				// valid empty result, never an error path.
				throw new RuntimeException( 'Failed to retrieve entitlements from the membership kiosk: ' . $e->getMessage(), 0, $e );
			}

			if ( ! is_array( $entitlements ) ) {
				throw new RuntimeException( 'Unexpected response retrieving entitlements from the membership kiosk.' );
			}

			$rows = array();

			foreach ( $entitlements as $entitlement ) {
				if ( ! ( $entitlement instanceof Iugo_Membership_Kiosk_API_Entitlement ) ) {
					continue;
				}

				$rows[] = array(
					'category'           => (string) $entitlement->get_entitlement_category(),
					'type'               => (string) $entitlement->get_entitlement_type(),
					'name'               => (string) $entitlement->get_entitlement_display_name(),
					'starts_at'          => $entitlement->get_the_start_date(),
					'expires_at'         => $entitlement->get_the_end_date(),
					'quantity_allowed'   => $entitlement->get_quantity_allowed(),
					'quantity_remaining' => $entitlement->get_quantity_remaining(),
				);
			}

			return $rows;
		}

		/**
		 * @param string $member_id Kiosk membership number.
		 *
		 * @return array{email: string, first_name: string, last_name: string}
		 */
		public function fetch_member_profile( string $member_id ): array {
			$profile = array(
				'email'      => '',
				'first_name' => '',
				'last_name'  => '',
			);

			if ( ! $this->is_available() ) {
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
		 * @return array<int, array{category: string, type: string}>
		 *
		 * @throws RuntimeException When the source is unavailable.
		 */
		public function fetch_entitlement_types(): array {
			if ( ! $this->is_available() ) {
				throw new RuntimeException( $this->get_unavailable_reason() );
			}

			$types = Iugo_Membership_Kiosk_API::instance()->get_entitlement_types();

			if ( ! is_array( $types ) ) {
				return array();
			}

			$rows = array();

			foreach ( $types as $type ) {
				if ( ! ( $type instanceof Iugo_Membership_Kiosk_API_Entitlement_Type ) ) {
					continue;
				}

				$rows[] = array(
					'category' => (string) $type->get_category(),
					'type'     => (string) $type->get_type(),
				);
			}

			return $rows;
		}

		/**
		 * @param int $max Maximum members to yield (0 = unbounded).
		 *
		 * @return iterable<array{member_id: string, email: string, first_name: string, last_name: string}>
		 *
		 * @throws RuntimeException When the source is unavailable.
		 */
		public function enumerate_members( int $max ): iterable {
			if ( ! $this->is_available() ) {
				throw new RuntimeException( $this->get_unavailable_reason() );
			}

			$api     = Iugo_Membership_Kiosk_API::instance();
			$page    = 1;
			$yielded = 0;

			do {
				$members = $api->get_members( '', (string) self::MEMBER_PAGE_SIZE, (string) $page );

				if ( ! is_array( $members ) ) {
					return;
				}

				foreach ( $members as $member ) {
					if ( ! ( $member instanceof Iugo_Membership_Kiosk_API_MemberDetail ) ) {
						continue;
					}

					yield array(
						'member_id'  => (string) $member->get_membership_number(),
						'email'      => (string) $member->get_email(),
						'first_name' => (string) $member->get_first_name(),
						'last_name'  => (string) $member->get_last_name(),
					);
					++$yielded;

					if ( $max > 0 && $yielded >= $max ) {
						return;
					}
				}

				++$page;
			} while ( $api->has_more_pages( 'Iugo_Membership_Kiosk_API_MemberDetail' ) && ! empty( $members ) );
		}
	}
endif;

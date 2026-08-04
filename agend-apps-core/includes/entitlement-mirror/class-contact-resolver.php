<?php
/**
 * Contact resolver.
 *
 * SPEC-AMS-20260804-upbeat-entitlement-mirror US-2.2 AC2 / Decision 2.4.
 * Resolves the Agend contact for a mirrored member: externalId-first via the
 * gateway, falling back to an exact-email match, creating the contact (with
 * the flag values already set) when neither resolves. NEVER sends `user_id` --
 * the `crm_contacts` auto-link trigger (see
 * `.claude/rules/contact-identity-linking.md` in the monorepo) binds the
 * created contact to the member's user at their first sign-in.
 *
 * KNOWN GATEWAY GAP (documented, not silently worked around -- see this
 * feature's implementation report). As shipped on
 * SPEC-AMS-20260804-upbeat-entitlement-mirror Epic 1 (monorepo commit
 * 5d79d092c), `POST /v1/crm/contacts` (`CreateContactSchema` /
 * `CreateContactInput`) has NO `external_source` / `external_id` fields --
 * only the US-1.1 LIST filter landed, not an equivalent create-time field.
 * This class still SENDS `external_source` / `external_id` in the create body
 * (the contract Decision 2.4 and US-2.2 AC2 specify), because that is
 * forward-compatible with the gateway gaining the field and self-documents the
 * intent. Today those two keys are silently dropped by the gateway's Zod
 * schema (unknown keys are stripped, not rejected) and the created contact
 * carries NO external identity -- so a member created via this fallback will
 * not resolve by externalId on a later sync until either (a) the monorepo
 * gains create-time external-identity support, or (b) the member signs in and
 * the existing auto-link trigger binds their contact to their user, or (c) an
 * admin sets the external identity by hand. The next sync for that member
 * still self-heals via the exact-email fallback, so entitlements are not lost
 * -- only the externalId fast path is unavailable until the gap is closed.
 *
 * Similarly, there is no exact-email lookup endpoint on the gateway: the
 * broadest available match is the ILIKE `search` filter on
 * `GET /v1/crm/contacts` (`first_name`/`last_name`/`email`/`company`
 * substring). This class requests a bounded page of `search` matches and then
 * filters IN PHP for a case-insensitive EXACT match on the returned `email`
 * field, which is the best available approximation of "exact-email lookup"
 * against the current contract.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Entitlement_Contact_Resolver' ) ) :

	/**
	 * Resolves or creates the Agend contact for a mirrored kiosk member.
	 */
	class Agend_Entitlement_Contact_Resolver {

		/**
		 * Bounded page size for the exact-email fallback lookup. Wide enough to
		 * catch the true match among ILIKE noise for a typical account, without
		 * pulling an unbounded result set.
		 *
		 * @var int
		 */
		const EMAIL_LOOKUP_LIMIT = 20;

		/**
		 * Resolves the member's Agend contact id, creating the contact (with the
		 * flag values already set) when neither the externalId nor the
		 * exact-email lookup finds one.
		 *
		 * @param string             $member_id Kiosk membership number.
		 * @param array              $profile   {
		 *     Member profile used only if a contact must be created.
		 *
		 *     @type string $email      Member email, or ''.
		 *     @type string $first_name Member first name, or ''.
		 *     @type string $last_name  Member last name, or ''.
		 * }
		 * @param array<int, string> $slugs     Current mirrored entitlement slugs,
		 *                                      written into the create body's
		 *                                      `custom_fields` in the same call
		 *                                      when a contact must be created.
		 * @return array{contact_id: string, created: bool}|WP_Error
		 */
		public static function resolve_or_create( string $member_id, array $profile, array $slugs ) {
			$email = isset( $profile['email'] ) ? trim( (string) $profile['email'] ) : '';

			$found = self::find_only( $member_id, $email );

			if ( is_wp_error( $found ) ) {
				return $found;
			}

			if ( null !== $found ) {
				return array(
					'contact_id' => $found,
					'created'    => false,
				);
			}

			$external_source = Agend_Apps_Settings::get_entitlement_mirror_external_source();

			return self::create( $member_id, $external_source, $profile, $slugs );
		}

		/**
		 * Resolves the member's existing Agend contact id WITHOUT creating one:
		 * externalId-first, falling back to an exact-email match (Decision 2.4's
		 * lookup order, minus the create-on-miss step). Used by the nightly
		 * sweep (US-2.5), which reads before writing so a quiet directory costs
		 * reads, not writes, and deliberately does not create a contact for a
		 * member with no mirrorable entitlements.
		 *
		 * @param string $member_id Kiosk membership number.
		 * @param string $email     Member email, or '' when unknown.
		 * @return string|null|WP_Error Contact id, null when not found, or WP_Error on transport failure.
		 */
		public static function find_only( string $member_id, string $email ) {
			$external_source = Agend_Apps_Settings::get_entitlement_mirror_external_source();

			if ( '' !== $external_source ) {
				$found = self::find_by_external_id( $external_source, $member_id );

				if ( is_wp_error( $found ) || null !== $found ) {
					return $found;
				}
			}

			$email = trim( $email );

			if ( '' !== $email ) {
				return self::find_by_exact_email( $email );
			}

			return null;
		}

		/**
		 * Looks up a contact by `(external_source, external_id)`.
		 *
		 * @param string $external_source Configured external identity source.
		 * @param string $member_id       Kiosk membership number.
		 * @return string|null|WP_Error Contact id, null when not found, or WP_Error on transport failure.
		 */
		private static function find_by_external_id( string $external_source, string $member_id ) {
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

			$rows = self::list_rows( $response );

			return isset( $rows[0]['id'] ) ? (string) $rows[0]['id'] : null;
		}

		/**
		 * Looks up a contact by a case-insensitive EXACT email match, using the
		 * gateway's ILIKE `search` filter as the widest available approximation
		 * and narrowing in PHP (see the class docblock).
		 *
		 * @param string $email Member email.
		 * @return string|null|WP_Error Contact id, null when not found, or WP_Error on transport failure.
		 */
		private static function find_by_exact_email( string $email ) {
			$response = agend_apps_crm_get_contacts(
				array(
					'search' => $email,
					'limit'  => self::EMAIL_LOOKUP_LIMIT,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$rows = self::list_rows( $response );

			foreach ( $rows as $row ) {
				if ( isset( $row['email'] ) && is_string( $row['email'] ) && 0 === strcasecmp( $row['email'], $email ) ) {
					return isset( $row['id'] ) ? (string) $row['id'] : null;
				}
			}

			return null;
		}

		/**
		 * Creates the contact with the external identity (see the KNOWN GATEWAY
		 * GAP note above) and the flag values in one call. Never sends `user_id`
		 * (Decision 2.4).
		 *
		 * @param string             $member_id       Kiosk membership number.
		 * @param string             $external_source Configured external identity source.
		 * @param array              $profile         Member profile (email, first_name, last_name).
		 * @param array<int, string> $slugs           Current mirrored entitlement slugs.
		 * @return array{contact_id: string, created: bool}|WP_Error
		 */
		private static function create( string $member_id, string $external_source, array $profile, array $slugs ) {
			$first_name = trim( (string) ( $profile['first_name'] ?? '' ) );
			$last_name  = trim( (string) ( $profile['last_name'] ?? '' ) );
			$email      = trim( (string) ( $profile['email'] ?? '' ) );

			// CreateContactSchema requires non-empty first_name/last_name; a
			// kiosk member missing a name is rare but must not hard-fail the
			// sync -- fall back to the membership number so the contact is
			// still creatable and identifiable.
			if ( '' === $first_name && '' === $last_name ) {
				$first_name = sprintf( 'Member %s', $member_id );
			}
			if ( '' === $last_name ) {
				$last_name = '(unknown)';
			}
			if ( '' === $first_name ) {
				$first_name = '(unknown)';
			}

			$body = array(
				'first_name'    => $first_name,
				'last_name'     => $last_name,
				'custom_fields' => array(
					Agend_Apps_Settings::get_entitlement_mirror_field_key() => $slugs,
				),
			);

			if ( '' !== $email ) {
				$body['email'] = $email;
			}

			// KNOWN GATEWAY GAP: see the class docblock. Sent for forward
			// compatibility; currently dropped by CreateContactSchema.
			if ( '' !== $external_source ) {
				$body['external_source'] = $external_source;
				$body['external_id']     = $member_id;
			}

			$response = agend_apps_crm_create_contact( $body );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$contact_id = isset( $response['data']['id'] ) ? (string) $response['data']['id'] : '';

			if ( '' === $contact_id ) {
				return new WP_Error(
					'agend_entitlement_mirror_create_failed',
					__( 'The Agend API did not return a contact id after creation.', 'agend-apps-core' )
				);
			}

			return array(
				'contact_id' => $contact_id,
				'created'    => true,
			);
		}

		/**
		 * Extracts the list-envelope `data` rows from a decoded gateway response.
		 *
		 * @param array $response Decoded response body.
		 * @return array<int, array>
		 */
		private static function list_rows( array $response ): array {
			if ( ! isset( $response['data'] ) || ! is_array( $response['data'] ) ) {
				return array();
			}

			return array_values( array_filter( $response['data'], 'is_array' ) );
		}
	}

endif;

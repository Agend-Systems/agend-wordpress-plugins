<?php
/**
 * WP-CLI command for the Upbeat entitlement mirror.
 *
 * SPEC-AMS-20260804-upbeat-entitlement-mirror US-2.5. The recovery path for
 * missed webhooks: enumerates every kiosk member, runs the collector, and
 * writes only CHANGED states -- a quiet directory costs reads, not writes.
 *
 *   wp agend-apps entitlement-mirror sweep
 *   wp agend-apps entitlement-mirror sweep --max=50 --dry-run
 *
 * Deliberately NO WP-Cron auto-schedule (the agend-directory-sync precedent:
 * avoids double-runs). Add a crontab entry per the README.
 *
 * This file is only loaded under WP-CLI; the command class is never defined
 * in a web request.
 *
 * @package Agend_Entitlement_Mirror
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

if ( ! class_exists( 'Agend_Entitlement_Mirror_CLI_Command' ) ) :

	/**
	 * `wp agend-apps entitlement-mirror ...` commands.
	 */
	final class Agend_Entitlement_Mirror_CLI_Command {

		/**
		 * Page size used when enumerating kiosk members.
		 *
		 * @var int
		 */
		const PAGE_SIZE = 100;

		/**
		 * Reconciles every kiosk member's entitlement state to Agend.
		 *
		 * ## OPTIONS
		 *
		 * [--max=<number>]
		 * : Cap the number of members processed this run. 0 or omitted means
		 *   process all members.
		 *
		 * [--dry-run]
		 * : Report the would-change set (member, before, after) without writing
		 *   anything.
		 *
		 * ## EXAMPLES
		 *
		 *     wp agend-apps entitlement-mirror sweep
		 *     wp agend-apps entitlement-mirror sweep --max=50 --dry-run
		 *
		 * @param array<int, string>    $args       Positional args (unused).
		 * @param array<string, string> $assoc_args Associative args.
		 */
		public function sweep( $args, $assoc_args ): void {
			unset( $args );

			if ( ! Agend_Entitlement_Mirror_Settings::is_entitlement_mirror_enabled() ) {
				WP_CLI::error( 'The entitlement mirror is not enabled (Tools > Agend Entitlement Mirror).' );
				return;
			}

			if ( ! class_exists( 'Iugo_Membership_Kiosk_API' ) ) {
				WP_CLI::error( 'The iugo-membership-kiosk plugin is not available.' );
				return;
			}

			$max     = isset( $assoc_args['max'] ) ? max( 0, (int) $assoc_args['max'] ) : 0;
			$dry_run = isset( $assoc_args['dry-run'] );

			if ( $dry_run ) {
				WP_CLI::log( 'Dry run: reporting the would-change set only, nothing will be written.' );
			}

			$checked = 0;
			$changed = 0;
			$created = 0;
			$skipped = 0;
			$errors  = 0;

			foreach ( $this->enumerate_members( $max ) as $member ) {
				++$checked;

				$member_id = (string) $member->get_membership_number();

				try {
					$entries = Agend_Entitlement_Collector::collect( $member_id );
				} catch ( Throwable $e ) {
					++$errors;
					WP_CLI::warning( sprintf( 'Member %s: collector failed: %s', $member_id, $e->getMessage() ) );
					continue;
				}

				$desired = Agend_Entitlement_Collector::slugs_only( $entries );
				$email   = (string) $member->get_email();

				$contact_id = Agend_Entitlement_Contact_Resolver::find_only( $member_id, $email );

				if ( is_wp_error( $contact_id ) ) {
					++$errors;
					WP_CLI::warning( sprintf( 'Member %s: lookup failed: %s', $member_id, $contact_id->get_error_message() ) );
					continue;
				}

				if ( null === $contact_id ) {
					if ( empty( $desired ) ) {
						// Nothing to mirror and no existing contact -- skip. The
						// sweep never creates an Agend contact solely to record
						// zero entitlements.
						++$skipped;
						continue;
					}

					if ( $dry_run ) {
						WP_CLI::log( sprintf( 'Member %s: would CREATE contact with %s', $member_id, wp_json_encode( $desired ) ) );
						++$changed;
						continue;
					}

					$profile = array(
						'email'      => $email,
						'first_name' => (string) $member->get_first_name(),
						'last_name'  => (string) $member->get_last_name(),
					);

					$result = Agend_Entitlement_Contact_Resolver::resolve_or_create( $member_id, $profile, $desired );

					if ( is_wp_error( $result ) ) {
						++$errors;
						WP_CLI::warning( sprintf( 'Member %s: create failed: %s', $member_id, $result->get_error_message() ) );
						continue;
					}

					++$created;
					++$changed;
					continue;
				}

				$current = $this->current_flag_values( $contact_id );

				if ( is_wp_error( $current ) ) {
					++$errors;
					WP_CLI::warning( sprintf( 'Member %s: read failed: %s', $member_id, $current->get_error_message() ) );
					continue;
				}

				if ( $this->slugs_equal( $current, $desired ) ) {
					++$skipped;
					continue;
				}

				if ( $dry_run ) {
					WP_CLI::log(
						sprintf(
							'Member %s: would CHANGE %s -> %s',
							$member_id,
							wp_json_encode( $current ),
							wp_json_encode( $desired )
						)
					);
					++$changed;
					continue;
				}

				$patch = agend_apps_crm_update_contact(
					$contact_id,
					array(
						'custom_fields' => array(
							Agend_Entitlement_Mirror_Settings::get_entitlement_mirror_field_key() => $desired,
						),
					)
				);

				if ( is_wp_error( $patch ) ) {
					++$errors;
					WP_CLI::warning( sprintf( 'Member %s: write failed: %s', $member_id, $patch->get_error_message() ) );
					continue;
				}

				++$changed;
			}

			WP_CLI::log( sprintf( 'Checked: %d', $checked ) );
			WP_CLI::log( sprintf( 'Changed: %d', $changed ) );
			WP_CLI::log( sprintf( 'Created: %d', $created ) );
			WP_CLI::log( sprintf( 'Skipped (no-op): %d', $skipped ) );
			WP_CLI::log( sprintf( 'Errors: %d', $errors ) );

			if ( $errors > 0 ) {
				// Non-zero exit so cron alerting works (AC3).
				WP_CLI::error( sprintf( 'Sweep completed with %d error(s).', $errors ) );
				return;
			}

			WP_CLI::success( $dry_run ? 'Dry run complete.' : 'Sweep complete.' );
		}

		/**
		 * Yields kiosk members, paginating via `get_members()`, capped at `$max`
		 * when non-zero.
		 *
		 * @param int $max Maximum members to yield (0 = unbounded).
		 * @return \Generator<Iugo_Membership_Kiosk_API_MemberDetail>
		 */
		private function enumerate_members( int $max ) {
			$api      = Iugo_Membership_Kiosk_API::instance();
			$page     = 1;
			$yielded  = 0;

			do {
				$members = $api->get_members( '', (string) self::PAGE_SIZE, (string) $page );

				if ( ! is_array( $members ) ) {
					return;
				}

				foreach ( $members as $member ) {
					if ( ! ( $member instanceof Iugo_Membership_Kiosk_API_MemberDetail ) ) {
						continue;
					}

					yield $member;
					++$yielded;

					if ( $max > 0 && $yielded >= $max ) {
						return;
					}
				}

				++$page;
			} while ( $api->has_more_pages( 'Iugo_Membership_Kiosk_API_MemberDetail' ) && ! empty( $members ) );
		}

		/**
		 * Reads a contact's current mirrored entitlement value list from the
		 * gateway.
		 *
		 * @param string $contact_id Agend contact id.
		 * @return array<int, string>|WP_Error
		 */
		private function current_flag_values( string $contact_id ) {
			$response = agend_apps_crm_get_contact( $contact_id );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$field_key = Agend_Entitlement_Mirror_Settings::get_entitlement_mirror_field_key();
			$values    = $response['data']['custom_fields'][ $field_key ] ?? array();

			if ( ! is_array( $values ) ) {
				return array();
			}

			$values = array_values( array_map( 'strval', $values ) );
			sort( $values, SORT_STRING );

			return $values;
		}

		/**
		 * Whether two slug lists are equal regardless of order (both are
		 * expected pre-sorted, but this compares defensively).
		 *
		 * @param array<int, string> $current Current value list.
		 * @param array<int, string> $desired Desired value list.
		 * @return bool
		 */
		private function slugs_equal( array $current, array $desired ): bool {
			$a = $current;
			$b = $desired;
			sort( $a, SORT_STRING );
			sort( $b, SORT_STRING );

			return $a === $b;
		}
	}

endif;

WP_CLI::add_command( 'agend-apps entitlement-mirror', 'Agend_Entitlement_Mirror_CLI_Command' );

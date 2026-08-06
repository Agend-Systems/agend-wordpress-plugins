<?php
/**
 * WP-CLI command for the Upbeat entitlement mirror.
 *
 * SPEC-AMS-20260804-upbeat-entitlement-mirror US-2.5. The recovery path for
 * missed webhooks: enumerates every kiosk member and reconciles their
 * entitlement grants (SPEC-CRM-20260805-member-entitlement-grants US-5.1) --
 * the grants endpoint is itself idempotent, so a quiet member costs one
 * network round trip, not a write.
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
		 * Reconciles every kiosk member's entitlement grants with Agend.
		 *
		 * ## OPTIONS
		 *
		 * [--max=<number>]
		 * : Cap the number of members processed this run. 0 or omitted means
		 *   process all members.
		 *
		 * [--dry-run]
		 * : Report the would-sync set (member, gate keys) without calling the
		 *   grants endpoint.
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
				WP_CLI::log( 'Dry run: reporting the would-sync set only, the grants endpoint will not be called.' );
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

				$gate_keys = wp_list_pluck( $entries, 'gate_key' );

				if ( $dry_run ) {
					if ( empty( $gate_keys ) ) {
						++$skipped;
						continue;
					}

					WP_CLI::log( sprintf( 'Member %s: would reconcile grants %s', $member_id, wp_json_encode( $gate_keys ) ) );
					++$changed;
					continue;
				}

				// Delegates to the same reconcile flow sync_member() uses (types
				// pre-declaration, the empty-entries/no-contact skip, and the
				// empty-profile-field omission the gateway schema requires),
				// rather than re-deriving the payload here.
				$profile = array(
					'email'      => (string) $member->get_email(),
					'first_name' => (string) $member->get_first_name(),
					'last_name'  => (string) $member->get_last_name(),
				);

				$result = Agend_Entitlement_Sync::reconcile_member( $member_id, $entries, $profile );

				if ( is_wp_error( $result ) ) {
					++$errors;
					WP_CLI::warning( sprintf( 'Member %s: sync failed: %s', $member_id, $result->get_error_message() ) );
					continue;
				}

				if ( true === $result ) {
					++$skipped;
					continue;
				}

				$data      = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
				$granted   = (array) ( $data['granted'] ?? array() );
				$refreshed = (array) ( $data['refreshed'] ?? array() );
				$revoked   = (array) ( $data['revoked'] ?? array() );

				if ( ! empty( $data['contact_created'] ) ) {
					++$created;
				}

				if ( empty( $granted ) && empty( $refreshed ) && empty( $revoked ) ) {
					++$skipped;
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
	}

endif;

WP_CLI::add_command( 'agend-apps entitlement-mirror', 'Agend_Entitlement_Mirror_CLI_Command' );

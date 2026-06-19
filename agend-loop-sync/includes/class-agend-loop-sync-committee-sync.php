<?php
/**
 * Committee sync (per-member model).
 *
 * @package Agend_Loop_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Syncs Upbeat committees into Loop channels using a per-member model.
 *
 * Upbeat returns contact *names* (not membership ids) in foreign-key fields,
 * so committee members cannot be resolved from get_committees() alone. Instead
 * this iterates the local WordPress users that carry an imk_membership_number,
 * asks Upbeat for each member's committee activities, and aggregates those into
 * committee rosters keyed by committee name. The wp_id is therefore always
 * known, and committees only include members who exist locally (exactly who can
 * become a Loop user).
 *
 * get_committees() is still read once to index committee metadata
 * (allow_on_web, groups) for filtering. Each committee is keyed by
 * sanitize_title(name) since Upbeat committees have no numeric id.
 *
 * Note: this makes one membership-activities API call per local member (the
 * Upbeat client caches each), so on a very large membership the run is best
 * left to WP-Cron; batching/async is a future optimisation.
 */
class Agend_Loop_Sync_Committee_Sync {

	/**
	 * Registers the WP-Cron handler.
	 *
	 * @return void
	 */
	public function register() {
		add_action( AGEND_LOOP_SYNC_CRON_HOOK, array( $this, 'run' ) );
	}

	/**
	 * Runs a full per-member committee sync.
	 *
	 * @return array<string, mixed> A summary of the run.
	 */
	public function run(): array {
		$summary = array(
			'members_scanned'    => 0,
			'committees'         => 0,
			'synced'             => 0,
			'skipped_committees' => 0,
			'errors'             => array(),
			'dry_run'            => Agend_Loop_Sync_Settings::is_dry_run(),
		);

		if ( ! Agend_Loop_Sync_Settings::is_enabled() || ! Agend_Loop_Sync_Settings::is_committee_sync_enabled() ) {
			$summary['errors'][] = 'Committee sync is disabled in settings';
			return $this->finish( $summary );
		}

		if ( ! class_exists( 'Iugo_Membership_Kiosk_API' ) || ! function_exists( 'agend_apps_loop_sync_committee' ) ) {
			$summary['errors'][] = 'Required plugins (membership API / agend-apps-core) are unavailable';
			return $this->finish( $summary );
		}

		$index   = $this->build_committee_index();
		$rosters = $this->build_rosters( $summary );

		foreach ( $rosters as $committee_name => $members ) {
			if ( ! $this->passes_filter( $committee_name, $index ) ) {
				++$summary['skipped_committees'];
				continue;
			}

			++$summary['committees'];

			if ( $this->sync_committee_roster( $committee_name, array_values( $members ), $summary ) ) {
				++$summary['synced'];
			}
		}

		Agend_Loop_Sync_Logger::info( 'Committee sync run complete', $summary );

		return $this->finish( $summary );
	}

	/**
	 * Builds committee rosters by aggregating every local member's committee
	 * activities. Returns committee_name => [ wp_id => roster_entry ].
	 *
	 * @param array<string, mixed> $summary Run summary, by reference for counters.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function build_rosters( array &$summary ): array {
		$api     = Iugo_Membership_Kiosk_API::instance();
		$rosters = array();

		foreach ( $this->get_member_users() as $user ) {
			$membership_number = trim( (string) get_user_meta( $user->ID, 'imk_membership_number', true ) );
			if ( '' === $membership_number ) {
				continue;
			}

			++$summary['members_scanned'];

			$activities = $api->get_member_committee_activities( $membership_number );
			$rows       = ( $activities && method_exists( $activities, 'get_results' ) )
				? (array) $activities->get_results()
				: array();

			foreach ( $rows as $activity ) {
				if ( ! is_object( $activity ) || ! method_exists( $activity, 'get_committee' ) ) {
					continue;
				}

				$committee_name = trim( (string) $activity->get_committee() );
				if ( '' === $committee_name ) {
					continue;
				}

				$role = method_exists( $activity, 'get_role' ) ? (string) $activity->get_role() : '';

				$rosters[ $committee_name ][ (int) $user->ID ] = array(
					'wp_id'                => (int) $user->ID,
					'channel_role'         => Agend_Loop_Sync_Role_Mapper::to_channel_role( $role ),
					'committee_role_label' => $role,
				);
			}
		}

		return $rosters;
	}

	/**
	 * Pushes a single committee roster to the gateway (or logs it in dry run).
	 *
	 * @param string                      $committee_name Committee name.
	 * @param array<int, array<string,mixed>> $roster     Resolved roster entries.
	 * @param array<string, mixed>        $summary        Run summary, by reference.
	 * @return bool True on success or dry-run; false on failure.
	 */
	private function sync_committee_roster( string $committee_name, array $roster, array &$summary ): bool {
		$payload = array(
			'unique_id' => sanitize_title( $committee_name ),
			'name'      => $committee_name,
			'is_active' => true,
			'roster'    => $roster,
		);

		if ( Agend_Loop_Sync_Settings::is_dry_run() ) {
			Agend_Loop_Sync_Logger::info( 'Dry run: would sync committee', $payload );
			return true;
		}

		$response = agend_apps_loop_sync_committee( $payload );

		if ( is_wp_error( $response ) ) {
			$message             = $response->get_error_message();
			$summary['errors'][] = sprintf( '%s: %s', $committee_name, $message );
			Agend_Loop_Sync_Logger::error(
				'Committee sync failed',
				array(
					'committee' => $committee_name,
					'error'     => $message,
				)
			);
			return false;
		}

		Agend_Loop_Sync_Logger::info(
			'Committee synced',
			array(
				'committee' => $committee_name,
				'members'   => count( $roster ),
			)
		);

		return true;
	}

	/**
	 * Reads get_committees() once to index committee metadata for filtering.
	 *
	 * @return array<string, array<string, mixed>> name => { allow_on_web, groups }.
	 */
	private function build_committee_index(): array {
		$index      = array();
		$collection = Iugo_Membership_Kiosk_API::instance()->get_committees();
		$committees = ( $collection && method_exists( $collection, 'get_results' ) )
			? (array) $collection->get_results()
			: array();

		foreach ( $committees as $committee ) {
			if ( ! is_object( $committee ) || ! method_exists( $committee, 'get_name' ) ) {
				continue;
			}

			$name   = trim( (string) $committee->get_name() );
			$groups = array();
			if ( method_exists( $committee, 'get_groups' ) ) {
				foreach ( (array) $committee->get_groups() as $group ) {
					if ( is_object( $group ) && method_exists( $group, 'get_group' ) ) {
						$groups[] = (string) $group->get_group();
					}
				}
			}

			$index[ $name ] = array(
				'allow_on_web' => method_exists( $committee, 'get_allow_on_web' ) ? (bool) $committee->get_allow_on_web() : true,
				'groups'       => $groups,
			);
		}

		return $index;
	}

	/**
	 * Returns local WordPress users that carry a membership number.
	 *
	 * @return WP_User[]
	 */
	private function get_member_users(): array {
		return get_users(
			array(
				'meta_key'     => 'imk_membership_number', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
				'orderby'      => 'ID',
				'order'        => 'ASC',
			)
		);
	}

	/**
	 * Applies the configured filter to a committee by name, using the metadata
	 * index from get_committees(). Committees absent from the index default to
	 * web-visible with no groups.
	 *
	 * @param string                              $committee_name Committee name.
	 * @param array<string, array<string, mixed>> $index          Committee metadata index.
	 * @return bool
	 */
	private function passes_filter( string $committee_name, array $index ): bool {
		$mode = Agend_Loop_Sync_Settings::get_filter_mode();

		if ( 'allowlist' === $mode ) {
			return in_array( $committee_name, Agend_Loop_Sync_Settings::get_filter_allowlist(), true );
		}

		$meta         = $index[ $committee_name ] ?? array( 'allow_on_web' => true, 'groups' => array() );
		$allow_on_web = ! empty( $meta['allow_on_web'] );

		if ( ! $allow_on_web ) {
			return false;
		}

		if ( 'groups' === $mode ) {
			$wanted = Agend_Loop_Sync_Settings::get_filter_groups();
			foreach ( (array) $meta['groups'] as $group ) {
				if ( in_array( $group, $wanted, true ) ) {
					return true;
				}
			}
			return false;
		}

		return true;
	}

	/**
	 * Diagnostic: scans local members and reports which of them are in a
	 * committee, with their committees and roles, plus their email and
	 * membership number so the operator can pick a test subject. Read-only; no
	 * gateway calls. This is the user-centric counterpart to inspect().
	 *
	 * @return array<string, mixed>
	 */
	public function scan_members(): array {
		$report = array(
			'generated_at'         => time(),
			'available'            => false,
			'members_total'        => 0,
			'members_in_committee' => 0,
			'roles'                => array(),
			'members'              => array(),
			'error'                => '',
		);

		if ( ! class_exists( 'Iugo_Membership_Kiosk_API' ) ) {
			$report['error'] = 'Membership API unavailable';
			return $report;
		}

		$api                 = Iugo_Membership_Kiosk_API::instance();
		$report['available'] = true;
		$users               = $this->get_member_users();
		$report['members_total'] = count( $users );

		foreach ( $users as $user ) {
			$membership_number = trim( (string) get_user_meta( $user->ID, 'imk_membership_number', true ) );
			if ( '' === $membership_number ) {
				continue;
			}

			$activities = $api->get_member_committee_activities( $membership_number );
			$rows       = ( $activities && method_exists( $activities, 'get_results' ) )
				? (array) $activities->get_results()
				: array();

			$committees = array();
			foreach ( $rows as $activity ) {
				if ( ! is_object( $activity ) || ! method_exists( $activity, 'get_committee' ) ) {
					continue;
				}
				$cname = trim( (string) $activity->get_committee() );
				if ( '' === $cname ) {
					continue;
				}
				$role         = method_exists( $activity, 'get_role' ) ? (string) $activity->get_role() : '';
				$committees[] = array(
					'committee' => $cname,
					'role'      => $role,
				);
				if ( '' !== $role ) {
					$report['roles'][ $role ] = ( $report['roles'][ $role ] ?? 0 ) + 1;
				}
			}

			if ( array() === $committees ) {
				continue;
			}

			++$report['members_in_committee'];

			if ( count( $report['members'] ) < 200 ) {
				$report['members'][] = array(
					'wp_id'             => (int) $user->ID,
					'email'             => (string) $user->user_email,
					'display_name'      => (string) $user->display_name,
					'membership_number' => $membership_number,
					'committees'        => $committees,
				);
			}
		}

		ksort( $report['roles'] );

		return $report;
	}

	/**
	 * Read-only committee catalogue from get_committees(): committee names, web
	 * flag, groups, raw activity count, and the distinct role strings (the
	 * mapping vocabulary). Does NOT resolve members — Upbeat returns contact
	 * names rather than ids, so member resolution is done per-member by the
	 * sync and by scan_members(), not here.
	 *
	 * @return array<string, mixed>
	 */
	public function inspect(): array {
		$preview = array(
			'generated_at'     => time(),
			'available'        => false,
			'total_committees' => 0,
			'roles'            => array(),
			'committees'       => array(),
			'error'            => '',
		);

		if ( ! class_exists( 'Iugo_Membership_Kiosk_API' ) ) {
			$preview['error'] = 'Membership API unavailable';
			return $preview;
		}

		$collection = Iugo_Membership_Kiosk_API::instance()->get_committees();
		$committees = ( $collection && method_exists( $collection, 'get_results' ) )
			? (array) $collection->get_results()
			: array();

		$preview['available']        = true;
		$preview['total_committees'] = count( $committees );

		foreach ( $committees as $committee ) {
			if ( ! is_object( $committee ) || ! method_exists( $committee, 'get_name' ) ) {
				continue;
			}

			$name  = (string) $committee->get_name();
			$allow = method_exists( $committee, 'get_allow_on_web' ) ? (bool) $committee->get_allow_on_web() : true;

			$groups = array();
			if ( method_exists( $committee, 'get_groups' ) ) {
				foreach ( (array) $committee->get_groups() as $group ) {
					if ( is_object( $group ) && method_exists( $group, 'get_group' ) ) {
						$groups[] = (string) $group->get_group();
					}
				}
			}

			$activities = method_exists( $committee, 'get_activities' ) ? (array) $committee->get_activities() : array();
			$members    = 0;

			foreach ( $activities as $activity ) {
				if ( ! is_object( $activity ) || ! method_exists( $activity, 'get_contact' ) ) {
					continue;
				}
				++$members;
				$role = method_exists( $activity, 'get_role' ) ? (string) $activity->get_role() : '';
				if ( '' !== $role ) {
					$preview['roles'][ $role ] = ( $preview['roles'][ $role ] ?? 0 ) + 1;
				}
			}

			$preview['committees'][] = array(
				'name'         => $name,
				'allow_on_web' => $allow,
				'groups'       => $groups,
				'members'      => $members,
			);
		}

		ksort( $preview['roles'] );

		return $preview;
	}

	/**
	 * Persists the run timestamp and summary, then returns the summary.
	 *
	 * @param array<string, mixed> $summary Run summary.
	 * @return array<string, mixed>
	 */
	private function finish( array $summary ): array {
		update_option( Agend_Loop_Sync_Settings::OPT_LAST_SYNC, time(), false );
		update_option( Agend_Loop_Sync_Settings::OPT_LAST_SUMMARY, $summary, false );

		return $summary;
	}
}

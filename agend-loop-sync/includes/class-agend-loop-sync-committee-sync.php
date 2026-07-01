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
			'members_scanned'         => 0,
			'committees'              => 0,
			'synced'                  => 0,
			'skipped_committees'      => 0,
			'applied_members'         => 0,
			'skipped_members'         => 0,
			'removed_members'         => 0,
			'archived'                => 0,
			'unresolved_external_ids' => array(),
			'errors'                  => array(),
			'dry_run'                 => Agend_Loop_Sync_Settings::is_dry_run(),
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

		// Archive Loop committees that no longer exist upstream (deprovisioning).
		$this->archive_removed_committees( $index, $summary );

		Agend_Loop_Sync_Logger::info( 'Committee sync run complete', $summary );

		return $this->finish( $summary );
	}

	/**
	 * Builds committee rosters by aggregating every local member's committee
	 * activities. Returns committee_name => [ wp_user_id => roster_entry ],
	 * where the WP user id key only de-duplicates a member within a committee.
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
					// external_id is the subject the SSO assertion's NameID
					// carries (for this site the Upbeat membership number), the
					// value Loop stores on the user, so the gateway can resolve
					// this roster entry to the right Loop user. (The array key
					// stays the WP user id only to de-duplicate a member within
					// a committee.)
					'external_id'          => (int) $membership_number,
					// Email is the reliable fallback the gateway uses when
					// external_id does not resolve (the Loop user's email matches
					// the WordPress user's email regardless of the SSO NameID
					// format).
					'email'                => (string) $user->user_email,
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
			'idp_entity_id' => Agend_Loop_Sync_Settings::idp_entity_id(),
			'unique_id'     => sanitize_title( $committee_name ),
			'name'          => $committee_name,
			'is_active'     => true,
			'roster'        => $roster,
			// The roster built here is the authoritative membership for this
			// committee, so use replace mode: a member dropped upstream is
			// removed from the Loop channel (deprovisioning). Requires the
			// gateway committee-sync replace mode.
			'mode'          => 'replace',
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

		// Read the reconciliation counts the gateway returns. The apps-core
		// client may return the full envelope or the unwrapped data, so accept
		// both shapes.
		$data       = is_array( $response ) ? ( $response['data'] ?? $response ) : array();
		$applied    = (int) ( $data['applied'] ?? 0 );
		$skipped    = (int) ( $data['skipped'] ?? 0 );
		$removed    = (int) ( $data['removed'] ?? 0 );
		$unresolved = is_array( $data['skipped_external_ids'] ?? null ) ? $data['skipped_external_ids'] : array();

		$summary['applied_members'] += $applied;
		$summary['skipped_members'] += $skipped;
		$summary['removed_members'] += $removed;
		foreach ( $unresolved as $ext_id ) {
			$summary['unresolved_external_ids'][] = (int) $ext_id;
		}

		Agend_Loop_Sync_Logger::info(
			'Committee synced',
			array(
				'committee' => $committee_name,
				'members'   => count( $roster ),
				'applied'   => $applied,
				'skipped'   => $skipped,
				'removed'   => $removed,
			)
		);

		return true;
	}

	/**
	 * Archives Loop committee channels that no longer exist upstream. Every
	 * IMK-managed committee present in Loop whose unique_id is not among the
	 * current Upbeat committees is archived (hidden), so a committee removed
	 * upstream stops appearing in Loop. Skipped in dry-run (logged only).
	 *
	 * @param array<string, array<string, mixed>> $index   Current Upbeat committee index (keyed by name).
	 * @param array<string, mixed>                $summary Run summary, by reference.
	 * @return void
	 */
	private function archive_removed_committees( array $index, array &$summary ): void {
		if ( ! function_exists( 'agend_apps_loop_list_committees' ) || ! function_exists( 'agend_apps_loop_archive_committee' ) ) {
			return;
		}

		// The unique_id the sync uses for every current Upbeat committee.
		$current = array();
		foreach ( array_keys( $index ) as $name ) {
			$current[] = sanitize_title( (string) $name );
		}

		$response = agend_apps_loop_list_committees( 1, 100 );
		if ( is_wp_error( $response ) ) {
			$summary['errors'][] = 'List committees for archive failed: ' . $response->get_error_message();
			return;
		}

		$items = array();
		if ( is_array( $response ) ) {
			$items = is_array( $response['data'] ?? null ) ? $response['data'] : array();
		}

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$uid = (string) ( $item['unique_id'] ?? '' );
			if ( '' === $uid || ! empty( $item['is_archived'] ) ) {
				continue;
			}
			if ( in_array( $uid, $current, true ) ) {
				continue; // Still present upstream.
			}

			if ( Agend_Loop_Sync_Settings::is_dry_run() ) {
				Agend_Loop_Sync_Logger::info( 'Dry run: would archive committee removed upstream', array( 'unique_id' => $uid ) );
				++$summary['archived'];
				continue;
			}

			$res = agend_apps_loop_archive_committee( $uid );
			if ( is_wp_error( $res ) ) {
				$summary['errors'][] = sprintf( 'Archive %s: %s', $uid, $res->get_error_message() );
				Agend_Loop_Sync_Logger::error(
					'Committee archive failed',
					array(
						'unique_id' => $uid,
						'error'     => $res->get_error_message(),
					)
				);
				continue;
			}

			++$summary['archived'];
			Agend_Loop_Sync_Logger::info( 'Committee archived (removed upstream)', array( 'unique_id' => $uid ) );
		}
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
			// Case-insensitive match so an allow-list entry of "Executive Board"
			// still matches an Upbeat committee returned as "executive board".
			$needle    = strtolower( trim( $committee_name ) );
			$allowlist = array_map(
				static function ( $name ) {
					return strtolower( trim( (string) $name ) );
				},
				Agend_Loop_Sync_Settings::get_filter_allowlist()
			);
			return in_array( $needle, $allowlist, true );
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

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
 * get_committees() is still read once per run to index committee metadata
 * (allow_on_web, groups) for filtering, plus each committee's stable Upbeat
 * unique id. Loop channels are keyed by that id, so renaming a committee in
 * Upbeat renames its channel instead of creating a new one. Activities name
 * their committee but carry no id, so the id is joined from the committee list
 * by name. The membership plugin caches that list for 24 hours and each
 * member's activities for 1 hour, so just after a committee is created or
 * renamed the two can disagree; a run that finds a name missing from the list
 * syncs nothing for it and makes no removals or archiving (see run()).
 *
 * Upbeat pages both responses, so each is walked page by page and a result that
 * cannot be shown to be complete is treated as a failed read, never as a
 * shorter list (see fetch_committees() and fetch_member_activities()).
 *
 * Note: this makes one membership-activities API call per local member (the
 * Upbeat client caches each; a member whose activities span several pages
 * costs one call per page), so on a very large membership the run is best
 * left to WP-Cron; batching/async is a future optimisation.
 */
class Agend_Loop_Sync_Committee_Sync {

	/**
	 * Default committees per Upbeat page. Small on purpose: /committees embeds
	 * each committee's full activity roster and the server takes about 1.8 ms per
	 * roster activity, so one page of every committee can outrun the HTTP
	 * timeout. Filter with `agend_loop_sync_committee_page_size`.
	 */
	private const COMMITTEE_PAGE_SIZE = 5;

	/**
	 * Hard stop on committee pages, so an Upbeat that never reports the last
	 * page cannot loop forever.
	 */
	private const COMMITTEE_MAX_PAGES = 200;

	/**
	 * Hard stop on pages of one member's committee activities.
	 */
	private const ACTIVITY_MAX_PAGES = 20;

	/**
	 * Upbeat client override, used by tests. Null means the real
	 * Iugo_Membership_Kiosk_API singleton.
	 *
	 * @var object|null
	 */
	private $api_override;

	/**
	 * @param object|null $api Optional Upbeat client; defaults to the membership plugin's.
	 */
	public function __construct( $api = null ) {
		$this->api_override = $api;
	}

	/**
	 * The Upbeat client, or null when the membership plugin is not available.
	 *
	 * @return object|null
	 */
	private function api() {
		if ( null !== $this->api_override ) {
			return $this->api_override;
		}

		return class_exists( 'Iugo_Membership_Kiosk_API' ) ? Iugo_Membership_Kiosk_API::instance() : null;
	}

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
			'members_scanned'             => 0,
			'committees'                  => 0,
			'synced'                      => 0,
			'skipped_committees'          => 0,
			'skipped_unlisted_committees' => 0,
			'skipped_no_id_committees'    => 0,
			'applied_members'             => 0,
			'skipped_members'             => 0,
			'skipped_invalid_members'     => 0,
			'removed_members'             => 0,
			'archived'                    => 0,
			'archived_empty'              => 0,
			'failed_members'              => 0,
			'mode'                        => 'replace',
			'unresolved_external_ids'     => array(),
			'errors'                      => array(),
			'dry_run'                     => Agend_Loop_Sync_Settings::is_dry_run(),
			'member_counts_reported'      => false,
		);

		if ( ! Agend_Loop_Sync_Settings::is_enabled() || ! Agend_Loop_Sync_Settings::is_committee_sync_enabled() ) {
			$summary['errors'][] = 'Committee sync is disabled in settings';
			return $this->finish( $summary );
		}

		if ( null === $this->api() || ! function_exists( 'agend_apps_loop_sync_committee' ) ) {
			$summary['errors'][] = 'Required plugins (membership API / agend-apps-core) are unavailable';
			return $this->finish( $summary );
		}

		$index = $this->build_committee_index();
		if ( null === $index ) {
			// Without the committee index nothing below can be trusted: an empty
			// one would archive every committee in Loop.
			$summary['errors'][] = 'Could not read committees from Upbeat; run aborted before any change';
			Agend_Loop_Sync_Logger::error( 'Committee sync aborted: Upbeat committee list unavailable' );
			return $this->finish( $summary );
		}

		$rosters = $this->build_rosters( $summary );

		// Replace mode and archiving both treat the data as complete. If any
		// member's lookup failed, or nothing was read at all, a missing member
		// is indistinguishable from one who left, so only add and update.
		$data_complete = 0 === $summary['failed_members'] && $summary['members_scanned'] > 0 && array() !== $index;

		// A committee named in the activities but absent from the committee list
		// means the list is behind (it is cached longer than the activities), so
		// the list cannot be trusted to say which committees still exist.
		$unlisted = $this->unlisted_committee_names( $rosters, $index );
		$complete = $data_complete && array() === $unlisted;
		if ( ! $complete ) {
			$summary['mode'] = 'merge';

			if ( ! $data_complete ) {
				$summary['errors'][] = 'Upbeat data was incomplete; ran in merge mode with no removals or archiving';
				Agend_Loop_Sync_Logger::warning(
					'Committee sync ran in merge mode: Upbeat data incomplete',
					array(
						'failed_members'  => $summary['failed_members'],
						'members_scanned' => $summary['members_scanned'],
					)
				);
			}

			if ( array() !== $unlisted ) {
				$summary['errors'][] = 'Upbeat committee list is out of date compared with member activities; ran in merge mode with no removals or archiving';
				Agend_Loop_Sync_Logger::warning(
					'Committee sync ran in merge mode: committees in member activities are missing from the committee list',
					array( 'committees' => $unlisted )
				);
			}
		}

		// Loop's view of the committees this site has synced. Needed to archive a
		// committee whose last member left, and to archive removed ones.
		$loop_committees = $complete ? $this->fetch_loop_committees( $summary ) : null;

		foreach ( $rosters as $committee_name => $members ) {
			$committee_name = (string) $committee_name;
			$entry          = $index[ $committee_name ] ?? null;

			// Not in the committee list, so it has no id to sync under. The run
			// is already in merge mode for this (see above).
			if ( null === $entry ) {
				++$summary['skipped_unlisted_committees'];
				continue;
			}

			// Activities join to the list by name, so a name shared by two
			// committees cannot be tied to either id.
			if ( $entry['ambiguous'] || '' === $entry['unique_id'] ) {
				++$summary['skipped_no_id_committees'];
				Agend_Loop_Sync_Logger::warning(
					'Committee skipped: no usable Upbeat unique id',
					array(
						'committee' => $committee_name,
						'reason'    => $entry['ambiguous'] ? 'name shared by several committees' : 'empty unique id',
					)
				);
				continue;
			}

			if ( ! $this->passes_filter( $committee_name, $index ) ) {
				++$summary['skipped_committees'];
				continue;
			}

			++$summary['committees'];

			if ( $this->sync_committee_roster( $entry['unique_id'], $committee_name, array_values( $members ), $summary ) ) {
				++$summary['synced'];
			}
		}

		if ( null !== $loop_committees ) {
			// A committee still in Upbeat but with no local member left has no
			// roster, so the loop above never visits it and its last members
			// would keep access. The gateway ignores an empty roster, so archive
			// the channel instead. That is reversible: once the committee has a
			// local member again the roster sync upserts it as active.
			foreach ( $this->committees_without_local_members( $index, $rosters, $loop_committees ) as $committee ) {
				if ( ! $this->passes_filter( $committee['name'], $index ) ) {
					continue;
				}

				if ( $this->archive_committee( $committee['unique_id'], 'no local member left', $summary ) ) {
					++$summary['archived_empty'];
				}
			}

			// Archive Loop committees that no longer exist upstream (deprovisioning).
			$this->archive_removed_committees( $index, $loop_committees, $summary );
		}

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
		$rosters = array();

		foreach ( $this->get_member_users() as $user ) {
			$membership_number = trim( (string) get_user_meta( $user->ID, 'imk_membership_number', true ) );
			if ( '' === $membership_number ) {
				continue;
			}

			$external_id = Agend_Loop_Sync_Contact_Resolver::to_external_id( $membership_number );
			if ( null === $external_id ) {
				++$summary['skipped_invalid_members'];
				continue;
			}

			++$summary['members_scanned'];

			$rows = $this->fetch_member_activities( $membership_number );

			// A failed lookup is not an empty one: the member may still be on
			// committees. Count it so the run does not remove them. A lookup that
			// could not read every page counts the same, since a missing page may
			// hold a committee they are still on.
			if ( null === $rows ) {
				++$summary['failed_members'];
				continue;
			}

			foreach ( $rows as $activity ) {
				if ( ! is_object( $activity ) || ! method_exists( $activity, 'get_committee' ) ) {
					continue;
				}

				$committee_name = trim( (string) $activity->get_committee() );
				if ( '' === $committee_name ) {
					continue;
				}

				$role = method_exists( $activity, 'get_role' ) ? (string) $activity->get_role() : '';

				$channel_role = Agend_Loop_Sync_Role_Mapper::to_channel_role( $role );
				$existing     = $rosters[ $committee_name ][ (int) $user->ID ] ?? null;

				// A member can hold several roles in one committee (e.g. Chair
				// and Member). The highest channel role wins so a later, lower
				// row cannot downgrade them.
				if ( null !== $existing && Agend_Loop_Sync_Role_Mapper::rank( $existing['channel_role'] ) >= Agend_Loop_Sync_Role_Mapper::rank( $channel_role ) ) {
					continue;
				}

				$entry = array(
					// external_id is the subject the SSO assertion's NameID
					// carries (for this site the Upbeat membership number), the
					// value Loop stores on the user, so the gateway can resolve
					// this roster entry to the right Loop user. (The array key
					// stays the WP user id only to de-duplicate a member within
					// a committee.)
					'external_id'          => $external_id,
					'channel_role'         => $channel_role,
					'committee_role_label' => $role,
				);

				// Email is the fallback the gateway uses when external_id does
				// not resolve. The gateway rejects a malformed one, which would
				// fail the whole committee, so omit it rather than send it.
				if ( is_email( $user->user_email ) ) {
					$entry['email'] = (string) $user->user_email;
				}

				$rosters[ $committee_name ][ (int) $user->ID ] = $entry;
			}
		}

		return $rosters;
	}

	/**
	 * Pushes a single committee roster to the gateway (or logs it in dry run).
	 *
	 * @param string                      $unique_id      Upbeat committee unique id, the channel's key in Loop.
	 * @param string                      $committee_name Committee name, the channel's display name.
	 * @param array<int, array<string,mixed>> $roster     Resolved roster entries.
	 * @param array<string, mixed>        $summary        Run summary, by reference.
	 * @return bool True on success or dry-run; false on failure.
	 */
	private function sync_committee_roster( string $unique_id, string $committee_name, array $roster, array &$summary ): bool {
		$payload = array(
			'idp_entity_id' => Agend_Loop_Sync_Settings::idp_entity_id(),
			'unique_id'     => $unique_id,
			'name'          => $committee_name,
			'is_active'     => true,
			'roster'        => $roster,
			// When every Upbeat read succeeded the roster is the authoritative
			// membership, so replace mode removes a member dropped upstream
			// (deprovisioning). When any read failed the run uses merge mode, so
			// a missing member is never mistaken for a departed one. Replace mode
			// requires the gateway committee-sync replace mode.
			'mode'          => $summary['mode'],
		);

		if ( Agend_Loop_Sync_Settings::is_dry_run() ) {
			Agend_Loop_Sync_Logger::info(
				'Dry run: would sync committee',
				array(
					'unique_id'   => $payload['unique_id'],
					'mode'        => $payload['mode'],
					'roster_size' => count( $roster ),
				)
			);
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

		// A gateway without roster replace mode ignores `mode` and returns none
		// of these counts, so removals silently never happen. Surface it.
		if ( 'replace' === $summary['mode'] && is_array( $data ) && ! array_key_exists( 'removed', $data ) && ! in_array( 'Gateway did not confirm replace mode; departed members are not being removed', $summary['errors'], true ) ) {
			$summary['errors'][] = 'Gateway did not confirm replace mode; departed members are not being removed';
		}

		// Without these counts the member totals stay at zero, which would read
		// as "nothing happened". Record whether the gateway actually reported them.
		if ( is_array( $data ) && array_key_exists( 'applied', $data ) ) {
			$summary['member_counts_reported'] = true;
		}

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
	 * Reads every committee Loop holds for this site, following pagination.
	 *
	 * Returns null (with the reason recorded) when the list cannot be read in
	 * full, so callers skip the work that depends on it rather than act on a
	 * partial list.
	 *
	 * @param array<string, mixed> $summary Run summary, by reference.
	 * @return array<int, array<string, mixed>>|null
	 */
	private function fetch_loop_committees( array &$summary ): ?array {
		if ( ! function_exists( 'agend_apps_loop_list_committees' ) ) {
			return null;
		}

		$per_page = 100;
		$items    = array();

		// Hard stop so a gateway that never reports the last page cannot loop forever.
		for ( $page = 1; $page <= 50; $page++ ) {
			$response = agend_apps_loop_list_committees( $page, $per_page );
			if ( is_wp_error( $response ) ) {
				$summary['errors'][] = 'List committees failed: ' . $response->get_error_message();
				return null;
			}

			$data = is_array( $response ) && is_array( $response['data'] ?? null ) ? $response['data'] : array();
			foreach ( $data as $item ) {
				if ( is_array( $item ) ) {
					$items[] = $item;
				}
			}

			$pagination = is_array( $response ) ? ( $response['meta']['pagination'] ?? null ) : null;
			$has_next   = is_array( $pagination ) ? ! empty( $pagination['has_next'] ) : count( $data ) >= $per_page;

			if ( ! $has_next ) {
				return $items;
			}
		}

		$summary['errors'][] = 'List committees did not finish within 50 pages';

		return null;
	}

	/**
	 * Committees that exist in Upbeat and are live in Loop but received no
	 * roster this run (every member left, or none are local), so they are due
	 * to be archived. Matched by unique id, so a committee without one is never
	 * touched.
	 *
	 * @param array<string, array<string, mixed>> $index           Upbeat committee index.
	 * @param array<string, mixed>                $rosters         Rosters built this run, keyed by name.
	 * @param array<int, array<string, mixed>>    $loop_committees Committees Loop holds.
	 * @return array<int, array{unique_id: string, name: string}>
	 */
	private function committees_without_local_members( array $index, array $rosters, array $loop_committees ): array {
		$live = array();
		foreach ( $loop_committees as $item ) {
			if ( empty( $item['is_archived'] ) && '' !== (string) ( $item['unique_id'] ?? '' ) ) {
				$live[ (string) $item['unique_id'] ] = true;
			}
		}

		$committees = array();
		foreach ( $index as $name => $entry ) {
			$name      = (string) $name;
			$unique_id = (string) $entry['unique_id'];
			if ( '' === $unique_id || isset( $rosters[ $name ] ) ) {
				continue;
			}
			if ( isset( $live[ $unique_id ] ) ) {
				$committees[] = array(
					'unique_id' => $unique_id,
					'name'      => $name,
				);
			}
		}

		return $committees;
	}

	/**
	 * Committee names that member activities mention but the committee list does
	 * not. Names only, so the result is safe to log.
	 *
	 * @param array<string, mixed>                $rosters Rosters built this run, keyed by name.
	 * @param array<string, array<string, mixed>> $index   Upbeat committee index.
	 * @return string[]
	 */
	private function unlisted_committee_names( array $rosters, array $index ): array {
		$names = array();
		foreach ( array_keys( $rosters ) as $name ) {
			if ( ! isset( $index[ $name ] ) ) {
				$names[] = (string) $name;
			}
		}

		return $names;
	}

	/**
	 * Archives Loop committee channels that no longer exist upstream. Every
	 * IMK-managed committee present in Loop whose unique_id is not the Upbeat
	 * unique id of a current committee is archived (hidden), so a committee
	 * removed upstream stops appearing in Loop. Skipped in dry-run (logged only).
	 *
	 * @param array<string, array<string, mixed>> $index           Current Upbeat committee index (keyed by name).
	 * @param array<int, array<string, mixed>>    $loop_committees Every committee Loop holds, all pages.
	 * @param array<string, mixed>                $summary         Run summary, by reference.
	 * @return void
	 */
	private function archive_removed_committees( array $index, array $loop_committees, array &$summary ): void {
		// The unique_id of every current Upbeat committee. A name shared by
		// several committees is not synced, but its ids are still current.
		$current = array();
		foreach ( $index as $entry ) {
			foreach ( $entry['unique_ids'] as $unique_id ) {
				$current[] = $unique_id;
			}
		}

		$items = $loop_committees;

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

			$this->archive_committee( $uid, 'removed upstream', $summary );
		}
	}

	/**
	 * Archives one Loop committee channel (hidden) by unique id, or logs it in
	 * dry run. Counts it in the summary's `archived` total and records a failure
	 * in its errors. The caller adds any count specific to its reason.
	 *
	 * @param string               $unique_id Upbeat committee unique id, the channel's key in Loop.
	 * @param string               $reason    Why it is archived, for the log.
	 * @param array<string, mixed> $summary   Run summary, by reference.
	 * @return bool True when archived or, in dry run, would be; false when it failed or archiving is unavailable.
	 */
	private function archive_committee( string $unique_id, string $reason, array &$summary ): bool {
		if ( ! function_exists( 'agend_apps_loop_archive_committee' ) ) {
			return false;
		}

		if ( Agend_Loop_Sync_Settings::is_dry_run() ) {
			Agend_Loop_Sync_Logger::info(
				'Dry run: would archive committee',
				array(
					'unique_id' => $unique_id,
					'reason'    => $reason,
				)
			);
			++$summary['archived'];
			return true;
		}

		$res = agend_apps_loop_archive_committee( $unique_id );
		if ( is_wp_error( $res ) ) {
			$summary['errors'][] = sprintf( 'Archive %s: %s', $unique_id, $res->get_error_message() );
			Agend_Loop_Sync_Logger::error(
				'Committee archive failed',
				array(
					'unique_id' => $unique_id,
					'reason'    => $reason,
					'error'     => $res->get_error_message(),
				)
			);
			return false;
		}

		++$summary['archived'];
		Agend_Loop_Sync_Logger::info(
			'Committee archived',
			array(
				'unique_id' => $unique_id,
				'reason'    => $reason,
			)
		);

		return true;
	}

	/**
	 * Reads the committee list once (every page, see fetch_committees()) to index
	 * committee metadata for filtering and each committee's Upbeat unique id.
	 *
	 * A name shared by several committees is marked ambiguous with an empty
	 * unique_id, since activities join by name and cannot tell them apart. Its
	 * ids stay in unique_ids so they still count as current.
	 *
	 * @return array<string, array<string, mixed>>|null name => { allow_on_web, groups, unique_id, unique_ids, ambiguous }; null when Upbeat could not be read in full.
	 */
	private function build_committee_index(): ?array {
		$index      = array();
		$ambiguous  = array();
		$committees = $this->fetch_committees();
		if ( null === $committees ) {
			return null;
		}

		foreach ( $committees as $committee ) {
			if ( ! is_object( $committee ) || ! method_exists( $committee, 'get_name' ) ) {
				continue;
			}

			$name      = trim( (string) $committee->get_name() );
			$unique_id = method_exists( $committee, 'get_unique_id' ) ? trim( (string) $committee->get_unique_id() ) : '';

			if ( isset( $index[ $name ] ) ) {
				if ( ! $index[ $name ]['ambiguous'] ) {
					$index[ $name ]['ambiguous'] = true;
					$index[ $name ]['unique_id'] = '';
					$ambiguous[]                 = $name;
				}
				if ( '' !== $unique_id ) {
					$index[ $name ]['unique_ids'][] = $unique_id;
				}
				continue;
			}

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
				'unique_id'    => $unique_id,
				'unique_ids'   => '' === $unique_id ? array() : array( $unique_id ),
				'ambiguous'    => false,
			);
		}

		if ( array() !== $ambiguous ) {
			Agend_Loop_Sync_Logger::warning(
				'Committees sharing a name cannot be told apart; they will not be synced',
				array( 'committees' => $ambiguous )
			);
		}

		return $index;
	}

	/**
	 * Reads every committee from Upbeat, one small page at a time.
	 *
	 * Upbeat pages its responses, so one unpaged call returns only the first
	 * page, and a truncated list must never read as complete: a committee on a
	 * later page would look unlisted, and archiving would act on a partial
	 * picture. Pages are small because /committees embeds each committee's full
	 * roster, so a large page can run past the HTTP timeout. They are sorted by
	 * name because that order stays stable while rosters change (Upbeat rejects a
	 * sort on the unique id). The membership plugin caches each page under its own
	 * URL and returns an empty collection for a failed call, so an empty page
	 * after one that promised more is a failure, not the end of the list.
	 *
	 * Returns null when completeness cannot be shown: a page that is not a
	 * collection, an empty page after one that reported more records, the page cap
	 * reached with more still reported, or a reported total that differs from the
	 * committees collected. An empty first page is an empty list, as before.
	 *
	 * @return object[]|null Committees in Upbeat's order; null when Upbeat could not be read in full.
	 */
	private function fetch_committees(): ?array {
		$api        = $this->api();
		$page_size  = $this->committee_page_size();
		$committees = array();
		$seen       = array();
		$totals     = array();

		for ( $page = 1; $page <= self::COMMITTEE_MAX_PAGES; $page++ ) {
			$collection = $api->get_committees( (string) $page_size, (string) $page, 'name', 'asc' );
			if ( ! is_object( $collection ) || ! method_exists( $collection, 'get_results' ) ) {
				return null;
			}

			$rows = (array) $collection->get_results();

			// Only a page that reported more records leads here, so an empty one
			// is a failed read rather than the end of the list.
			if ( $page > 1 && array() === $rows ) {
				return null;
			}

			// A committee seen on an earlier page is the same committee, not a
			// second one sharing its name, so it must not turn up as ambiguous.
			$page_ids = array();
			foreach ( $rows as $committee ) {
				$unique_id = is_object( $committee ) && method_exists( $committee, 'get_unique_id' ) ? trim( (string) $committee->get_unique_id() ) : '';
				if ( '' !== $unique_id ) {
					if ( isset( $seen[ $unique_id ] ) ) {
						continue;
					}
					$page_ids[ $unique_id ] = true;
				}
				$committees[] = $committee;
			}
			$seen += $page_ids;

			$total = method_exists( $collection, 'get_total_record_count' ) ? (int) $collection->get_total_record_count() : 0;
			if ( $total > 0 ) {
				$totals[] = $total;
			}

			if ( method_exists( $collection, 'get_more_records' ) && $collection->get_more_records() ) {
				continue;
			}

			// Every page that reports a total must agree with what was collected,
			// so a committee skipped or repeated across a page boundary (the list
			// changed mid-walk) fails the read instead of passing as complete.
			foreach ( $totals as $reported ) {
				if ( count( $committees ) !== $reported ) {
					return null;
				}
			}

			return $committees;
		}

		// Still more records after the cap: the list is incomplete.
		return null;
	}

	/**
	 * Committees requested per Upbeat page (see COMMITTEE_PAGE_SIZE), never below 1.
	 *
	 * @return int
	 */
	private function committee_page_size(): int {
		return max( 1, (int) apply_filters( 'agend_loop_sync_committee_page_size', self::COMMITTEE_PAGE_SIZE ) );
	}

	/**
	 * Reads every committee activity row Upbeat holds for one member.
	 *
	 * The first call is exactly the unpaged one the membership plugin's own
	 * features make, so they share one cache entry. Only when Upbeat says more
	 * records exist are further pages fetched, by page number alone: leaving the
	 * page size unset keeps it Upbeat's default, the same as the first page, so
	 * the pages line up.
	 *
	 * Returns null when any page failed or the page cap is reached with more
	 * records reported, since a missing page may hold a committee the member is
	 * still on. Callers must treat that as a failed lookup, never as a member
	 * with fewer committees.
	 *
	 * @param string $membership_number Upbeat membership number.
	 * @return object[]|null Activity rows across all pages; null when the lookup failed or was incomplete.
	 */
	private function fetch_member_activities( string $membership_number ): ?array {
		$api        = $this->api();
		$collection = $api->get_member_committee_activities( $membership_number );
		if ( ! is_object( $collection ) || ! method_exists( $collection, 'get_results' ) ) {
			return null;
		}

		$rows = (array) $collection->get_results();

		for ( $page = 2; method_exists( $collection, 'get_more_records' ) && $collection->get_more_records(); $page++ ) {
			// The first page counts towards the cap.
			if ( $page > self::ACTIVITY_MAX_PAGES ) {
				return null;
			}

			$collection = $api->get_member_committee_activities( $membership_number, '', (string) $page );
			if ( ! is_object( $collection ) || ! method_exists( $collection, 'get_results' ) ) {
				return null;
			}

			$rows = array_merge( $rows, (array) $collection->get_results() );
		}

		return $rows;
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

		if ( null === $this->api() ) {
			$report['error'] = 'Membership API unavailable';
			return $report;
		}

		$report['available']     = true;
		$users                   = $this->get_member_users();
		$report['members_total'] = count( $users );

		foreach ( $users as $user ) {
			$membership_number = trim( (string) get_user_meta( $user->ID, 'imk_membership_number', true ) );
			if ( '' === $membership_number ) {
				continue;
			}

			// A lookup that failed or could not read every page shows no
			// committees: this report is read-only and acts on nothing.
			$rows = $this->fetch_member_activities( $membership_number ) ?? array();

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
	 * Read-only committee catalogue from the full committee list: committee
	 * names, unique ids, web flag, groups, raw activity count, and the distinct
	 * role strings (the mapping vocabulary). Does NOT resolve members: Upbeat
	 * returns contact names rather than ids, so member resolution is done
	 * per-member by the sync and by scan_members(), not here.
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

		if ( null === $this->api() ) {
			$preview['error'] = 'Membership API unavailable';
			return $preview;
		}

		$preview['available'] = true;

		$committees = $this->fetch_committees();
		if ( null === $committees ) {
			// Show the failure rather than a partial catalogue that reads as complete.
			$preview['error'] = 'Could not read the full committee list from Upbeat';
			return $preview;
		}

		$preview['total_committees'] = count( $committees );

		foreach ( $committees as $committee ) {
			if ( ! is_object( $committee ) || ! method_exists( $committee, 'get_name' ) ) {
				continue;
			}

			$name      = (string) $committee->get_name();
			$unique_id = method_exists( $committee, 'get_unique_id' ) ? trim( (string) $committee->get_unique_id() ) : '';
			$allow     = method_exists( $committee, 'get_allow_on_web' ) ? (bool) $committee->get_allow_on_web() : true;

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
				'unique_id'    => $unique_id,
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
		// Stored in an option on every run: keep a large membership's unresolved
		// ids from bloating it.
		$total = count( $summary['unresolved_external_ids'] );
		if ( $total > 100 ) {
			$summary['unresolved_external_ids']       = array_slice( $summary['unresolved_external_ids'], 0, 100 );
			$summary['unresolved_external_ids_total'] = $total;
		}

		update_option( Agend_Loop_Sync_Settings::OPT_LAST_SYNC, time(), false );
		update_option( Agend_Loop_Sync_Settings::OPT_LAST_SUMMARY, $summary, false );

		return $summary;
	}
}

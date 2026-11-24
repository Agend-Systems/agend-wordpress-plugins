<?php
/**
 * Shared gateway helpers for the committee and community rule syncs.
 *
 * @package Agend_Loop_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The parts of a roster sync that do not depend on where the roster came from:
 * reading the gateway's reply, recording sync holds, listing and archiving
 * Loop channels, and trimming the stored run summary.
 *
 * Both runners use it: the committee sync (rosters from Upbeat committees) and
 * the community rule sync (rosters from membership rules). All methods are
 * static; this class is never instantiated.
 */
class Agend_Loop_Sync_Gateway {

	/**
	 * Prefix of the unique id of every channel the community rule sync owns. The
	 * committee sync never reads, updates or archives a channel with this prefix.
	 */
	const RULE_PREFIX = 'rule:';

	/**
	 * Most members the gateway accepts in one roster.
	 */
	const ROSTER_MAX = 2000;

	/**
	 * The kinds of sync hold Loop reports: a Loop administrator's emergency
	 * removal or role cap, or a member who left a channel that lets members
	 * leave. Anything else is recorded as 'unknown'.
	 *
	 * @var string[]
	 */
	const HOLD_KINDS = array( 'removal', 'role_cap', 'member_left' );

	/**
	 * The channel roles a role cap can name. Anything else is dropped.
	 *
	 * @var string[]
	 */
	const HOLD_ROLES = array( 'owner', 'admin', 'moderator', 'member' );

	/**
	 * Longest hold reason kept. The reason is free text an administrator typed in
	 * Loop, and it lands in the stored run summary, the log and the member picker.
	 */
	const HOLD_REASON_MAX = 500;

	/**
	 * Most held members kept in the stored run summary, so a channel with many
	 * holds cannot bloat the option. The total is always kept.
	 */
	const HOLD_RESULTS_KEPT = 100;

	/**
	 * Most held members written to the log for one channel.
	 */
	const HOLD_RESULTS_LOGGED = 25;

	/**
	 * Most unresolved external ids kept in the stored run summary.
	 */
	const UNRESOLVED_KEPT = 100;

	/**
	 * Whether a Loop channel unique id belongs to the community rule sync.
	 *
	 * @param string $unique_id The channel's unique id.
	 * @return bool
	 */
	public static function is_rule_channel( string $unique_id ): bool {
		return 0 === strpos( $unique_id, self::RULE_PREFIX );
	}

	/**
	 * Returns local WordPress users that carry a membership number.
	 *
	 * @return WP_User[]
	 */
	public static function member_users(): array {
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
	 * The HTTP status a gateway call failed with.
	 *
	 * Apps Core carries it as `status_code` in the WP_Error data, on the
	 * gateway's own refusals and on the ones it makes locally for a key that is
	 * known to lack a scope, so both read the same.
	 *
	 * @param WP_Error $error The failed call.
	 * @return int The status, or 0 when the error carries none (a transport failure).
	 */
	public static function error_status( $error ): int {
		$data = $error->get_error_data();

		return is_array( $data ) && isset( $data['status_code'] ) ? (int) $data['status_code'] : 0;
	}

	/**
	 * A gateway value as a string, or '' when it is not a scalar.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function scalar_string( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Reads one hold as the gateway reports it: { external_id, kind, role_cap,
	 * reason, held_since }. Pure.
	 *
	 * @param mixed $hold One entry of the gateway's `held` or `holds` list.
	 * @return array<string, mixed>|null external_id (int), kind ('removal', 'role_cap', 'member_left' or 'unknown'), role_cap (a channel role, '' when none), reason (capped) and held_since (as sent); null when the entry names no member.
	 */
	public static function normalise_hold( $hold ): ?array {
		if ( ! is_array( $hold ) ) {
			return null;
		}

		if ( ! is_scalar( $hold['external_id'] ?? null ) ) {
			return null;
		}

		$external_id = (int) $hold['external_id'];
		if ( $external_id <= 0 ) {
			return null;
		}

		$kind     = self::scalar_string( $hold['kind'] ?? '' );
		$role_cap = self::scalar_string( $hold['role_cap'] ?? '' );
		$reason   = trim( self::scalar_string( $hold['reason'] ?? '' ) );

		if ( mb_strlen( $reason ) > self::HOLD_REASON_MAX ) {
			$reason = mb_substr( $reason, 0, self::HOLD_REASON_MAX ) . '...';
		}

		return array(
			'external_id' => $external_id,
			'kind'        => in_array( $kind, self::HOLD_KINDS, true ) ? $kind : 'unknown',
			'role_cap'    => in_array( $role_cap, self::HOLD_ROLES, true ) ? $role_cap : '',
			'reason'      => $reason,
			'held_since'  => self::scalar_string( $hold['held_since'] ?? '' ),
		);
	}

	/**
	 * Records the members the gateway skipped or capped because of a sync hold:
	 * a Loop administrator removed or capped them, or the member left the
	 * channel themselves.
	 *
	 * The gateway reports `held` on every roster sync, `[]` when nothing is
	 * held. A response without it comes from a gateway that does not know about
	 * holds, and is read as no holds.
	 *
	 * Logs membership numbers, the kind, the role cap, the reason and the date,
	 * never a name or an email.
	 *
	 * @param string               $label   Channel name, for the summary and log.
	 * @param array<string, mixed> $data    The gateway's response data.
	 * @param array<string, mixed> $summary Run summary, by reference.
	 * @param string               $kind    What the channel is, 'committee' or 'rule'. Keys the summary entry and words the log.
	 * @return int How many held members this channel reported.
	 */
	public static function record_holds( string $label, array $data, array &$summary, string $kind = 'committee' ): int {
		$held = is_array( $data['held'] ?? null ) ? $data['held'] : array();

		$total = 0;
		foreach ( $held as $entry ) {
			$hold = self::normalise_hold( $entry );
			if ( null === $hold ) {
				continue;
			}

			++$total;
			++$summary['held_members'];
			$summary['held_results'][] = array( $kind => $label ) + $hold;

			if ( $total <= self::HOLD_RESULTS_LOGGED ) {
				Agend_Loop_Sync_Logger::info(
					'member_left' === $hold['kind']
						? ucfirst( $kind ) . ' member left the channel in Loop'
						: ucfirst( $kind ) . ' member held by a Loop administrator',
					array(
						$kind         => $label,
						'external_id' => $hold['external_id'],
						'kind'        => $hold['kind'],
						'role_cap'    => $hold['role_cap'],
						'held_since'  => $hold['held_since'],
					)
				);
			}
		}

		if ( $total > self::HOLD_RESULTS_LOGGED ) {
			Agend_Loop_Sync_Logger::info(
				'More held members than the log lists',
				array(
					$kind    => $label,
					'held'   => $total,
					'logged' => self::HOLD_RESULTS_LOGGED,
				)
			);
		}

		return $total;
	}

	/**
	 * Reads the reconciliation counts the gateway returns for one roster and adds
	 * them to the run summary.
	 *
	 * The apps-core client may return the full envelope or the unwrapped data;
	 * the caller passes the data. A gateway without roster replace mode ignores
	 * `mode` and returns none of the counts, so removals silently never happen;
	 * that is surfaced as an error on the summary.
	 *
	 * @param array<string, mixed> $data    The gateway's response data.
	 * @param array<string, mixed> $summary Run summary, by reference.
	 * @return array{applied:int, skipped:int, removed:int, unresolved:int[]} What this roster reported.
	 */
	public static function apply_response( array $data, array &$summary ): array {
		$applied    = (int) ( $data['applied'] ?? 0 );
		$skipped    = (int) ( $data['skipped'] ?? 0 );
		$removed    = (int) ( $data['removed'] ?? 0 );
		$unresolved = is_array( $data['skipped_external_ids'] ?? null ) ? $data['skipped_external_ids'] : array();

		$not_confirmed = 'Gateway did not confirm replace mode; departed members are not being removed';
		if ( 'replace' === $summary['mode'] && ! array_key_exists( 'removed', $data ) && ! in_array( $not_confirmed, $summary['errors'], true ) ) {
			$summary['errors'][] = $not_confirmed;
		}

		// Without these counts the member totals stay at zero, which would read
		// as "nothing happened". Record whether the gateway actually reported them.
		if ( array_key_exists( 'applied', $data ) ) {
			$summary['member_counts_reported'] = true;
		}

		$summary['applied_members'] += $applied;
		$summary['skipped_members'] += $skipped;
		$summary['removed_members'] += $removed;

		$ids = array();
		foreach ( $unresolved as $ext_id ) {
			$ids[]                                = (int) $ext_id;
			$summary['unresolved_external_ids'][] = (int) $ext_id;
		}

		return array(
			'applied'    => $applied,
			'skipped'    => $skipped,
			'removed'    => $removed,
			'unresolved' => $ids,
		);
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
	public static function fetch_loop_committees( array &$summary ): ?array {
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
	 * Archives one Loop channel (hidden) by unique id, or logs it in dry run.
	 * Counts it in the summary's `archived` total and records a failure in its
	 * errors. The caller adds any count specific to its reason.
	 *
	 * @param string               $unique_id The channel's key in Loop.
	 * @param string               $reason    Why it is archived, for the log.
	 * @param array<string, mixed> $summary   Run summary, by reference.
	 * @param string               $kind      What the channel is, 'committee' or 'rule channel', for the log.
	 * @return bool True when archived or, in dry run, would be; false when it failed or archiving is unavailable.
	 */
	public static function archive_channel( string $unique_id, string $reason, array &$summary, string $kind = 'committee' ): bool {
		if ( ! function_exists( 'agend_apps_loop_archive_committee' ) ) {
			return false;
		}

		if ( Agend_Loop_Sync_Settings::is_dry_run() ) {
			Agend_Loop_Sync_Logger::info(
				'Dry run: would archive ' . $kind,
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
				ucfirst( $kind ) . ' archive failed',
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
			ucfirst( $kind ) . ' archived',
			array(
				'unique_id' => $unique_id,
				'reason'    => $reason,
			)
		);

		return true;
	}

	/**
	 * Caps the parts of a run summary that can grow with the membership, since
	 * the summary is stored in an option on every run.
	 *
	 * @param array<string, mixed> $summary Run summary.
	 * @return array<string, mixed> The trimmed summary.
	 */
	public static function trim_summary( array $summary ): array {
		$total = count( $summary['unresolved_external_ids'] ?? array() );
		if ( $total > self::UNRESOLVED_KEPT ) {
			$summary['unresolved_external_ids']       = array_slice( $summary['unresolved_external_ids'], 0, self::UNRESOLVED_KEPT );
			$summary['unresolved_external_ids_total'] = $total;
		}

		// The held members; `held_members` keeps the full count.
		if ( count( $summary['held_results'] ?? array() ) > self::HOLD_RESULTS_KEPT ) {
			$summary['held_results'] = array_slice( $summary['held_results'], 0, self::HOLD_RESULTS_KEPT );
		}

		return $summary;
	}
}

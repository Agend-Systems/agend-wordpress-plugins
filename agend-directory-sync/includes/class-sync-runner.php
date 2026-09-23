<?php
/**
 * Shared sync pipeline for Agend Directory Sync.
 *
 * One place that resolves the active source from the registry, fetches from
 * it, transforms with the resolved field map, and (unless dry-run) POSTs to
 * the Agend gateway. Both the admin "Send to Agend" action and the WP-CLI
 * command call this, so the manual and scheduled paths can never drift.
 *
 * No concrete source class is named here (SPEC-DIR-20260731 US-1.1 criterion
 * 5): the source is resolved via Agend_Directory_Sync_Source_Registry, so
 * adding a client source never requires touching this file.
 *
 * Pure orchestration: it owns no I/O of its own beyond delegating to the
 * resolved source and the Agend client, so it is safe to call from a
 * request, from cron, or from the CLI.
 *
 * @package Agend_Directory_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Directory_Sync_Runner' ) ) :
	final class Agend_Directory_Sync_Runner {

		/**
		 * Fetch, transform, and (unless dry-run) send.
		 *
		 * @param int    $max_records     Cap on source rows processed (0 = no cap),
		 *                                applied after fetch and before transform.
		 * @param bool   $dry_run         When true, transform only; do not POST. The
		 *                                transformed listings are returned so callers
		 *                                can preview them.
		 * @param string $timeout_context Passed through to
		 *                                Agend_Directory_Sync_Agend_Client::effective_timeout()
		 *                                for the send. Defaults to 'web', the safer of
		 *                                the two: a caller that forgets to say otherwise
		 *                                gets the capped timeout, not an unbounded one.
		 *                                WP-CLI passes 'cli' explicitly.
		 *
		 * @return array{
		 *     kind: string,
		 *     status: string,
		 *     dry_run: bool,
		 *     source: string,
		 *     fetched: int,
		 *     transformed: int,
		 *     skipped: int,
		 *     skip_reasons: array<string, int>,
		 *     duplicate_external_ids: int,
		 *     dropped_fields: array<string, int>,
		 *     dropped_field_examples: array<string, array<int, array{external_id: string, fullname: string}>>,
		 *     status_counts: array<string, int>,
		 *     external_source: string,
		 *     auto_publish_approved: bool,
		 *     max_records: int,
		 *     pages_fetched: int,
		 *     page_window_truncated: bool,
		 *     listings: array<int, array<string, mixed>>,
		 *     send: array<string, mixed>
		 * }
		 *
		 * @throws RuntimeException When the active source is unavailable, the fetch fails, or the send fails.
		 */
		public static function run( int $max_records = 0, bool $dry_run = false, string $timeout_context = 'web' ): array {
			$external_source = self::resolve_external_source();
			$auto_publish    = self::resolve_auto_publish_approved();
			$field_map       = Agend_Directory_Sync_Field_Map::resolve();

			$source = Agend_Directory_Sync_Source_Registry::active();

			if ( ! $source->is_available() ) {
				throw new RuntimeException( $source->get_unavailable_reason() );
			}

			$contacts = self::cap( $source->fetch_all(), $max_records );

			$transformed = Agend_Directory_Sync_Listing_Transformer::transform_all( $contacts, $field_map, $source );
			$listings    = $transformed['listings'];

			// Rows the source itself dropped before the transformer saw them
			// (e.g. non-JSON-object rows resolved from the Custom HTTP API
			// data path) were still fetched-and-skipped work, so they surface
			// in the run summary as a skip reason (SPEC-DIR-20260731 US-2.1
			// criterion 4). Duck-typed via method_exists so the runner still
			// names no concrete source class (US-1.1 criterion 5); a source
			// without the accessor simply contributes nothing here.
			$fetched      = count( $contacts );
			$skipped      = $transformed['skipped'];
			$skip_reasons = $transformed['skip_reasons'];

			$source_row_skips = method_exists( $source, 'get_skipped_non_associative_count' )
				? (int) $source->get_skipped_non_associative_count()
				: 0;

			if ( $source_row_skips > 0 ) {
				$fetched                            += $source_row_skips;
				$skipped                            += $source_row_skips;
				$skip_reasons['row_not_an_object']   = ( $skip_reasons['row_not_an_object'] ?? 0 ) + $source_row_skips;
			}

			// A paged source can be configured to fetch only part of the set (a
			// FetchXML page window). Surfaced because every downstream count
			// then describes a slice, not the directory: an operator reading
			// "fetched 500" without it would conclude the source had 500 rows.
			$page_window_truncated = method_exists( $source, 'stopped_at_page_limit' )
				&& $source->stopped_at_page_limit();
			$pages_fetched         = method_exists( $source, 'get_pages_fetched' )
				? (int) $source->get_pages_fetched()
				: 0;

			$send_summary = array();
			if ( ! $dry_run && ! empty( $listings ) ) {
				$batch_size = Agend_Directory_Sync_Agend_Client::batch_size();
				$timeout    = Agend_Directory_Sync_Agend_Client::effective_timeout(
					Agend_Directory_Sync_Agend_Client::timeout_seconds(),
					0,
					$timeout_context
				);

				$agend        = new Agend_Directory_Sync_Agend_Client();
				$send_summary = $agend->send_listings( $listings, $external_source, $auto_publish, $batch_size, $timeout );

				// send_listings() makes exactly one call for the whole listings
				// set, so its own batch_index is already run-wide (there is no
				// job restamping it needed a job does); this only adds the
				// run-wide listing_position and external_id an issue does not
				// carry yet, the same way the job does for a browser run, so
				// the CLI's own log lines read the same way.
				$send_summary['http_errors'] = self::stamp_http_error_positions(
					is_array( $send_summary['http_errors'] ?? null ) ? $send_summary['http_errors'] : array(),
					$listings,
					$batch_size
				);
			}

			return array(
				'kind'                   => $dry_run ? 'preview' : 'send',
				'status'                 => 'ok',
				'dry_run'                => $dry_run,
				'source'                 => $source->get_key(),
				'fetched'                => $fetched,
				'transformed'            => count( $listings ),
				'skipped'                => $skipped,
				'skip_reasons'           => $skip_reasons,
				'duplicate_external_ids' => $transformed['duplicate_external_ids'],
				'dropped_fields'         => $transformed['dropped_fields'] ?? array(),
				'dropped_field_examples' => $transformed['dropped_field_examples'] ?? array(),
				'status_counts'          => $transformed['status_counts'] ?? array(),
				'external_source'        => $external_source,
				'auto_publish_approved'  => $auto_publish,
				'max_records'            => $max_records,
				'pages_fetched'          => $pages_fetched,
				'page_window_truncated'  => $page_window_truncated,
				'listings'               => $listings,
				'send'                   => $send_summary,
			);
		}

		/**
		 * Resolve the external_source option, applying the generic default
		 * when unset or blank.
		 */
		public static function resolve_external_source(): string {
			$value = trim( (string) get_option( Agend_Directory_Sync::OPTION_EXTERNAL_SOURCE, '' ) );
			return '' !== $value ? $value : Agend_Directory_Sync::DEFAULT_EXTERNAL_SOURCE;
		}

		/**
		 * Resolve the auto-publish setting, applying the bootstrap default if
		 * the option has never been saved.
		 */
		public static function resolve_auto_publish_approved(): bool {
			$raw = get_option( Agend_Directory_Sync::OPTION_AUTO_PUBLISH_APPROVED, null );
			if ( null === $raw ) {
				return Agend_Directory_Sync::DEFAULT_AUTO_PUBLISH_APPROVED;
			}
			return '1' === (string) $raw;
		}

		/**
		 * @param array<int, array<string, mixed>> $contacts
		 *
		 * @return array<int, array<string, mixed>>
		 */
		private static function cap( array $contacts, int $max_records ): array {
			if ( $max_records <= 0 ) {
				return $contacts;
			}
			return array_slice( $contacts, 0, $max_records );
		}

		/**
		 * Apply Agend_Directory_Sync_Agend_Client::stamp_issue_position() to
		 * every issue in a run's http_errors, using the same $batch_size
		 * chunking send_listings() itself used, so each issue's record index
		 * resolves against the same listing it was reported against.
		 *
		 * Public so it is directly unit-testable against a plain http_errors
		 * array, without standing up the full fetch/transform pipeline
		 * run() otherwise requires.
		 *
		 * @param array<int, array<string, mixed>> $http_errors
		 * @param array<int, array<string, mixed>> $listings
		 *
		 * @return array<int, array<string, mixed>>
		 */
		public static function stamp_http_error_positions( array $http_errors, array $listings, int $batch_size ): array {
			$chunks = array_chunk( $listings, max( 1, $batch_size ) );

			foreach ( $http_errors as &$http_error ) {
				if ( ! is_array( $http_error['issues'] ?? null ) ) {
					continue;
				}

				$batch_index = (int) ( $http_error['batch_index'] ?? 0 );
				$batch       = $chunks[ $batch_index ] ?? array();

				$http_error['issues'] = array_map(
					static function ( array $issue ) use ( $batch_index, $batch, $batch_size ): array {
						return Agend_Directory_Sync_Agend_Client::stamp_issue_position( $issue, $batch_index, $batch, $batch_size );
					},
					$http_error['issues']
				);
			}
			unset( $http_error );

			return $http_errors;
		}
	}
endif;

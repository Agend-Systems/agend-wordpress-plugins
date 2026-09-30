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

			$child_list_attach = self::attach_child_lists( $contacts, $field_map, $source );
			$contacts           = $child_list_attach['contacts'];

			$transformed = Agend_Directory_Sync_Listing_Transformer::transform_all( $contacts, $field_map, $source );
			$listings    = $transformed['listings'];

			$listings_with_child_list = self::count_listings_with_child_list( $listings, $field_map );

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

				// Same run-wide stamping, for a row error's own field-level
				// issues (error.fields) rather than a batch-level 400's.
				$send_summary['error_examples'] = self::stamp_error_example_positions(
					is_array( $send_summary['error_examples'] ?? null ) ? $send_summary['error_examples'] : array(),
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
				'child_rows_fetched'         => $child_list_attach['summary']['child_rows_fetched'],
				'child_rows_without_parent'  => $child_list_attach['summary']['child_rows_without_parent'],
				'listings_with_child_list'   => $listings_with_child_list,
				'listings'               => $listings,
				'send'                   => $send_summary,
			);
		}

		/**
		 * Fetch every configured child list (SPEC-DIR-20260930-directory-item-list-field
		 * US-2.1) and attach each entry's rows, grouped by parent asset id, to
		 * the asset contact whose configured id column matches
		 * (`Agend_Directory_Sync_Listing_Transformer::CHILD_ROWS_KEY`), so
		 * `transform_all()` can build the item list and its aggregates purely
		 * from the contact it already has.
		 *
		 * The asset's own id is resolved through `field_map['core']['external_id']`
		 * (the same configured source `Agend_Directory_Sync_Listing_Transformer`
		 * uses to build each listing's external_id), not a literal column
		 * name: a non-PCA Dataverse configuration names its asset id column
		 * differently, and this file is the field map's runner, not a
		 * PCA-specific one.
		 *
		 * A no-op (returns `$contacts` unchanged) when there are no configured
		 * child lists, or the active source has no `fetch_child_list()` method
		 * (child lists are Dataverse-only; US-2.1 "Out of Scope"). Duck-typed
		 * via `method_exists()`, matching how this class already tests for
		 * `get_pages_fetched()` etc., so this file still names no concrete
		 * source class.
		 *
		 * Only the item field sources and the aggregate sources (plus their
		 * `where` sources) are kept per reduced row, not the whole child row,
		 * to keep memory bounded on a large child set.
		 *
		 * @param array<int, array<string, mixed>>                                                                   $contacts
		 * @param array{core?: array{external_id?: string}, child_lists?: array<int, array{target: string, entity_set: string, fetch_xml: string, parent_key: string, items: array<string,string>, aggregates: array<int, array<string,mixed>>}>} $field_map
		 *
		 * @return array{contacts: array<int, array<string, mixed>>, summary: array{child_rows_fetched: int, child_rows_without_parent: int}}
		 */
		private static function attach_child_lists( array $contacts, array $field_map, Agend_Directory_Sync_Source $source ): array {
			$child_lists = is_array( $field_map['child_lists'] ?? null ) ? $field_map['child_lists'] : array();

			if ( empty( $child_lists ) || ! method_exists( $source, 'fetch_child_list' ) ) {
				return array(
					'contacts' => $contacts,
					'summary'  => array( 'child_rows_fetched' => 0, 'child_rows_without_parent' => 0 ),
				);
			}

			// Resolved from the configured core external_id source -- the same
			// source the transformer uses to build each listing's own
			// external_id -- rather than a literal PCA column name, so a
			// non-PCA Dataverse configuration (a different asset id key) joins
			// correctly too. An unconfigured external_id source resolves every
			// asset id to '', so every child row is counted as an orphan
			// rather than silently matching on a column that does not exist.
			$asset_id_source = (string) ( $field_map['core']['external_id'] ?? '' );

			$assets_by_id = array();
			foreach ( $contacts as $index => $contact ) {
				$raw_asset_id = '' !== $asset_id_source
					? Agend_Directory_Sync_Path_Resolver::resolve( $contact, $asset_id_source )
					: null;
				$asset_id     = self::normalize_guid( (string) ( is_scalar( $raw_asset_id ) ? $raw_asset_id : '' ) );
				if ( '' !== $asset_id ) {
					$assets_by_id[ $asset_id ][] = $index;
				}
			}

			$rows_fetched = 0;
			$orphans      = 0;

			foreach ( $child_lists as $entry ) {
				$target     = (string) ( $entry['target'] ?? '' );
				$entity_set = (string) ( $entry['entity_set'] ?? '' );
				$parent_key = (string) ( $entry['parent_key'] ?? '' );
				$fetch_xml  = (string) ( $entry['fetch_xml'] ?? '' );

				if ( '' === $target || '' === $entity_set || '' === $parent_key || '' === $fetch_xml ) {
					continue;
				}

				foreach ( array_keys( $contacts ) as $index ) {
					$contacts[ $index ][ Agend_Directory_Sync_Listing_Transformer::CHILD_ROWS_KEY ][ $target ] = array();
				}

				$rows          = $source->fetch_child_list( $entity_set, $fetch_xml );
				$rows_fetched += count( $rows );
				$keep_keys     = self::child_row_keys( $entry );

				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}

					$normalized = self::normalize_guid( (string) Agend_Directory_Sync_Path_Resolver::resolve( $row, $parent_key ) );

					if ( '' === $normalized || ! isset( $assets_by_id[ $normalized ] ) ) {
						$orphans++;
						continue;
					}

					$reduced = array();
					foreach ( $keep_keys as $key ) {
						if ( array_key_exists( $key, $row ) ) {
							$reduced[ $key ] = $row[ $key ];
						}
					}

					foreach ( $assets_by_id[ $normalized ] as $index ) {
						$contacts[ $index ][ Agend_Directory_Sync_Listing_Transformer::CHILD_ROWS_KEY ][ $target ][] = $reduced;
					}
				}
			}

			return array(
				'contacts' => $contacts,
				'summary'  => array(
					'child_rows_fetched'        => $rows_fetched,
					'child_rows_without_parent' => $orphans,
				),
			);
		}

		/**
		 * The exact top-level row keys one child list entry's items and
		 * aggregates actually read, so `attach_child_lists()` can discard
		 * everything else from a raw child row before holding onto it.
		 *
		 * @param array{items?: array<string,string>, aggregates?: array<int, array<string,mixed>>} $entry
		 *
		 * @return array<int, string>
		 */
		private static function child_row_keys( array $entry ): array {
			$keys = array_values( is_array( $entry['items'] ?? null ) ? $entry['items'] : array() );

			foreach ( is_array( $entry['aggregates'] ?? null ) ? $entry['aggregates'] : array() as $aggregate ) {
				if ( '' !== (string) ( $aggregate['source'] ?? '' ) ) {
					$keys[] = $aggregate['source'];
				}
				if ( '' !== (string) ( $aggregate['where_source'] ?? '' ) ) {
					$keys[] = $aggregate['where_source'];
				}
			}

			return array_values( array_unique( $keys ) );
		}

		/**
		 * Normalise a Dataverse GUID for parent/child matching: braces
		 * stripped, lowercased, trimmed. `''` when the input has no GUID-shaped
		 * content at all.
		 */
		private static function normalize_guid( string $value ): string {
			return strtolower( trim( trim( $value ), '{}' ) );
		}

		/**
		 * Count listings whose custom_fields hold a non-empty array for at
		 * least one configured child list target (SPEC-DIR-20260930-directory-item-list-field
		 * US-2.1 AC12).
		 *
		 * @param array<int, array<string, mixed>>            $listings
		 * @param array{child_lists?: array<int, array{target: string}>} $field_map
		 */
		private static function count_listings_with_child_list( array $listings, array $field_map ): int {
			$targets = array_values(
				array_filter(
					array_map(
						static function ( array $entry ): string {
							return (string) ( $entry['target'] ?? '' );
						},
						is_array( $field_map['child_lists'] ?? null ) ? $field_map['child_lists'] : array()
					)
				)
			);

			if ( empty( $targets ) ) {
				return 0;
			}

			$count = 0;
			foreach ( $listings as $listing ) {
				$custom_fields = is_array( $listing['custom_fields'] ?? null ) ? $listing['custom_fields'] : array();
				foreach ( $targets as $target ) {
					if ( ! empty( $custom_fields[ $target ] ) ) {
						$count++;
						break;
					}
				}
			}

			return $count;
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

		/**
		 * Same stamping as stamp_http_error_positions(), for a row error's own
		 * field-level issues (`fields`, from the gateway's per-row `error.
		 * fields`) rather than a batch-level 400's `issues`. Each error
		 * example already carries the batch_index its row was reported
		 * against (run-wide here, since send_listings() makes one call for
		 * the whole listings set); this only adds the run-wide
		 * listing_position and external_id each field issue does not carry
		 * yet.
		 *
		 * Public so it is directly unit-testable against a plain
		 * error_examples array, without standing up the full fetch/transform
		 * pipeline run() otherwise requires.
		 *
		 * @param array<int, array<string, mixed>> $error_examples
		 * @param array<int, array<string, mixed>> $listings
		 *
		 * @return array<int, array<string, mixed>>
		 */
		public static function stamp_error_example_positions( array $error_examples, array $listings, int $batch_size ): array {
			$chunks = array_chunk( $listings, max( 1, $batch_size ) );

			foreach ( $error_examples as &$example ) {
				if ( ! is_array( $example['fields'] ?? null ) ) {
					continue;
				}

				$batch_index = (int) ( $example['batch_index'] ?? 0 );
				$batch       = $chunks[ $batch_index ] ?? array();

				$example['fields'] = array_map(
					static function ( array $issue ) use ( $batch_index, $batch, $batch_size ): array {
						return Agend_Directory_Sync_Agend_Client::stamp_issue_position( $issue, $batch_index, $batch, $batch_size );
					},
					$example['fields']
				);
			}
			unset( $example );

			return $error_examples;
		}
	}
endif;

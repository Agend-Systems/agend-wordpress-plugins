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
		 * @param int  $max_records Cap on source rows processed (0 = no cap),
		 *                          applied after fetch and before transform.
		 * @param bool $dry_run     When true, transform only; do not POST. The
		 *                          transformed listings are returned so callers
		 *                          can preview them.
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
		 *     listings: array<int, array<string, mixed>>,
		 *     send: array<string, mixed>
		 * }
		 *
		 * @throws RuntimeException When the active source is unavailable, the fetch fails, or the send fails.
		 */
		public static function run( int $max_records = 0, bool $dry_run = false ): array {
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

			$send_summary = array();
			if ( ! $dry_run && ! empty( $listings ) ) {
				$agend        = new Agend_Directory_Sync_Agend_Client();
				$send_summary = $agend->send_listings( $listings, $external_source, $auto_publish );
			}

			return array(
				'kind'                   => $dry_run ? 'preview' : 'send',
				'status'                 => 'ok',
				'dry_run'                => $dry_run,
				'source'                 => $source->get_key(),
				'fetched'                => count( $contacts ),
				'transformed'            => count( $listings ),
				'skipped'                => $transformed['skipped'],
				'skip_reasons'           => $transformed['skip_reasons'],
				'duplicate_external_ids' => $transformed['duplicate_external_ids'],
				'dropped_fields'         => $transformed['dropped_fields'] ?? array(),
				'dropped_field_examples' => $transformed['dropped_field_examples'] ?? array(),
				'status_counts'          => $transformed['status_counts'] ?? array(),
				'external_source'        => $external_source,
				'auto_publish_approved'  => $auto_publish,
				'max_records'            => $max_records,
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
	}
endif;

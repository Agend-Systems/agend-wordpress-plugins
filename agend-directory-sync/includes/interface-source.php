<?php
/**
 * Data source contract for Agend Directory Sync.
 *
 * A source is anything that can hand the sync pipeline an array of source
 * rows to transform and upsert. The runner never names a concrete source
 * class: it resolves the active source from
 * Agend_Directory_Sync_Source_Registry and calls only this interface, so
 * adding a client source is one class plus configuration rather than a
 * pipeline fork (SPEC-DIR-20260731 Decision 2.1).
 *
 * @package Agend_Directory_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'Agend_Directory_Sync_Source' ) ) :
	interface Agend_Directory_Sync_Source {

		/**
		 * Stable machine key for this source (e.g. `upbeat`, `http_api`). Used
		 * to persist and resolve the active source via the
		 * `agend_directory_sync_source` option.
		 */
		public function get_key(): string;

		/**
		 * Human-readable label for the admin source selector.
		 */
		public function get_label(): string;

		/**
		 * Whether this source is currently usable (its dependencies / required
		 * configuration are present). When false, `get_unavailable_reason()`
		 * must return a non-blank reason.
		 */
		public function is_available(): bool;

		/**
		 * The reason this source is unavailable, for the admin page and the
		 * run failure message. Empty string when the source is available.
		 */
		public function get_unavailable_reason(): string;

		/**
		 * Fetch every source row as an array of associative arrays.
		 *
		 * @return array<int, array<string, mixed>>
		 *
		 * @throws RuntimeException When the fetch fails.
		 */
		public function fetch_all(): array;

		/**
		 * Build this source's contribution to a transformed listing's
		 * `external_metadata` block for one contact row (SPEC-DIR-20260731
		 * Decision 2.7). Keeps the transformer source-neutral: each source
		 * owns the shape of the metadata it wants recorded against a synced
		 * listing.
		 *
		 * @param array<string, mixed>  $contact  Source contact row.
		 * @param array<string, string> $core_map Resolved core field map (target key => source field/path).
		 *
		 * @return array<string, mixed>
		 */
		public function get_external_metadata( array $contact, array $core_map ): array;
	}
endif;

<?php
/**
 * WP-CLI command for Agend Directory Sync.
 *
 * Exposes the same fetch -> transform -> send pipeline the admin "Send to
 * Agend" button uses, so the sync can run unattended from the server's cron:
 *
 *   wp agend-directory-sync run
 *
 * Add a crontab entry that cd's to the WordPress root and runs the command on
 * the cadence you want (see README.md for a copy-paste example). Running from
 * real cron means the sync does not depend on site traffic the way WP-Cron
 * does.
 *
 * This file is only loaded under WP-CLI; the command class is never defined in
 * a web request.
 *
 * @package Agend_Directory_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

if ( ! class_exists( 'Agend_Directory_Sync_CLI_Command' ) ) :
	/**
	 * Run the Upbeat -> Agend directory sync from the command line.
	 */
	final class Agend_Directory_Sync_CLI_Command {

		/**
		 * Fetch members from the source, transform them, and push to the Agend
		 * directory.
		 *
		 * ## OPTIONS
		 *
		 * [--max=<number>]
		 * : Cap the number of source rows processed this run, applied after the
		 *   fetch and before transforming. Useful for a small verification run.
		 *   0 or omitted means process all rows.
		 *
		 * [--dry-run]
		 * : Fetch and transform only; do not POST anything to Agend. Reports the
		 *   counts that a real run would produce.
		 *
		 * [--secondary-filter=<fetchxml>]
		 * : Microsoft Dataverse source only. A FetchXML <filter> (or single
		 *   <condition>) fragment applied on top of the saved query for this
		 *   run, replacing the saved secondary filter, so a grouped upload can
		 *   be scripted one group at a time. The saved query is not changed.
		 *
		 * ## EXAMPLES
		 *
		 *     wp agend-directory-sync run
		 *     wp agend-directory-sync run --max=50 --dry-run
		 *     wp agend-directory-sync run --secondary-filter='<condition attribute="pca_membergroup" operator="eq" value="Region North" />'
		 *
		 * @param array<int, string>    $args       Positional args (unused).
		 * @param array<string, string> $assoc_args Associative args.
		 *
		 * @return void
		 */
		public function run( $args, $assoc_args ): void {
			$max     = isset( $assoc_args['max'] ) ? max( 0, (int) $assoc_args['max'] ) : 0;
			$dry_run = isset( $assoc_args['dry-run'] );

			if ( $dry_run ) {
				WP_CLI::log( 'Dry run: fetching and transforming only, nothing will be sent.' );
			}

			if ( isset( $assoc_args['secondary-filter'] ) ) {
				$this->apply_secondary_filter( (string) $assoc_args['secondary-filter'] );
			}

			try {
				$summary = Agend_Directory_Sync_Runner::run( $max, $dry_run );
			} catch ( Throwable $e ) {
				WP_CLI::error( 'Sync failed: ' . $e->getMessage() );
				return;
			}

			if ( ! empty( $summary['page_window_truncated'] ) ) {
				WP_CLI::warning(
					sprintf(
						'Partial fetch: stopped after %d page(s) because of the configured page window, with more records available at the source.',
						(int) ( $summary['pages_fetched'] ?? 0 )
					)
				);
			}

			WP_CLI::log( sprintf( 'Fetched: %d', (int) $summary['fetched'] ) );
			WP_CLI::log( sprintf( 'Transformed: %d', (int) $summary['transformed'] ) );
			WP_CLI::log( sprintf( 'Skipped: %d', (int) $summary['skipped'] ) );

			$this->log_counter_map( 'Skip reasons', $summary['skip_reasons'] ?? array() );
			$this->log_counter_map( 'Listing status', $summary['status_counts'] ?? array() );
			$this->log_counter_map( 'Dropped fields', $summary['dropped_fields'] ?? array() );

			if ( $dry_run ) {
				WP_CLI::success( 'Dry run complete. Nothing was sent.' );
				return;
			}

			$send = is_array( $summary['send'] ?? null ) ? $summary['send'] : array();

			if ( empty( $send ) ) {
				WP_CLI::warning( 'Nothing to send after filtering; no listings were uploaded.' );
				return;
			}

			WP_CLI::log( sprintf( 'external_source: %s', (string) $summary['external_source'] ) );
			WP_CLI::log( sprintf( 'auto_publish_approved: %s', ! empty( $summary['auto_publish_approved'] ) ? 'on' : 'off' ) );
			WP_CLI::log( sprintf( 'Batches: %d', (int) ( $send['batches'] ?? 0 ) ) );
			WP_CLI::log( sprintf( 'Created: %d', (int) ( $send['created'] ?? 0 ) ) );
			WP_CLI::log( sprintf( 'Updated: %d', (int) ( $send['updated'] ?? 0 ) ) );
			WP_CLI::log( sprintf( 'Errored: %d', (int) ( $send['errored'] ?? 0 ) ) );

			$http_errors = is_array( $send['http_errors'] ?? null ) ? $send['http_errors'] : array();
			$errored     = (int) ( $send['errored'] ?? 0 );

			if ( ! empty( $http_errors ) ) {
				WP_CLI::warning( sprintf( '%d batch(es) failed at the HTTP level; see the gateway response.', count( $http_errors ) ) );
			}

			if ( $errored > 0 ) {
				WP_CLI::warning( sprintf( 'Completed with %d per-row error(s).', $errored ) );
				return;
			}

			WP_CLI::success(
				sprintf(
					'Sync complete: %d created, %d updated.',
					(int) ( $send['created'] ?? 0 ),
					(int) ( $send['updated'] ?? 0 )
				)
			);
		}

		/**
		 * Route a --secondary-filter fragment to the Dataverse source for this
		 * run through its filter hook, after validating it the way the settings
		 * form does so a typo fails here with the parser's message rather than
		 * as a Dataverse 400 mid-run.
		 *
		 * The override replaces the saved fragment even when blank, which is
		 * how a script asks for an unfiltered run against a site whose saved
		 * settings carry a filter: `--secondary-filter=''`.
		 */
		private function apply_secondary_filter( string $fragment ): void {
			$fragment = trim( $fragment );

			if ( '' !== $fragment && ! Agend_Directory_Sync_Dataverse_Source::is_valid_secondary_filter( $fragment ) ) {
				WP_CLI::error( '--secondary-filter must be a valid FetchXML <filter> or <condition> element.' );
				return;
			}

			$active = Agend_Directory_Sync_Source_Registry::active()->get_key();
			if ( Agend_Directory_Sync_Dataverse_Source::SOURCE_KEY !== $active ) {
				WP_CLI::warning( sprintf( '--secondary-filter applies to the Microsoft Dataverse source only; the active source is "%s", so it has no effect on this run.', $active ) );
			}

			add_filter(
				Agend_Directory_Sync_Dataverse_Source::SECONDARY_FILTER_HOOK,
				static function () use ( $fragment ): string {
					return $fragment;
				}
			);

			WP_CLI::log( '' !== $fragment ? 'Secondary filter: applied from --secondary-filter.' : 'Secondary filter: cleared for this run by --secondary-filter.' );
		}

		/**
		 * Log a counter map (reason/status => count) as one indented line each.
		 *
		 * @param string             $heading
		 * @param array<string, int> $map
		 */
		private function log_counter_map( string $heading, array $map ): void {
			if ( empty( $map ) ) {
				return;
			}
			WP_CLI::log( $heading . ':' );
			foreach ( $map as $key => $count ) {
				WP_CLI::log( sprintf( '  %s: %d', (string) $key, (int) $count ) );
			}
		}
	}
endif;

WP_CLI::add_command( 'agend-directory-sync', 'Agend_Directory_Sync_CLI_Command' );

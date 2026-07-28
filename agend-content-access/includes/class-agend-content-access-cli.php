<?php
/**
 * WP-CLI commands.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agend Content Access command line tools.
 */
class Agend_Content_Access_CLI {

	/**
	 * Reports migrated protected files whose public original is still served.
	 *
	 * Performs no deletion (SPEC-CMS-20260727 US-5.3 criterion 5). Removing a
	 * file on the strength of a URL probe would be irreversible, and a false
	 * positive would destroy something legitimately public. The judgement stays
	 * with a person; re-running clears the flag once they have acted.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. `table` for review, `json` for machine consumption.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp agend-content-access audit-originals
	 *     wp agend-content-access audit-originals --format=json
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function audit_originals( $args, $assoc_args ): void {
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		$result = Agend_Content_Access_Originals_Audit::audit(
			Agend_Content_Access_Originals_Audit::collect(),
			array( 'Agend_Content_Access_Originals_Audit', 'is_reachable' )
		);

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $result ) );
		} else {
			WP_CLI::line( Agend_Content_Access_Originals_Audit::render_text( $result ) );
		}

		// A non-zero exit on findings, so a pipeline gating on this stops
		// rather than printing a warning nobody reads.
		if ( $result['flagged'] > 0 ) {
			WP_CLI::halt( 1 );
		}
	}
}

// Registered as an explicit subcommand rather than by exposing the class.
// WP-CLI derives a subcommand name from the method name verbatim, so exposing
// the class would publish `audit_originals` while every example here, and the
// README, say `audit-originals`. Naming it once, here, keeps the documented
// command and the real one from drifting apart.
WP_CLI::add_command(
	'agend-content-access audit-originals',
	array( 'Agend_Content_Access_CLI', 'audit_originals' )
);

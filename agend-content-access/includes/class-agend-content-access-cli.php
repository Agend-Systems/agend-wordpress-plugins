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

	/**
	 * Flags every post whose document carries section-level rules.
	 *
	 * Core search, automatic excerpts and other readers of `post_content` see the
	 * text of every section a page builder wrote into it, restricted ones
	 * included. Posts that carry section rules are kept out of search by a flag
	 * that is set when they are saved. This sets it for posts saved before it
	 * existed, in batches, from the first post, so it can be run again.
	 *
	 * The same work runs on its own, a hundred posts at a time, when an
	 * administrator loads wp-admin. Run this to finish it at once, or after
	 * installing an integration for another editor. When it finishes the
	 * background pass is marked done.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<number>]
	 * : Posts to examine per pass.
	 * ---
	 * default: 100
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp agend-content-access backfill-fragment-flags
	 *     wp agend-content-access backfill-fragment-flags --batch-size=500
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function backfill_fragment_flags( $args, $assoc_args ): void {
		$batch = isset( $assoc_args['batch-size'] )
			? max( 1, (int) $assoc_args['batch-size'] )
			: Agend_Content_Access_Fragment_Rules::BACKFILL_BATCH;

		$total = Agend_Content_Access_Fragment_Rules::backfill_all(
			$batch,
			static function ( array $pass ): void {
				WP_CLI::log(
					sprintf(
						'Examined up to post %d (%d in this pass, %d flagged).',
						$pass['last_id'],
						$pass['processed'],
						$pass['flagged']
					)
				);
			}
		);

		WP_CLI::success(
			sprintf(
				'%d posts examined, %d carry section-level rules.',
				$total['processed'],
				$total['flagged']
			)
		);
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

WP_CLI::add_command(
	'agend-content-access backfill-fragment-flags',
	array( 'Agend_Content_Access_CLI', 'backfill_fragment_flags' )
);

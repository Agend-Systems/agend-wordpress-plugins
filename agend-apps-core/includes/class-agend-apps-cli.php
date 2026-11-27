<?php
/**
 * WP-CLI commands for Agend Apps Core.
 *
 * This is the FIRST WP-CLI command this repository ships (scope item 6), so
 * it establishes the pattern the sibling plugins can follow: one class per
 * plugin, registered from the bootstrap behind `defined( 'WP_CLI' ) && WP_CLI`,
 * never required or loaded on a web request.
 *
 * `wp agend-apps scrub-secrets` is the operator-facing "other half" of the
 * clone-detection gate in `includes/connect-site.php`
 * ({@see agend_apps_connect_site_moved()}): that gate stands the SAML link
 * flow down when a cloned site's stamped `site_url()` no longer matches, but
 * it does NOT revoke anything -- the clone's copied Agend gateway API key
 * keeps working for every other gateway call this plugin makes until an
 * operator actually deletes it. This command is that deletion.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the plan {@see Agend_Apps_CLI::scrub_secrets()} executes: which
 * options to delete, the per-user-data advisory, and the IdP-side advisory or
 * chain decision, given only the two facts the decision actually depends on.
 *
 * Kept PURE and separate from the `WP_CLI`-calling method above it precisely
 * so this decision can be unit tested without a real `WP_CLI` runtime -- see
 * this file's header and `phpcs.xml.dist`/`phpunit.xml.dist`'s
 * `failOnWarning`/`failOnRisky`, which is why every other pure/thin split in
 * this codebase ({@see agend_apps_connect_preflight()} and its thin
 * `WP_User_Query` wrappers, for one) exists.
 *
 * `--include-idp` combined with the IdP class being ABSENT is the one case
 * this function reports as a failure (`ok => false`): silently succeeding
 * would tell an operator the signing key was scrubbed when nothing of the
 * kind happened, on a site that never had one to scrub in the first place.
 *
 * @param bool $idp_class_exists Whether `WP_SAML_IDP_CLI` is loaded (i.e. the
 *                                 sibling `agend-saml-idp` plugin is active
 *                                 and ships its own scrub command).
 * @param bool $include_idp      Whether the operator passed `--include-idp`.
 * @return array{
 *     options_to_delete: string[],
 *     user_meta_to_delete: string[],
 *     per_user_notice: string,
 *     idp_advisory: string,
 *     should_chain_idp: bool,
 *     ok: bool
 * }
 */
function agend_apps_cli_scrub_secrets_plan( bool $idp_class_exists, bool $include_idp ): array {
	$options_to_delete = array(
		Agend_Apps_Secret_Store::OPTION_SECRETS,
		AGEND_APPS_CONNECT_OPTION,
	);

	// Literal fallbacks: neither class is guaranteed to be loaded where this
	// pure function runs.
	$user_meta_to_delete = array(
		class_exists( 'Agend_Apps_Member_Session' ) ? Agend_Apps_Member_Session::META_KEY : '_agend_apps_member_session',
		class_exists( 'Agend_Apps_Token_Worker' ) ? Agend_Apps_Token_Worker::META_KEY : '_agend_apps_sso_token',
	);

	$per_user_notice = __(
		'Every member\'s stored Agend session and cached SSO token were deleted from this database. They were deleted, not revoked: on a copy of a live site they are the live site\'s own sessions, and revoking them would sign those members out of the live site. A refresh token cannot be re-derived, so a copy that keeps one holds a working credential. Per-user link state is kept, because it holds no credential and is re-derived on the next request.',
		'agend-apps-core'
	);

	if ( $include_idp && ! $idp_class_exists ) {
		return array(
			'options_to_delete'   => $options_to_delete,
			'user_meta_to_delete' => $user_meta_to_delete,
			'per_user_notice'     => $per_user_notice,
			'idp_advisory'      => __(
				'--include-idp was passed, but the agend-saml-idp plugin is not active here, so there is no "wp saml-idp scrub-secrets" command to chain. Nothing on the IdP side was touched.',
				'agend-apps-core'
			),
			'should_chain_idp'  => false,
			'ok'                => false,
		);
	}

	if ( $include_idp ) {
		return array(
			'options_to_delete'   => $options_to_delete,
			'user_meta_to_delete' => $user_meta_to_delete,
			'per_user_notice'     => $per_user_notice,
			'idp_advisory'      => __(
				'Chaining "wp saml-idp scrub-secrets" to also clear the SAML signing key and IdP entity id.',
				'agend-apps-core'
			),
			'should_chain_idp'  => true,
			'ok'                => true,
		);
	}

	$idp_advisory = $idp_class_exists
		? __(
			'The SAML signing key and IdP entity id live in the agend-saml-idp plugin and are NOT scrubbed by this command. Run "wp saml-idp scrub-secrets" as well (or re-run this command with --include-idp).',
			'agend-apps-core'
		)
		: __(
			'The agend-saml-idp plugin is not active here, so there is no SAML signing key to scrub on this site.',
			'agend-apps-core'
		);

	return array(
		'options_to_delete'   => $options_to_delete,
		'user_meta_to_delete' => $user_meta_to_delete,
		'per_user_notice'     => $per_user_notice,
		'idp_advisory'      => $idp_advisory,
		'should_chain_idp'  => false,
		'ok'                => true,
	);
}

/**
 * Why `wp agend-apps backfill-external-ids` must not run on this site, or an
 * empty string when it may.
 *
 * Outside `wordpress` sign-in mode, or with an external id meta key
 * configured, {@see agend_apps_ensure_external_id()} resolves the configured
 * value and mints nothing, so the command would report success while doing
 * nothing.
 *
 * @return string The refusal message, or "" when the backfill may run.
 */
function agend_apps_cli_backfill_refusal(): string {
	if ( ! Agend_Apps_Settings::wordpress_idp_enabled() ) {
		return __( 'This site is not in WordPress account sign-in mode, so members are not identified by a minted external id. Nothing was changed.', 'agend-apps-core' );
	}

	if ( ! agend_apps_guid_external_id_active() ) {
		return __( 'A member ID meta key is configured on Settings > Identity and SSO, so members are identified by that key, not a minted external id. Nothing was changed.', 'agend-apps-core' );
	}

	return '';
}

/**
 * Gives every user a minted external id, one page of user ids at a time.
 *
 * Pure apart from {@see agend_apps_ensure_external_id()}: the caller supplies
 * the pages, so the unit suite can drive it without `WP_User_Query`. Paging
 * stops at the first page shorter than `$batch_size`.
 *
 * A user who already has a GUID is counted and left alone. A user who
 * resolves an external id from somewhere else (the default
 * `imk_membership_number` meta, or the `agend_apps_current_user_external_id`
 * filter) is counted as `other_id` and also left alone: minting would not
 * change what the plugin resolves them on, and the IdP, asserting the GUID,
 * sends an empty NameID for them. Otherwise a dry run counts them as
 * would-mint and writes nothing; a real run mints through
 * {@see agend_apps_ensure_external_id()}, which never overwrites and returns
 * the winner's value on a concurrent mint, so a re-run is safe and mints
 * nothing. A user whose GUID is stored as an empty value cannot be minted
 * for, because that never overwrites, and is counted as failed.
 *
 * @param callable $fetch_page Takes a 1-based page number and the page size,
 *                             returns that page's user ids.
 * @param bool     $dry_run    Count only; write nothing.
 * @param int      $batch_size Users per page (at least 1).
 * @return array{scanned: int, existing: int, other_id: int, minted: int, failed: int}
 *         `minted` counts would-mint users on a dry run.
 */
function agend_apps_cli_backfill_external_ids( callable $fetch_page, bool $dry_run, int $batch_size ): array {
	$batch_size = max( 1, $batch_size );
	$counts     = array(
		'scanned'  => 0,
		'existing' => 0,
		'other_id' => 0,
		'minted'   => 0,
		'failed'   => 0,
	);

	for ( $page = 1; ; $page++ ) {
		$user_ids = (array) $fetch_page( $page, $batch_size );

		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;
			++$counts['scanned'];

			if ( $user_id <= 0 ) {
				++$counts['failed'];
				continue;
			}

			if ( '' !== (string) get_user_meta( $user_id, AGEND_APPS_EXTERNAL_ID_META, true ) ) {
				++$counts['existing'];
				continue;
			}

			if ( '' !== agend_apps_user_external_id( $user_id ) ) {
				++$counts['other_id'];
				continue;
			}

			if ( $dry_run ) {
				++$counts['minted'];
				continue;
			}

			if ( '' === agend_apps_ensure_external_id( $user_id ) ) {
				++$counts['failed'];
				continue;
			}

			++$counts['minted'];
		}

		if ( count( $user_ids ) < $batch_size ) {
			break;
		}
	}

	return $counts;
}

/**
 * What `wp agend-apps backfill-external-ids` prints for a set of counts.
 *
 * Pure, like {@see agend_apps_cli_scrub_secrets_plan()}, so the wording and
 * the outcome are tested without a `WP_CLI` runtime. Counts only: never an
 * email, user id or external id.
 *
 * @param array{scanned: int, existing: int, other_id: int, minted: int, failed: int} $counts  From {@see agend_apps_cli_backfill_external_ids()}.
 * @param bool                                                                     $dry_run Whether nothing was written.
 * @return array{lines: string[], warnings: string[], error: string, success: string}
 *         `error` is "" unless the command must exit non-zero.
 */
function agend_apps_cli_backfill_report( array $counts, bool $dry_run ): array {
	$lines = array(
		/* translators: %d: number of users checked. */
		sprintf( __( 'Users checked: %d', 'agend-apps-core' ), $counts['scanned'] ),
		/* translators: %d: number of users who already had a GUID. */
		sprintf( __( 'Already had a GUID: %d', 'agend-apps-core' ), $counts['existing'] ),
		/* translators: %d: number of users identified by another external id. */
		sprintf( __( 'Identified by another id, not given a GUID: %d', 'agend-apps-core' ), $counts['other_id'] ),
	);

	if ( $dry_run ) {
		/* translators: %d: number of users who would be given a GUID. */
		$lines[] = sprintf( __( 'Would mint: %d', 'agend-apps-core' ), $counts['minted'] );
	} else {
		/* translators: %d: number of users given a GUID. */
		$lines[] = sprintf( __( 'Minted: %d', 'agend-apps-core' ), $counts['minted'] );
	}

	/* translators: %d: number of users who could not be given a GUID. */
	$lines[] = sprintf( __( 'Failed: %d', 'agend-apps-core' ), $counts['failed'] );

	$warnings = array();

	if ( $counts['other_id'] > 0 ) {
		$warnings[] = __( 'Some users are identified by another external id (the imk_membership_number user meta, or the agend_apps_current_user_external_id filter), so they were not given a GUID. An IdP asserting the GUID as NameID sends an empty NameID for them, and they cannot be linked.', 'agend-apps-core' );
	}

	$error = '';

	if ( $counts['failed'] > 0 ) {
		$error = __( 'Some users could not be given a GUID. A user whose _agend_apps_external_id user meta is stored empty is never overwritten: delete that empty value, then run the command again.', 'agend-apps-core' );
	}

	if ( $dry_run ) {
		$success = __( 'Dry run: nothing was written.', 'agend-apps-core' );
	} elseif ( $counts['other_id'] > 0 ) {
		$success = __( 'Backfill complete. Every user not identified by another id has a GUID.', 'agend-apps-core' );
	} else {
		$success = __( 'Backfill complete. Every user has a GUID.', 'agend-apps-core' );
	}

	return array(
		'lines'    => $lines,
		'warnings' => $warnings,
		'error'    => $error,
		'success'  => $success,
	);
}

/**
 * One page of this site's user ids, in id order, for
 * {@see agend_apps_cli_backfill_external_ids()}.
 *
 * Thin `WP_User_Query` wrapper, kept out of the unit suite like
 * {@see agend_apps_connect_nameid_empty_count()}. Clears the runtime object
 * cache first, when the object cache supports it, so a large site's user meta
 * does not pile up across pages. Pages by offset: a user deleted during the
 * run shifts the next page, so a later user can be missed until the next run.
 *
 * @param int $page       1-based page number.
 * @param int $batch_size Users per page.
 * @return int[]
 */
function agend_apps_cli_backfill_fetch_page( int $page, int $batch_size ): array {
	if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
		wp_cache_flush_runtime();
	}

	$query = new WP_User_Query(
		array(
			'fields'      => 'ID',
			'orderby'     => 'ID',
			'order'       => 'ASC',
			'number'      => $batch_size,
			'paged'       => $page,
			'count_total' => false,
		)
	);

	return array_map( 'intval', (array) $query->get_results() );
}

if ( ! class_exists( 'Agend_Apps_CLI' ) ) :
	/**
	 * `wp agend-apps <command>` -- registered only in a WP-CLI context, from
	 * `agend-apps-core.php`'s `defined( 'WP_CLI' ) && WP_CLI` guard.
	 */
	class Agend_Apps_CLI {

		/**
		 * Deletes this site's encrypted Agend gateway API key and its stored
		 * "Connect this site" connection record (including the `site_url`
		 * stamp {@see agend_apps_connect_site_moved()} reads), so a cloned
		 * copy of this site's database no longer carries live credentials or
		 * presents itself as already connected.
		 *
		 * ## OPTIONS
		 *
		 * [--include-idp]
		 * : Also chain "wp saml-idp scrub-secrets" to clear the SAML signing
		 * key and IdP entity id the agend-saml-idp plugin holds. When the
		 * plugin is not active, this exits non-zero rather than silently
		 * succeeding.
		 *
		 * [--yes]
		 * : Skip the confirmation prompt. Standard WP-CLI convention --
		 * handled automatically by `WP_CLI::confirm()` below.
		 *
		 * ## EXAMPLES
		 *
		 *     wp agend-apps scrub-secrets
		 *     wp agend-apps scrub-secrets --include-idp
		 *     wp agend-apps scrub-secrets --yes
		 *
		 * @param array $args       Positional arguments (unused).
		 * @param array $assoc_args Associative arguments: `include-idp`, `yes`.
		 */
		public function scrub_secrets( array $args, array $assoc_args ): void {
			try {
				$include_idp = ! empty( $assoc_args['include-idp'] );

				WP_CLI::confirm(
					__( 'This permanently deletes this site\'s stored Agend gateway API key, its connection record, and every member\'s stored Agend session and SSO token. Continue?', 'agend-apps-core' ),
					$assoc_args
				);

				$plan = agend_apps_cli_scrub_secrets_plan( class_exists( 'WP_SAML_IDP_CLI' ), $include_idp );

				foreach ( $plan['options_to_delete'] as $option_name ) {
					delete_option( $option_name );
					// translators: %s: the deleted option name.
					WP_CLI::log( sprintf( __( 'Deleted option: %s', 'agend-apps-core' ), $option_name ) );
				}

				foreach ( $plan['user_meta_to_delete'] as $meta_key ) {
					delete_metadata( 'user', 0, $meta_key, '', true );
					// translators: %s: the deleted user meta key.
					WP_CLI::log( sprintf( __( 'Deleted user meta for every user: %s', 'agend-apps-core' ), $meta_key ) );
				}

				WP_CLI::log( $plan['per_user_notice'] );

				if ( $plan['should_chain_idp'] ) {
					WP_CLI::log( $plan['idp_advisory'] );
					WP_CLI::runcommand( 'saml-idp scrub-secrets' );
				} elseif ( ! $plan['ok'] ) {
					// WP_CLI::error() halts the command with a non-zero exit
					// code; the explicit return is only for readability here,
					// since real WP-CLI never returns control past this call.
					WP_CLI::error( $plan['idp_advisory'] );
					return;
				} else {
					WP_CLI::log( $plan['idp_advisory'] );
				}

				WP_CLI::success( __( 'Agend Apps secrets scrubbed.', 'agend-apps-core' ) );
			} catch ( Throwable $e ) {
				WP_CLI::error( $e->getMessage() );
			}
		}

		/**
		 * Gives every existing user a minted Agend external id (GUID).
		 *
		 * Users created before this site switched to WordPress account
		 * sign-in mode have none, so they cannot be linked to Agend, and the
		 * IdP asserts an empty NameID for them once it asserts the GUID.
		 * Never changes an existing value, so it is safe to run again.
		 * Refuses unless the site is in WordPress account sign-in mode with
		 * no member ID meta key configured. Prints counts only.
		 *
		 * ## OPTIONS
		 *
		 * [--dry-run]
		 * : Report how many users would get an external id, and write nothing.
		 *
		 * ## EXAMPLES
		 *
		 *     wp agend-apps backfill-external-ids --dry-run
		 *     wp agend-apps backfill-external-ids
		 *
		 * @param array $args       Positional arguments (unused).
		 * @param array $assoc_args Associative arguments: `dry-run`.
		 */
		public function backfill_external_ids( array $args, array $assoc_args ): void {
			$refusal = agend_apps_cli_backfill_refusal();

			if ( '' !== $refusal ) {
				WP_CLI::error( $refusal );
				return;
			}

			$dry_run = ! empty( $assoc_args['dry-run'] );
			$counts  = agend_apps_cli_backfill_external_ids( 'agend_apps_cli_backfill_fetch_page', $dry_run, 500 );
			$report  = agend_apps_cli_backfill_report( $counts, $dry_run );

			foreach ( $report['lines'] as $line ) {
				WP_CLI::log( $line );
			}

			foreach ( $report['warnings'] as $warning ) {
				WP_CLI::warning( $warning );
			}

			if ( '' !== $report['error'] ) {
				WP_CLI::error( $report['error'] );
				return;
			}

			WP_CLI::success( $report['success'] );
		}
	}
endif;

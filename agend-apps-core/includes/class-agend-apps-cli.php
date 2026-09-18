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

	$per_user_notice = __(
		'Per-user link state and cached tokens are NOT deleted by this command. Those are per-user rows a clone will simply re-derive on its next request, so deleting them across every user on this site would be a destructive mass operation this command deliberately does not perform.',
		'agend-apps-core'
	);

	if ( $include_idp && ! $idp_class_exists ) {
		return array(
			'options_to_delete' => $options_to_delete,
			'per_user_notice'   => $per_user_notice,
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
			'options_to_delete' => $options_to_delete,
			'per_user_notice'   => $per_user_notice,
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
		'options_to_delete' => $options_to_delete,
		'per_user_notice'   => $per_user_notice,
		'idp_advisory'      => $idp_advisory,
		'should_chain_idp'  => false,
		'ok'                => true,
	);
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
					__( 'This permanently deletes this site\'s stored Agend gateway API key and connection record. Continue?', 'agend-apps-core' ),
					$assoc_args
				);

				$plan = agend_apps_cli_scrub_secrets_plan( class_exists( 'WP_SAML_IDP_CLI' ), $include_idp );

				foreach ( $plan['options_to_delete'] as $option_name ) {
					delete_option( $option_name );
					// translators: %s: the deleted option name.
					WP_CLI::log( sprintf( __( 'Deleted option: %s', 'agend-apps-core' ), $option_name ) );
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
	}
endif;

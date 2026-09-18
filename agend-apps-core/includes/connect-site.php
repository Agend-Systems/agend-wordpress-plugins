<?php
/**
 * "Connect this site" -- the one-click action that registers this site's SAML
 * service provider with the local IdP plugin (agend-saml-idp) and creates (or
 * finds) the matching SSO connection on the Agend gateway.
 *
 * Every function here is written as pure as the surrounding code allows, so
 * the unit suite can drive the decision logic without a real IdP plugin or a
 * live gateway. Loaded unconditionally (gated on nothing at require time)
 * because the admin page must be able to explain WHY the button is disabled
 * (missing scopes, an old IdP plugin, the wrong sign-in mode) in every sign-in
 * mode, not only `wordpress` mode. Every function that touches the connect
 * flow itself still checks that mode internally.
 *
 * Security findings this file is the fix for (numbered per the review that
 * settled this design):
 * - Finding 5: the gateway connection payload never sends `role_attribute` or
 *   `role_mapping`, so no WordPress role -- administrator included -- maps to
 *   a privileged Agend role. See {@see agend_apps_connect_connection_payload()}.
 * - Finding 10: the IdP service-provider record is created with sha256
 *   signing, both response and assertion signed, and the unspecified NameID
 *   format explicitly, because the IdP's own defaults are sha1/unsigned/
 *   emailAddress. See {@see agend_apps_connect_sp_data()}.
 * The allow-list-despite-the-name trap for `restricted_roles`, and the
 * catch-conflict-and-refetch idempotency instead of an update scope, are
 * documented at {@see agend_apps_connect_member_roles()} and
 * {@see agend_apps_connect_run()} respectively.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option name storing the one connection this site has made, written by
 * {@see agend_apps_connect_store()} and read by {@see agend_apps_connect_stored()}.
 *
 * @var string
 */
const AGEND_APPS_CONNECT_OPTION = 'agend_apps_sso_connection';

/**
 * Derives this site's SP entity id, ACS url, and metadata url, byte-identical
 * to the gateway's own `buildSpUrls` derivation: `{root}/api/auth/sso/{slug}/metadata`,
 * `.../acs`, `.../metadata` (entity id and metadata url share the same path),
 * with any trailing slash stripped from `$root_url` first.
 *
 * The slug is interpolated RAW, deliberately: the gateway does not URL-encode
 * it either, and the entity id this site registers with the IdP is compared
 * as an exact string against the entity id the gateway's OWN connection
 * record carries (see {@see agend_apps_connect_run()} step vi, "authoritative
 * SP urls"). Encoding the slug here would produce a string that no longer
 * matches the gateway's own derivation for the same slug, breaking that
 * comparison for any slug containing a character encoding would touch.
 *
 * @param string $root_url     The Agend root URL (unversioned), e.g. {@see Agend_Apps_Settings::get_root_url()}.
 * @param string $account_slug The connected Agend account slug.
 * @return array{sp_entity_id: string, sp_acs_url: string, sp_metadata_url: string}
 *         All three empty when either input is empty.
 */
function agend_apps_connect_sp_urls( string $root_url, string $account_slug ): array {
	if ( '' === $root_url || '' === $account_slug ) {
		return array(
			'sp_entity_id'    => '',
			'sp_acs_url'      => '',
			'sp_metadata_url' => '',
		);
	}

	$root = rtrim( $root_url, '/' );
	$base = $root . '/api/auth/sso/' . $account_slug;

	return array(
		'sp_entity_id'    => $base . '/metadata',
		'sp_acs_url'      => $base . '/acs',
		'sp_metadata_url' => $base . '/metadata',
	);
}

/**
 * Derives the deterministic slug this site's SSO connection is created under:
 * `wp-saml-` followed by the first 16 hex characters of the SHA-256 hash of
 * the IdP entity id.
 *
 * Deterministic on purpose: two concurrent connect attempts (a double click,
 * two admins, a retried request) compute the SAME slug, so it is the
 * account-scoped unique index on the gateway side -- not a read-then-write
 * check on this side -- that actually keeps this to one row. This mirrors the
 * gateway's own retired `deriveWordPressConnectionSlug()` pattern, but the
 * `wp-saml-` prefix deliberately differs from that scheme's `wordpress-`
 * prefix so this can never collide with a legacy `protocol='wordpress'` row
 * registered for the same site under the old naming.
 *
 * Note that the slug is NOT the real idempotency key: {@see agend_apps_connect_run()}
 * matches an existing connection on `idp_entity_id` (see
 * {@see agend_apps_connect_find_existing()}), not on this slug, precisely
 * because a legacy connection for this site can exist under a different slug
 * or protocol and must still be recognised as "already connected".
 *
 * @param string $idp_entity_id This site's SAML IdP entity id.
 * @return string The derived slug, or '' for an empty input.
 */
function agend_apps_connect_connection_slug( string $idp_entity_id ): string {
	if ( '' === $idp_entity_id ) {
		return '';
	}

	return 'wp-saml-' . substr( hash( 'sha256', $idp_entity_id ), 0, 16 );
}

/**
 * The gateway API-key scopes the connect action needs, and no more.
 *
 * Exactly `sso.connections.browse` (to look for an existing connection) and
 * `sso.connections.create` (to make one). Deliberately excludes
 * `sso.connections.update`: {@see agend_apps_connect_run()} never updates an
 * existing connection, it only creates one or finds one already there, so
 * granting an update scope for this action would be broader than the action
 * ever needs.
 *
 * @return string[]
 */
function agend_apps_connect_required_scopes(): array {
	return array( 'sso.connections.browse', 'sso.connections.create' );
}

/**
 * Reports which of {@see agend_apps_connect_required_scopes()} the connected
 * API key does NOT currently hold.
 *
 * When the scope cache has never been successfully populated
 * ({@see Agend_Apps_Key_Scopes::known()} false, or the class is not loaded at
 * all), every required scope is reported as unknown rather than missing: a
 * key that has simply never been checked yet is a different situation from
 * one confirmed to lack a scope, and the admin surface must say so rather
 * than showing a false "missing" state before the first scope fetch
 * completes.
 *
 * @return array{missing: string[], unknown: bool} `missing` lists every
 *         required scope not held (or, when `unknown` is true, every
 *         required scope, since none of them can be confirmed either way).
 */
function agend_apps_connect_missing_scopes(): array {
	$required = agend_apps_connect_required_scopes();

	if ( ! class_exists( 'Agend_Apps_Key_Scopes' ) || ! Agend_Apps_Key_Scopes::known() ) {
		return array(
			'missing' => $required,
			'unknown' => true,
		);
	}

	$missing = array();

	foreach ( $required as $scope ) {
		if ( ! Agend_Apps_Key_Scopes::has( $scope ) ) {
			$missing[] = $scope;
		}
	}

	return array(
		'missing' => $missing,
		'unknown' => false,
	);
}

/**
 * Looks for an SSO connection already registered for a given IdP entity id,
 * by paging `agend_apps_sso_list_connections()`.
 *
 * Matches on `idp_entity_id` rather than on the slug {@see agend_apps_connect_connection_slug()}
 * would derive: that is what makes "already connected" correct even when a
 * legacy connection for this site exists under a different slug or protocol
 * (see that function's docblock). Stops after 20 pages of 100 so a broken or
 * looping pagination response can never hang the connect action.
 *
 * Unwraps the envelope the house way (`$result['data'] ?? $result`), then
 * handles the connections list itself being carried either as
 * `data.connections` or as `data` directly, since callers of the list
 * endpoint have historically seen both shapes depending on which filters ran.
 *
 * @param string $idp_entity_id The SAML IdP entity id to match on.
 * @return array|WP_Error|null The matching connection, `null` when no page
 *         contained a match, or the `WP_Error` from a failed list call.
 */
function agend_apps_connect_find_existing( string $idp_entity_id ) {
	for ( $page = 1; $page <= 20; $page++ ) {
		$result = agend_apps_sso_list_connections(
			array(
				'page'  => $page,
				'limit' => 100,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = ( isset( $result['data'] ) && is_array( $result['data'] ) ) ? $result['data'] : $result;

		if ( isset( $data['connections'] ) && is_array( $data['connections'] ) ) {
			$connections = $data['connections'];
		} elseif ( is_array( $data ) ) {
			$connections = $data;
		} else {
			$connections = array();
		}

		if ( empty( $connections ) ) {
			break;
		}

		foreach ( $connections as $connection ) {
			if (
				is_array( $connection )
				&& isset( $connection['idp_entity_id'] )
				&& $connection['idp_entity_id'] === $idp_entity_id
			) {
				return $connection;
			}
		}
	}

	return null;
}

/**
 * Builds the allow-list of WordPress roles the IdP is told to let sign in via
 * SSO: every registered role except `administrator`.
 *
 * CRITICAL, and counter-intuitive: `restricted_roles` is an ALLOW-list
 * despite its name (`check_user_sso_permissions()` allows the login when the
 * user's roles intersect this list). Two traps this function exists to avoid:
 *
 * 1. Reading it as a deny-list. Listing `administrator` here would allow ONLY
 *    administrators through and block every ordinary member -- the exact
 *    inverse of the intended restriction.
 * 2. Returning an empty list while `role_restriction_enabled` is true. An
 *    empty allow-list allows EVERYONE, so the flag alone restricts nothing.
 *    This function therefore GUARANTEES a non-empty result: `wp_roles()` is
 *    read defensively (`function_exists()`-guarded, since it may not exist in
 *    every runtime this file loads in) and, whether because `wp_roles()` is
 *    unavailable, no roles are registered, or the `agend_apps_connect_member_roles`
 *    filter below returns an empty list, the result falls back to
 *    `array( 'subscriber' )` rather than ever being returned empty.
 *
 * `editor`, `author`, and `contributor` are deliberately left in this
 * allow-list: elevated WordPress roles below `administrator` are not a
 * privilege escalation risk here, because the GATEWAY side is the second line
 * of defence. {@see agend_apps_connect_connection_payload()} sends no role
 * mapping at all, so every asserted member -- regardless of their WordPress
 * role -- lands on the connection's `default_role` (`contact`, the
 * lowest-privilege Agend role). This function's allow-list only ever decides
 * who may attempt SSO at all, never what Agend role they end up with.
 *
 * @return string[] Non-empty list of WordPress role slugs.
 */
function agend_apps_connect_member_roles(): array {
	$roles = array();

	if ( function_exists( 'wp_roles' ) ) {
		foreach ( wp_roles()->get_names() as $slug => $label ) {
			if ( 'administrator' === $slug ) {
				continue;
			}

			$roles[] = (string) $slug;
		}
	}

	if ( empty( $roles ) ) {
		$roles = array( 'subscriber' );
	}

	/**
	 * Filters the WordPress roles allowed to sign in via the Agend SSO
	 * connection. See this function's docblock for the allow-list-despite-
	 * the-name trap and the empty-list trap before overriding this.
	 *
	 * @param string[] $roles Non-empty list of WordPress role slugs.
	 */
	$roles = (array) apply_filters( 'agend_apps_connect_member_roles', $roles );

	if ( empty( $roles ) ) {
		$roles = array( 'subscriber' );
	}

	return array_values( array_map( 'strval', $roles ) );
}

/**
 * Builds the `upsert_service_provider()` payload registering this site's SP
 * with the local IdP plugin.
 *
 * Every security-relevant value here is set EXPLICITLY rather than left to
 * the IdP's own default, because those defaults are exactly the weak
 * settings this connection must not carry (finding 10):
 * - `signature_algorithm` => `sha256`: the IdP defaults to sha1.
 * - `response_signed` and `assertion_signed` => `true`: the IdP defaults both
 *   to unsigned, and `upsert_service_provider()` would reject this payload
 *   outright if neither were true without also setting `allow_insecure`,
 *   which this payload deliberately never sets.
 * - `nameid_format` => the literal `urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified`
 *   URN: the IdP defaults to the `emailAddress` format, which silently
 *   asserts email as the subject instead of the configured NameID attribute.
 *   `persistent` is never used here: its branch in the IdP's `create_name_id()`
 *   ignores `nameid_attribute` entirely.
 * - `allow_unsolicited_sso` => `false`: this connection only ever originates
 *   IdP-initiated flows this plugin itself drives (see
 *   `includes/wp-idp-saml-link.php`), never an unsolicited third-party POST.
 *
 * Takes only `$sp_urls`: every other field is a fixed constant. Nothing here
 * is derived from this site's IdP metadata, because this payload describes the
 * Agend SP (the gateway end of the trip), not the IdP end.
 *
 * @param array $sp_urls {@see agend_apps_connect_sp_urls()}'s return shape.
 * @return array The `upsert_service_provider()` payload.
 */
function agend_apps_connect_sp_data( array $sp_urls ): array {
	return array(
		'name'                  => 'Agend',
		'acs_url'               => (string) ( $sp_urls['sp_acs_url'] ?? '' ),
		'enabled'               => true,
		'assertion_signed'      => true,
		'response_signed'       => true,
		'signature_algorithm'   => 'sha256',
		'nameid_format'         => 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified',
		'allow_unsolicited_sso' => false,
	);
}

/**
 * Builds the `save_attribute_mapping()` payload: which SAML attribute the IdP
 * asserts for the NameID and for each of the standard user fields, plus the
 * group-membership attribute.
 *
 * @param string $nameid_meta_key The user-meta key the IdP should assert as
 *                                 NameID, from {@see agend_apps_connect_nameid_meta_key()}.
 * @return array The `save_attribute_mapping()` payload.
 */
function agend_apps_connect_attribute_mapping( string $nameid_meta_key ): array {
	return array(
		'nameid_attribute' => $nameid_meta_key,
		'user_attributes'  => array(
			'user_email'   => 'email',
			'first_name'   => 'first_name',
			'last_name'    => 'last_name',
			'display_name' => 'display_name',
		),
		'group_mapping'    => array(
			'enabled'        => true,
			'attribute_name' => 'groups',
		),
	);
}

/**
 * Resolves the single external-id source this site's own member population
 * actually resolves on: {@see agend_apps_external_id_meta_key()} when a
 * non-empty value is explicitly CONFIGURED (the `agend_apps_external_id_meta_key`
 * option itself, not that function's own `imk_membership_number` default),
 * otherwise the minted GUID meta {@see AGEND_APPS_EXTERNAL_ID_META} when this
 * site is in `wordpress` sign-in mode.
 *
 * This is the one value that MUST be identical on both sides of the
 * connection: the IdP asserts NameID from this meta key
 * ({@see agend_apps_connect_attribute_mapping()}'s `nameid_attribute`), and
 * {@see agend_apps_user_external_id()} resolves a member's identity from the
 * same chain when checking a link or minting a token. Nothing today keeps
 * those two independently-evolving constants in sync automatically; this
 * function is the single place that decision gets made, so both sides read
 * from it rather than duplicating the choice.
 *
 * @return string The meta key to assert NameID from.
 */
function agend_apps_connect_nameid_meta_key(): string {
	$configured = (string) get_option( 'agend_apps_external_id_meta_key', '' );

	if ( '' !== $configured ) {
		return agend_apps_external_id_meta_key();
	}

	if ( Agend_Apps_Settings::wordpress_idp_enabled() ) {
		return AGEND_APPS_EXTERNAL_ID_META;
	}

	return agend_apps_external_id_meta_key();
}

/**
 * Field name the "Connect this site" form's pre-flight acknowledgement
 * checkbox is submitted under, read by
 * {@see agend_apps_connect_preflight_acknowledged()}.
 *
 * @var string
 */
const AGEND_APPS_CONNECT_ACK_FIELD = 'agend_apps_connect_preflight_ack';

/**
 * Counts WordPress users who resolve an EMPTY value for the given NameID
 * meta key -- either the key is entirely absent for that user, or present but
 * stored as an empty string.
 *
 * THIN `WP_User_Query` WRAPPER, deliberately kept out of the unit suite: this
 * suite runs with no database and no WordPress bootstrap (see this file's
 * header and `phpunit.xml.dist`), so there is no `WP_User_Query` to exercise
 * here. The DECISION this count feeds -- whether the connect action should
 * block -- lives in the pure {@see agend_apps_connect_preflight()}, which
 * takes this count as an argument and is what the unit suite actually tests.
 *
 * WHY this matters: the IdP's `create_name_id()` reads exactly the one meta
 * key configured as `nameid_attribute` with no fallback, and
 * `get_user_field_value()` returns `''` for a missing key, so an empty value
 * here means the member is asserted to the gateway with an EMPTY NameID. The
 * gateway fails an empty subject closed. Left undetected, each such member
 * discovers the problem one at a time, invisibly, inside the hidden iframe
 * this site's silent-link flow drives -- exactly the failure mode this
 * pre-flight exists to turn into one number an operator sees BEFORE
 * connecting, rather than a trickle of unexplained support tickets after.
 *
 * Returns 0 -- rather than throwing or fataling an admin screen -- for an
 * empty `$meta_key`, when `WP_User_Query` is unavailable, or if the query
 * itself throws.
 *
 * @param string $meta_key The user-meta key configured as the NameID source
 *                          (see {@see agend_apps_connect_nameid_meta_key()}).
 * @return int
 */
function agend_apps_connect_nameid_empty_count( string $meta_key ): int {
	$count = 0;

	if ( '' === $meta_key || ! class_exists( 'WP_User_Query' ) ) {
		return agend_apps_connect_filter_nameid_empty_count( $count, $meta_key );
	}

	try {
		$query = new WP_User_Query(
			array(
				'count_total' => true,
				'number'      => 1,
				'fields'      => 'ID',
				'meta_query'  => array(
					'relation' => 'OR',
					array(
						'key'     => $meta_key,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => $meta_key,
						'value'   => '',
						'compare' => '=',
					),
				),
			)
		);

		$count = (int) $query->get_total();
	} catch ( Throwable $e ) {
		$count = 0;
	}

	return agend_apps_connect_filter_nameid_empty_count( $count, $meta_key );
}

/**
 * Applies the `agend_apps_connect_nameid_empty_count` filter.
 *
 * Extracted so EVERY return path of {@see agend_apps_connect_nameid_empty_count()},
 * including its early bail-outs, runs the filter: a site that substitutes its
 * own count must have that substitution honoured whether or not this runtime
 * could have computed one itself. That is also what lets the unit suite, which
 * has no database and therefore no `WP_User_Query`, drive
 * {@see agend_apps_connect_run()} into its blocking branch.
 *
 * @param int    $count    The count this runtime computed (0 when it could not).
 * @param string $meta_key The NameID meta key the count was taken against.
 * @return int
 */
function agend_apps_connect_filter_nameid_empty_count( int $count, string $meta_key ): int {
	/**
	 * Filters how many WordPress users resolve an empty NameID value.
	 *
	 * A large site may prefer to substitute a cheaper or cached count than the
	 * bounded `WP_User_Query` this plugin runs, or to supply one at all on a
	 * runtime where that query is unavailable.
	 *
	 * @param int    $count    The count this plugin computed (0 when it could not).
	 * @param string $meta_key The NameID meta key the count was taken against.
	 */
	return (int) apply_filters( 'agend_apps_connect_nameid_empty_count', $count, $meta_key );
}

/**
 * Counts WordPress users who cannot be linked to Agend SILENTLY once this
 * site connects: those flagged as holding a `credentials`-mode member session,
 * or as an account this integration itself provisioned.
 *
 * THIN `WP_User_Query` WRAPPER, kept out of the unit suite for the same reason
 * as {@see agend_apps_connect_nameid_empty_count()} -- no database in this
 * suite. The DECISION this count feeds is the pure
 * {@see agend_apps_connect_preflight()}, which takes it as an argument and is
 * tested directly.
 *
 * This is an ESTIMATE, not an exact count, and it deliberately over-counts
 * rather than under-counts: the authoritative signal is `has_password` on the
 * Agend auth user, which the gateway computes in `sso_find_user_by_email` and
 * never exposes to this plugin. A member provisioned under `credentials` mode
 * holds an Agend password, and the SAML JIT path refuses to link an asserted
 * email to a password-holding user (`email_link_forbidden`), so that
 * population needs the OTP-verified link route instead of a silent SAML link
 * and CANNOT be migrated silently. Over-counting here is the safe direction;
 * under-counting is not. Never state or imply this number is exact in any
 * copy that shows it.
 *
 * Resolves both meta keys defensively (`class_exists()`/`defined()`, falling
 * back to the literal strings) because `includes/member-identity.php` -- which
 * defines `AGEND_APPS_MANAGED_META` -- only loads in `credentials` mode, and
 * so may not be loaded at all when this runs.
 *
 * Returns 0 -- rather than throwing or fataling an admin screen -- when
 * `WP_User_Query` is unavailable, or if the query itself throws.
 *
 * @return int
 */
function agend_apps_connect_credentials_member_count(): int {
	if ( ! class_exists( 'WP_User_Query' ) ) {
		return agend_apps_connect_filter_credentials_member_count( 0 );
	}

	$session_meta_key = class_exists( 'Agend_Apps_Member_Session' ) ? Agend_Apps_Member_Session::META_KEY : '_agend_apps_member_session';
	$managed_meta_key = defined( 'AGEND_APPS_MANAGED_META' ) ? AGEND_APPS_MANAGED_META : '_agend_apps_managed';

	try {
		$query = new WP_User_Query(
			array(
				'count_total' => true,
				'number'      => 1,
				'fields'      => 'ID',
				'meta_query'  => array(
					'relation' => 'OR',
					array(
						'key'     => $session_meta_key,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => $managed_meta_key,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$count = (int) $query->get_total();
	} catch ( Throwable $e ) {
		$count = 0;
	}

	return agend_apps_connect_filter_credentials_member_count( $count );
}

/**
 * Applies the `agend_apps_connect_credentials_member_count` filter, on every
 * return path of {@see agend_apps_connect_credentials_member_count()}, for the
 * same reasons {@see agend_apps_connect_filter_nameid_empty_count()} documents.
 *
 * @param int $count The count this runtime computed (0 when it could not).
 * @return int
 */
function agend_apps_connect_filter_credentials_member_count( int $count ): int {
	/**
	 * Filters the estimated count of members provisioned under `credentials`
	 * mode, who therefore hold an Agend password and cannot be linked silently.
	 *
	 * A site that knows its own population better than the two local markers
	 * this plugin counts (see the calling function's docblock on why the exact
	 * signal is gateway-side only) can substitute a better figure here.
	 *
	 * @param int $count The count this plugin computed (0 when it could not).
	 */
	return (int) apply_filters( 'agend_apps_connect_credentials_member_count', $count );
}

/**
 * The pure pre-flight gate the connect action consults before doing anything
 * with the IdP or the gateway.
 *
 * When either `$nameid_empty` or `$credentials_members` is `null`, it falls
 * back to the corresponding thin `WP_User_Query` wrapper above -- so
 * production code ({@see agend_apps_connect_run()}) calls this with NO
 * arguments, while the unit suite passes both counts in directly, since the
 * suite has no database to seed a real query against.
 *
 * `credentials_members` NEVER contributes to `blocks`. A non-zero credentials
 * population does not make connecting WRONG, it makes a member-visible
 * OTP-verified link step necessary for that population afterwards -- an
 * accepted cost of this design, not a fault to fix before connecting.
 * Blocking on it would stop a site from ever being able to connect at all,
 * for a condition that has no fix available on the connect screen.
 *
 * @param int|null $nameid_empty        Injected count, or `null` to resolve
 *                                       from {@see agend_apps_connect_nameid_empty_count()}.
 * @param int|null $credentials_members Injected count, or `null` to resolve
 *                                       from {@see agend_apps_connect_credentials_member_count()}.
 * @return array{nameid_empty: int, credentials_members: int, nameid_meta_key: string, blocks: bool}
 */
function agend_apps_connect_preflight( ?int $nameid_empty = null, ?int $credentials_members = null ): array {
	$nameid_meta_key = agend_apps_connect_nameid_meta_key();

	if ( null === $nameid_empty ) {
		$nameid_empty = agend_apps_connect_nameid_empty_count( $nameid_meta_key );
	}

	if ( null === $credentials_members ) {
		$credentials_members = agend_apps_connect_credentials_member_count();
	}

	return array(
		'nameid_empty'        => $nameid_empty,
		'credentials_members' => $credentials_members,
		'nameid_meta_key'     => $nameid_meta_key,
		'blocks'              => $nameid_empty > 0,
	);
}

/**
 * Reads the pre-flight acknowledgement checkbox out of a POST-like array.
 * Pure -- takes the array rather than reading `$_POST` directly, so this is
 * unit testable without a superglobal or a WordPress bootstrap. Only presence
 * and truthiness of the field are checked, so no sanitisation of the value
 * itself is needed here.
 *
 * @param array $post The POST-like array to read (e.g. `$_POST`).
 * @return bool
 */
function agend_apps_connect_preflight_acknowledged( array $post ): bool {
	return ! empty( $post[ AGEND_APPS_CONNECT_ACK_FIELD ] );
}

/**
 * Builds the `POST /v1/sso/connections` request body.
 *
 * Deliberately carries NEITHER `role_attribute` NOR `role_mapping` (finding
 * 5): the gateway's `attribute_mappings` accepts both, but omitting them
 * entirely is what stops any WordPress role -- administrator included -- from
 * ever mapping to a privileged Agend role. Every asserted member instead
 * lands on `default_role` (`contact`, the lowest-privilege role), regardless
 * of which WordPress role they hold; the IdP-side allow-list
 * ({@see agend_apps_connect_member_roles()}) only decides who may attempt SSO
 * at all, never what Agend role the attempt resolves to.
 *
 * `jit_contact_provisioning` is `false` as a decided precedent: a member
 * asserted via this connection gets a user account (`jit_provisioning: true`)
 * but not an automatic CRM contact record, keeping contact creation a
 * separate, deliberate step rather than a side effect of first sign-in.
 *
 * `idp_sso_url` is the IdP-INITIATED endpoint (`$idp_metadata['idp_sso_url']`,
 * not `sso_url`), since that is the endpoint this WordPress-as-IdP flow
 * actually drives (`includes/wp-idp-saml-link.php`).
 *
 * Deliberately takes NO NameID meta key, unlike
 * {@see agend_apps_connect_attribute_mapping()}: the gateway's
 * `attribute_mappings` shape has no NameID-mapping key of its own. Choosing
 * which WordPress field feeds the NameID is entirely between this site and its
 * own IdP plugin; the gateway just reads whatever subject the signed assertion
 * carries.
 *
 * @param array $idp_metadata {@see WP_SAML_IDP_Api::get_idp_metadata()}'s return shape.
 * @return array The connection payload.
 */
function agend_apps_connect_connection_payload( array $idp_metadata ): array {
	$site_name = get_bloginfo( 'name' );

	if ( '' === $site_name ) {
		$site_name = Agend_Apps_Settings::get_account_slug();
	}

	return array(
		'name'                     => $site_name,
		'slug'                     => agend_apps_connect_connection_slug( (string) ( $idp_metadata['entity_id'] ?? '' ) ),
		'idp_entity_id'            => (string) ( $idp_metadata['entity_id'] ?? '' ),
		'idp_sso_url'              => (string) ( $idp_metadata['idp_sso_url'] ?? '' ),
		'idp_certificate'          => (string) ( $idp_metadata['certificate'] ?? '' ),
		'name_id_format'           => 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified',
		'jit_provisioning'         => true,
		'jit_contact_provisioning' => false,
		'default_role'             => 'contact',
		'allow_idp_initiated'      => true,
		'want_assertions_signed'   => true,
		'attribute_mappings'       => array(
			'email_attribute'        => 'email',
			'first_name_attribute'   => 'first_name',
			'last_name_attribute'    => 'last_name',
			'display_name_attribute' => 'display_name',
			'groups_attribute'       => 'groups',
		),
	);
}

/**
 * Stores the one connection this site has made.
 *
 * `site_url` is captured here, at connect time, because this is the only
 * moment it can be captured honestly -- a later enforcement step (scope item
 * 6) reads it back to detect the connection having been cloned onto another
 * site, and a value captured at THAT point would already be compromised by
 * the very clone it is meant to catch.
 *
 * @param array $connection The connection record (from the gateway, whether
 *                           freshly created or found already existing).
 * @param array $sp_urls    The authoritative SP urls in effect for this connection.
 */
function agend_apps_connect_store( array $connection, array $sp_urls ): void {
	update_option(
		AGEND_APPS_CONNECT_OPTION,
		array(
			'id'              => (string) ( $connection['id'] ?? '' ),
			'slug'            => (string) ( $connection['slug'] ?? '' ),
			'approval_state'  => (string) ( $connection['approval_state'] ?? '' ),
			'idp_entity_id'   => (string) ( $connection['idp_entity_id'] ?? '' ),
			'sp_entity_id'    => (string) ( $sp_urls['sp_entity_id'] ?? '' ),
			'sp_acs_url'      => (string) ( $sp_urls['sp_acs_url'] ?? '' ),
			'sp_metadata_url' => (string) ( $sp_urls['sp_metadata_url'] ?? '' ),
			'site_url'        => site_url(),
			'connected_at'    => time(),
		)
	);
}

/**
 * Reads back the stored connection, fully defaulted so callers never need to
 * test for missing keys.
 *
 * @return array{id: string, slug: string, approval_state: string, idp_entity_id: string,
 *         sp_entity_id: string, sp_acs_url: string, sp_metadata_url: string,
 *         site_url: string, connected_at: int}
 */
function agend_apps_connect_stored(): array {
	$defaults = array(
		'id'              => '',
		'slug'            => '',
		'approval_state'  => '',
		'idp_entity_id'   => '',
		'sp_entity_id'    => '',
		'sp_acs_url'      => '',
		'sp_metadata_url' => '',
		'site_url'        => '',
		'connected_at'    => 0,
	);

	$stored = get_option( AGEND_APPS_CONNECT_OPTION, array() );

	if ( ! is_array( $stored ) ) {
		return $defaults;
	}

	return array_merge( $defaults, $stored );
}

/**
 * Runs the "Connect this site" action end to end, recording each step's
 * outcome so the admin page can show exactly how far it got.
 *
 * Stops at the first hard failure. Steps, in order:
 *
 * i.    Capability/mode sanity: not `wordpress` mode, an unconfigured account
 *       slug/root URL, or a missing scope ({@see agend_apps_connect_missing_scopes()}) -- error, stop.
 * i.5.  {@see agend_apps_connect_preflight()} (with no arguments -- production
 *       always resolves the counts from the real `WP_User_Query` wrappers).
 *       When it `blocks` and `$acknowledged` is false -- error naming the
 *       count and the meta key, stop. When it blocks and `$acknowledged` is
 *       true -- recorded `ok`, noting how many members were acknowledged, and
 *       the run continues. The `credentials_members` estimate never stops
 *       this step; see that function's docblock for why.
 * ii.   `WP_SAML_IDP_Api` presence, method by method -- "IdP plugin too old",
 *       naming the missing method, stop.
 * iii.  `get_idp_metadata()` -- an empty entity id, SSO url, or certificate is
 *       an error, stop.
 * iv.   {@see agend_apps_connect_find_existing()} on the IdP entity id -- a
 *       `WP_Error` is an error, stop; a match records "already connected" and
 *       SKIPS creation, carrying that connection forward.
 * v.    No match -- `agend_apps_sso_create_connection()`. On a `WP_Error`
 *       whose status is 409, or whose gateway code names a duplicate/unique
 *       violation, {@see agend_apps_connect_find_existing()} is re-run and
 *       that result used ("already connected") instead. This is the
 *       catch-conflict-and-refetch idempotency the review settled on, in
 *       place of granting the connected key an update scope it would
 *       otherwise never need. Any other error -- error, stop.
 * vi.   Resolves the AUTHORITATIVE SP urls: the connection response's own
 *       `sp_entity_id`/`sp_acs_url`/`sp_metadata_url` when present, falling
 *       back to {@see agend_apps_connect_sp_urls()}'s local derivation only
 *       when the response omits them. A mismatch between the two is NOT
 *       silent: it is recorded in `sp_mismatch` (both sets), because it means
 *       this site's derivation of the gateway's own URL scheme has drifted --
 *       the SP is still registered with the AUTHORITATIVE values regardless.
 * vii.  `upsert_service_provider()` -- a falsy `success` is an error (using
 *       its own `errors`), stop.
 * viii. `save_attribute_mapping()` -- false is an error, stop.
 * ix.   `save_sp_sso_settings()` with the role restriction turned on (this
 *       call REPLACES the whole SSO-settings record) -- false is an error.
 * x.    {@see agend_apps_connect_store()}.
 *
 * Every gateway or IdP call is implicitly guarded: the whole run is wrapped
 * in try/catch so a thrown `Throwable` becomes a recorded error, never a
 * fatal on an admin screen.
 *
 * @param bool $acknowledged Whether the operator ticked the pre-flight
 *                            acknowledgement checkbox (see
 *                            {@see agend_apps_connect_preflight_acknowledged()}).
 *                            Only meaningful when the pre-flight blocks;
 *                            ignored otherwise.
 * @return array{steps: array<int, array{step: string, status: string, message: string}>,
 *         errors: string[], connection: array, sp_mismatch: array, preflight: array}
 */
function agend_apps_connect_run( bool $acknowledged = false ): array {
	$steps       = array();
	$errors      = array();
	$connection  = array();
	$sp_mismatch = array();
	$preflight   = array();

	try {
		// i. Capability and mode sanity.
		if ( ! Agend_Apps_Settings::wordpress_idp_enabled() ) {
			$errors[] = __( 'This site is not in WordPress account sign-in mode. The connect action only applies in that mode.', 'agend-apps-core' );
			$steps[]  = array(
				'step'    => 'preflight',
				'status'  => 'error',
				'message' => '',
			);

			return compact( 'steps', 'errors', 'connection', 'sp_mismatch', 'preflight' );
		}

		$account_slug = Agend_Apps_Settings::get_account_slug();
		$root_url     = Agend_Apps_Settings::get_root_url();

		if ( '' === $account_slug || '' === $root_url ) {
			$errors[] = __( 'The Agend account slug or root URL is not configured.', 'agend-apps-core' );
			$steps[]  = array(
				'step'    => 'preflight',
				'status'  => 'error',
				'message' => '',
			);

			return compact( 'steps', 'errors', 'connection', 'sp_mismatch', 'preflight' );
		}

		$scope_check = agend_apps_connect_missing_scopes();

		if ( ! empty( $scope_check['missing'] ) ) {
			$errors[] = sprintf(
				/* translators: %s: comma-separated list of missing API key scopes. */
				__( 'The connected API key is missing the required scope(s): %s.', 'agend-apps-core' ),
				implode( ', ', $scope_check['missing'] )
			);
			$steps[] = array(
				'step'    => 'preflight',
				'status'  => 'error',
				'message' => '',
			);

			return compact( 'steps', 'errors', 'connection', 'sp_mismatch', 'preflight' );
		}

		$steps[] = array(
			'step'    => 'preflight',
			'status'  => 'ok',
			'message' => '',
		);

		// i.5. NameID pre-flight: see agend_apps_connect_preflight() for why
		// credentials_members never blocks this step.
		$preflight = agend_apps_connect_preflight();

		if ( $preflight['blocks'] ) {
			if ( ! $acknowledged ) {
				$errors[] = sprintf(
					/* translators: 1: count of members who would be asserted with an empty NameID, 2: the configured NameID user-meta key. */
					__( '%1$d member(s) would be asserted with an empty NameID because the "%2$s" meta key is not populated for them. Populate it for those members, or acknowledge the pre-flight to continue anyway.', 'agend-apps-core' ),
					$preflight['nameid_empty'],
					$preflight['nameid_meta_key']
				);
				$steps[]  = array(
					'step'    => 'nameid_preflight',
					'status'  => 'error',
					'message' => '',
				);

				return compact( 'steps', 'errors', 'connection', 'sp_mismatch', 'preflight' );
			}

			$steps[] = array(
				'step'    => 'nameid_preflight',
				'status'  => 'ok',
				/* translators: %d: count of members the operator acknowledged had an empty NameID. */
				'message' => sprintf( __( 'operator acknowledged %d member(s) with an empty NameID', 'agend-apps-core' ), $preflight['nameid_empty'] ),
			);
		} else {
			$steps[] = array(
				'step'    => 'nameid_preflight',
				'status'  => 'ok',
				'message' => '',
			);
		}

		// ii. WP_SAML_IDP_Api presence.
		$required_methods = array( 'get_idp_metadata', 'upsert_service_provider', 'save_attribute_mapping', 'save_sp_sso_settings' );

		if ( ! class_exists( 'WP_SAML_IDP_Api' ) ) {
			$errors[] = __( 'IdP plugin too old: WP_SAML_IDP_Api is not available.', 'agend-apps-core' );
			$steps[]  = array(
				'step'    => 'idp_api',
				'status'  => 'error',
				'message' => '',
			);

			return compact( 'steps', 'errors', 'connection', 'sp_mismatch', 'preflight' );
		}

		foreach ( $required_methods as $method ) {
			if ( ! method_exists( 'WP_SAML_IDP_Api', $method ) ) {
				$errors[] = sprintf(
					/* translators: %s: the missing method name on WP_SAML_IDP_Api. */
					__( 'IdP plugin too old: missing %s().', 'agend-apps-core' ),
					$method
				);
				$steps[] = array(
					'step'    => 'idp_api',
					'status'  => 'error',
					'message' => '',
				);

				return compact( 'steps', 'errors', 'connection', 'sp_mismatch', 'preflight' );
			}
		}

		$steps[] = array(
			'step'    => 'idp_api',
			'status'  => 'ok',
			'message' => '',
		);

		// iii. IdP metadata.
		$idp_metadata = WP_SAML_IDP_Api::get_idp_metadata();

		if ( empty( $idp_metadata['entity_id'] ) || empty( $idp_metadata['idp_sso_url'] ) || empty( $idp_metadata['certificate'] ) ) {
			$errors[] = __( 'The IdP metadata is incomplete: entity id, SSO URL, and certificate are all required.', 'agend-apps-core' );
			$steps[]  = array(
				'step'    => 'idp_metadata',
				'status'  => 'error',
				'message' => '',
			);

			return compact( 'steps', 'errors', 'connection', 'sp_mismatch', 'preflight' );
		}

		$steps[] = array(
			'step'    => 'idp_metadata',
			'status'  => 'ok',
			'message' => '',
		);

		// iv./v. Find or create the gateway connection.
		$existing = agend_apps_connect_find_existing( (string) $idp_metadata['entity_id'] );

		if ( is_wp_error( $existing ) ) {
			$errors[] = $existing->get_error_message();
			$steps[]  = array(
				'step'    => 'connection',
				'status'  => 'error',
				'message' => '',
			);

			return compact( 'steps', 'errors', 'connection', 'sp_mismatch', 'preflight' );
		}

		if ( null !== $existing ) {
			$connection = $existing;
			$steps[]    = array(
				'step'    => 'connection',
				'status'  => 'skipped',
				'message' => __( 'already connected', 'agend-apps-core' ),
			);
		} else {
			$payload = agend_apps_connect_connection_payload( $idp_metadata );
			$created = agend_apps_sso_create_connection( $payload );

			if ( is_wp_error( $created ) ) {
				$status      = agend_apps_auth_error_status( $created );
				$code        = agend_apps_auth_error_code( $created );
				$is_conflict = ( 409 === $status ) || in_array( $code, array( 'DUPLICATE', 'CONFLICT', 'UNIQUE_VIOLATION', 'ALREADY_EXISTS' ), true );

				$refetched = $is_conflict ? agend_apps_connect_find_existing( (string) $idp_metadata['entity_id'] ) : null;

				if ( $is_conflict && is_array( $refetched ) ) {
					$connection = $refetched;
					$steps[]    = array(
						'step'    => 'connection',
						'status'  => 'skipped',
						'message' => __( 'already connected', 'agend-apps-core' ),
					);
				} else {
					$errors[] = $created->get_error_message();
					$steps[]  = array(
						'step'    => 'connection',
						'status'  => 'error',
						'message' => '',
					);

					return compact( 'steps', 'errors', 'connection', 'sp_mismatch', 'preflight' );
				}
			} else {
				$connection = ( isset( $created['data'] ) && is_array( $created['data'] ) ) ? $created['data'] : $created;
				$steps[]    = array(
					'step'    => 'connection',
					'status'  => 'ok',
					'message' => '',
				);
			}
		}

		// vi. Resolve the authoritative SP urls.
		$local_sp_urls         = agend_apps_connect_sp_urls( $root_url, $account_slug );
		$authoritative_sp_urls = array(
			'sp_entity_id'    => (string) ( $connection['sp_entity_id'] ?? '' ),
			'sp_acs_url'      => (string) ( $connection['sp_acs_url'] ?? '' ),
			'sp_metadata_url' => (string) ( $connection['sp_metadata_url'] ?? '' ),
		);

		$sp_urls = array();

		foreach ( array( 'sp_entity_id', 'sp_acs_url', 'sp_metadata_url' ) as $key ) {
			$sp_urls[ $key ] = ( '' !== $authoritative_sp_urls[ $key ] ) ? $authoritative_sp_urls[ $key ] : $local_sp_urls[ $key ];

			if ( '' !== $authoritative_sp_urls[ $key ] && '' !== $local_sp_urls[ $key ] && $authoritative_sp_urls[ $key ] !== $local_sp_urls[ $key ] ) {
				$sp_mismatch = array(
					'authoritative' => $authoritative_sp_urls,
					'local'         => $local_sp_urls,
				);
			}
		}

		$steps[] = array(
			'step'    => 'sp_urls',
			'status'  => empty( $sp_mismatch ) ? 'ok' : 'mismatch',
			'message' => '',
		);

		// vii. Register the SP.
		$sp_result = WP_SAML_IDP_Api::upsert_service_provider( $sp_urls['sp_entity_id'], agend_apps_connect_sp_data( $sp_urls ) );

		if ( empty( $sp_result['success'] ) ) {
			$sp_errors = ( isset( $sp_result['errors'] ) && is_array( $sp_result['errors'] ) && ! empty( $sp_result['errors'] ) )
				? $sp_result['errors']
				: array( __( 'Failed to register the service provider with the IdP.', 'agend-apps-core' ) );
			$errors    = array_merge( $errors, $sp_errors );
			$steps[]   = array(
				'step'    => 'upsert_sp',
				'status'  => 'error',
				'message' => '',
			);

			return compact( 'steps', 'errors', 'connection', 'sp_mismatch', 'preflight' );
		}

		$steps[] = array(
			'step'    => 'upsert_sp',
			'status'  => 'ok',
			'message' => '',
		);

		// viii. Attribute mapping.
		$mapping_ok = WP_SAML_IDP_Api::save_attribute_mapping( $sp_urls['sp_entity_id'], agend_apps_connect_attribute_mapping( agend_apps_connect_nameid_meta_key() ) );

		if ( ! $mapping_ok ) {
			$errors[] = __( 'Failed to save the attribute mapping.', 'agend-apps-core' );
			$steps[]  = array(
				'step'    => 'attribute_mapping',
				'status'  => 'error',
				'message' => '',
			);

			return compact( 'steps', 'errors', 'connection', 'sp_mismatch', 'preflight' );
		}

		$steps[] = array(
			'step'    => 'attribute_mapping',
			'status'  => 'ok',
			'message' => '',
		);

		// ix. SSO settings: role restriction. REPLACES the whole record.
		$sso_settings_ok = WP_SAML_IDP_Api::save_sp_sso_settings(
			$sp_urls['sp_entity_id'],
			array(
				'role_restriction_enabled' => true,
				'restricted_roles'         => agend_apps_connect_member_roles(),
				'restriction_action'       => 'show_error',
			)
		);

		if ( ! $sso_settings_ok ) {
			$errors[] = __( 'Failed to save the role restriction settings.', 'agend-apps-core' );
			$steps[]  = array(
				'step'    => 'sso_settings',
				'status'  => 'error',
				'message' => '',
			);

			return compact( 'steps', 'errors', 'connection', 'sp_mismatch', 'preflight' );
		}

		$steps[] = array(
			'step'    => 'sso_settings',
			'status'  => 'ok',
			'message' => '',
		);

		// x. Store.
		agend_apps_connect_store( $connection, $sp_urls );

		$steps[] = array(
			'step'    => 'store',
			'status'  => 'ok',
			'message' => '',
		);
	} catch ( Throwable $e ) {
		$errors[] = $e->getMessage();
		$steps[]  = array(
			'step'    => 'exception',
			'status'  => 'error',
			'message' => '',
		);
	}

	return compact( 'steps', 'errors', 'connection', 'sp_mismatch', 'preflight' );
}

/**
 * The verbatim operator copy shown for a connection's approval state.
 *
 * @param string $approval_state The connection's `approval_state` (`pending`, `approved`, or '').
 * @return string
 */
function agend_apps_connect_approval_notice( string $approval_state ): string {
	if ( 'pending' === $approval_state ) {
		return __( 'pending Agend approval, SSO inactive until approved', 'agend-apps-core' );
	}

	if ( 'approved' === $approval_state ) {
		return __( 'approved -- SSO is active for this connection', 'agend-apps-core' );
	}

	return '';
}

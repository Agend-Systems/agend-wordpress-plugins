<?php
/**
 * Identity and SSO admin settings page controller.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A dedicated second settings page ("Identity and SSO"), following the
 * `records/settings.php` precedent of a second `add_options_page()`
 * (`includes/records/settings.php:267-273`) rather than a third `?tab=` on
 * the main Agend Apps screen (`docs/PLAN-wordpress-idp-option-b.md` section
 * 6). Holds every setting that decides who a WordPress user is to Agend and
 * how that identity is linked: the member sign-in mode, the external-id
 * source, the SSO linking mechanism, and the read-only connection entity id.
 *
 * These fields previously lived in {@see Agend_Apps_Admin} on the main
 * "Agend Apps" page. Moving them here changes only their registered option
 * group, page slug, and section -- every option NAME is unchanged, so an
 * existing site's saved values carry over untouched.
 */
class Agend_Apps_Identity_Admin {

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'agend-apps-identity';

	/**
	 * Option group name used with `settings_fields()`.
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'agend_apps_identity_settings';

	/**
	 * Nonce action for the diagnostics panel's member lookup form
	 * (docs/PLAN-wordpress-idp-option-b.md section 6). A GET-based,
	 * non-destructive lookup, but still nonce-checked per the brief this
	 * panel was built against: it is the only field on this page that reads
	 * arbitrary request input.
	 *
	 * @var string
	 */
	const DIAGNOSTICS_LOOKUP_ACTION = 'agend_apps_wp_idp_diagnostics_lookup';

	/**
	 * Request field name carrying the diagnostics panel's user id/email query.
	 *
	 * @var string
	 */
	const DIAGNOSTICS_QUERY_FIELD = 'agend_apps_wp_idp_user';

	/**
	 * Nonce action for the "Connect this site" form. Follows the same shape as
	 * {@see DIAGNOSTICS_LOOKUP_ACTION}: `current_user_can( 'manage_options' )`
	 * first, then `check_admin_referer()`, then the work -- this is the only
	 * other form on this page that actually performs work, rather than only
	 * reading state or saving via the Settings API's own nonce.
	 *
	 * @var string
	 */
	const CONNECT_ACTION = 'agend_apps_connect_site';

	/**
	 * `admin_post_{action}` hook suffix {@see CONNECT_ACTION} is registered
	 * under.
	 *
	 * @var string
	 */
	const CONNECT_POST_ACTION = 'agend_apps_connect_site';

	/**
	 * Transient name carrying the last "Connect this site" run's result across
	 * the redirect back to this page. Keyed to nothing more specific than the
	 * site, since only one admin at a time is expected to run this action and
	 * the result is only ever meant to be shown once.
	 *
	 * @var string
	 */
	const CONNECT_RESULT_TRANSIENT = 'agend_apps_connect_site_result';

	/**
	 * Registers all admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_' . self::CONNECT_POST_ACTION, array( $this, 'handle_connect_site' ) );
	}

	/**
	 * Adds the Identity and SSO settings page to the WordPress admin menu.
	 */
	public function add_menu_page(): void {
		add_options_page(
			__( 'Identity and SSO', 'agend-apps-core' ),
			__( 'Identity and SSO', 'agend-apps-core' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Registers all settings, sections, and fields via the Settings API.
	 */
	public function register_settings(): void {
		// Member Identity section: which WordPress users become Agend
		// members, and by which subject. Moved from the main "Agend Apps"
		// page (docs/PLAN-wordpress-idp-option-b.md section 6).
		add_settings_section(
			'agend_apps_identity_section',
			__( 'Member Identity', 'agend-apps-core' ),
			array( $this, 'render_identity_section' ),
			self::PAGE_SLUG
		);

		register_setting(
			self::OPTION_GROUP,
			'agend_apps_member_auth_mode',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_member_auth_mode' ),
				'default'           => Agend_Apps_Settings::MEMBER_AUTH_CREDENTIALS,
			)
		);

		add_settings_field(
			'agend_apps_member_auth_mode',
			__( 'Member sign-in', 'agend-apps-core' ),
			array( $this, 'render_member_auth_mode_field' ),
			self::PAGE_SLUG,
			'agend_apps_identity_section'
		);

		register_setting(
			self::OPTION_GROUP,
			'agend_apps_account_slug',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		add_settings_field(
			'agend_apps_account_slug',
			__( 'Account Slug', 'agend-apps-core' ),
			array( $this, 'render_account_slug_field' ),
			self::PAGE_SLUG,
			'agend_apps_identity_section'
		);

		register_setting(
			self::OPTION_GROUP,
			'agend_apps_portal_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);

		add_settings_field(
			'agend_apps_portal_url',
			__( 'Portal URL', 'agend-apps-core' ),
			array( $this, 'render_portal_url_field' ),
			self::PAGE_SLUG,
			'agend_apps_identity_section'
		);

		register_setting(
			self::OPTION_GROUP,
			'agend_apps_external_id_meta_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => '',
			)
		);

		add_settings_field(
			'agend_apps_external_id_meta_key',
			__( 'Member ID Meta Key', 'agend-apps-core' ),
			array( $this, 'render_external_id_meta_key_field' ),
			self::PAGE_SLUG,
			'agend_apps_identity_section'
		);

		// Member reset URL: previously had no admin UI at all (settable only
		// by filter or a direct option write). It only ever applies to
		// `credentials` mode, so the field is registered unconditionally
		// (existing filtered values must keep sanitising the same way) but
		// only added to the page -- and so only ever shown -- in that mode.
		register_setting(
			self::OPTION_GROUP,
			'agend_apps_member_reset_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);

		if ( Agend_Apps_Settings::MEMBER_AUTH_CREDENTIALS === Agend_Apps_Settings::get_member_auth_mode() ) {
			add_settings_field(
				'agend_apps_member_reset_url',
				__( 'Member Reset URL', 'agend-apps-core' ),
				array( $this, 'render_member_reset_url_field' ),
				self::PAGE_SLUG,
				'agend_apps_identity_section'
			);
		}

		// Single Sign-On Linking section: how a WordPress user's Agend
		// identity is decided when linking happens (docs/PLAN-wordpress-idp-
		// option-b.md sections 5-6). Nothing consumes this yet -- the linking
		// step itself is not built -- so this section only lets an admin set
		// the mechanism ahead of that work landing.
		add_settings_section(
			'agend_apps_identity_sso_section',
			__( 'Single Sign-On Linking', 'agend-apps-core' ),
			array( $this, 'render_sso_section' ),
			self::PAGE_SLUG
		);

		// Identity provider plugin: which SAML IdP plugin, if any, takes part
		// in the Agend connection. Registered before the linking mechanism
		// field, since the mechanism's "Detected" line depends on this.
		register_setting(
			self::OPTION_GROUP,
			'agend_apps_idp_plugin',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_idp_plugin' ),
				'default'           => Agend_Apps_Settings::IDP_PLUGIN_AUTO,
			)
		);

		add_settings_field(
			'agend_apps_idp_plugin',
			__( 'Identity provider plugin', 'agend-apps-core' ),
			array( $this, 'render_idp_plugin_field' ),
			self::PAGE_SLUG,
			'agend_apps_identity_sso_section'
		);

		register_setting(
			self::OPTION_GROUP,
			'agend_apps_sso_link_mechanism',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_sso_link_mechanism' ),
				'default'           => Agend_Apps_Settings::SSO_LINK_MECHANISM_AUTO,
			)
		);

		add_settings_field(
			'agend_apps_sso_link_mechanism',
			__( 'Linking mechanism', 'agend-apps-core' ),
			array( $this, 'render_sso_link_mechanism_field' ),
			self::PAGE_SLUG,
			'agend_apps_identity_sso_section'
		);

		// Connection section: the read-only entity id an admin pastes into
		// the Agend dashboard when creating the connection. Not a registered
		// setting -- it is derived, and it is HALF of the identity key every
		// member link is keyed on ((idp_entity_id, external_id)), so it is
		// deliberately not a free-text field an admin could edit and silently
		// unlink every member.
		add_settings_section(
			'agend_apps_identity_connection_section',
			__( 'Connection', 'agend-apps-core' ),
			array( $this, 'render_connection_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'agend_apps_idp_entity_id',
			__( 'Connection entity id', 'agend-apps-core' ),
			array( $this, 'render_entity_id_field' ),
			self::PAGE_SLUG,
			'agend_apps_identity_connection_section'
		);

		// "Connect this site": registers this site's SP with the local IdP
		// plugin and creates (or finds) the matching Agend gateway connection.
		// Not a registered setting -- it performs an action rather than
		// storing a value, so it is added as a field like the read-only entity
		// id above rather than through register_setting().
		add_settings_field(
			'agend_apps_connect_site',
			__( 'Connect this site', 'agend-apps-core' ),
			array( $this, 'render_connect_site_field' ),
			self::PAGE_SLUG,
			'agend_apps_identity_connection_section'
		);

		// Diagnostics section (docs/PLAN-wordpress-idp-option-b.md section 6,
		// "Diagnostic panel"): reports the recorded link state for a chosen
		// WordPress user. Not optional -- agend-content-access fails closed
		// on an empty bearer, so a linking gap otherwise presents only as
		// "member can't see their content", with no error anywhere. Added as
		// a settings section/field like Connection above, rather than a
		// registered setting, because it reads state and never writes one.
		add_settings_section(
			'agend_apps_identity_diagnostics_section',
			__( 'Diagnostics', 'agend-apps-core' ),
			array( $this, 'render_diagnostics_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'agend_apps_wp_idp_diagnostics',
			__( 'Member lookup', 'agend-apps-core' ),
			array( $this, 'render_diagnostics_field' ),
			self::PAGE_SLUG,
			'agend_apps_identity_diagnostics_section'
		);
	}

	/**
	 * Sanitizes the member sign-in mode option value (moved from
	 * {@see Agend_Apps_Admin}, unchanged: SPEC-CORE-20260907 US-4.1 AC2,
	 * widened by the WordPress-IdP scope to a third value).
	 *
	 * @param string $value Raw submitted value.
	 *
	 * @return string `sso` or `wordpress` when submitted exactly, otherwise `credentials`.
	 */
	public function sanitize_member_auth_mode( string $value ): string {
		if ( Agend_Apps_Settings::MEMBER_AUTH_SSO === $value || Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS === $value ) {
			return $value;
		}

		return Agend_Apps_Settings::MEMBER_AUTH_CREDENTIALS;
	}

	/**
	 * Sanitizes the SSO link mechanism option value to the closed three-value
	 * vocabulary. Delegates to {@see Agend_Apps_Settings::normalize_sso_link_mechanism()}
	 * so the same rule governs what gets saved and what a stray stored value
	 * (a filter, a rollback, direct DB edit, or the retired `server` value)
	 * resolves to when read back.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string One of `auto`, `saml`, `disabled`.
	 */
	public function sanitize_sso_link_mechanism( $value ): string {
		return Agend_Apps_Settings::normalize_sso_link_mechanism( $value );
	}

	/**
	 * Sanitizes the IdP plugin selection option value to the closed
	 * four-value vocabulary. Delegates to
	 * {@see Agend_Apps_Settings::normalize_idp_plugin()} so the same rule
	 * governs what gets saved and what a stray stored value resolves to.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string One of `auto`, `saml`, `miniorange`, `none`.
	 */
	public function sanitize_idp_plugin( $value ): string {
		return Agend_Apps_Settings::normalize_idp_plugin( $value );
	}

	/**
	 * Sanitizes a Settings API checkbox: present in `$_POST` (any truthy
	 * string) means checked/true; absent means false. Mirrors
	 * {@see Agend_Apps_Admin::sanitize_checkbox()} -- duplicated rather than
	 * shared, matching the existing pattern of each settings surface owning
	 * its own small checkbox sanitiser (see `agend_apps_records_sanitize_checkbox()`
	 * in `includes/records/settings.php`).
	 *
	 * @param mixed $value The posted field value, or null when unchecked.
	 * @return bool
	 */
	public function sanitize_checkbox( $value ): bool {
		return ! empty( $value );
	}

	/**
	 * Renders the settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		require_once AGEND_APPS_CORE_DIR . 'admin/views/identity-settings.php';
	}

	/**
	 * Renders the Member Identity section description.
	 */
	public function render_identity_section(): void {
		echo '<p>' . esc_html__( 'Controls which WordPress users become Agend members, and by which identity.', 'agend-apps-core' ) . '</p>';
	}

	/**
	 * Renders the Single Sign-On Linking section description.
	 */
	public function render_sso_section(): void {
		echo '<p>' . esc_html__( 'Controls how a WordPress user is linked to their Agend identity.', 'agend-apps-core' ) . '</p>';
	}

	/**
	 * Renders the Connection section description.
	 */
	public function render_connection_section(): void {
		echo '<p>' . esc_html__( 'Read-only identifiers for the Agend dashboard side of this connection.', 'agend-apps-core' ) . '</p>';
	}

	/**
	 * Renders the Diagnostics section description.
	 */
	public function render_diagnostics_section(): void {
		echo '<p>' . esc_html__( 'Reports the recorded WordPress-IdP link state for a chosen member. Reads recorded state only -- opening this page never attempts a link or mints a token.', 'agend-apps-core' ) . '</p>';
	}

	/**
	 * Resolves which WordPress user the diagnostics panel should report on,
	 * from the request (nonce-checked) or the current admin as the default.
	 *
	 * Deliberately never triggers a link attempt or a token mint: it only
	 * resolves WHICH user id {@see agend_apps_wp_idp_diagnostics()} should
	 * then read recorded state for.
	 *
	 * @return array{user_id: int, query: string, not_found: bool} `user_id`
	 *         is 0 when a submitted query resolved to no WordPress user (see
	 *         `not_found`), and defaults to the current admin's id when no
	 *         query was submitted at all.
	 */
	private function resolve_diagnostics_lookup(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below via check_admin_referer() once we know a query was actually submitted.
		$raw_query = isset( $_GET[ self::DIAGNOSTICS_QUERY_FIELD ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::DIAGNOSTICS_QUERY_FIELD ] ) ) : '';

		if ( '' === $raw_query ) {
			return array(
				'user_id'   => get_current_user_id(),
				'query'     => '',
				'not_found' => false,
			);
		}

		check_admin_referer( self::DIAGNOSTICS_LOOKUP_ACTION, 'agend_apps_wp_idp_nonce' );

		$user = is_numeric( $raw_query )
			? get_user_by( 'id', absint( $raw_query ) )
			: get_user_by( 'email', sanitize_email( $raw_query ) );

		if ( ! ( $user instanceof WP_User ) ) {
			return array(
				'user_id'   => 0,
				'query'     => $raw_query,
				'not_found' => true,
			);
		}

		return array(
			'user_id'   => (int) $user->ID,
			'query'     => $raw_query,
			'not_found' => false,
		);
	}

	/**
	 * Renders the diagnostics panel: the member lookup form, and the
	 * assembled facts for whichever user {@see resolve_diagnostics_lookup()}
	 * resolves. Markup lives in the view partial; this only builds the data
	 * ({@see agend_apps_wp_idp_diagnostics()} is the tested, pure part).
	 */
	public function render_diagnostics_field(): void {
		$lookup = $this->resolve_diagnostics_lookup();

		$diagnostics = ( $lookup['user_id'] > 0 && function_exists( 'agend_apps_wp_idp_diagnostics' ) )
			? agend_apps_wp_idp_diagnostics( $lookup['user_id'] )
			: null;

		require AGEND_APPS_CORE_DIR . 'admin/views/identity-diagnostics.php';
	}

	/**
	 * Renders the member sign-in mode radio field (moved from
	 * {@see Agend_Apps_Admin}, unchanged: SPEC-CORE-20260907 US-4.1 AC2,
	 * widened by the WordPress-IdP scope to a third option).
	 *
	 * In `sso` and `wordpress` mode the credential login surface (login
	 * bridge, provisioning hook, `/auth/*` REST routes, member-login widget)
	 * is not loaded at all, because `Agend_Apps_Settings::credential_login_enabled()`
	 * returns false for both.
	 */
	public function render_member_auth_mode_field(): void {
		$value = Agend_Apps_Settings::get_member_auth_mode();

		$options = array(
			Agend_Apps_Settings::MEMBER_AUTH_CREDENTIALS => array(
				'label' => __( 'Agend credentials', 'agend-apps-core' ),
				'help'  => __( 'Members sign in on this site with their Agend email and password. WordPress logins create and adopt Agend accounts, and the member sign-in widget and REST proxy are active.', 'agend-apps-core' ),
			),
			Agend_Apps_Settings::MEMBER_AUTH_SSO         => array(
				'label' => __( 'SSO connection only', 'agend-apps-core' ),
				'help'  => __( 'Members reach Agend only through your SSO connection. Credential sign-in, account provisioning on user creation, and the /auth REST routes are switched off. Existing member sessions are kept until they expire.', 'agend-apps-core' ),
			),
			Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS   => array(
				'label' => __( 'WordPress account', 'agend-apps-core' ),
				'help'  => __( 'Members sign in with their WordPress password, which is never sent to Agend. On first sign-in they are passed once through this site\'s SAML identity provider to link their Agend identity; a bearer is then minted server to server from that link for the member widgets. Requires a SAML identity provider plugin (see Linking mechanism below).', 'agend-apps-core' ),
			),
		);

		foreach ( $options as $option_value => $option ) {
			printf(
				'<p><label><input type="radio" name="agend_apps_member_auth_mode" value="%1$s"%2$s /> %3$s</label></p>',
				esc_attr( $option_value ),
				checked( $value, $option_value, false ),
				esc_html( $option['label'] )
			);
			echo '<p class="description" style="margin-left:24px;">' . esc_html( $option['help'] ) . '</p>';
		}
	}

	/**
	 * Renders the account slug text field (moved from {@see Agend_Apps_Admin},
	 * unchanged).
	 *
	 * The slug names the connected Agend account in browser SSO URLs
	 * (`/api/auth/sso/{slug}/initiate`). Required for the account-link widget
	 * to build its sign-in URL.
	 */
	public function render_account_slug_field(): void {
		$value = get_option( 'agend_apps_account_slug', '' );
		printf(
			'<input type="text" id="agend_apps_account_slug" name="agend_apps_account_slug" value="%s" class="regular-text" autocomplete="off" />',
			esc_attr( $value )
		);
		echo '<p class="description">';
		esc_html_e( 'The Agend account slug, used to build SSO links for the account-link widget.', 'agend-apps-core' );
		echo '</p>';
	}

	/**
	 * Renders the portal URL text field (moved from {@see Agend_Apps_Admin},
	 * unchanged).
	 *
	 * Optional. When empty the portal URL is derived from the selected
	 * environment; set it only for associations on a custom portal domain.
	 */
	public function render_portal_url_field(): void {
		$value = get_option( 'agend_apps_portal_url', '' );
		printf(
			'<input type="url" id="agend_apps_portal_url" name="agend_apps_portal_url" value="%s" class="regular-text" placeholder="%s" autocomplete="off" />',
			esc_attr( $value ),
			esc_attr__( 'Derived from environment when empty', 'agend-apps-core' )
		);
		echo '<p class="description">';
		esc_html_e( 'Optional. The member portal URL the account-link widget links to. Leave empty to derive it from the environment.', 'agend-apps-core' );
		echo '</p>';
	}

	/**
	 * Renders the external-id meta key text field (moved from
	 * {@see Agend_Apps_Admin}, unchanged).
	 *
	 * The user-meta key holding each member's Agend external id (the SAML
	 * NameID the site's IdP asserts). IdP-plugin-agnostic by design: the value
	 * depends on which IdP plugin the site runs, so it is configuration, not
	 * code.
	 */
	public function render_external_id_meta_key_field(): void {
		$value = get_option( 'agend_apps_external_id_meta_key', '' );
		printf(
			'<input type="text" id="agend_apps_external_id_meta_key" name="agend_apps_external_id_meta_key" value="%s" class="regular-text" placeholder="imk_membership_number" autocomplete="off" />',
			esc_attr( $value )
		);
		echo '<p class="description">';
		esc_html_e( 'User-meta key holding each member\'s Agend external id (the SAML NameID your IdP asserts). Defaults to the Upbeat membership number.', 'agend-apps-core' );
		echo '</p>';
	}

	/**
	 * Renders the member reset URL field. Only ever added to the page in
	 * `credentials` mode (see {@see self::register_settings()}), since it is
	 * meaningless in `sso` or `wordpress` mode.
	 */
	public function render_member_reset_url_field(): void {
		$value = get_option( 'agend_apps_member_reset_url', '' );
		printf(
			'<input type="url" id="agend_apps_member_reset_url" name="agend_apps_member_reset_url" value="%s" class="regular-text" placeholder="%s" autocomplete="off" />',
			esc_attr( $value ),
			esc_attr__( 'Empty = the member portal recovery page', 'agend-apps-core' )
		);
		echo '<p class="description">';
		esc_html_e( 'Optional. The URL of a WordPress page hosting the Agend Member Login widget, used to complete a password reset on this site instead of the member portal. The URL must also be added to the API key\'s redirect allowlist in the Agend dashboard, or the gateway will reject it.', 'agend-apps-core' );
		echo '</p>';
	}

	/**
	 * Renders the identity provider plugin radio field: lets an admin
	 * override which SAML IdP plugin, if any, takes part in the Agend
	 * connection, instead of always relying on auto-detection.
	 *
	 * Exists because a site can run a SAML IdP plugin for a purpose unrelated
	 * to Agend (miniOrange kept for another integration, say) and needs a way
	 * to keep it out of this connection entirely.
	 */
	public function render_idp_plugin_field(): void {
		$configured = Agend_Apps_Settings::configured_idp_plugin();

		$options = array(
			Agend_Apps_Settings::IDP_PLUGIN_AUTO       => array(
				'label' => __( 'Auto-detect (recommended)', 'agend-apps-core' ),
				'help'  => __( 'Uses agend-saml-idp when it is active, otherwise miniOrange SAML IDP when it is active, otherwise none. Re-evaluated on every page load.', 'agend-apps-core' ),
			),
			Agend_Apps_Settings::IDP_PLUGIN_SAML       => array(
				'label' => __( 'agend-saml-idp', 'agend-apps-core' ),
				'help'  => __( 'Always treat agend-saml-idp as this site\'s identity provider, even if another SAML IdP plugin is also installed.', 'agend-apps-core' ),
			),
			Agend_Apps_Settings::IDP_PLUGIN_MINIORANGE => array(
				'label' => __( 'miniOrange SAML IDP', 'agend-apps-core' ),
				'help'  => __( 'Always treat the miniOrange SAML IDP plugin as this site\'s identity provider.', 'agend-apps-core' ),
			),
			Agend_Apps_Settings::IDP_PLUGIN_NONE       => array(
				'label' => __( 'None', 'agend-apps-core' ),
				'help'  => __( 'Ignore any SAML identity provider plugin installed on this site. Use this when a SAML IdP plugin is present for another purpose and must not take part in the Agend connection. Automatic linking then resolves to Disabled.', 'agend-apps-core' ),
			),
		);

		foreach ( $options as $option_value => $option ) {
			printf(
				'<p><label><input type="radio" name="agend_apps_idp_plugin" value="%1$s"%2$s /> %3$s</label></p>',
				esc_attr( $option_value ),
				checked( $configured, $option_value, false ),
				esc_html( $option['label'] )
			);
			echo '<p class="description" style="margin-left:24px;">' . esc_html( $option['help'] ) . '</p>';
		}

		$saml_present       = Agend_Apps_Settings::saml_idp_plugin_present();
		$miniorange_present = Agend_Apps_Settings::miniorange_idp_plugin_present();

		echo '<p class="description">';
		if ( $saml_present && $miniorange_present ) {
			esc_html_e( 'Currently active on this site: agend-saml-idp and miniOrange SAML IDP.', 'agend-apps-core' );
		} elseif ( $saml_present ) {
			esc_html_e( 'Currently active on this site: agend-saml-idp.', 'agend-apps-core' );
		} elseif ( $miniorange_present ) {
			esc_html_e( 'Currently active on this site: miniOrange SAML IDP.', 'agend-apps-core' );
		} else {
			esc_html_e( 'No SAML identity provider plugin is currently active on this site.', 'agend-apps-core' );
		}
		echo '</p>';

		$selected_plugin_not_active =
			( Agend_Apps_Settings::IDP_PLUGIN_SAML === $configured && ! $saml_present )
			|| ( Agend_Apps_Settings::IDP_PLUGIN_MINIORANGE === $configured && ! $miniorange_present );

		if ( $selected_plugin_not_active ) {
			echo '<div class="notice notice-warning inline"><p>';
			esc_html_e(
				'The selected identity provider plugin is not active on this site, so no SAML assertion will arrive from it.',
				'agend-apps-core'
			);
			echo '</p></div>';
		}
	}

	/**
	 * Renders the SSO link mechanism radio field and the live "detected IdP"
	 * line.
	 *
	 * The server-to-server mechanism is retired: an Agend identity may only
	 * be created from a signed SAML assertion, so the only choices left are
	 * SAML assertion or no linking at all.
	 */
	public function render_sso_link_mechanism_field(): void {
		$configured = Agend_Apps_Settings::normalize_sso_link_mechanism( get_option( 'agend_apps_sso_link_mechanism', Agend_Apps_Settings::SSO_LINK_MECHANISM_AUTO ) );
		$resolved   = Agend_Apps_Settings::sso_link_mechanism();
		$detected   = Agend_Apps_Settings::detected_idp_plugin();

		$options = array(
			Agend_Apps_Settings::SSO_LINK_MECHANISM_AUTO     => array(
				'label' => __( 'Automatic (recommended)', 'agend-apps-core' ),
				'help'  => __( 'Uses the SAML assertion when a SAML identity provider plugin is detected on this site, otherwise linking is disabled. Re-evaluated on every page load, so installing or removing a SAML IdP plugin changes behaviour immediately.', 'agend-apps-core' ),
			),
			Agend_Apps_Settings::SSO_LINK_MECHANISM_SAML     => array(
				'label' => __( 'SAML assertion', 'agend-apps-core' ),
				'help'  => __( 'The linked identity is the NameID your SAML identity provider plugin asserts at sign-in. Choose this explicitly to make the SAML assertion the source of truth for linking, whether or not a SAML plugin is currently detected.', 'agend-apps-core' ),
			),
			Agend_Apps_Settings::SSO_LINK_MECHANISM_DISABLED => array(
				'label' => __( 'Disabled', 'agend-apps-core' ),
				'help'  => __( 'No identity is linked to Agend from a WordPress sign-in. Existing links are left as they are; no new ones are created.', 'agend-apps-core' ),
			),
		);

		foreach ( $options as $option_value => $option ) {
			printf(
				'<p><label><input type="radio" name="agend_apps_sso_link_mechanism" value="%1$s"%2$s /> %3$s</label></p>',
				esc_attr( $option_value ),
				checked( $configured, $option_value, false ),
				esc_html( $option['label'] )
			);
			echo '<p class="description" style="margin-left:24px;">' . esc_html( $option['help'] ) . '</p>';
		}

		$mechanism_labels = array(
			Agend_Apps_Settings::SSO_LINK_MECHANISM_SAML     => __( 'SAML assertion', 'agend-apps-core' ),
			Agend_Apps_Settings::SSO_LINK_MECHANISM_DISABLED => __( 'Disabled', 'agend-apps-core' ),
		);

		$configured_idp_plugin = Agend_Apps_Settings::configured_idp_plugin();
		$explicit_selection    = Agend_Apps_Settings::IDP_PLUGIN_AUTO !== $configured_idp_plugin;

		echo '<p class="description">';
		if ( '' === $detected ) {
			if ( $explicit_selection ) {
				esc_html_e( 'Identity provider: none (selected above).', 'agend-apps-core' );
			} else {
				esc_html_e( 'Identity provider: none (no SAML identity provider plugin found on this site).', 'agend-apps-core' );
			}
		} elseif ( 'saml' === $detected ) {
			if ( $explicit_selection ) {
				esc_html_e( 'Identity provider: agend-saml-idp (selected above).', 'agend-apps-core' );
			} else {
				esc_html_e( 'Identity provider: agend-saml-idp (auto-detected).', 'agend-apps-core' );
			}
		} elseif ( 'miniorange' === $detected ) {
			if ( $explicit_selection ) {
				esc_html_e( 'Identity provider: miniOrange SAML IDP (selected above).', 'agend-apps-core' );
			} else {
				esc_html_e( 'Identity provider: miniOrange SAML IDP (auto-detected).', 'agend-apps-core' );
			}
		} else {
			printf(
				/* translators: %s: the third-party IdP name a filter declared via `agend_apps_detected_idp_plugin`. */
				esc_html__( 'Identity provider: %s.', 'agend-apps-core' ),
				esc_html( $detected )
			);
		}
		echo ' ';
		printf(
			/* translators: %s: the resolved mechanism label ("SAML assertion" or "Disabled"). */
			esc_html__( 'Automatic currently resolves to: %s.', 'agend-apps-core' ),
			'<strong>' . esc_html( $mechanism_labels[ $resolved ] ?? $resolved ) . '</strong>'
		);
		echo '</p>';

		// Member sign-in cannot function in `wordpress` mode without a SAML
		// IdP to link through: `agend_apps_wp_idp_link_user()`'s server-to-
		// server path (the fallback this warning used to describe) is
		// retired, so no linking mechanism does anything with no SAML IdP
		// detected.
		if (
			'' === $detected
			&& Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS === Agend_Apps_Settings::get_member_auth_mode()
		) {
			echo '<div class="notice notice-warning inline"><p>';
			esc_html_e(
				'No SAML identity provider plugin is active, so members cannot be linked to Agend. Install and configure agend-saml-idp.',
				'agend-apps-core'
			);
			echo '</p></div>';
		}
	}

	/**
	 * Renders the read-only connection entity id field.
	 *
	 * Deliberately not editable: this id is half of the identity key every
	 * member link is keyed on ((idp_entity_id, external_id) -- docs/PLAN-
	 * wordpress-idp-option-b.md section 5). Changing it after linking has
	 * begun would unlink every member, so it is shown for copying into the
	 * Agend dashboard, not for editing here.
	 */
	public function render_entity_id_field(): void {
		$value = function_exists( 'agend_apps_idp_entity_id' ) ? agend_apps_idp_entity_id() : '';
		printf(
			'<input type="text" id="agend_apps_idp_entity_id" value="%s" class="regular-text" readonly="readonly" onclick="this.select();" />',
			esc_attr( $value )
		);
		echo '<p class="description">';
		esc_html_e(
			'Paste this into the Agend dashboard when creating the SSO connection for this site. Read-only: this id is half of the key every member link is stored under, so changing it after linking has begun would unlink every member. Click the field to select it for copying.',
			'agend-apps-core'
		);
		echo '</p>';
	}

	/**
	 * `admin_post_{CONNECT_POST_ACTION}` handler: runs
	 * {@see agend_apps_connect_run()} and redirects back to this page with the
	 * result in a transient for {@see render_connect_site_field()} to display.
	 *
	 * Follows the same shape as {@see resolve_diagnostics_lookup()}'s nonce
	 * precedent: `current_user_can( 'manage_options' )` checked FIRST, then
	 * `check_admin_referer()`, then the work, then `wp_safe_redirect()`. There
	 * was no existing work-performing POST handler on this page to copy --
	 * the diagnostics lookup is GET and read-only -- so this is the first one,
	 * built to match that same precedent as closely as a POST/redirect action
	 * allows.
	 */
	public function handle_connect_site(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'agend-apps-core' ) );
		}

		check_admin_referer( self::CONNECT_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() runs above, before any request input is read.
		$acknowledged = function_exists( 'agend_apps_connect_preflight_acknowledged' ) && agend_apps_connect_preflight_acknowledged( $_POST );

		$result = function_exists( 'agend_apps_connect_run' ) ? agend_apps_connect_run( $acknowledged ) : array(
			'steps'       => array(),
			'errors'      => array( __( 'The connect action is not available on this install.', 'agend-apps-core' ) ),
			'connection'  => array(),
			'sp_mismatch' => array(),
			'preflight'   => array(),
		);

		// A short-lived transient, not an option: this result is meant to be
		// shown exactly once, immediately after the redirect, not to persist
		// as site state. {@see agend_apps_connect_stored()} is the persisted
		// record this run itself writes on success.
		set_transient( self::CONNECT_RESULT_TRANSIENT, $result, MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Builds the data {@see render_connect_site_field()}'s view partial
	 * renders: whether the action can be attempted at all right now, why not
	 * when it cannot, the last run's result (consumed from the transient, so
	 * it is shown exactly once), and the currently stored connection.
	 *
	 * Deliberately never calls {@see agend_apps_connect_run()} itself --
	 * rendering a settings page must never perform the action, only report on
	 * it or offer the button that triggers it via the POST handler above.
	 *
	 * @return array{
	 *     can_connect: bool,
	 *     blocked_reasons: string[],
	 *     stored: array,
	 *     last_run: array|null,
	 *     sp_urls: array{sp_entity_id: string, sp_acs_url: string, sp_metadata_url: string},
	 *     preflight: array{nameid_empty: int, credentials_members: int, nameid_meta_key: string, blocks: bool}
	 * }
	 */
	private function build_connect_site_data(): array {
		$blocked_reasons = array();

		if ( ! class_exists( 'Agend_Apps_Settings' ) || ! Agend_Apps_Settings::wordpress_idp_enabled() ) {
			$blocked_reasons[] = __( 'This site is not in WordPress account sign-in mode.', 'agend-apps-core' );
		}

		if ( function_exists( 'agend_apps_connect_missing_scopes' ) ) {
			$scope_check = agend_apps_connect_missing_scopes();

			if ( $scope_check['unknown'] ) {
				$blocked_reasons[] = __( 'The connected API key\'s scopes are not yet known. Verify the API key first.', 'agend-apps-core' );
			} elseif ( ! empty( $scope_check['missing'] ) ) {
				$blocked_reasons[] = sprintf(
					/* translators: %s: comma-separated list of missing API key scopes. */
					__( 'The connected API key is missing the required scope(s): %s.', 'agend-apps-core' ),
					implode( ', ', $scope_check['missing'] )
				);
			}
		}

		if ( ! class_exists( 'WP_SAML_IDP_Api' ) ) {
			$blocked_reasons[] = __( 'IdP plugin too old: WP_SAML_IDP_Api is not available.', 'agend-apps-core' );
		} else {
			foreach ( array( 'get_idp_metadata', 'upsert_service_provider', 'save_attribute_mapping', 'save_sp_sso_settings' ) as $method ) {
				if ( ! method_exists( 'WP_SAML_IDP_Api', $method ) ) {
					$blocked_reasons[] = sprintf(
						/* translators: %s: the missing method name on WP_SAML_IDP_Api. */
						__( 'IdP plugin too old: missing %s().', 'agend-apps-core' ),
						$method
					);
				}
			}
		}

		$last_run = get_transient( self::CONNECT_RESULT_TRANSIENT );
		delete_transient( self::CONNECT_RESULT_TRANSIENT );

		$stored  = function_exists( 'agend_apps_connect_stored' ) ? agend_apps_connect_stored() : array();
		$sp_urls = function_exists( 'agend_apps_connect_sp_urls' )
			? agend_apps_connect_sp_urls( Agend_Apps_Settings::get_root_url(), Agend_Apps_Settings::get_account_slug() )
			: array(
				'sp_entity_id'    => '',
				'sp_acs_url'      => '',
				'sp_metadata_url' => '',
			);

		// The pre-flight is built here, not in the view, matching this method's
		// whole purpose: the view partial never computes anything itself, only
		// renders what it is handed. Shown whether or not it currently blocks
		// (see agend-apps-core/admin/views/identity-connect.php), so an
		// operator sees both counts on every visit to this page, not only when
		// something is wrong.
		$preflight = function_exists( 'agend_apps_connect_preflight' ) ? agend_apps_connect_preflight() : array(
			'nameid_empty'        => 0,
			'credentials_members' => 0,
			'nameid_meta_key'     => '',
			'blocks'              => false,
		);

		return array(
			'can_connect'     => empty( $blocked_reasons ),
			'blocked_reasons' => $blocked_reasons,
			'stored'          => $stored,
			'last_run'        => is_array( $last_run ) ? $last_run : null,
			'sp_urls'         => $sp_urls,
			'preflight'       => $preflight,
		);
	}

	/**
	 * Renders the "Connect this site" action: the derived/authoritative SP
	 * urls, the stored connection's approval state, the last run's per-step
	 * outcomes and errors, and the SP-mismatch block when present. Markup
	 * lives in the view partial; this only builds the data, matching
	 * {@see render_diagnostics_field()}'s split.
	 */
	public function render_connect_site_field(): void {
		$data = $this->build_connect_site_data();

		require AGEND_APPS_CORE_DIR . 'admin/views/identity-connect.php';
	}
}

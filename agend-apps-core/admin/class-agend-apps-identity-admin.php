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
	 * Registers all admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
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

		register_setting(
			self::OPTION_GROUP,
			'agend_apps_sso_link_on_user_create',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
				'default'           => false,
			)
		);

		add_settings_field(
			'agend_apps_sso_link_on_user_create',
			__( 'Link on user creation', 'agend-apps-core' ),
			array( $this, 'render_link_on_user_create_field' ),
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
	 * Sanitizes the SSO link mechanism option value to the closed four-value
	 * vocabulary. Delegates to {@see Agend_Apps_Settings::normalize_sso_link_mechanism()}
	 * so the same rule governs what gets saved and what a stray stored value
	 * (a filter, a rollback, direct DB edit) resolves to when read back.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string One of `auto`, `saml`, `server`, `disabled`.
	 */
	public function sanitize_sso_link_mechanism( $value ): string {
		return Agend_Apps_Settings::normalize_sso_link_mechanism( $value );
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
				'label' => __( 'WordPress account (not yet complete)', 'agend-apps-core' ),
				'help'  => __( 'Members sign in with their WordPress password, which is never sent to Agend. Their Agend account stays separate, and a bearer is minted server to server from the WordPress session. This mode is not yet complete: the step that links a WordPress account to its Agend identity has not been built, so selecting it will not yet give members access.', 'agend-apps-core' ),
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
	 * Renders the SSO link mechanism radio field, the live "detected IdP"
	 * line, and the duplicate-identity warning
	 * (docs/PLAN-wordpress-idp-option-b.md sections 5-6).
	 */
	public function render_sso_link_mechanism_field(): void {
		$configured = Agend_Apps_Settings::normalize_sso_link_mechanism( get_option( 'agend_apps_sso_link_mechanism', Agend_Apps_Settings::SSO_LINK_MECHANISM_AUTO ) );
		$resolved   = Agend_Apps_Settings::sso_link_mechanism();
		$detected   = Agend_Apps_Settings::detected_idp_plugin();

		$options = array(
			Agend_Apps_Settings::SSO_LINK_MECHANISM_AUTO     => array(
				'label' => __( 'Automatic (recommended)', 'agend-apps-core' ),
				'help'  => __( 'Uses the SAML assertion when a SAML identity provider plugin is detected on this site, otherwise links server to server. Re-evaluated on every page load, so installing or removing a SAML IdP plugin changes behaviour immediately.', 'agend-apps-core' ),
			),
			Agend_Apps_Settings::SSO_LINK_MECHANISM_SAML     => array(
				'label' => __( 'SAML assertion', 'agend-apps-core' ),
				'help'  => __( 'The linked identity is the NameID your SAML identity provider plugin asserts at sign-in. Choose this explicitly to make the SAML assertion the source of truth for linking, whether or not a SAML plugin is currently detected.', 'agend-apps-core' ),
			),
			Agend_Apps_Settings::SSO_LINK_MECHANISM_SERVER   => array(
				'label' => __( 'Server to server', 'agend-apps-core' ),
				'help'  => __( 'The linked identity is the GUID WordPress mints for each user and sends to Agend server to server at sign-in. Choose this on a site with no SAML identity provider plugin, or to keep using the WordPress-minted identity even if a SAML IdP plugin is installed.', 'agend-apps-core' ),
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
			Agend_Apps_Settings::SSO_LINK_MECHANISM_SAML   => __( 'SAML assertion', 'agend-apps-core' ),
			Agend_Apps_Settings::SSO_LINK_MECHANISM_SERVER => __( 'Server to server', 'agend-apps-core' ),
		);

		echo '<p class="description">';
		if ( '' === $detected ) {
			esc_html_e( 'Detected: no SAML identity provider plugin found on this site.', 'agend-apps-core' );
		} elseif ( 'saml' === $detected ) {
			esc_html_e( 'Detected: the agend-saml-idp plugin is active.', 'agend-apps-core' );
		} else {
			esc_html_e( 'Detected: the miniOrange SAML IDP plugin is active.', 'agend-apps-core' );
		}
		echo ' ';
		printf(
			/* translators: %s: the resolved mechanism label ("SAML assertion" or "Server to server"). */
			esc_html__( 'Automatic currently resolves to: %s.', 'agend-apps-core' ),
			'<strong>' . esc_html( $mechanism_labels[ $resolved ] ?? $resolved ) . '</strong>'
		);
		echo '</p>';

		// The reason this control exists at all (docs/PLAN-wordpress-idp-
		// option-b.md section 5): a SAML IdP plugin's NameID is not the
		// WordPress-minted GUID, so leaving `wordpress` mode on Server to
		// server once a SAML IdP plugin is added would link the same person
		// twice, as two separate Agend identities.
		if (
			'' !== $detected
			&& Agend_Apps_Settings::MEMBER_AUTH_WORDPRESS === Agend_Apps_Settings::get_member_auth_mode()
			&& Agend_Apps_Settings::SSO_LINK_MECHANISM_SERVER === $resolved
		) {
			echo '<div class="notice notice-warning inline"><p>';
			esc_html_e(
				'A SAML identity provider plugin is active, member sign-in is set to WordPress account, and linking resolves to Server to server. The SAML NameID this plugin asserts will not match the GUID WordPress mints, so the same person will be linked twice as two separate Agend identities. Set the linking mechanism to SAML assertion, or switch member sign-in away from WordPress account.',
				'agend-apps-core'
			);
			echo '</p></div>';
		}
	}

	/**
	 * Renders the "link on user creation" checkbox field.
	 */
	public function render_link_on_user_create_field(): void {
		$value = Agend_Apps_Settings::link_on_user_create();
		?>
		<label>
			<input type="checkbox" name="agend_apps_sso_link_on_user_create" value="1" <?php checked( true, $value ); ?> />
			<?php esc_html_e( 'Attempt the Agend identity link as soon as a WordPress user account is created', 'agend-apps-core' ); ?>
		</label>
		<p class="description">
			<?php
			esc_html_e(
				'Off by default. This fires for every WordPress user created, including administrators and bulk imports, which is not always wanted. When off, the link is only attempted at the user\'s first sign-in.',
				'agend-apps-core'
			);
			?>
		</p>
		<?php
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
}

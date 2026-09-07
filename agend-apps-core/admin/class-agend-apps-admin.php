<?php
/**
 * Admin controller class.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all WordPress admin UI for Agend Apps Core.
 *
 * Registers the Settings API fields and sections, enqueues admin styles,
 * and handles the AJAX cache-clear action from the cache management tab.
 */
class Agend_Apps_Admin {

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'agend-apps';

	/**
	 * Option group name used with `settings_fields()`.
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'agend_apps_settings';

	/**
	 * Registers all admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_agend_apps_clear_cache', array( $this, 'handle_clear_cache' ) );
		add_action( 'wp_ajax_agend_apps_verify_api_key', array( $this, 'handle_verify_api_key' ) );
		add_action( 'show_user_profile', array( $this, 'render_user_agend_account' ) );
		add_action( 'edit_user_profile', array( $this, 'render_user_agend_account' ) );
	}

	/**
	 * Read-only "Agend account" state on the user profile
	 * (SPEC-CORE-20260907 US-2.2). Display only: no fields, no save handler,
	 * and never a token or identifier.
	 *
	 * @param WP_User $user Profile being viewed.
	 */
	public function render_user_agend_account( WP_User $user ): void {
		if ( get_current_user_id() !== (int) $user->ID && ! current_user_can( 'edit_users' ) ) {
			return;
		}

		if ( Agend_Apps_Member_Session::has_session( (int) $user->ID ) ) {
			$state = __( 'Linked', 'agend-apps-core' );
			$help  = __( 'This user holds an Agend member session on this site.', 'agend-apps-core' );
		} elseif ( '1' === (string) get_user_meta( (int) $user->ID, AGEND_APPS_IDENTITY_CONFLICT_META, true ) ) {
			$state = __( 'Existing Agend account', 'agend-apps-core' );
			$help  = __( 'An Agend account already exists for this email. The user must sign in with their Agend password, not a WordPress password.', 'agend-apps-core' );
		} else {
			$state = __( 'Not linked', 'agend-apps-core' );
			$help  = __( 'No Agend member session yet. One is created at the next sign-in with Agend credentials.', 'agend-apps-core' );
		}

		echo '<h2>' . esc_html__( 'Agend account', 'agend-apps-core' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tr>';
		echo '<th>' . esc_html__( 'Status', 'agend-apps-core' ) . '</th>';
		echo '<td><strong>' . esc_html( $state ) . '</strong><p class="description">' . esc_html( $help ) . '</p></td>';
		echo '</tr></table>';
	}

	/**
	 * Adds the Agend Apps settings page to the WordPress admin menu.
	 */
	public function add_menu_page(): void {
		add_options_page(
			__( 'Agend Apps', 'agend-apps-core' ),
			__( 'Agend Apps', 'agend-apps-core' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueues the admin stylesheet and script on the Agend Apps settings page.
	 *
	 * Passes translated strings to the script via `wp_localize_script()` so
	 * the JS file contains no PHP and can be served as a static asset.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'agend-apps-admin',
			AGEND_APPS_CORE_URL . 'admin/agend-apps-admin.css',
			array(),
			AGEND_APPS_CORE_VERSION
		);

		wp_enqueue_script(
			'agend-apps-admin',
			AGEND_APPS_CORE_URL . 'admin/agend-apps-admin.js',
			array(),
			AGEND_APPS_CORE_VERSION,
			true
		);

		wp_localize_script(
			'agend-apps-admin',
			'agendAppsAdminI18n',
			array(
				'keyVerified'        => __( 'Key verified.', 'agend-apps-core' ),
				'verificationFailed' => __( 'Verification failed.', 'agend-apps-core' ),
				'unexpectedError'    => __( 'An unexpected error occurred.', 'agend-apps-core' ),
				'type'               => __( 'Type:', 'agend-apps-core' ),
				'scopes'             => __( 'Scopes:', 'agend-apps-core' ),
				'apps'               => __( 'Apps:', 'agend-apps-core' ),
			)
		);
	}

	/**
	 * Registers all settings, sections, and fields via the Settings API.
	 */
	public function register_settings(): void {
		// API Configuration section.
		add_settings_section(
			'agend_apps_api_section',
			__( 'API Configuration', 'agend-apps-core' ),
			array( $this, 'render_api_section' ),
			self::PAGE_SLUG
		);

		register_setting(
			self::OPTION_GROUP,
			'agend_apps_environment',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_environment' ),
				'default'           => 'production',
			)
		);

		add_settings_field(
			'agend_apps_environment',
			__( 'Environment', 'agend-apps-core' ),
			array( $this, 'render_environment_field' ),
			self::PAGE_SLUG,
			'agend_apps_api_section'
		);

		register_setting(
			self::OPTION_GROUP,
			'agend_apps_custom_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);

		add_settings_field(
			'agend_apps_custom_url',
			__( 'Custom API URL', 'agend-apps-core' ),
			array( $this, 'render_custom_url_field' ),
			self::PAGE_SLUG,
			'agend_apps_api_section'
		);

		register_setting(
			self::OPTION_GROUP,
			'agend_apps_api_key',
			array(
				'type'              => 'string',
				// Write-only interception: a posted key is routed into the
				// encrypted secret store and NEVER persisted in this plain
				// option (the callback always returns ''). See
				// sanitize_api_key_setting() for the blank-keeps / explicit
				// clear semantics.
				'sanitize_callback' => array( $this, 'sanitize_api_key_setting' ),
				'default'           => '',
			)
		);

		add_settings_field(
			'agend_apps_api_key',
			__( 'API Key', 'agend-apps-core' ),
			array( $this, 'render_api_key_field' ),
			self::PAGE_SLUG,
			'agend_apps_api_section'
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
			'agend_apps_api_section'
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
			'agend_apps_api_section'
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
			'agend_apps_api_section'
		);

		register_setting(
			self::OPTION_GROUP,
			'agend_apps_webhook_secret',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		add_settings_field(
			'agend_apps_webhook_secret',
			__( 'Webhook Signing Secret', 'agend-apps-core' ),
			array( $this, 'render_webhook_secret_field' ),
			self::PAGE_SLUG,
			'agend_apps_api_section'
		);

		register_setting(
			self::OPTION_GROUP,
			'agend_apps_vercel_bypass_token',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		add_settings_field(
			'agend_apps_vercel_bypass_token',
			__( 'Vercel Protection Bypass', 'agend-apps-core' ),
			array( $this, 'render_vercel_bypass_field' ),
			self::PAGE_SLUG,
			'agend_apps_api_section'
		);

		// Cache TTL section.
		add_settings_section(
			'agend_apps_cache_section',
			__( 'Cache Settings', 'agend-apps-core' ),
			array( $this, 'render_cache_section' ),
			self::PAGE_SLUG
		);

		foreach ( Agend_Apps_Cache::get_all_keys() as $key => $config ) {
			$option_name = 'agend_apps_cache_' . $key;

			register_setting(
				self::OPTION_GROUP,
				$option_name,
				array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'default'           => $config['default_ttl'],
				)
			);

			add_settings_field(
				$option_name,
				/* translators: %s: Cache endpoint label. */
				sprintf( __( '%s TTL (seconds)', 'agend-apps-core' ), $config['label'] ),
				array( $this, 'render_ttl_field' ),
				self::PAGE_SLUG,
				'agend_apps_cache_section',
				array(
					'option_name' => $option_name,
					'default'     => $config['default_ttl'],
					'label_for'   => $option_name,
				)
			);
		}
	}

	/**
	 * Sanitizes the environment option value.
	 *
	 * @param string $value Raw submitted value.
	 *
	 * @return string One of `production`, `staging`, `local`, or `custom`.
	 */
	public function sanitize_environment( string $value ): string {
		$allowed = array( 'production', 'staging', 'local', 'custom' );

		return in_array( $value, $allowed, true ) ? $value : 'production';
	}

	/**
	 * Renders the settings page — delegates to view partials.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		require_once AGEND_APPS_CORE_DIR . 'admin/views/settings.php';
	}

	/**
	 * Renders the API Configuration settings section description.
	 */
	public function render_api_section(): void {
		echo '<p>' . esc_html__( 'Configure the connection to the Agend Gateway API.', 'agend-apps-core' ) . '</p>';
	}

	/**
	 * Renders the Cache Settings section description.
	 */
	public function render_cache_section(): void {
		echo '<p>' . esc_html__( 'Set how long (in seconds) API responses are cached.', 'agend-apps-core' ) . '</p>';
	}

	/**
	 * Renders the environment select field.
	 */
	public function render_environment_field(): void {
		$value   = get_option( 'agend_apps_environment', 'production' );
		$options = array(
			'production' => __( 'Production', 'agend-apps-core' ),
			'staging'    => __( 'Staging', 'agend-apps-core' ),
			'local'      => __( 'Local', 'agend-apps-core' ),
			'custom'     => __( 'Custom', 'agend-apps-core' ),
		);

		echo '<select id="agend_apps_environment" name="agend_apps_environment">';
		foreach ( $options as $option_value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $option_value ),
				selected( $value, $option_value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Renders the custom URL text field.
	 */
	public function render_custom_url_field(): void {
		$value = get_option( 'agend_apps_custom_url', '' );
		printf(
			'<input type="url" id="agend_apps_custom_url" name="agend_apps_custom_url" value="%s" class="regular-text" placeholder="https://your-api.example.com" />',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Required when Environment is set to Custom.', 'agend-apps-core' ) . '</p>';
	}

	/**
	 * Renders the optional Vercel deployment-protection bypass token field.
	 *
	 * When set, the value is sent as the `x-vercel-protection-bypass` header on
	 * every outbound API request, allowing the plugin to reach a gateway
	 * deployment protected by Vercel (typically the Staging environment).
	 */
	public function render_vercel_bypass_field(): void {
		$value = get_option( 'agend_apps_vercel_bypass_token', '' );
		printf(
			'<input type="password" id="agend_apps_vercel_bypass_token" name="agend_apps_vercel_bypass_token" value="%s" class="regular-text" autocomplete="off" />',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Optional. Sent as the x-vercel-protection-bypass header on all API requests. Required when the selected environment (typically Staging) sits behind Vercel deployment protection. Leave blank to omit the header.', 'agend-apps-core' ) . '</p>';
	}

	/**
	 * Settings-API interceptor for the API key field: routes a posted key
	 * into the encrypted secret store and returns '' so nothing lands in the
	 * plain `agend_apps_api_key` option. A blank submit keeps the stored
	 * value; the explicit clear checkbox (read from the same options.php
	 * POST) removes it. The value is trimmed but never content-sanitised —
	 * a key is opaque and sanitize_text_field() could corrupt it.
	 *
	 * @param mixed $value The posted field value.
	 * @return string Always '' — the plain option never holds the key.
	 */
	public function sanitize_api_key_setting( $value ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php has already verified the settings nonce before sanitize callbacks run.
		if ( ! empty( $_POST['agend_apps_api_key_clear'] ) ) {
			Agend_Apps_Secret_Store::delete( Agend_Apps_Secret_Store::KEY_API_KEY );
			return '';
		}

		$posted = trim( (string) $value );
		if ( '' !== $posted ) {
			Agend_Apps_Secret_Store::set( Agend_Apps_Secret_Store::KEY_API_KEY, $posted );
		}

		return '';
	}

	/**
	 * Renders the write-only API key field with an inline verify button.
	 *
	 * The stored key is never rendered (it is encrypted at rest and shown
	 * only as a set / not-set status). The verify button triggers an AJAX
	 * call to `agend_apps_verify_api_key`, which reads the SAVED key
	 * server-side, and displays the returned scopes and app IDs (or an
	 * error message) in a result panel below the field without a page
	 * reload — so save first, then verify.
	 */
	public function render_api_key_field(): void {
		$source = Agend_Apps_Secret_Store::source_of( Agend_Apps_Secret_Store::KEY_API_KEY, 'AGEND_APPS_API_KEY' );

		printf(
			'<input type="password" id="agend_apps_api_key" name="agend_apps_api_key" value="" placeholder="%s" class="regular-text" autocomplete="new-password" />',
			esc_attr( '' !== $source ? '********' : '' )
		);
		printf(
			'<button type="button" id="agend-apps-verify-key-btn" class="button" style="margin-left:8px;" data-nonce="%s" data-ajax-url="%s">%s</button>',
			esc_attr( wp_create_nonce( 'agend_apps_verify_api_key' ) ),
			esc_attr( admin_url( 'admin-ajax.php' ) ),
			esc_html__( 'Verify Key', 'agend-apps-core' )
		);

		// The verify JS dereferences this panel unconditionally before it
		// fires the request — omitting it kills the button (regression found
		// 2026-08-03 after the write-only field rewrite dropped it).
		echo '<div id="agend-apps-verify-result" class="agend-apps-verify-result" style="display:none;"></div>';

		echo '<p class="description">';
		echo esc_html__( 'Status:', 'agend-apps-core' ) . ' ';
		if ( 'constant' === $source ) {
			echo '<strong>' . esc_html__( 'set via the AGEND_APPS_API_KEY wp-config.php constant (overrides the field above)', 'agend-apps-core' ) . '</strong>';
		} elseif ( 'stored' === $source ) {
			echo '<strong>' . esc_html__( 'set (stored encrypted)', 'agend-apps-core' ) . '</strong>';
		} else {
			echo '<strong style="color:#b32d2e;">' . esc_html__( 'not set', 'agend-apps-core' ) . '</strong>';
		}
		echo '</p>';

		echo '<p class="description">';
		esc_html_e( 'The key is stored encrypted and never shown again after saving. Leave the field blank on save to keep the stored value. Save before using Verify Key. Note: rotating the WordPress salts invalidates the stored key — re-enter it if that happens.', 'agend-apps-core' );
		echo '</p>';

		if ( 'stored' === $source ) {
			echo '<p><label>';
			echo '<input type="checkbox" name="agend_apps_api_key_clear" value="1" /> ';
			esc_html_e( 'Clear the stored key on save', 'agend-apps-core' );
			echo '</label></p>';
		}
	}

	/**
	 * Renders the account slug text field.
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
	 * Renders the portal URL text field.
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
	 * Renders the webhook signing secret field.
	 *
	 * The secret shown once when the Agend webhook subscription is created in
	 * the dashboard. It authenticates deliveries to the incoming webhook
	 * endpoint, which keeps signed-in members' membership snapshot usermeta
	 * fresh for content restrictions.
	 */
	public function render_webhook_secret_field(): void {
		$value = get_option( 'agend_apps_webhook_secret', '' );
		printf(
			'<input type="password" id="agend_apps_webhook_secret" name="agend_apps_webhook_secret" value="%s" class="regular-text" autocomplete="off" />',
			esc_attr( $value )
		);
		echo '<p class="description">';
		printf(
			/* translators: %s: the webhook receiver URL. */
			esc_html__( 'Signing secret of the Agend webhook subscription pointed at this site. Subscribe crm.membership.* and crm.seat.* events to: %s', 'agend-apps-core' ),
			'<code>' . esc_html( rest_url( 'agend-apps/v1/webhooks/incoming' ) ) . '</code>'
		);
		echo '</p>';
	}

	/**
	 * Renders the external-id meta key text field.
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
	 * Renders a TTL number input field.
	 *
	 * @param array $args Field arguments including `option_name` and `default`.
	 */
	public function render_ttl_field( array $args ): void {
		$option_name = $args['option_name'];
		$default     = $args['default'];
		$value       = (int) get_option( $option_name, $default );

		printf(
			'<input type="number" id="%s" name="%s" value="%d" min="0" step="1" class="small-text" />',
			esc_attr( $option_name ),
			esc_attr( $option_name ),
			$value
		);
	}

	/**
	 * Sanitizes a Settings API checkbox: present in `$_POST` (any truthy
	 * string) means checked/true; absent means false. WordPress does not post
	 * an unchecked checkbox at all, so the sanitize callback receives no
	 * argument in that case -- `register_setting()`'s boolean type coercion
	 * then needs an explicit false rather than the field being skipped.
	 *
	 * @param mixed $value The posted field value, or null when unchecked.
	 * @return bool
	 */
	public function sanitize_checkbox( $value ): bool {
		return ! empty( $value );
	}

	/**
	 * Handles the AJAX request to verify the configured API key.
	 *
	 * Calls `agend_apps_verify_api_key()` and returns a JSON payload
	 * containing the key's scopes and authorised app IDs on success, or
	 * the API error message on failure.
	 */
	public function handle_verify_api_key(): void {
		check_ajax_referer( 'agend_apps_verify_api_key', '_ajax_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'agend-apps-core' ) ), 403 );
		}

		$result = agend_apps_verify_api_key();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Handles the AJAX request to clear one or all cache entries.
	 *
	 * Expects `_ajax_nonce` and an optional `endpoint_key` parameter.
	 * Responds with JSON.
	 */
	public function handle_clear_cache(): void {
		check_ajax_referer( 'agend_apps_clear_cache', '_ajax_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'agend-apps-core' ) ), 403 );
		}

		$endpoint_key = isset( $_POST['endpoint_key'] ) ? sanitize_key( $_POST['endpoint_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( '' === $endpoint_key || 'all' === $endpoint_key ) {
			Agend_Apps_Cache::clear_all();
			wp_send_json_success( array( 'message' => __( 'All caches cleared.', 'agend-apps-core' ) ) );
		}

		$all_keys = Agend_Apps_Cache::get_all_keys();

		if ( ! array_key_exists( $endpoint_key, $all_keys ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown cache key.', 'agend-apps-core' ) ), 400 );
		}

		Agend_Apps_Cache::clear( $endpoint_key );
		wp_send_json_success( array( 'message' => __( 'Cache cleared.', 'agend-apps-core' ) ) );
	}
}

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
				'sanitize_callback' => 'sanitize_text_field',
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
	 * Renders the API key password field with an inline verify button.
	 *
	 * The button triggers an AJAX call to `agend_apps_verify_api_key` and
	 * displays the returned scopes and app IDs (or an error message) in a
	 * result panel below the field without a page reload.
	 */
	public function render_api_key_field(): void {
		$value = get_option( 'agend_apps_api_key', '' );
		printf(
			'<input type="password" id="agend_apps_api_key" name="agend_apps_api_key" value="%s" class="regular-text" autocomplete="off" />',
			esc_attr( $value )
		);
		printf(
			'<button type="button" id="agend-apps-verify-key-btn" class="button" style="margin-left:8px;" data-nonce="%s" data-ajax-url="%s">%s</button>',
			esc_attr( wp_create_nonce( 'agend_apps_verify_api_key' ) ),
			esc_attr( admin_url( 'admin-ajax.php' ) ),
			esc_html__( 'Verify Key', 'agend-apps-core' )
		);
		echo '<div id="agend-apps-verify-result" class="agend-apps-verify-result" style="display:none;"></div>';
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

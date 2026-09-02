<?php
/**
 * Admin settings page for the Upbeat Entitlement Mirror.
 *
 * A Tools submenu page (matching the agend-directory-sync precedent for a
 * standalone sibling plugin), registering the same Settings API
 * section/fields and types-sync AJAX action the module used when it lived
 * inside agend-apps-core (SPEC-AMS-20260804-upbeat-entitlement-mirror
 * US-2.1/US-2.2/US-2.3/US-2.4, SPEC-CRM-20260805-member-entitlement-grants
 * US-5.1).
 *
 * @package Agend_Entitlement_Mirror
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Entitlement_Mirror_Admin_Page' ) ) :

	/**
	 * Registers the Tools > Agend Entitlement Mirror admin page.
	 */
	final class Agend_Entitlement_Mirror_Admin_Page {

		/**
		 * Tools submenu slug, also used as the Settings-API "page" argument
		 * for `do_settings_sections()`.
		 *
		 * @var string
		 */
		const MENU_SLUG = 'agend-entitlement-mirror';

		/**
		 * Option group name used with `settings_fields()`.
		 *
		 * @var string
		 */
		const OPTION_GROUP = 'agend_entitlement_mirror_settings';

		/**
		 * Registers WP hooks. Called from the plugin's post_include_files().
		 */
		public static function setup_hooks(): void {
			add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
			add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
			add_action( 'wp_ajax_agend_apps_sync_entitlement_types', array( __CLASS__, 'handle_sync_entitlement_types' ) );
		}

		/**
		 * Adds the Agend Entitlement Mirror page under Tools.
		 */
		public static function register_menu(): void {
			add_submenu_page(
				'tools.php',
				__( 'Agend Entitlement Mirror', 'agend-entitlement-mirror' ),
				__( 'Agend Entitlement Mirror', 'agend-entitlement-mirror' ),
				'manage_options',
				self::MENU_SLUG,
				array( __CLASS__, 'render_page' )
			);
		}

		/**
		 * Registers the Settings API section and fields
		 * (SPEC-AMS-20260804-upbeat-entitlement-mirror US-2.1/US-2.2/US-2.3).
		 */
		public static function register_settings(): void {
			add_settings_section(
				'agend_entitlement_mirror_section',
				__( 'Entitlement Mirror', 'agend-entitlement-mirror' ),
				array( __CLASS__, 'render_entitlement_mirror_section' ),
				self::MENU_SLUG
			);

			register_setting(
				self::OPTION_GROUP,
				'agend_entitlement_mirror_enabled',
				array(
					'type'              => 'boolean',
					'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
					'default'           => false,
				)
			);
			add_settings_field(
				'agend_entitlement_mirror_enabled',
				__( 'Enable Entitlement Mirror', 'agend-entitlement-mirror' ),
				array( __CLASS__, 'render_entitlement_mirror_enabled_field' ),
				self::MENU_SLUG,
				'agend_entitlement_mirror_section'
			);

			register_setting(
				self::OPTION_GROUP,
				'agend_entitlement_mirror_categories',
				array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'default'           => Agend_Entitlement_Mirror_Settings::ENTITLEMENT_MIRROR_DEFAULT_CATEGORY,
				)
			);
			add_settings_field(
				'agend_entitlement_mirror_categories',
				__( 'Mirrored Categories', 'agend-entitlement-mirror' ),
				array( __CLASS__, 'render_entitlement_mirror_categories_field' ),
				self::MENU_SLUG,
				'agend_entitlement_mirror_section'
			);

			register_setting(
				self::OPTION_GROUP,
				'agend_entitlement_mirror_external_source',
				array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
				)
			);
			add_settings_field(
				'agend_entitlement_mirror_external_source',
				__( 'Contact External Source', 'agend-entitlement-mirror' ),
				array( __CLASS__, 'render_entitlement_mirror_external_source_field' ),
				self::MENU_SLUG,
				'agend_entitlement_mirror_section'
			);

			register_setting(
				self::OPTION_GROUP,
				'agend_entitlement_mirror_login_throttle',
				array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'default'           => Agend_Entitlement_Mirror_Settings::ENTITLEMENT_MIRROR_DEFAULT_LOGIN_THROTTLE,
				)
			);
			add_settings_field(
				'agend_entitlement_mirror_login_throttle',
				__( 'Login Reconciliation Throttle (seconds)', 'agend-entitlement-mirror' ),
				array( __CLASS__, 'render_entitlement_mirror_login_throttle_field' ),
				self::MENU_SLUG,
				'agend_entitlement_mirror_section'
			);

			register_setting(
				self::OPTION_GROUP,
				'agend_entitlement_mirror_source_key',
				array(
					'type'              => 'string',
					'sanitize_callback' => array( __CLASS__, 'sanitize_source_key' ),
					'default'           => Agend_Entitlement_Mirror_Settings::ENTITLEMENT_MIRROR_DEFAULT_SOURCE_KEY,
				)
			);
			add_settings_field(
				'agend_entitlement_mirror_source_key',
				__( 'Source Key', 'agend-entitlement-mirror' ),
				array( __CLASS__, 'render_entitlement_mirror_source_key_field' ),
				self::MENU_SLUG,
				'agend_entitlement_mirror_section'
			);

			register_setting(
				self::OPTION_GROUP,
				Agend_Entitlement_Mirror_Source_Registry::OPTION_DATA_SOURCE,
				array(
					'type'              => 'string',
					'sanitize_callback' => array( __CLASS__, 'sanitize_data_source' ),
					'default'           => Agend_Entitlement_Mirror_Source_Registry::DEFAULT_SOURCE_KEY,
				)
			);
			add_settings_field(
				Agend_Entitlement_Mirror_Source_Registry::OPTION_DATA_SOURCE,
				__( 'Data Source', 'agend-entitlement-mirror' ),
				array( __CLASS__, 'render_entitlement_mirror_data_source_field' ),
				self::MENU_SLUG,
				'agend_entitlement_mirror_section'
			);

			register_setting(
				self::OPTION_GROUP,
				Agend_Entitlement_Mirror_Http_Api_Source::OPTION_SETTINGS,
				array(
					'type'              => 'array',
					'sanitize_callback' => array( 'Agend_Entitlement_Mirror_Http_Api_Source', 'sanitize_settings' ),
					'default'           => array(),
				)
			);
			add_settings_field(
				Agend_Entitlement_Mirror_Http_Api_Source::OPTION_SETTINGS,
				__( 'Custom HTTP API Settings', 'agend-entitlement-mirror' ),
				array( __CLASS__, 'render_entitlement_mirror_http_api_field' ),
				self::MENU_SLUG,
				'agend_entitlement_mirror_section'
			);
		}

		/**
		 * Sanitizes the data-source field: only a key registered in
		 * Agend_Entitlement_Mirror_Source_Registry is accepted, otherwise the
		 * default (`upbeat`) is stored -- an unrecognised value would silently
		 * resolve to `upbeat` anyway at read time
		 * (Agend_Entitlement_Mirror_Source_Registry::active()), so rejecting it
		 * at save time keeps the stored option consistent with what actually
		 * runs.
		 *
		 * @param mixed $value The posted field value.
		 * @return string
		 */
		public static function sanitize_data_source( $value ): string {
			$candidate = trim( (string) $value );
			$sources   = Agend_Entitlement_Mirror_Source_Registry::all();

			return isset( $sources[ $candidate ] ) ? $candidate : Agend_Entitlement_Mirror_Source_Registry::DEFAULT_SOURCE_KEY;
		}

		/**
		 * Sanitizes the `source_key` field (US-5.1 AC8): lowercase + trim, then
		 * reject (keep the prior stored value, with an admin notice) when the
		 * result is malformed or the reserved literal `manual`. Silently
		 * coercing an invalid value to the default would let an operator save a
		 * value that only LOOKS like their intended key while the mirror keeps
		 * running under the default -- the settings error makes the rejection
		 * visible instead.
		 *
		 * @param mixed $value The posted field value.
		 * @return string
		 */
		public static function sanitize_source_key( $value ): string {
			$candidate = strtolower( trim( (string) $value ) );

			if ( 'manual' === $candidate || 1 !== preg_match( Agend_Entitlement_Mirror_Settings::ENTITLEMENT_MIRROR_SOURCE_KEY_PATTERN, $candidate ) ) {
				add_settings_error(
					'agend_entitlement_mirror_source_key',
					'agend_entitlement_mirror_source_key_invalid',
					__( 'Source Key was not saved: it must be lowercase letters/digits/underscores/dots, 3-64 characters, and cannot be "manual" (reserved). The previous value was kept.', 'agend-entitlement-mirror' )
				);

				return (string) get_option( 'agend_entitlement_mirror_source_key', Agend_Entitlement_Mirror_Settings::ENTITLEMENT_MIRROR_DEFAULT_SOURCE_KEY );
			}

			return $candidate;
		}

		/**
		 * Sanitizes a Settings API checkbox: present in `$_POST` (any truthy
		 * string) means checked/true; absent means false. WordPress does not
		 * post an unchecked checkbox at all, so the sanitize callback receives
		 * no argument in that case -- `register_setting()`'s boolean type
		 * coercion then needs an explicit false rather than the field being
		 * skipped.
		 *
		 * @param mixed $value The posted field value, or null when unchecked.
		 * @return bool
		 */
		public static function sanitize_checkbox( $value ): bool {
			return ! empty( $value );
		}

		/**
		 * Renders the settings page.
		 */
		public static function render_page(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			require_once AGEND_ENTITLEMENT_MIRROR_DIR . '/admin/views/entitlement-mirror.php';
		}

		/**
		 * Renders the Entitlement Mirror settings section description.
		 */
		public static function render_entitlement_mirror_section(): void {
			echo '<p>' . esc_html__( 'Mirrors member entitlements from the configured Data Source into Agend CRM entitlement grants. Off by default: enable only after the Contact External Source below is confirmed for this install.', 'agend-entitlement-mirror' ) . '</p>';
		}

		/**
		 * Renders the enable-mirror checkbox.
		 */
		public static function render_entitlement_mirror_enabled_field(): void {
			$checked = Agend_Entitlement_Mirror_Settings::is_entitlement_mirror_enabled();
			printf(
				'<label><input type="checkbox" id="agend_entitlement_mirror_enabled" name="agend_entitlement_mirror_enabled" value="1"%s /> %s</label>',
				checked( $checked, true, false ),
				esc_html__( 'Sync Upbeat entitlements to Agend CRM entitlement grants on webhook, login, and sweep.', 'agend-entitlement-mirror' )
			);
		}

		/**
		 * Renders the mirrored-categories textarea (one category per line).
		 */
		public static function render_entitlement_mirror_categories_field(): void {
			$value = get_option( 'agend_entitlement_mirror_categories', Agend_Entitlement_Mirror_Settings::ENTITLEMENT_MIRROR_DEFAULT_CATEGORY );
			printf(
				'<textarea id="agend_entitlement_mirror_categories" name="agend_entitlement_mirror_categories" rows="3" class="regular-text" placeholder="%s">%s</textarea>',
				esc_attr( Agend_Entitlement_Mirror_Settings::ENTITLEMENT_MIRROR_DEFAULT_CATEGORY ),
				esc_textarea( $value )
			);
			echo '<p class="description">';
			esc_html_e( 'One Upbeat entitlement category per line. Only entitlements in these categories are mirrored (Decision 2.5).', 'agend-entitlement-mirror' );
			echo '</p>';
		}

		/**
		 * Renders the contact external_source text field.
		 */
		public static function render_entitlement_mirror_external_source_field(): void {
			$value = get_option( 'agend_entitlement_mirror_external_source', '' );
			printf(
				'<input type="text" id="agend_entitlement_mirror_external_source" name="agend_entitlement_mirror_external_source" value="%s" class="regular-text" placeholder="%s" />',
				esc_attr( $value ),
				esc_attr__( 'e.g. upbeat_membership_number', 'agend-entitlement-mirror' )
			);
			echo '<p class="description">';
			esc_html_e( 'Must match whatever your Agend account provisioning stamps as external_source on crm_contacts, or externalId resolution always falls through to the exact-email fallback (spec Open Question 1). Leave empty to resolve/create contacts by email only.', 'agend-entitlement-mirror' );
			echo '</p>';
		}

		/**
		 * Renders the login-throttle number field.
		 */
		public static function render_entitlement_mirror_login_throttle_field(): void {
			$value = (int) get_option( 'agend_entitlement_mirror_login_throttle', Agend_Entitlement_Mirror_Settings::ENTITLEMENT_MIRROR_DEFAULT_LOGIN_THROTTLE );
			printf(
				'<input type="number" id="agend_entitlement_mirror_login_throttle" name="agend_entitlement_mirror_login_throttle" value="%d" min="60" step="1" class="small-text" />',
				$value
			);
			echo '<p class="description">';
			esc_html_e( 'Minimum seconds between login-triggered reconciliation syncs for the same member (default 900 = 15 minutes).', 'agend-entitlement-mirror' );
			echo '</p>';
		}

		/**
		 * Renders the `source_key` text field.
		 */
		public static function render_entitlement_mirror_source_key_field(): void {
			$value = Agend_Entitlement_Mirror_Settings::get_entitlement_mirror_source_key();
			printf(
				'<input type="text" id="agend_entitlement_mirror_source_key" name="agend_entitlement_mirror_source_key" value="%s" class="regular-text" placeholder="%s" />',
				esc_attr( $value ),
				esc_attr( Agend_Entitlement_Mirror_Settings::ENTITLEMENT_MIRROR_DEFAULT_SOURCE_KEY )
			);
			echo '<p class="description">';
			esc_html_e( 'The stable identifier Agend uses for this upstream system\'s entitlement types and grants. Lowercase letters, digits, underscores, and dots only, 3-64 characters. "manual" is reserved for staff-made grants and cannot be used. Set this once before the first sync: changing it later strands grants made under the old key -- they stay granted under that old source until revoked there, they do not move.', 'agend-entitlement-mirror' );
			echo '</p>';
		}

		/**
		 * Renders the data-source `<select>`, listing every source registered
		 * in Agend_Entitlement_Mirror_Source_Registry (built-ins plus anything
		 * added via the `agend_entitlement_mirror_sources` filter), each
		 * annotated with its current availability.
		 */
		public static function render_entitlement_mirror_data_source_field(): void {
			$sources     = Agend_Entitlement_Mirror_Source_Registry::all();
			$active_key  = Agend_Entitlement_Mirror_Source_Registry::active()->get_key();

			echo '<select id="' . esc_attr( Agend_Entitlement_Mirror_Source_Registry::OPTION_DATA_SOURCE ) . '" name="' . esc_attr( Agend_Entitlement_Mirror_Source_Registry::OPTION_DATA_SOURCE ) . '">';
			foreach ( $sources as $key => $source ) {
				printf(
					'<option value="%s"%s>%s%s</option>',
					esc_attr( $key ),
					selected( $active_key, $key, false ),
					esc_html( $source->get_label() ),
					$source->is_available() ? '' : ' ' . esc_html__( '(unavailable)', 'agend-entitlement-mirror' )
				);
			}
			echo '</select>';

			$active_source = $sources[ $active_key ] ?? null;
			if ( $active_source && ! $active_source->is_available() ) {
				echo '<p class="description" style="color:#b32d2e;">' . esc_html( $active_source->get_unavailable_reason() ) . '</p>';
			}

			echo '<p class="description">';
			esc_html_e( 'Which system the mirror reads member entitlements, member profiles, the entitlement-type catalogue, and the member list from.', 'agend-entitlement-mirror' );
			echo '</p>';
		}

		/**
		 * Renders the Custom HTTP API source's settings (base URL, timeout,
		 * endpoint paths, member-list page size). Shown regardless of which
		 * source is currently active -- kept simple rather than JS-toggled,
		 * since it is only consulted when Data Source above is set to Custom
		 * HTTP API. The bearer token itself is never rendered or stored here:
		 * see AGEND_ENTITLEMENT_MIRROR_HTTP_TOKEN in wp-config.php.
		 */
		public static function render_entitlement_mirror_http_api_field(): void {
			$settings = Agend_Entitlement_Mirror_Http_Api_Source::resolve_settings();
			$prefix   = Agend_Entitlement_Mirror_Http_Api_Source::OPTION_SETTINGS;

			printf(
				'<p><label>%s<br /><input type="url" name="%s[base_url]" value="%s" class="regular-text" placeholder="https://example.test/api" /></label></p>',
				esc_html__( 'Base URL', 'agend-entitlement-mirror' ),
				esc_attr( $prefix ),
				esc_attr( $settings['base_url'] )
			);

			foreach (
				array(
					'entitlements_path' => __( 'Member Entitlements Path', 'agend-entitlement-mirror' ),
					'profile_path'      => __( 'Member Profile Path', 'agend-entitlement-mirror' ),
					'types_path'        => __( 'Entitlement Types Path', 'agend-entitlement-mirror' ),
					'members_path'      => __( 'Member List Path', 'agend-entitlement-mirror' ),
				) as $field => $label
			) {
				printf(
					'<p><label>%s<br /><input type="text" name="%s[%s]" value="%s" class="regular-text" /></label></p>',
					esc_html( $label ),
					esc_attr( $prefix ),
					esc_attr( $field ),
					esc_attr( $settings[ $field ] )
				);
			}

			printf(
				'<p><label>%s<br /><input type="number" name="%s[page_size]" value="%d" min="%d" max="%d" class="small-text" /></label></p>',
				esc_html__( 'Member List Page Size', 'agend-entitlement-mirror' ),
				esc_attr( $prefix ),
				(int) $settings['page_size'],
				Agend_Entitlement_Mirror_Http_Api_Source::MIN_PAGE_SIZE,
				Agend_Entitlement_Mirror_Http_Api_Source::MAX_PAGE_SIZE
			);

			echo '<p class="description">';
			esc_html_e( 'Entitlements/profile paths accept a {member_id} placeholder. Optional bearer token: define AGEND_ENTITLEMENT_MIRROR_HTTP_TOKEN in wp-config.php (never stored in the database).', 'agend-entitlement-mirror' );
			echo '</p>';
		}

		/**
		 * Handles the AJAX request to sync the entitlement type declarations
		 * (US-2.4 AC2 / US-5.1).
		 *
		 * Renders the per-entry created/updated/existing outcome on success, or
		 * the gateway error verbatim (no secret material) on failure.
		 */
		public static function handle_sync_entitlement_types(): void {
			check_ajax_referer( 'agend_apps_sync_entitlement_types', '_ajax_nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'agend-entitlement-mirror' ) ), 403 );
			}

			if ( ! class_exists( 'Agend_Entitlement_Sync' ) ) {
				wp_send_json_error( array( 'message' => __( 'The entitlement mirror module is not loaded.', 'agend-entitlement-mirror' ) ) );
			}

			$result = Agend_Entitlement_Sync::sync_types();

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			wp_send_json_success( $result );
		}
	}

endif;

<?php
/**
 * Community section of the Agend Apps settings screen.
 *
 * One setting, which matters: the channels of the association's Agend Loop
 * community that may be shown to anyone on the website. Everything the
 * Community widgets show is held to it. See `includes/community.php`.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Community section on the existing Agend Apps settings page, and
 * the button that clears what the Community widgets have stored.
 */
class Agend_Apps_Community_Admin {

	/**
	 * Settings section id.
	 */
	const SECTION = 'agend_apps_community_section';

	/**
	 * Admin-post action that clears the stored community copies.
	 */
	const ACTION_CLEAR = 'agend_apps_community_clear_cache';

	/**
	 * Hooks the section in. It joins the page after the core sections have been
	 * registered, then moves above the Cache settings, which are long.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ), 20 );
		add_action( 'admin_post_' . self::ACTION_CLEAR, array( $this, 'handle_clear_cache' ) );
	}

	/**
	 * Registers the option, the section and its field.
	 */
	public function register_settings(): void {
		register_setting(
			Agend_Apps_Admin::OPTION_GROUP,
			AGEND_APPS_COMMUNITY_OPTION_CHANNELS,
			array(
				'type'              => 'array',
				'sanitize_callback' => 'agend_apps_community_clean_ids',
				'default'           => array(),
			)
		);

		register_setting(
			Agend_Apps_Admin::OPTION_GROUP,
			AGEND_APPS_COMMUNITY_OPTION_SSO_CONNECTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'agend_apps_community_clean_connection_slug',
				'default'           => '',
			)
		);

		add_settings_section(
			self::SECTION,
			__( 'Community', 'agend-apps-core' ),
			array( $this, 'render_section' ),
			Agend_Apps_Admin::PAGE_SLUG
		);

		add_settings_field(
			AGEND_APPS_COMMUNITY_OPTION_CHANNELS,
			__( 'Channels shown on the website', 'agend-apps-core' ),
			array( $this, 'render_channels_field' ),
			Agend_Apps_Admin::PAGE_SLUG,
			self::SECTION
		);

		add_settings_field(
			AGEND_APPS_COMMUNITY_OPTION_SSO_CONNECTION,
			__( 'Single sign-on connection for My Communities', 'agend-apps-core' ),
			array( $this, 'render_sso_connection_field' ),
			Agend_Apps_Admin::PAGE_SLUG,
			self::SECTION
		);

		$this->move_above_cache();
	}

	/**
	 * Renders the optional SSO connection slug used by the My Communities links.
	 */
	public function render_sso_connection_field(): void {
		printf(
			'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" autocomplete="off" />',
			esc_attr( AGEND_APPS_COMMUNITY_OPTION_SSO_CONNECTION ),
			esc_attr( agend_apps_community_sso_connection() )
		);
		echo '<p class="description">' . esc_html__( 'Optional. The slug of the Agend single sign-on connection that signs members in when they choose Access community on the My Communities page. Leave empty to let Agend choose. The My Communities links also need the Agend account slug set above.', 'agend-apps-core' ) . '</p>';
	}

	/**
	 * Puts the Community section directly above the Cache settings, so an
	 * administrator does not have to scroll past every cache timer to find it.
	 */
	private function move_above_cache(): void {
		global $wp_settings_sections;

		$page = Agend_Apps_Admin::PAGE_SLUG;

		if ( ! isset( $wp_settings_sections[ $page ][ self::SECTION ], $wp_settings_sections[ $page ]['agend_apps_cache_section'] ) ) {
			return;
		}

		$mine    = $wp_settings_sections[ $page ][ self::SECTION ];
		$ordered = array();

		foreach ( $wp_settings_sections[ $page ] as $id => $section ) {
			if ( self::SECTION === $id ) {
				continue;
			}

			if ( 'agend_apps_cache_section' === $id ) {
				$ordered[ self::SECTION ] = $mine;
			}

			$ordered[ $id ] = $section;
		}

		$wp_settings_sections[ $page ] = $ordered;
	}

	/**
	 * Renders the section introduction: what the setting means for the
	 * association, in plain words.
	 */
	public function render_section(): void {
		echo '<p>' . esc_html__( 'The Community widgets show posts and channels from your Agend Loop community on your website. Nothing is shown until you allow a channel here.', 'agend-apps-core' ) . '</p>';
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Anyone who visits your website can read what is in an allowed channel, whether or not they are a member. That includes any names written in the text of posts. Allow only channels you are happy to publish.', 'agend-apps-core' ) . '</p></div>';
	}

	/**
	 * Renders the channel checkboxes.
	 *
	 * Only public channels and feeds are offered. Committees, private channels
	 * and direct messages are never listed. If the list could not be loaded the
	 * saved choice is carried through unchanged, so saving the page cannot wipe
	 * it.
	 */
	public function render_channels_field(): void {
		$allowed = agend_apps_community_allowed_ids();
		$choices = agend_apps_community_admin_channel_choices();
		$name    = AGEND_APPS_COMMUNITY_OPTION_CHANNELS;

		if ( is_wp_error( $choices ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Your channels could not be loaded, so the list below is unavailable. Your saved choice is kept as it is. Check the connection above, then reload this page.', 'agend-apps-core' ) . '</p></div>';

			foreach ( $allowed as $id ) {
				echo '<input type="hidden" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $id ) . '">';
			}

			$this->render_clear_button();
			return;
		}

		if ( array() === $choices ) {
			echo '<p>' . esc_html__( 'There are no public channels in your community yet.', 'agend-apps-core' ) . '</p>';
			$this->render_clear_button();
			return;
		}

		echo '<fieldset><legend class="screen-reader-text"><span>' . esc_html__( 'Channels shown on the website', 'agend-apps-core' ) . '</span></legend>';

		foreach ( $choices as $channel ) {
			printf(
				'<p><label><input type="checkbox" name="%1$s[]" value="%2$s"%3$s> <strong>%4$s</strong></label>%5$s</p>',
				esc_attr( $name ),
				esc_attr( $channel['id'] ),
				checked( in_array( $channel['id'], $allowed, true ), true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by checked().
				esc_html( $channel['name'] ),
				'' !== $channel['description'] ? '<br><span class="description">' . esc_html( $channel['description'] ) . '</span>' : ''  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			);
		}

		echo '</fieldset>';

		$unavailable = count( array_diff( $allowed, array_column( $choices, 'id' ) ) );

		if ( $unavailable > 0 ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %d: number of saved channels that are no longer available. */
					_n(
						'%d saved channel is no longer available, so it is not shown. Saving this page removes it from the list.',
						'%d saved channels are no longer available, so they are not shown. Saving this page removes them from the list.',
						$unavailable,
						'agend-apps-core'
					),
					$unavailable
				)
			) . '</p>';
		}

		echo '<p class="description">' . esc_html__( 'Only public channels are listed. Private channels, committees and direct messages are never shown on the website.', 'agend-apps-core' ) . '</p>';

		$this->render_clear_button();
	}

	/**
	 * Renders the button that clears the stored copies. A link rather than a
	 * form button: this sits inside the settings form, and forms cannot nest.
	 */
	private function render_clear_button(): void {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_CLEAR ), self::ACTION_CLEAR );

		echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Clear community content', 'agend-apps-core' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'Community content is kept for a few minutes so your website stays fast. If you remove or change a post and need it gone from your website straight away, clear it here.', 'agend-apps-core' ) . '</p>';
	}

	/**
	 * Clears the stored copies and returns to the settings page.
	 */
	public function handle_clear_cache(): void {
		check_admin_referer( self::ACTION_CLEAR );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'agend-apps-core' ), '', array( 'response' => 403 ) );
		}

		agend_apps_community_clear_cache();

		wp_safe_redirect( admin_url( 'options-general.php?page=' . Agend_Apps_Admin::PAGE_SLUG ) );
		exit;
	}
}

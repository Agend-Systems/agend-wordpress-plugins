<?php
/**
 * Plugin Name:     Agend Entitlement Mirror
 * Plugin URI:      https://www.agend.com.au
 * Update URI:      https://agend-systems.github.io/agend-wordpress-plugins/agend-entitlement-mirror
 * Description:     Mirrors Upbeat entitlements into Agend CRM entitlement grants, so directory/content gating segments react to standing granted or revoked in Upbeat.
 * Author:          Iugo Pty Ltd
 * Author URI:      https://www.iugo.com.au
 * Text Domain:     agend-entitlement-mirror
 * Version:         0.4.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Tested up to:      7.1
 *
 * @package         Agend_Entitlement_Mirror
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGEND_ENTITLEMENT_MIRROR_DIR', rtrim( plugin_dir_path( __FILE__ ), '/' ) );
define( 'AGEND_ENTITLEMENT_MIRROR_URL', esc_url( rtrim( plugin_dir_url( __FILE__ ), '/' ) ) );

/**
 * Loads includes and registers hooks. Hooked on `plugins_loaded`, matching
 * agend-apps-core's and agend-loop-sync's own bootstrap (and
 * agend-directory-sync's, after the same conversion). There is deliberately
 * no plugin class extending iugo-membership-kiosk's Iugo_Membership_Kiosk_Plugin
 * here: that base class's run()/check_requirements() only loaded a plugin's
 * own files once the kiosk plugin was ACTIVE, which meant this plugin's
 * admin page, settings, and CLI command could never register at all on a
 * site without the kiosk active -- regardless of which entitlement data
 * source is configured. Only the Upbeat source
 * (Agend_Entitlement_Mirror_Upbeat_Source::is_available()) gates on the
 * kiosk now, at the point it is actually asked for data.
 *
 * Extracted from agend-apps-core (operator decision 2026-08-04): agend-apps-core
 * carries connection details and generic gateway API bindings only;
 * Upbeat/kiosk-coupled (Pro-client) logic lives in this standalone plugin so
 * non-Pro installs never carry it.
 */
function agend_entitlement_mirror_bootstrap() {
	// Settings first: the collector/resolver/sync/CLI classes below all call
	// Agend_Entitlement_Mirror_Settings:: at runtime.
	require_once AGEND_ENTITLEMENT_MIRROR_DIR . '/includes/class-entitlement-mirror-settings.php';
	require_once AGEND_ENTITLEMENT_MIRROR_DIR . '/includes/interface-source.php';
	require_once AGEND_ENTITLEMENT_MIRROR_DIR . '/includes/class-upbeat-source.php';
	require_once AGEND_ENTITLEMENT_MIRROR_DIR . '/includes/class-http-api-source.php';
	require_once AGEND_ENTITLEMENT_MIRROR_DIR . '/includes/class-source-registry.php';
	require_once AGEND_ENTITLEMENT_MIRROR_DIR . '/includes/class-entitlement-collector.php';
	require_once AGEND_ENTITLEMENT_MIRROR_DIR . '/includes/class-entitlement-sync.php';
	require_once AGEND_ENTITLEMENT_MIRROR_DIR . '/includes/class-admin-page.php';

	// The generic HTTP API source ships with this plugin, but registers
	// through the same `agend_entitlement_mirror_sources` filter a client
	// source would use, rather than a second hard-coded entry in
	// Agend_Entitlement_Mirror_Source_Registry::register_defaults().
	add_filter(
		'agend_entitlement_mirror_sources',
		static function ( array $sources ): array {
			$http_api                        = new Agend_Entitlement_Mirror_Http_Api_Source();
			$sources[ $http_api->get_key() ] = $http_api;
			return $sources;
		}
	);

	Agend_Entitlement_Mirror_Source_Registry::register_defaults();

	Agend_Entitlement_Mirror_Admin_Page::setup_hooks();

	// Upbeat entitlements as member job posting credits. Registered lazily:
	// the provider implements agend-apps-core's interface, so its file is only
	// loaded when core is active and the filter is actually asked.
	add_filter( 'agend_apps_jobs_credit_provider', 'agend_entitlement_mirror_job_credit_provider' );

	// "Has an unused job credit", for Agend Content Personalisation (ESAC)
	// rules: the kiosk's own entitlement rule passes for a used or expired
	// credit, so it cannot tell a member who can post from one who cannot.
	add_filter( 'iugo_esac_filter_condition_sets', 'agend_entitlement_mirror_esac_job_credit_condition_set' );
	add_filter( 'iugo_esac_filter_check_condition_agend_jobs', 'agend_entitlement_mirror_esac_check_job_credit', 10, 2 );

	// Subscribes to the kiosk's existing webhook actions and wp_login. No-ops
	// silently when the module is disabled (the enable option is read here,
	// once, at boot time) -- turning the option on within the same request
	// does NOT retroactively register the listeners; the change takes effect
	// from the next request onward (known caveat, carried over unchanged
	// from the agend-apps-core module).
	Agend_Entitlement_Sync::register();

	// WP-CLI entitlement mirror sweep (US-2.5): loaded only under WP-CLI so
	// the command class is never defined in a web request.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once AGEND_ENTITLEMENT_MIRROR_DIR . '/includes/class-cli-command.php';
	}
}
add_action( 'plugins_loaded', 'agend_entitlement_mirror_bootstrap' );

/**
 * Supplies the Upbeat job credit provider to agend-apps-core when Job credits
 * are switched on.
 *
 * @param mixed $provider Provider from an earlier filter, or null.
 * @return mixed
 */
function agend_entitlement_mirror_job_credit_provider( $provider ) {
	if ( null !== $provider || ! interface_exists( 'Agend_Apps_Jobs_Credit_Provider' ) || ! Agend_Entitlement_Mirror_Settings::is_job_credits_enabled() ) {
		return $provider;
	}

	require_once AGEND_ENTITLEMENT_MIRROR_DIR . '/includes/class-kiosk-credit-store.php';
	require_once AGEND_ENTITLEMENT_MIRROR_DIR . '/includes/class-upbeat-job-credits.php';

	return new Agend_Entitlement_Mirror_Upbeat_Job_Credits(
		new Agend_Entitlement_Mirror_Kiosk_Credit_Store(),
		Agend_Entitlement_Mirror_Settings::get_job_credits_type(),
		Agend_Entitlement_Mirror_Settings::get_job_credits_category()
	);
}

/**
 * Adds the Agend Jobs condition set to Agend Content Personalisation (ESAC).
 *
 * Only offered while job credits are switched on, so an editor never picks a
 * rule that can never pass.
 *
 * @param array $sets Condition sets.
 * @return array
 */
function agend_entitlement_mirror_esac_job_credit_condition_set( $sets ) {
	$sets = is_array( $sets ) ? $sets : array();

	if ( ! Agend_Entitlement_Mirror_Settings::is_job_credits_enabled() ) {
		return $sets;
	}

	$sets['agend_jobs'] = array(
		'label'      => __( 'Agend Jobs', 'agend-entitlement-mirror' ),
		'conditions' => array(
			'has_job_credit' => __( 'Has an unused job credit', 'agend-entitlement-mirror' ),
		),
	);

	return $sets;
}

/**
 * Checks an Agend Jobs ESAC condition for the current visitor.
 *
 * A visitor who is signed out, or whose credits cannot be read just now,
 * does not pass: the section offering "Post a job" stays hidden and the
 * inverse section (buy credits) shows, which is the safe way round. ESAC
 * caches each condition's answer for the request.
 *
 * @param bool   $check     The result so far.
 * @param string $condition Condition id within the set.
 * @return bool
 */
function agend_entitlement_mirror_esac_check_job_credit( $check, $condition ) {
	if ( 'has_job_credit' !== $condition ) {
		return (bool) $check;
	}

	$user_id = get_current_user_id();

	if ( ! $check || $user_id <= 0 || ! class_exists( 'Agend_Apps_Jobs_Credits' ) ) {
		return false;
	}

	$credits = Agend_Apps_Jobs_Credits::available( $user_id );

	return ! is_wp_error( $credits ) && count( $credits ) > 0;
}


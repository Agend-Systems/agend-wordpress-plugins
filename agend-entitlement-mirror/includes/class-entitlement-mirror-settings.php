<?php
/**
 * Settings helper class for the Upbeat Entitlement Mirror.
 *
 * Extracted from `Agend_Apps_Settings` (SPEC-AMS-20260804-upbeat-entitlement-mirror,
 * operator decision 2026-08-04: agend-apps-core carries connection details and
 * generic gateway API bindings only; Upbeat/kiosk-coupled logic lives in this
 * standalone plugin). The option names are UNCHANGED from the apps-core module
 * -- they are plugin-neutral and existing installs already carry configured
 * values under them.
 *
 * @package Agend_Entitlement_Mirror
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides static helpers for reading Agend Entitlement Mirror settings from
 * the WordPress options table.
 *
 * All methods are static — this class is never instantiated.
 */
class Agend_Entitlement_Mirror_Settings {

	/**
	 * Default category allow-list for the Upbeat entitlement mirror
	 * (SPEC-AMS-20260804-upbeat-entitlement-mirror Decision 2.5).
	 *
	 * @var string
	 */
	const ENTITLEMENT_MIRROR_DEFAULT_CATEGORY = 'Web Personalisation';

	/**
	 * Default source key the mirror declares entitlement types and reconciles
	 * grants under (SPEC-CRM-20260805-member-entitlement-grants US-5.1).
	 *
	 * @var string
	 */
	const ENTITLEMENT_MIRROR_DEFAULT_SOURCE_KEY = 'upbeat';

	/**
	 * Default login-reconciliation throttle in seconds (US-2.3 AC1).
	 *
	 * @var int
	 */
	const ENTITLEMENT_MIRROR_DEFAULT_LOGIN_THROTTLE = 900;

	/**
	 * Whether the Upbeat entitlement mirror module is enabled.
	 *
	 * Defaults OFF: a module that fires gateway writes on every Upbeat webhook
	 * and every WordPress login must be opt-in per install
	 * (SPEC-AMS-20260804-upbeat-entitlement-mirror US-2.x scope note).
	 *
	 * @return bool
	 */
	public static function is_entitlement_mirror_enabled(): bool {
		return '1' === (string) get_option( 'agend_entitlement_mirror_enabled', '0' );
	}

	/**
	 * Returns the configured entitlement category allow-list.
	 *
	 * Stored as one category per line; defaults to `Web Personalisation`
	 * (PCA's reserved gating category, Decision 2.5).
	 *
	 * @return array<int, string> Trimmed, non-empty category names.
	 */
	public static function get_entitlement_mirror_categories(): array {
		$raw = (string) get_option( 'agend_entitlement_mirror_categories', self::ENTITLEMENT_MIRROR_DEFAULT_CATEGORY );

		$categories = array_filter(
			array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) )
		);

		if ( empty( $categories ) ) {
			$categories = array( self::ENTITLEMENT_MIRROR_DEFAULT_CATEGORY );
		}

		/**
		 * Filters the entitlement mirror's configured category allow-list.
		 *
		 * @param array<int, string> $categories Configured category allow-list.
		 */
		return (array) apply_filters( 'agend_entitlement_mirror_categories', array_values( $categories ) );
	}

	/**
	 * Returns the stable `source_key` the mirror declares entitlement types
	 * and reconciles grants under (SPEC-CRM-20260805-member-entitlement-grants
	 * US-5.1). Never `manual` -- that value is reserved for staff-made grants.
	 *
	 * @return string
	 */
	public static function get_entitlement_mirror_source_key(): string {
		$value = trim( (string) get_option( 'agend_entitlement_mirror_source_key', self::ENTITLEMENT_MIRROR_DEFAULT_SOURCE_KEY ) );

		return '' !== $value ? $value : self::ENTITLEMENT_MIRROR_DEFAULT_SOURCE_KEY;
	}

	/**
	 * Returns the `external_source` value the mirror uses to resolve and
	 * create contacts.
	 *
	 * OPEN QUESTION (spec Section 8, #1): the production value must match
	 * whatever SSO/registration provisioning stamps on `crm_contacts` for this
	 * install, or externalId resolution always falls through to the
	 * exact-email fallback. There is no platform-wide default -- this is
	 * per-install configuration, deliberately left unset (empty disables the
	 * externalId lookup and create-time identity entirely, falling back to
	 * email-only resolution) until the owning team confirms the value.
	 *
	 * @return string
	 */
	public static function get_entitlement_mirror_external_source(): string {
		$value = (string) get_option( 'agend_entitlement_mirror_external_source', '' );

		/**
		 * Filters the `external_source` value used to resolve/create contacts.
		 *
		 * @param string $value Configured external_source value (may be empty).
		 */
		return (string) apply_filters( 'agend_entitlement_mirror_external_source', trim( $value ) );
	}

	/**
	 * Returns the login-reconciliation throttle in seconds (US-2.3 AC1).
	 *
	 * @return int
	 */
	public static function get_entitlement_mirror_login_throttle(): int {
		$value = (int) get_option( 'agend_entitlement_mirror_login_throttle', self::ENTITLEMENT_MIRROR_DEFAULT_LOGIN_THROTTLE );

		return $value > 0 ? $value : self::ENTITLEMENT_MIRROR_DEFAULT_LOGIN_THROTTLE;
	}
}

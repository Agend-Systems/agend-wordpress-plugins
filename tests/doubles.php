<?php
/**
 * Test doubles for Agend classes and functions the units under test depend on.
 *
 * Declared once at bootstrap, with mutable behaviour driven through
 * {@see Agend_Test_WP}. Kept separate from wp-stubs.php so the WordPress
 * surface and the Agend surface stay distinguishable.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

if ( ! class_exists( 'Agend_Apps_Settings' ) ) {
	/** Settings double. Values are fixed; nothing under test varies by them. */
	class Agend_Apps_Settings {
		public static function get_api_key(): string {
			return 'test-api-key';
		}

		public static function get_base_url(): string {
			return 'https://api.example.test/v1';
		}

		public static function get_vercel_bypass_token(): string {
			return '';
		}

		public static function get_cache_ttl( string $endpoint_key ): int {
			return 300;
		}
	}
}

if ( ! class_exists( 'Agend_Apps_Cache' ) ) {
	/** Cache-key builder double, matching Core's deterministic key shape. */
	class Agend_Apps_Cache {
		public static function build_key( string $prefix, array $query = array() ): string {
			return $prefix . ( array() === $query ? '' : '_' . md5( (string) wp_json_encode( $query ) ) );
		}

		public static function get_all_keys(): array {
			return array();
		}
	}
}

// The real Core API client, loaded once here rather than doubled.
//
// It declares agend_apps_api() and agend_apps_get_bearer_token() WITHOUT
// function_exists guards, so a double for either would fatal the moment a test
// required the real file. Loading it after the Settings and Cache doubles above
// gives every test both the genuine client and the two functions the Content
// Access dependency probe looks for.
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-api.php';

if ( ! function_exists( 'agend_apps_crm_get_my_entitlements' ) ) {
	/**
	 * Resolved member standing double.
	 *
	 * Tests drive the outcome with
	 * `Agend_Test_WP::set_filter( 'agend_apps_crm_get_my_entitlements_response', ... )`,
	 * including returning a WP_Error to exercise an outage.
	 *
	 * It records a request so callers can assert on call COUNT, which is what
	 * makes the per-request memoisation testable.
	 *
	 * @return mixed
	 */
	function agend_apps_crm_get_my_entitlements() {
		Agend_Test_WP::$requests[] = array(
			'url'     => '/crm/me/entitlements',
			'headers' => array(),
		);

		return apply_filters(
			'agend_apps_crm_get_my_entitlements_response',
			array( 'data' => array( 'tier_ids' => array(), 'is_member' => false ) )
		);
	}
}

if ( ! function_exists( 'agend_apps_crm_get_tiers' ) ) {
	/**
	 * Tier catalogue double.
	 *
	 * Returns whatever the test placed in Agend_Test_WP::$tiers_response,
	 * including a WP_Error when the test is exercising an outage.
	 *
	 * @param array $query Ignored.
	 * @return mixed
	 */
	function agend_apps_crm_get_tiers( array $query = array() ) {
		return Agend_Test_WP::$tiers_response;
	}
}

if ( ! function_exists( 'agend_apps_crm_get_my_segments' ) ) {
	/**
	 * Member segments double.
	 *
	 * Tests drive the outcome with
	 * `Agend_Test_WP::$filters['agend_apps_crm_get_my_segments_response']`,
	 * including a WP_Error to exercise an outage. Records a request so call
	 * COUNT is assertable, which is what makes the per-viewer cache testable.
	 *
	 * @return mixed
	 */
	function agend_apps_crm_get_my_segments() {
		Agend_Test_WP::$requests[] = array(
			'url'     => '/crm/me/segments',
			'headers' => array(),
		);

		return apply_filters(
			'agend_apps_crm_get_my_segments_response',
			array( 'data' => array() )
		);
	}
}

if ( ! class_exists( 'Agend_Test_Mirror_Gateway' ) ) {
	/**
	 * Spy registry for the entitlement mirror's three gateway call sites
	 * (`agend_apps_crm_reconcile_entitlement_grants`,
	 * `agend_apps_crm_sync_entitlement_types`, `agend_apps_crm_get_contacts`).
	 *
	 * The real functions live in agend-apps-core/includes/api/crm.php, which the
	 * mirror plugin does not load in the unit suite (its own gateway HTTP
	 * plumbing is out of scope here). Kept separate from {@see Agend_Test_WP} --
	 * that class is the generic WordPress surface; this one is specific to the
	 * three mirror-facing gateway endpoints and their call-count assertions
	 * (SPEC-CRM-20260805-member-entitlement-grants US-5.1 AC6/AC12/AC13).
	 */
	final class Agend_Test_Mirror_Gateway {

		/** @var array<int, array<string, mixed>> Every reconcile-grants payload, in call order. */
		public static array $reconcile_calls = array();

		/** @var array<int, array<string, mixed>> Every sync-types payload, in call order. */
		public static array $types_calls = array();

		/** @var array<int, array<string, mixed>> Every get-contacts query, in call order. */
		public static array $get_contacts_calls = array();

		/** @var mixed Value the next agend_apps_crm_reconcile_entitlement_grants() call returns. */
		public static $reconcile_response = array( 'data' => array() );

		/** @var mixed Value the next agend_apps_crm_sync_entitlement_types() call returns. */
		public static $types_response = array( 'data' => array( 'types' => array() ) );

		/** @var mixed Value the next agend_apps_crm_get_contacts() call returns. */
		public static $get_contacts_response = array( 'data' => array() );

		/** Resets every spy back to a clean state. */
		public static function reset(): void {
			self::$reconcile_calls      = array();
			self::$types_calls          = array();
			self::$get_contacts_calls   = array();
			self::$reconcile_response   = array( 'data' => array() );
			self::$types_response       = array( 'data' => array( 'types' => array() ) );
			self::$get_contacts_response = array( 'data' => array() );
		}
	}
}

if ( ! function_exists( 'agend_apps_crm_reconcile_entitlement_grants' ) ) {
	/**
	 * Reconcile-grants spy. Records the payload and returns the scripted
	 * response (a decoded array, or a WP_Error to exercise a write failure).
	 *
	 * @param array $payload Reconciliation payload.
	 * @return mixed
	 */
	function agend_apps_crm_reconcile_entitlement_grants( array $payload ) {
		Agend_Test_Mirror_Gateway::$reconcile_calls[] = $payload;

		return Agend_Test_Mirror_Gateway::$reconcile_response;
	}
}

if ( ! function_exists( 'agend_apps_crm_sync_entitlement_types' ) ) {
	/**
	 * Sync-types spy. Records the payload and returns the scripted response.
	 *
	 * @param array $payload Type-declaration payload.
	 * @return mixed
	 */
	function agend_apps_crm_sync_entitlement_types( array $payload ) {
		Agend_Test_Mirror_Gateway::$types_calls[] = $payload;

		return Agend_Test_Mirror_Gateway::$types_response;
	}
}

if ( ! function_exists( 'agend_apps_crm_get_contacts' ) ) {
	/**
	 * Get-contacts spy. Records the query and returns the scripted response.
	 *
	 * @param array $query Query parameters.
	 * @return mixed
	 */
	function agend_apps_crm_get_contacts( array $query = array() ) {
		Agend_Test_Mirror_Gateway::$get_contacts_calls[] = $query;

		return Agend_Test_Mirror_Gateway::$get_contacts_response;
	}
}

if ( ! class_exists( 'Iugo_Membership_Kiosk_API_Entitlement' ) ) {
	/**
	 * Minimal fake of the kiosk's entitlement value object.
	 *
	 * The collector type-checks `instanceof Iugo_Membership_Kiosk_API_Entitlement`
	 * before trusting a row, so the fake must carry that exact class name. Every
	 * field is constructor-supplied so a test can build the exact shape it needs
	 * (a full entitlement, or one with null dates/quantities to exercise the
	 * collector's omission behaviour).
	 */
	final class Iugo_Membership_Kiosk_API_Entitlement {
		private string $category;
		private string $type;
		private string $display_name;
		private ?DateTime $start_date;
		private ?DateTime $end_date;
		private ?string $quantity_allowed;
		private ?string $quantity_remaining;

		public function __construct(
			string $category,
			string $type,
			string $display_name = '',
			?DateTime $start_date = null,
			?DateTime $end_date = null,
			?string $quantity_allowed = null,
			?string $quantity_remaining = null
		) {
			$this->category           = $category;
			$this->type                = $type;
			$this->display_name        = $display_name;
			$this->start_date          = $start_date;
			$this->end_date            = $end_date;
			$this->quantity_allowed    = $quantity_allowed;
			$this->quantity_remaining  = $quantity_remaining;
		}

		public function get_entitlement_category(): string {
			return $this->category;
		}

		public function get_entitlement_type(): string {
			return $this->type;
		}

		public function get_entitlement_display_name(): string {
			return $this->display_name;
		}

		public function get_the_start_date(): ?DateTime {
			return $this->start_date;
		}

		public function get_the_end_date(): ?DateTime {
			return $this->end_date;
		}

		public function get_quantity_allowed(): ?string {
			return $this->quantity_allowed;
		}

		public function get_quantity_remaining(): ?string {
			return $this->quantity_remaining;
		}
	}
}


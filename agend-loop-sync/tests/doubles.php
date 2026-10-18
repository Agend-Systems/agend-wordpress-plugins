<?php
/**
 * Test doubles for the Agend Loop Sync suite.
 *
 * Stands in for the three things the plugin talks to: WordPress user lookups,
 * the agend-apps-core gateway wrappers, and the iugo-membership-kiosk (Upbeat)
 * API. All state lives on {@see Loop_Sync_Test_State} and is cleared by
 * {@see Agend\Tests\LoopSync\LoopSyncTestCase::setUp()}.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

/**
 * Recorded calls and canned responses shared by the doubles below.
 */
final class Loop_Sync_Test_State {

	/** @var WP_User[] */
	public static array $users = array();

	/** @var array<int, array{0:string,1:array}> Gateway calls: [ function, args ]. */
	public static array $calls = array();

	/** @var array<string, mixed> Canned response per gateway function (default: success envelope). */
	public static array $responses = array();

	/** @var array<int, array<string, mixed>> Loop committees returned by the list endpoint, in order. */
	public static array $loop_committees = array();

	/** @var int Page size the fake list endpoint enforces. */
	public static int $list_page_size = 100;

	/** @var object[]|false Upbeat committees; false simulates an API failure. */
	public static $upbeat_committees = array();

	/** @var array<string, object[]|false> Upbeat activities per membership number; false simulates failure. */
	public static array $upbeat_activities = array();

	/**
	 * @var int|null Total the fake committee list reports on every page; null
	 *               reports the real count, 0 reports none (the kiosk's default).
	 */
	public static ?int $upbeat_committee_total = null;

	/**
	 * @var array<int, array{rows?:object[],more?:bool,total?:int}|false> Whole
	 *      committee pages by page number that replace the paged ones the fake
	 *      builds from $upbeat_committees. false is a response that is not a
	 *      collection; empty rows is what the kiosk returns for a failed call.
	 */
	public static array $upbeat_committee_pages = array();

	/** @var int Activities per page the fake serves; 0 serves every row as one page. */
	public static int $upbeat_activities_page_size = 0;

	/**
	 * @var array<string, array<int, array{rows?:object[],more?:bool,total?:int}|false>> Whole
	 *      activity pages per membership number and page number that replace the
	 *      paged ones the fake builds from $upbeat_activities.
	 */
	public static array $upbeat_activity_pages = array();

	/** @var array<int, array{0:string,1:array}> Upbeat calls: [ method, args exactly as passed ]. */
	public static array $upbeat_calls = array();

	public static function reset(): void {
		self::$users                       = array();
		self::$calls                       = array();
		self::$responses                   = array();
		self::$loop_committees             = array();
		self::$list_page_size              = 100;
		self::$upbeat_committees           = array();
		self::$upbeat_activities           = array();
		self::$upbeat_committee_total      = null;
		self::$upbeat_committee_pages      = array();
		self::$upbeat_activities_page_size = 0;
		self::$upbeat_activity_pages       = array();
		self::$upbeat_calls                = array();
	}

	/**
	 * @return array<int, array> Args, exactly as passed, of every Upbeat call to $method, in order.
	 */
	public static function upbeat_calls_to( string $method ): array {
		$out = array();
		foreach ( self::$upbeat_calls as $call ) {
			if ( $call[0] === $method ) {
				$out[] = $call[1];
			}
		}
		return $out;
	}

	/**
	 * @return array<int, array> Args of every call to $function, in order.
	 */
	public static function calls_to( string $function ): array {
		$out = array();
		foreach ( self::$calls as $call ) {
			if ( $call[0] === $function ) {
				$out[] = $call[1];
			}
		}
		return $out;
	}
}

if ( ! class_exists( 'Loop_Sync_Test_User' ) ) {
	/** WP_User with the display_name the plugin reads (the shared stub omits it). */
	class Loop_Sync_Test_User extends WP_User {
		public string $display_name = '';
	}
}

/**
 * Registers a local member and returns it.
 *
 * @param string|null $membership_number Upbeat membership number, or null for none.
 */
function loop_sync_test_user( int $id, ?string $membership_number, string $email = '', array $roles = array( 'subscriber' ) ): Loop_Sync_Test_User {
	$user               = new Loop_Sync_Test_User( $id );
	$user->user_email   = '' === $email ? "member{$id}@example.com" : $email;
	$user->display_name = "Member {$id}";
	$user->roles        = $roles;

	if ( null !== $membership_number ) {
		$GLOBALS['agend_test_user_meta'][ $id ]['imk_membership_number'] = $membership_number;
	}

	Loop_Sync_Test_State::$users[] = $user;

	return $user;
}

if ( ! function_exists( 'get_users' ) ) {
	function get_users( array $args = array() ): array {
		$users = Loop_Sync_Test_State::$users;

		if ( isset( $args['meta_key'] ) && isset( $args['meta_value'] ) ) {
			$users = array_values(
				array_filter(
					$users,
					static function ( $u ) use ( $args ) {
						return (string) ( $GLOBALS['agend_test_user_meta'][ $u->ID ][ $args['meta_key'] ] ?? '' ) === (string) $args['meta_value'];
					}
				)
			);
		} elseif ( isset( $args['meta_key'] ) && 'EXISTS' === ( $args['meta_compare'] ?? '' ) ) {
			$users = array_values(
				array_filter(
					$users,
					static function ( $u ) use ( $args ) {
						return isset( $GLOBALS['agend_test_user_meta'][ $u->ID ][ $args['meta_key'] ] );
					}
				)
			);
		}

		if ( 'ID' === ( $args['fields'] ?? '' ) ) {
			return array_map( static fn( $u ) => $u->ID, $users );
		}

		return $users;
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $id ) {
		foreach ( Loop_Sync_Test_State::$users as $user ) {
			if ( $user->ID === $id ) {
				return $user;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array() ): bool {
		Agend_Test_WP::$scheduled_events[] = array(
			'timestamp'  => $timestamp,
			'hook'       => $hook,
			'args'       => $args,
			'recurrence' => $recurrence,
		);
		return true;
	}
}

if ( ! function_exists( 'wp_unschedule_event' ) ) {
	function wp_unschedule_event( int $timestamp, string $hook, array $args = array() ): bool {
		Agend_Test_WP::$scheduled_events = array_values(
			array_filter(
				Agend_Test_WP::$scheduled_events,
				static fn( $e ) => ! ( $e['hook'] === $hook && $e['timestamp'] === $timestamp )
			)
		);
		return true;
	}
}

/**
 * Records a gateway call and returns its canned response (or a success envelope).
 */
function loop_sync_test_gateway( string $function, array $args, array $default = array( 'success' => true, 'data' => array() ) ) {
	Loop_Sync_Test_State::$calls[] = array( $function, $args );

	return Loop_Sync_Test_State::$responses[ $function ] ?? $default;
}

if ( ! function_exists( 'agend_apps_loop_sync_user' ) ) {
	function agend_apps_loop_sync_user( array $user ) {
		return loop_sync_test_gateway( __FUNCTION__, array( $user ) );
	}

	function agend_apps_loop_bulk_sync_users( array $users, string $idp_entity_id ) {
		return loop_sync_test_gateway( __FUNCTION__, array( $users, $idp_entity_id ) );
	}

	function agend_apps_loop_sync_committee( array $committee ) {
		return loop_sync_test_gateway( __FUNCTION__, array( $committee ) );
	}

	function agend_apps_loop_archive_committee( string $unique_id, string $visibility = 'hidden' ) {
		return loop_sync_test_gateway( __FUNCTION__, array( $unique_id, $visibility ) );
	}

	/**
	 * Pages Loop_Sync_Test_State::$loop_committees the way the gateway does.
	 */
	function agend_apps_loop_list_committees( int $page = 1, int $limit = 50 ) {
		Loop_Sync_Test_State::$calls[] = array( __FUNCTION__, array( $page, $limit ) );

		if ( isset( Loop_Sync_Test_State::$responses[ __FUNCTION__ ] ) ) {
			return Loop_Sync_Test_State::$responses[ __FUNCTION__ ];
		}

		$limit = min( $limit, Loop_Sync_Test_State::$list_page_size );
		$all   = Loop_Sync_Test_State::$loop_committees;
		$total = count( $all );
		$pages = (int) max( 1, ceil( $total / $limit ) );

		return array(
			'success' => true,
			'data'    => array_slice( $all, ( $page - 1 ) * $limit, $limit ),
			'meta'    => array(
				'pagination' => array(
					'page'        => $page,
					'limit'       => $limit,
					'total'       => $total,
					'total_pages' => $pages,
					'has_next'    => $page < $pages,
					'has_prev'    => $page > 1,
				),
			),
		);
	}
}

if ( ! class_exists( 'Loop_Sync_Test_Upbeat' ) ) {
	/**
	 * Upbeat client double driven by Loop_Sync_Test_State. Deliberately not named
	 * Iugo_Membership_Kiosk_API: other suites rely on that class being undefined.
	 *
	 * Pages the way Upbeat does when asked to (more records and a total on every
	 * page), and records the arguments of each call so tests can assert on them.
	 */
	class Loop_Sync_Test_Upbeat {

		/**
		 * Mirrors the kiosk: paging arguments are strings and empty means unset.
		 *
		 * @return Loop_Sync_Test_Collection|false
		 */
		public function get_committees( string $page_count = '', string $page_number = '', string $sort_col = '', string $sort_order = '' ) {
			Loop_Sync_Test_State::$upbeat_calls[] = array( __FUNCTION__, func_get_args() );

			$page = '' === $page_number ? 1 : (int) $page_number;
			if ( array_key_exists( $page, Loop_Sync_Test_State::$upbeat_committee_pages ) ) {
				return $this->page_from_spec( Loop_Sync_Test_State::$upbeat_committee_pages[ $page ] );
			}

			$rows = Loop_Sync_Test_State::$upbeat_committees;
			if ( false === $rows ) {
				return false;
			}

			// Rows are served in the order the test set them, whatever the sort
			// asked for: tests assert on the sort arguments, not on Upbeat's ordering.
			$collection = $this->page( $rows, '' === $page_count ? 0 : (int) $page_count, $page );
			if ( null !== Loop_Sync_Test_State::$upbeat_committee_total ) {
				$collection->set_total_record_count( Loop_Sync_Test_State::$upbeat_committee_total );
			}

			return $collection;
		}

		/**
		 * @return Loop_Sync_Test_Collection|false
		 */
		public function get_member_committee_activities( $membership_number = '', $page_count = '', $page_number = '', $sort_col = '', $sort_order = '' ) {
			Loop_Sync_Test_State::$upbeat_calls[] = array( __FUNCTION__, func_get_args() );

			$page = '' === $page_number ? 1 : (int) $page_number;
			if ( isset( Loop_Sync_Test_State::$upbeat_activity_pages[ (string) $membership_number ] ) && array_key_exists( $page, Loop_Sync_Test_State::$upbeat_activity_pages[ (string) $membership_number ] ) ) {
				return $this->page_from_spec( Loop_Sync_Test_State::$upbeat_activity_pages[ (string) $membership_number ][ $page ] );
			}

			$rows = Loop_Sync_Test_State::$upbeat_activities[ (string) $membership_number ] ?? array();
			if ( false === $rows ) {
				return false;
			}

			return $this->page( $rows, Loop_Sync_Test_State::$upbeat_activities_page_size, $page );
		}

		/**
		 * One page of $rows, as Upbeat reports it. A size of 0 means one page of everything.
		 */
		private function page( array $rows, int $size, int $page ): Loop_Sync_Test_Collection {
			$total = count( $rows );
			if ( $size > 0 ) {
				$start = ( $page - 1 ) * $size;
				$more  = $start + $size < $total;
				$rows  = array_slice( $rows, $start, $size );
			} else {
				$more = false;
			}

			return new Loop_Sync_Test_Collection( array_values( $rows ), $more, $total );
		}

		/**
		 * @param array{rows?:object[],more?:bool,total?:int}|false $spec
		 * @return Loop_Sync_Test_Collection|false
		 */
		private function page_from_spec( $spec ) {
			if ( false === $spec ) {
				return false;
			}

			return new Loop_Sync_Test_Collection( $spec['rows'] ?? array(), (bool) ( $spec['more'] ?? false ), (int) ( $spec['total'] ?? 0 ) );
		}
	}

	class Loop_Sync_Test_Collection {
		private array $rows;
		private bool $more_records;
		private int $total_record_count;

		public function __construct( array $rows, bool $more_records = false, int $total_record_count = 0 ) {
			$this->rows               = $rows;
			$this->more_records       = $more_records;
			$this->total_record_count = $total_record_count;
		}

		public function get_results(): array {
			return $this->rows;
		}

		public function get_more_records(): bool {
			return $this->more_records;
		}

		public function get_total_record_count(): int {
			return $this->total_record_count;
		}

		public function set_total_record_count( int $total_record_count ): self {
			$this->total_record_count = $total_record_count;
			return $this;
		}
	}

	class Loop_Sync_Test_Activity {
		private string $committee;
		private string $role;

		public function __construct( string $committee, string $role ) {
			$this->committee = $committee;
			$this->role      = $role;
		}

		public function get_committee(): string {
			return $this->committee;
		}

		public function get_role(): string {
			return $this->role;
		}
	}

	class Loop_Sync_Test_Committee {
		private string $name;
		private string $unique_id;
		private bool $allow_on_web;

		public function __construct( string $name, string $unique_id = '', bool $allow_on_web = true ) {
			$this->name         = $name;
			$this->unique_id    = $unique_id;
			$this->allow_on_web = $allow_on_web;
		}

		public function get_name(): string {
			return $this->name;
		}

		public function get_unique_id(): string {
			return $this->unique_id;
		}

		public function get_allow_on_web(): bool {
			return $this->allow_on_web;
		}

		public function get_groups(): array {
			return array();
		}
	}
}

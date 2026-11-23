<?php
/**
 * Community rule evaluation.
 *
 * @package Agend_Loop_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether one member belongs in the channel of one community rule.
 *
 * A rule has criteria (membership type, membership level, event registration)
 * and an access gate (the lapse rule). The criteria are combined by the rule's
 * `match` mode: `all` (the default) needs every configured criterion to pass,
 * `any` needs at least one. A criterion with no values is not configured, and a
 * rule with no configured criteria matches every member. Within one criterion
 * any listed value is enough, ignoring case.
 *
 * The access gate then applies on top, whatever the match mode: when the rule
 * applies the access statuses, a member whose status is not in the allowed list
 * does not match. Finally the `agend_loop_sync_rule_matches` filter sees the
 * result, so a client plugin can add criteria of its own or override it.
 *
 * Members listed in a rule's always-include list are added by the runner and do
 * not pass through here.
 *
 * All methods are static and pure apart from reading the access-status setting
 * and running the filters.
 */
class Agend_Loop_Sync_Rule_Matcher {

	/**
	 * Words that, found in a registration's transaction or attendance status,
	 * mean the registration no longer entitles the member to anything. Upbeat
	 * has no fixed list of these strings, so the check is deliberately wide: a
	 * wrongly excluded registration costs a member their channel until the
	 * status is clarified, while a wrongly counted one gives access to someone
	 * who cancelled.
	 *
	 * @var string[]
	 */
	const INACTIVE_REGISTRATION_WORDS = array( 'cancel', 'void', 'refund' );

	/**
	 * Whether a rule needs the member's event registrations.
	 *
	 * @param array<string, mixed> $rule A rule.
	 * @return bool
	 */
	public static function uses_events( array $rule ): bool {
		return array() !== ( $rule['event_ids'] ?? array() );
	}

	/**
	 * Whether a member belongs in a rule's channel.
	 *
	 * @param array<string, mixed> $rule          The rule.
	 * @param object               $detail        The member's Upbeat detail.
	 * @param array<int, object>   $registrations The member's registrations (only read when the rule lists events).
	 * @param int                  $user_id       The WordPress user id.
	 * @return bool
	 */
	public static function matches( array $rule, $detail, array $registrations, int $user_id ): bool {
		$matches = self::criteria_pass( $rule, $detail, $registrations );

		if ( $matches && ! empty( $rule['apply_access_statuses'] ) && ! self::has_access( $detail ) ) {
			$matches = false;
		}

		/**
		 * Filters whether a member belongs in a community rule's channel.
		 *
		 * Runs after the built-in criteria and the access gate, for every member
		 * and rule, so a client plugin can add a criterion of its own (return
		 * false for a member who fails it) or override the result.
		 *
		 * @param bool   $matches  Whether the member matches the rule so far.
		 * @param array  $rule     The rule: slug, name, match, membership_types, membership_levels, event_ids and so on.
		 * @param object $detail   The member's Upbeat detail.
		 * @param int    $user_id  The WordPress user id.
		 */
		return (bool) apply_filters( 'agend_loop_sync_rule_matches', $matches, $rule, $detail, $user_id );
	}

	/**
	 * Whether the configured criteria pass, combined by the rule's match mode.
	 *
	 * @param array<string, mixed> $rule          The rule.
	 * @param object               $detail        The member's Upbeat detail.
	 * @param array<int, object>   $registrations The member's registrations.
	 * @return bool
	 */
	public static function criteria_pass( array $rule, $detail, array $registrations ): bool {
		$results = array();

		if ( array() !== ( $rule['membership_types'] ?? array() ) ) {
			$results[] = self::in_list( self::read( $detail, 'get_membership_type' ), $rule['membership_types'] );
		}

		if ( array() !== ( $rule['membership_levels'] ?? array() ) ) {
			$results[] = self::in_list( self::read( $detail, 'get_membership_level' ), $rule['membership_levels'] );
		}

		if ( self::uses_events( $rule ) ) {
			$results[] = self::registered_for_any( $rule, $registrations );
		}

		if ( array() === $results ) {
			return true;
		}

		if ( 'any' === ( $rule['match'] ?? 'all' ) ) {
			return in_array( true, $results, true );
		}

		return ! in_array( false, $results, true );
	}

	/**
	 * Whether the member's status keeps access under the saved access statuses.
	 * A member with no status does not keep it.
	 *
	 * @param object $detail The member's Upbeat detail.
	 * @return bool
	 */
	public static function has_access( $detail ): bool {
		return self::in_list( self::read( $detail, 'get_member_status' ), Agend_Loop_Sync_Settings::get_access_statuses() );
	}

	/**
	 * Whether a registration still counts: it has an event and is not cancelled,
	 * voided or refunded. Filterable for a site whose Upbeat uses other words.
	 *
	 * @param object               $registration A registration.
	 * @param array<string, mixed> $rule         The rule being evaluated.
	 * @return bool
	 */
	public static function registration_counts( $registration, array $rule = array() ): bool {
		$counts = true;

		foreach ( array( 'get_transaction_status', 'get_attendance_status' ) as $getter ) {
			$status = strtolower( self::read( $registration, $getter ) );
			foreach ( self::INACTIVE_REGISTRATION_WORDS as $word ) {
				if ( '' !== $status && false !== strpos( $status, $word ) ) {
					$counts = false;
				}
			}
		}

		/**
		 * Filters whether a registration counts towards a community rule's event criterion.
		 *
		 * @param bool   $counts       Whether it counts: false when its transaction or attendance status mentions cancel, void or refund.
		 * @param object $registration The Upbeat registration.
		 * @param array  $rule         The rule being evaluated.
		 */
		return (bool) apply_filters( 'agend_loop_sync_registration_counts', $counts, $registration, $rule );
	}

	/**
	 * Whether any counting registration is for one of the rule's events.
	 *
	 * @param array<string, mixed> $rule          The rule.
	 * @param array<int, object>   $registrations The member's registrations.
	 * @return bool
	 */
	private static function registered_for_any( array $rule, array $registrations ): bool {
		foreach ( $registrations as $registration ) {
			if ( ! is_object( $registration ) ) {
				continue;
			}

			if ( self::in_list( self::read( $registration, 'get_event_id' ), $rule['event_ids'] ) && self::registration_counts( $registration, $rule ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Calls a getter on an Upbeat object and returns it as a trimmed string, or
	 * '' when the object has no such getter or it returns nothing.
	 *
	 * @param object $object Upbeat object.
	 * @param string $getter Method name.
	 * @return string
	 */
	private static function read( $object, string $getter ): string {
		if ( ! is_object( $object ) || ! method_exists( $object, $getter ) ) {
			return '';
		}

		$value = $object->$getter();

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Case-insensitive membership of a value in a list. An empty value is never in a list.
	 *
	 * @param string   $value Value.
	 * @param string[] $list  Allowed values.
	 * @return bool
	 */
	private static function in_list( string $value, array $list ): bool {
		if ( '' === $value ) {
			return false;
		}

		$value = strtolower( $value );
		foreach ( $list as $item ) {
			if ( strtolower( trim( (string) $item ) ) === $value ) {
				return true;
			}
		}

		return false;
	}
}

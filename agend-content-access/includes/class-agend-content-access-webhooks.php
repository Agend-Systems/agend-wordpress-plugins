<?php
/**
 * Makes cached member facts and editor catalogues forget sooner when Agend says
 * something changed.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens to Agend webhooks and invalidates; it never learns anything from them.
 *
 * TRIGGER AND RE-READ. Core's receiver verifies the signature, drops repeat
 * deliveries and handles `crm.membership.*` and `crm.seat.*` itself, then fires
 * `agend_apps_webhook_received` for every verified delivery. This class hangs off
 * that action. An event does exactly one thing here: it says WHOSE cached facts
 * are now suspect (one member, or everyone), and `Agend_Content_Access_Viewer_Facts`
 * forgets them. The next request that needs them asks Agend again, with the
 * member's own bearer, so the answer always comes from the gateway and never from
 * a payload. The same rule Core's own membership handler follows.
 *
 * THE PAYLOAD IS A LOCATOR, NOT A FACT. The only field read is `data.contact_id`,
 * and only to find WordPress users. `gate_keys`, `changed_fields`, `change` and the
 * rest are never read, stored, trusted or acted on. An entitlement `granted` event
 * therefore cannot grant anything: if the gateway does not return the key on the
 * re-read, the member does not have it. That is what keeps a forged, replayed or
 * out-of-order delivery harmless, and it is why this class has no write path to
 * the facts at all, only `flush()` and `bump_generation()`.
 *
 * AN ACCELERATOR, NEVER THE GUARANTEE. Several changes fire no event: a grace
 * period ending, a grant reaching its `expires_at`, a relative-date segment rule
 * admitting or dropping a member as the clock moves, imports and merges. The cache
 * lifetime (Core's `crm_me_entitlements` setting, never above
 * `Agend_Content_Access_Viewer_Facts::MAX_TTL`) covers those. A missed, dropped or
 * ignored webhook costs at most that lifetime. It can make WordPress forget sooner;
 * it cannot make it remember longer.
 *
 * NEVER FATAL. A webhook is a REST request, and an exception here would turn a
 * delivery Core has already verified into a 500, which Agend then retries
 * indefinitely. So nothing a payload contains can throw: every field is type
 * checked before it is used, anything malformed is ignored without a sound, and the
 * whole handler sits inside a `Throwable` catch as a last line.
 */
class Agend_Content_Access_Webhooks {

	/**
	 * The `Agend_Apps_Core` user meta that links a WordPress user to an Agend
	 * contact. Core writes it at login and its own receiver finds members by it.
	 *
	 * @var string
	 */
	const CONTACT_ID_META = '_agend_apps_contact_id';

	/**
	 * The most WordPress users one contact id may resolve to.
	 *
	 * One contact is one person, so one user is the normal answer and two is an
	 * identity conflict Core already flags. The cap exists so a pathological site
	 * (or an imported duplicate) cannot make one delivery flush thousands of users
	 * inside a request Agend is waiting on. Anyone beyond the cap is covered by
	 * the cache lifetime.
	 *
	 * @var int
	 */
	const MAX_USERS = 50;

	/**
	 * What an invalidation covered, passed to `agend_content_access_facts_invalidated`.
	 *
	 * @var string
	 */
	const SCOPE_USER    = 'user';
	const SCOPE_ACCOUNT = 'account';

	/**
	 * Registers the listener.
	 *
	 * Called on every request, front end, admin and REST alike: a webhook is a
	 * REST request, and gating it behind `is_admin()` would register nothing for
	 * the one request that matters. Two arguments, the event type and the full
	 * envelope, which is what Core passes.
	 */
	public static function init(): void {
		add_action( 'agend_apps_webhook_received', array( __CLASS__, 'handle' ), 10, 2 );
	}

	/**
	 * The event types that concern one member, found through `data.contact_id`.
	 *
	 * `exact` are whole event ids and `prefixes` are matched at the start, so a
	 * new `crm.seat.*` event is covered the day the gateway ships it. The existing
	 * `crm.membership.*` and `crm.seat.*` families are listed as prefixes for that
	 * reason; Core handles them for its own snapshot, and this adds the facts
	 * cache on top.
	 *
	 * @return array{exact: string[], prefixes: string[]}
	 */
	public static function contact_events(): array {
		$defaults = array(
			'exact'    => array(
				'crm.entitlement.granted',
				'crm.entitlement.revoked',
				'crm.contact.attributes_changed',
			),
			'prefixes' => array(
				'crm.membership.',
				'crm.seat.',
			),
		);

		/**
		 * Filters the webhook event types that invalidate ONE member's cached facts.
		 *
		 * Lets a gateway event added later be handled without a plugin release. The
		 * event must carry `data.contact_id`. A value that is not shaped as below is
		 * ignored and the defaults stand, so a careless filter cannot switch
		 * invalidation off by accident. An empty list, which is a deliberate
		 * answer, does remove those events.
		 *
		 * @param array{exact: string[], prefixes: string[]} $events Exact event ids and id prefixes.
		 */
		$filtered = apply_filters( 'agend_content_access_webhook_contact_events', $defaults );

		return array(
			'exact'    => self::event_list( $filtered, 'exact', $defaults['exact'] ),
			'prefixes' => self::event_list( $filtered, 'prefixes', $defaults['prefixes'] ),
		);
	}

	/**
	 * The event types that concern the whole account, and so invalidate everyone.
	 *
	 * @return string[]
	 */
	public static function account_events(): array {
		$defaults = array(
			'crm.segment.updated',
			'crm.benefit.changed',
			'crm.contact.bulk_updated',
		);

		/**
		 * Filters the webhook event types that invalidate EVERY member's cached facts.
		 *
		 * Exact event ids. An event added here bumps the global generation and
		 * nothing else; it clears no editor catalogue. A value that is not a list of
		 * strings is ignored and the defaults stand. An empty list, which is a
		 * deliberate answer, removes the events.
		 *
		 * @param string[] $events Exact event ids.
		 */
		$filtered = apply_filters( 'agend_content_access_webhook_account_events', $defaults );

		return self::strings( $filtered, $defaults );
	}

	/**
	 * Handles one verified Agend delivery.
	 *
	 * An account-wide event is acted on by its type alone: its `data` is never
	 * read, so a delivery without one still forgets, which is the safe direction.
	 * A contact event needs a usable `data.contact_id` and does nothing without
	 * one.
	 *
	 * Both parameters are untyped on purpose. The action is public, and a
	 * `TypeError` from a caller that passes something unexpected would be the very
	 * fatal this class promises never to raise.
	 *
	 * @param mixed $type     Event type, e.g. `crm.entitlement.revoked`.
	 * @param mixed $envelope Full decoded delivery envelope.
	 */
	public static function handle( $type = '', $envelope = array() ): void {
		try {
			// Core only fires the action for an envelope it decoded to an array, so
			// anything else is a caller that is not Core's receiver: ignore it
			// rather than act on a delivery that was never verified.
			if ( ! is_string( $type ) || '' === $type || ! is_array( $envelope ) ) {
				return;
			}

			// Account-wide first: it is the broader invalidation, so an id that
			// a filter lists in both is treated as the one that forgets more.
			if ( in_array( $type, self::account_events(), true ) ) {
				self::invalidate_account( $type );
				return;
			}

			if ( self::is_contact_event( $type ) ) {
				self::invalidate_contact( $type, $envelope );
			}
		} catch ( \Throwable $e ) {
			// Deliberately silent. A failure here must not become a 500 that Agend
			// retries for ever, and the cache lifetime still bounds the cost.
			return;
		}
	}

	/**
	 * Whether an event type concerns one member.
	 *
	 * @param string $type Event type.
	 * @return bool
	 */
	private static function is_contact_event( string $type ): bool {
		$events = self::contact_events();

		if ( in_array( $type, $events['exact'], true ) ) {
			return true;
		}

		foreach ( $events['prefixes'] as $prefix ) {
			if ( 0 === strpos( $type, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Forgets one member's cached facts, for every user the contact id resolves to.
	 *
	 * Silent when the envelope has no usable contact id or no user holds it. A
	 * site that does not hold every Agend contact as a WordPress user receives
	 * these events for contacts it has never seen, so "nobody found" is the most
	 * common outcome, not an error.
	 *
	 * @param string $type     Event type.
	 * @param mixed  $envelope Decoded delivery envelope.
	 */
	private static function invalidate_contact( string $type, $envelope ): void {
		$contact_id = self::contact_id( $envelope );

		if ( '' === $contact_id ) {
			return;
		}

		$user_ids = self::user_ids_for( $contact_id );

		if ( array() === $user_ids ) {
			return;
		}

		foreach ( $user_ids as $user_id ) {
			Agend_Content_Access_Viewer_Facts::flush( $user_id );
		}

		self::announce( self::SCOPE_USER, $type, $user_ids );
	}

	/**
	 * Forgets every member's cached facts, and the editor list the event changed.
	 *
	 * A segment event clears the segment catalogue and a benefit event clears the
	 * entitlement catalogue, so an editor sees a renamed or new item without
	 * waiting out the list's own lifetime. The plan catalogue is left alone: no
	 * event describes a plan change. Deleting a list also drops the last known copy
	 * the catalogue serves when Agend is unreachable, so an editor who opens the
	 * picker during an outage straight after one of these events gets an empty,
	 * stale-flagged list that blocks new selections rather than an out-of-date one.
	 * That is the safe direction, and an event is rare enough for it to be a fair
	 * price for seeing the change immediately.
	 *
	 * @param string $type Event type.
	 */
	private static function invalidate_account( string $type ): void {
		Agend_Content_Access_Viewer_Facts::bump_generation();

		if ( 'crm.segment.updated' === $type ) {
			delete_transient( Agend_Content_Access_Catalogue::SEGMENTS_TRANSIENT );
		} elseif ( 'crm.benefit.changed' === $type ) {
			delete_transient( Agend_Content_Access_Catalogue::ENTITLEMENTS_TRANSIENT );
		}

		self::announce( self::SCOPE_ACCOUNT, $type, array() );
	}

	/**
	 * Reads `data.contact_id` from an envelope, only when it is a UUID.
	 *
	 * The UUID shape is the same one policies use. It is checked before the value
	 * reaches a user query, so nothing else a payload could carry (an array, a
	 * wildcard, an oversized string) ever becomes a `meta_value`.
	 *
	 * @param mixed $envelope Decoded delivery envelope.
	 * @return string The contact id, or an empty string when absent or malformed.
	 */
	private static function contact_id( $envelope ): string {
		if ( ! is_array( $envelope ) || ! isset( $envelope['data'] ) || ! is_array( $envelope['data'] ) ) {
			return '';
		}

		$contact_id = $envelope['data']['contact_id'] ?? null;

		if ( ! is_string( $contact_id ) || 1 !== preg_match( Agend_Content_Access_Policy::UUID_PATTERN, $contact_id ) ) {
			return '';
		}

		return $contact_id;
	}

	/**
	 * The WordPress users linked to an Agend contact, by Core's contact id meta.
	 *
	 * Ids only, capped at `MAX_USERS`. The lookup is the one Core's own receiver
	 * uses for `crm.membership.*`.
	 *
	 * @param string $contact_id A validated contact UUID.
	 * @return int[]
	 */
	private static function user_ids_for( string $contact_id ): array {
		$found = get_users(
			array(
				'meta_key'    => self::CONTACT_ID_META,
				'meta_value'  => $contact_id,
				'number'      => self::MAX_USERS,
				'fields'      => 'ID',
				'count_total' => false,
			)
		);

		if ( ! is_array( $found ) ) {
			return array();
		}

		$ids = array();

		foreach ( $found as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$ids[ (int) $id ] = (int) $id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Tells observers that cached facts were just invalidated.
	 *
	 * Fired only when something was invalidated: a contact event that matched no
	 * user forgot nothing and is not announced.
	 *
	 * @param string $scope    `user` or `account`.
	 * @param string $type     The event type that caused it.
	 * @param int[]  $user_ids The users flushed; empty for `account`.
	 */
	private static function announce( string $scope, string $type, array $user_ids ): void {
		/**
		 * Fires after a webhook made cached member facts forget.
		 *
		 * For logging and tests. The user ids are WordPress ids only; nothing from
		 * the payload is passed on.
		 *
		 * @param string $scope    `user` for one member, `account` for everyone.
		 * @param string $type     The webhook event type.
		 * @param int[]  $user_ids Users flushed. Empty when the scope is `account`.
		 */
		do_action( 'agend_content_access_facts_invalidated', $scope, $type, $user_ids );
	}

	/**
	 * Reads one list out of a filtered `exact`/`prefixes` shape.
	 *
	 * @param mixed    $filtered What the filter returned.
	 * @param string   $key      `exact` or `prefixes`.
	 * @param string[] $default  The built-in list.
	 * @return string[]
	 */
	private static function event_list( $filtered, string $key, array $default ): array {
		return self::strings( is_array( $filtered ) ? ( $filtered[ $key ] ?? null ) : null, $default );
	}

	/**
	 * A list of non-empty strings, or the fallback when the value is not one.
	 *
	 * @param mixed    $value    Candidate list.
	 * @param string[] $fallback What to use instead.
	 * @return string[]
	 */
	private static function strings( $value, array $fallback ): array {
		if ( ! is_array( $value ) ) {
			return $fallback;
		}

		$out = array();

		foreach ( $value as $item ) {
			if ( is_string( $item ) && '' !== $item ) {
				$out[] = $item;
			}
		}

		return $out;
	}
}

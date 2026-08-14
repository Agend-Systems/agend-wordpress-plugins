<?php
/**
 * Data source contract for Agend Entitlement Mirror.
 *
 * A source is anything that can answer the four questions the collector,
 * sync, and CLI classes need about a membership system: a member's current
 * entitlements, a member's profile (for create-on-miss), the entitlement-type
 * catalogue, and the full member list (for the sweep). None of those classes
 * name a concrete source: they resolve the active source from
 * Agend_Entitlement_Mirror_Source_Registry and call only this interface, so
 * adding a client source is one class plus configuration rather than a
 * pipeline fork (mirrors the pattern in agend-directory-sync's
 * interface-source.php).
 *
 * @package Agend_Entitlement_Mirror
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'Agend_Entitlement_Mirror_Source' ) ) :
	interface Agend_Entitlement_Mirror_Source {

		/**
		 * Stable machine key for this source (e.g. `upbeat`, `http_api`). Used
		 * to persist and resolve the active source via the
		 * `agend_entitlement_mirror_data_source` option.
		 */
		public function get_key(): string;

		/**
		 * Human-readable label for the admin source selector.
		 */
		public function get_label(): string;

		/**
		 * Whether this source is currently usable (its dependencies / required
		 * configuration are present). When false, `get_unavailable_reason()`
		 * must return a non-blank reason.
		 */
		public function is_available(): bool;

		/**
		 * The reason this source is unavailable, for the admin page and the
		 * sync failure message. Empty string when the source is available.
		 */
		public function get_unavailable_reason(): string;

		/**
		 * Fetch one member's current mirrorable entitlements.
		 *
		 * @param string $member_id Membership number / external id.
		 *
		 * @return array<int, array{
		 *     category: string,
		 *     type: string,
		 *     name: string,
		 *     starts_at: ?DateTime,
		 *     expires_at: ?DateTime,
		 *     quantity_allowed: ?string,
		 *     quantity_remaining: ?string
		 * }>
		 *
		 * @throws RuntimeException When the fetch fails.
		 */
		public function fetch_member_entitlements( string $member_id ): array;

		/**
		 * Fetch one member's profile, used only for the grants endpoint's
		 * create-on-miss identity fields. Returns blank strings for a field the
		 * source cannot supply, never throws for a not-found member.
		 *
		 * @param string $member_id Membership number / external id.
		 *
		 * @return array{email: string, first_name: string, last_name: string}
		 */
		public function fetch_member_profile( string $member_id ): array;

		/**
		 * Fetch the full entitlement-type catalogue (independent of any one
		 * member), for the types-declaration sync.
		 *
		 * @return array<int, array{category: string, type: string}>
		 *
		 * @throws RuntimeException When the fetch fails.
		 */
		public function fetch_entitlement_types(): array;

		/**
		 * Enumerate members, for the CLI sweep. Yields in a stable order so a
		 * capped `$max` always returns the same leading set.
		 *
		 * @param int $max Maximum members to yield (0 = unbounded).
		 *
		 * @return iterable<array{member_id: string, email: string, first_name: string, last_name: string}>
		 *
		 * @throws RuntimeException When enumeration fails.
		 */
		public function enumerate_members( int $max ): iterable;
	}
endif;

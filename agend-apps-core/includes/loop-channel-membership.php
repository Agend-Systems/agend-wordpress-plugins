<?php
/**
 * Whether a member belongs to an active Loop channel.
 *
 * Offers "Is in an active Loop channel" as a condition to Agend Content
 * Personalisation (ESAC) rules and to the If Menu plugin, so a site can show
 * a "Community" link, or any other content, only to members who have actually
 * been added to a Loop channel. That suits an association piloting Loop with a
 * few invite-only groups: members outside the pilot never see a link to a
 * community they cannot use.
 *
 * Agend is the authority. The site asks with its own key, naming the member
 * by contact pair (`GET /v1/loop/integration/members/channels`), and Agend
 * answers whether they belong to a channel that is neither archived nor a
 * direct message. The member's bearer is never sent, so a member who has
 * never signed in through this site's SSO is answered too.
 *
 * The answer is cached per member for a few minutes, because a menu condition
 * is checked on every page that shows the menu. Loop sends no membership
 * webhooks, so a member added to or removed from a channel sees the link
 * change within {@see CACHE_TTL}.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Apps_Loop_Channel_Membership' ) ) :

	/**
	 * Checks whether the signed-in member belongs to an active Loop channel.
	 */
	final class Agend_Apps_Loop_Channel_Membership {

		/**
		 * The scope the check needs.
		 */
		const SCOPE = 'loop.memberships.read';

		/**
		 * ESAC condition set id.
		 */
		const ESAC_SET = 'agend_loop';

		/**
		 * ESAC condition id within the set.
		 */
		const ESAC_CONDITION = 'in_active_channel';

		/**
		 * If Menu condition id.
		 */
		const IF_MENU_CONDITION = 'agend-loop-in-active-channel';

		/**
		 * Transient prefix for cached answers, followed by the user id and a
		 * hash of their contact pair.
		 */
		const CACHE_PREFIX = 'agend_apps_loop_channels_';

		/**
		 * How long an answer is kept.
		 */
		const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

		/**
		 * How long an answer that could not be read is remembered, so a slow
		 * or failing gateway does not hold up every page a member views.
		 */
		const FAILURE_TTL = 30;

		/**
		 * How long an answer that the site cannot check at all is remembered:
		 * the key lacks the scope, has no access to Loop, or may not name
		 * members from this site's contact source.
		 */
		const REFUSED_TTL = 5 * MINUTE_IN_SECONDS;

		/**
		 * Gateway codes meaning the site cannot check channel membership at all.
		 */
		const REFUSED_CODES = array( 'INSUFFICIENT_SCOPES', 'APP_NOT_ALLOWED', 'SOURCE_NOT_ALLOWED' );

		/**
		 * Registers the ESAC and If Menu conditions.
		 *
		 * @return void
		 */
		public static function register(): void {
			add_filter( 'iugo_esac_filter_condition_sets', array( __CLASS__, 'esac_condition_sets' ) );
			add_filter( 'iugo_esac_filter_check_condition_' . self::ESAC_SET, array( __CLASS__, 'esac_check' ), 10, 2 );
			add_filter( 'if_menu_conditions', array( __CLASS__, 'if_menu_conditions' ) );
		}

		/**
		 * Whether the site's key is known to lack the scope the check needs.
		 * Only a scope list the site has actually read can rule it out.
		 *
		 * @return bool
		 */
		public static function lacks_scope(): bool {
			return class_exists( 'Agend_Apps_Key_Scopes' ) && Agend_Apps_Key_Scopes::known() && ! Agend_Apps_Key_Scopes::has( self::SCOPE );
		}

		/**
		 * Whether the signed-in member belongs to an active Loop channel.
		 *
		 * A member Agend cannot match to a user yet (`resolved: false`, usually
		 * one who has never signed in to Agend) is a plain no.
		 *
		 * Errors:
		 * - `agend_loop_no_member`: signed out, or no external id.
		 * - `agend_loop_not_available`: the site cannot check (a missing scope,
		 *   no access to Loop, or the contact source is not allowed).
		 * - `agend_loop_unavailable`: the answer could not be read just now.
		 *
		 * @return bool|WP_Error
		 */
		public static function status() {
			$user_id = get_current_user_id();
			$contact = agend_apps_user_contact( $user_id );

			if ( null === $contact ) {
				return new WP_Error( 'agend_loop_no_member', __( 'Sign in with your member account to use the community.', 'agend-apps-core' ) );
			}

			if ( self::lacks_scope() ) {
				return self::error( 'not_available' );
			}

			$key    = self::cache_key( $user_id, $contact );
			$cached = get_transient( $key );

			if ( is_array( $cached ) ) {
				return self::from_cache( $cached );
			}

			$response = agend_apps_loop_get_member_channels( $contact );

			if ( is_wp_error( $response ) ) {
				$code = self::gateway_code( $response );

				if ( in_array( $code, self::REFUSED_CODES, true ) ) {
					return self::remember( $key, array( 'error' => 'not_available' ), self::REFUSED_TTL );
				}

				return self::remember( $key, array( 'error' => 'unavailable' ), self::FAILURE_TTL );
			}

			$active = $response['data']['in_active_channel'] ?? null;

			if ( ! is_bool( $active ) ) {
				return self::remember( $key, array( 'error' => 'unavailable' ), self::FAILURE_TTL );
			}

			return self::remember( $key, array( 'active' => $active ), self::CACHE_TTL );
		}

		/**
		 * Whether the signed-in member belongs to an active Loop channel. Any
		 * error, including one that could not be checked, reads as no.
		 *
		 * @return bool
		 */
		public static function in_active_channel(): bool {
			return true === self::status();
		}

		/**
		 * Adds the Agend Loop condition set to ESAC, unless the site's key is
		 * known to lack the scope, so an editor never picks a rule that can
		 * never pass.
		 *
		 * @param mixed $sets Condition sets.
		 * @return array
		 */
		public static function esac_condition_sets( $sets ): array {
			$sets = is_array( $sets ) ? $sets : array();

			if ( self::lacks_scope() ) {
				return $sets;
			}

			$sets[ self::ESAC_SET ] = array(
				'label'      => __( 'Agend Loop', 'agend-apps-core' ),
				'conditions' => array(
					self::ESAC_CONDITION => __( 'Is in an active Loop channel', 'agend-apps-core' ),
				),
			);

			return $sets;
		}

		/**
		 * Checks an Agend Loop ESAC condition for the current visitor.
		 *
		 * A visitor who is signed out, not in an active channel, or whose
		 * answer cannot be read just now, does not pass.
		 *
		 * @param mixed  $check     The result so far.
		 * @param string $condition Condition id within the set.
		 * @return bool
		 */
		public static function esac_check( $check, $condition ): bool {
			if ( self::ESAC_CONDITION !== $condition ) {
				return (bool) $check;
			}

			if ( ! $check ) {
				return false;
			}

			return self::in_active_channel();
		}

		/**
		 * Adds the "Is in an active Loop channel" condition to the If Menu
		 * plugin, unless the site's key is known to lack the scope.
		 *
		 * @param mixed $conditions If Menu conditions.
		 * @return array
		 */
		public static function if_menu_conditions( $conditions ): array {
			$conditions = is_array( $conditions ) ? $conditions : array();

			if ( self::lacks_scope() ) {
				return $conditions;
			}

			$conditions[] = array(
				'id'        => self::IF_MENU_CONDITION,
				'name'      => __( 'Is in an active Loop channel', 'agend-apps-core' ),
				'condition' => array( __CLASS__, 'if_menu_check' ),
				'group'     => __( 'Agend Loop', 'agend-apps-core' ),
			);

			return $conditions;
		}

		/**
		 * If Menu condition callback.
		 *
		 * @param mixed $item The menu item being checked (unused).
		 * @return bool
		 */
		public static function if_menu_check( $item = null ): bool {
			unset( $item );

			return self::in_active_channel();
		}

		/**
		 * The transient an answer is cached under.
		 *
		 * @param int   $user_id WordPress user id.
		 * @param array $contact The member's contact pair.
		 * @return string
		 */
		private static function cache_key( int $user_id, array $contact ): string {
			return self::CACHE_PREFIX . $user_id . '_' . md5( $contact['external_source'] . '|' . $contact['external_id'] );
		}

		/**
		 * Caches an entry and returns it as the answer or its error.
		 *
		 * @param string $key   Transient key.
		 * @param array  $entry Entry to cache.
		 * @param int    $ttl   Seconds to keep it.
		 * @return bool|WP_Error
		 */
		private static function remember( string $key, array $entry, int $ttl ) {
			set_transient( $key, $entry, $ttl );

			return self::from_cache( $entry );
		}

		/**
		 * A cached entry as the answer or its error.
		 *
		 * @param array $entry Cached entry.
		 * @return bool|WP_Error
		 */
		private static function from_cache( array $entry ) {
			if ( isset( $entry['active'] ) && is_bool( $entry['active'] ) ) {
				return $entry['active'];
			}

			$error = (string) ( $entry['error'] ?? '' );

			return self::error( 'not_available' === $error ? $error : 'unavailable' );
		}

		/**
		 * The gateway's own error code, upper-cased, or ''.
		 *
		 * @param WP_Error $error Gateway error.
		 * @return string
		 */
		private static function gateway_code( WP_Error $error ): string {
			if ( 'agend_apps_scope_missing' === $error->get_error_code() ) {
				return 'INSUFFICIENT_SCOPES';
			}

			$data = $error->get_error_data();

			return is_array( $data ) && isset( $data['body']['error']['code'] ) && is_scalar( $data['body']['error']['code'] )
				? strtoupper( (string) $data['body']['error']['code'] )
				: '';
		}

		/**
		 * One of the check's errors.
		 *
		 * @param string $kind `not_available` or `unavailable`.
		 * @return WP_Error
		 */
		private static function error( string $kind ): WP_Error {
			if ( 'not_available' === $kind ) {
				return new WP_Error( 'agend_loop_not_available', __( 'The community is not available on this site.', 'agend-apps-core' ) );
			}

			return new WP_Error( 'agend_loop_unavailable', __( 'We could not check your community channels just now. Please try again in a minute.', 'agend-apps-core' ) );
		}
	}

endif;

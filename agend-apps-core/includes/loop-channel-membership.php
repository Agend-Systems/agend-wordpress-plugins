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
 * Agend is the authority. The site asks the gateway which channels the
 * signed-in member belongs to (`GET /v1/loop/me/channels`, as the member, so
 * it needs the member's bearer token from {@see Agend_Apps_Token_Worker}) and
 * counts a channel as active when it is not archived and is a real channel
 * rather than a direct message or a category heading.
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
		const SCOPE = 'loop.channels.browse';

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
		 * Channel types that do not count as being in a Loop channel: a direct
		 * message (every member has one with the help bot) and a category
		 * heading.
		 */
		const NON_CHANNEL_TYPES = array( 'dm', 'category' );

		/**
		 * Transient prefix for cached answers, followed by the user id and a
		 * hash of their external id.
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
		 * How long a member without an Agend identity link is remembered as
		 * such. Short, so a member who has just signed in to Loop for the first
		 * time sees the link soon after.
		 */
		const UNLINKED_TTL = MINUTE_IN_SECONDS;

		/**
		 * How long an answer that the site cannot check at all is remembered:
		 * the key lacks the scope or has no access to Loop.
		 */
		const REFUSED_TTL = 5 * MINUTE_IN_SECONDS;

		/**
		 * Gateway codes meaning the site cannot check channel membership at all.
		 */
		const REFUSED_CODES = array( 'INSUFFICIENT_SCOPES', 'APP_NOT_ALLOWED' );

		/**
		 * Gateway codes meaning the request carried no usable member identity:
		 * the member is not linked to Agend, or not part of the organisation.
		 */
		const UNLINKED_CODES = array( 'MISSING_USER_TOKEN', 'INVALID_USER_TOKEN', 'NOT_ACCOUNT_MEMBER' );

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
		 * Errors:
		 * - `agend_loop_no_member`: signed out, or no external id.
		 * - `agend_loop_not_linked`: the member has no Agend identity link yet
		 *   (they have never signed in to Agend), so Agend cannot say.
		 * - `agend_loop_not_available`: the site cannot check (a missing scope,
		 *   or the key has no access to Loop).
		 * - `agend_loop_unavailable`: the answer could not be read just now.
		 *
		 * Only the current user can be checked: the gateway answers for the
		 * member whose bearer token the request carries.
		 *
		 * @return bool|WP_Error
		 */
		public static function status() {
			$user_id     = get_current_user_id();
			$external_id = $user_id > 0 && function_exists( 'agend_apps_user_external_id' ) ? trim( agend_apps_user_external_id( $user_id ) ) : '';

			if ( '' === $external_id ) {
				return new WP_Error( 'agend_loop_no_member', __( 'Sign in with your member account to use the community.', 'agend-apps-core' ) );
			}

			if ( self::lacks_scope() ) {
				return self::error( 'not_available' );
			}

			$key    = self::cache_key( $user_id, $external_id );
			$cached = get_transient( $key );

			if ( is_array( $cached ) ) {
				return self::from_cache( $cached );
			}

			// Without a bearer the gateway cannot know who is asking, so do not
			// ask: the member has not signed in to Agend through this site yet.
			if ( ! function_exists( 'agend_apps_get_bearer_token' ) || '' === agend_apps_get_bearer_token() ) {
				return self::remember( $key, array( 'error' => 'not_linked' ), self::UNLINKED_TTL );
			}

			$response = agend_apps_loop_get_my_channels();

			if ( is_wp_error( $response ) ) {
				$code = self::gateway_code( $response );

				if ( in_array( $code, self::REFUSED_CODES, true ) ) {
					return self::remember( $key, array( 'error' => 'not_available' ), self::REFUSED_TTL );
				}

				if ( in_array( $code, self::UNLINKED_CODES, true ) ) {
					return self::remember( $key, array( 'error' => 'not_linked' ), self::UNLINKED_TTL );
				}

				return self::remember( $key, array( 'error' => 'unavailable' ), self::FAILURE_TTL );
			}

			$channels = $response['data'] ?? null;

			if ( ! is_array( $channels ) ) {
				return self::remember( $key, array( 'error' => 'unavailable' ), self::FAILURE_TTL );
			}

			return self::remember( $key, array( 'active' => self::has_active_channel( $channels ) ), self::CACHE_TTL );
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
		 * Whether a list of channels from the gateway holds an active one.
		 *
		 * @param array $channels Channels as `GET /v1/loop/me/channels` returns them.
		 * @return bool
		 */
		public static function has_active_channel( array $channels ): bool {
			foreach ( $channels as $channel ) {
				if ( ! is_array( $channel ) || ! empty( $channel['is_archived'] ) ) {
					continue;
				}

				$type = isset( $channel['channel_type'] ) && is_string( $channel['channel_type'] ) ? $channel['channel_type'] : '';

				if ( '' !== $type && ! in_array( $type, self::NON_CHANNEL_TYPES, true ) ) {
					return true;
				}
			}

			return false;
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
		 * A visitor who is signed out, not yet linked to Agend, or whose
		 * channels cannot be read just now, does not pass.
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
		 * @param int    $user_id     WordPress user id.
		 * @param string $external_id The member's external id.
		 * @return string
		 */
		private static function cache_key( int $user_id, string $external_id ): string {
			return self::CACHE_PREFIX . $user_id . '_' . md5( $external_id );
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

			return self::error( in_array( $error, array( 'not_linked', 'not_available' ), true ) ? $error : 'unavailable' );
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
		 * @param string $kind `not_linked`, `not_available` or `unavailable`.
		 * @return WP_Error
		 */
		private static function error( string $kind ): WP_Error {
			switch ( $kind ) {
				case 'not_linked':
					return new WP_Error( 'agend_loop_not_linked', __( 'Your member account is not linked to Agend yet.', 'agend-apps-core' ) );
				case 'not_available':
					return new WP_Error( 'agend_loop_not_available', __( 'The community is not available on this site.', 'agend-apps-core' ) );
				default:
					return new WP_Error( 'agend_loop_unavailable', __( 'We could not check your community channels just now. Please try again in a minute.', 'agend-apps-core' ) );
			}
		}
	}

endif;

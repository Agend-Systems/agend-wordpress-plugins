<?php
/**
 * The communities (Agend Loop channels) the signed-in member belongs to.
 *
 * Feeds the My Communities surface: a page in the member portal that lists the
 * member's channels, each with an "Access community" button that signs them in
 * to Loop by single sign-on and lands them in that channel.
 *
 * Agend is the authority. The site asks with its own key, naming the member by
 * contact pair (`GET /v1/loop/integration/members/channels?include=channels`),
 * never with the member's bearer, so a member who has never signed in through
 * this site's SSO is answered too. The answer is cached per member for a few
 * minutes; Loop sends no membership webhooks, so a member added to or removed
 * from a channel sees the list change within the My Communities cache time
 * (Agend Apps > Cache, {@see self::CACHE_TTL} by default).
 *
 * Only the channel facts are cached. The access links are built when the list
 * is read, from the Agend account slug and SSO connection settings, so changing
 * a setting takes effect at once.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/community.php';
require_once __DIR__ . '/loop-channel-membership.php';

if ( ! class_exists( 'Agend_Apps_Loop_My_Communities' ) ) :

	/**
	 * Reads and shapes the signed-in member's communities.
	 */
	final class Agend_Apps_Loop_My_Communities {

		/**
		 * The scope the read needs.
		 */
		const SCOPE = 'loop.memberships.read';

		/**
		 * Transient prefix for cached answers, followed by the user id and a
		 * hash of their contact pair.
		 */
		const CACHE_PREFIX = 'agend_apps_loop_communities_';

		/**
		 * How long an answer is kept.
		 */
		const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

		/**
		 * How long an answer that could not be read is remembered.
		 */
		const FAILURE_TTL = 30;

		/**
		 * How long an answer that the site cannot read at all is remembered.
		 */
		const REFUSED_TTL = 5 * MINUTE_IN_SECONDS;

		/**
		 * The states an answer can be in.
		 */
		const STATE_SIGNED_OUT  = 'signed_out';
		const STATE_NO_CONTACT  = 'no_contact';
		const STATE_UNAVAILABLE = 'unavailable';
		const STATE_REFUSED     = 'refused';
		const STATE_OK          = 'ok';

		/**
		 * The member's communities.
		 *
		 * `state` is one of the STATE_ constants. `communities` is only filled
		 * for `ok`: each has `id`, `name`, `description`, `channel_type` and
		 * `access_url` ('' when a link cannot be built). `links` is whether
		 * links can be built at all, which is false until the Agend account
		 * slug is set.
		 *
		 * @return array{state: string, communities: array<int, array<string, string>>, links: bool}
		 */
		public static function for_current_user(): array {
			$user_id = (int) get_current_user_id();

			if ( $user_id <= 0 ) {
				return self::answer( self::STATE_SIGNED_OUT );
			}

			$contact = agend_apps_user_contact( $user_id );

			if ( null === $contact ) {
				return self::answer( self::STATE_NO_CONTACT );
			}

			if ( Agend_Apps_Loop_Channel_Membership::lacks_scope() ) {
				return self::answer( self::STATE_REFUSED );
			}

			$key    = self::cache_key( $user_id, $contact );
			$cached = get_transient( $key );

			if ( ! is_array( $cached ) ) {
				$cached = self::read( $key, $contact );
			}

			if ( self::STATE_OK !== ( $cached['state'] ?? '' ) ) {
				return self::answer( (string) ( $cached['state'] ?? self::STATE_UNAVAILABLE ) );
			}

			$communities = array();

			foreach ( (array) ( $cached['channels'] ?? array() ) as $channel ) {
				if ( ! is_array( $channel ) || self::excluded( $channel ) ) {
					continue;
				}

				$communities[] = array(
					'id'           => (string) $channel['id'],
					'name'         => (string) $channel['name'],
					'description'  => (string) $channel['description'],
					'channel_type' => (string) $channel['channel_type'],
					'access_url'   => self::access_url( $channel, $user_id ),
				);
			}

			return self::answer( self::STATE_OK, $communities );
		}

		/**
		 * The address that signs a member in to Loop and opens a channel.
		 *
		 * `{agend host}/sso/{account slug}/loop-channel/{channel slug}`, with
		 * `?idp={connection slug}` when the site names an SSO connection.
		 *
		 * @param array $channel A normalised channel: needs `slug`.
		 * @param int   $user_id WordPress user id.
		 * @return string '' when the account slug or the Agend host is not known.
		 */
		public static function access_url( array $channel, int $user_id ): string {
			$slug = trim( (string) ( $channel['slug'] ?? '' ) );
			$url  = '';

			if ( '' !== $slug && self::links_configured() ) {
				$url = self::sso_base_url() . '/sso/' . rawurlencode( self::account_slug() ) . '/loop-channel/' . rawurlencode( $slug );

				$connection = agend_apps_community_sso_connection();

				if ( '' !== $connection ) {
					$url .= '?idp=' . rawurlencode( $connection );
				}
			}

			/**
			 * Filters the address that opens a Loop channel for a member.
			 *
			 * @param string $url     The address, or '' when it cannot be built.
			 * @param array  $channel The channel: id, name, slug, description, channel_type, unique_id.
			 * @param int    $user_id WordPress user id.
			 */
			return (string) apply_filters( 'agend_apps_loop_channel_access_url', $url, $channel, $user_id );
		}

		/**
		 * Whether links into Loop can be built: the Agend account slug is set
		 * and the Agend host is known.
		 *
		 * @return bool
		 */
		public static function links_configured(): bool {
			return '' !== self::account_slug() && '' !== self::sso_base_url();
		}

		/**
		 * Where Agend's single sign-on links live: the host of the configured
		 * gateway, without the version path.
		 *
		 * @return string Without a trailing slash; '' when unknown.
		 */
		public static function sso_base_url(): string {
			$base = class_exists( 'Agend_Apps_Settings' ) ? rtrim( Agend_Apps_Settings::get_root_url(), '/' ) : '';

			/**
			 * Filters the base address of Agend's single sign-on links.
			 *
			 * @param string $base The gateway host, without a trailing slash.
			 */
			return rtrim( (string) apply_filters( 'agend_apps_loop_sso_base_url', $base ), '/' );
		}

		/**
		 * The Agend account slug the site is connected to.
		 *
		 * @return string
		 */
		private static function account_slug(): string {
			return trim( class_exists( 'Agend_Apps_Settings' ) ? Agend_Apps_Settings::get_account_slug() : '' );
		}

		/**
		 * Asks Agend, caches and returns what it said.
		 *
		 * @param string $key     Transient key.
		 * @param array  $contact The member's contact pair.
		 * @return array{state: string, channels?: array}
		 */
		private static function read( string $key, array $contact ): array {
			$response = agend_apps_loop_get_member_channel_list( $contact );

			if ( is_wp_error( $response ) ) {
				$code = Agend_Apps_Loop_Channel_Membership::gateway_code( $response );

				if ( in_array( $code, Agend_Apps_Loop_Channel_Membership::REFUSED_CODES, true ) ) {
					return self::remember( $key, array( 'state' => self::STATE_REFUSED ), self::REFUSED_TTL );
				}

				return self::remember( $key, array( 'state' => self::STATE_UNAVAILABLE ), self::FAILURE_TTL );
			}

			$data = is_array( $response ) ? ( $response['data'] ?? null ) : null;

			if ( ! is_array( $data ) ) {
				return self::remember( $key, array( 'state' => self::STATE_UNAVAILABLE ), self::FAILURE_TTL );
			}

			// No Agend user for the pair: an empty list, not an error.
			if ( isset( $data['resolved'] ) && false === $data['resolved'] ) {
				return self::remember(
					$key,
					array(
						'state'    => self::STATE_OK,
						'channels' => array(),
					),
					self::answer_ttl()
				);
			}

			// An older gateway ignores `include` and sends no `channels`. That is
			// "not available yet", which is not the same as being in no channel.
			if ( ! isset( $data['channels'] ) || ! is_array( $data['channels'] ) ) {
				return self::remember( $key, array( 'state' => self::STATE_UNAVAILABLE ), self::FAILURE_TTL );
			}

			$channels = array();

			foreach ( $data['channels'] as $channel ) {
				$channel = self::normalise( $channel );

				if ( null !== $channel ) {
					$channels[] = $channel;
				}
			}

			return self::remember(
				$key,
				array(
					'state'    => self::STATE_OK,
					'channels' => $channels,
				),
				self::answer_ttl()
			);
		}

		/**
		 * One channel from the gateway as plain strings, or null when it has no
		 * id, name or slug to show and link by.
		 *
		 * @param mixed $channel A channel as decoded.
		 * @return array<string, string>|null
		 */
		private static function normalise( $channel ): ?array {
			if ( ! is_array( $channel ) ) {
				return null;
			}

			$text = static function ( string $field ) use ( $channel ): string {
				return isset( $channel[ $field ] ) && is_scalar( $channel[ $field ] ) ? trim( (string) $channel[ $field ] ) : '';
			};

			$id   = $text( 'id' );
			$name = $text( 'name' );
			$slug = $text( 'slug' );

			if ( '' === $id || '' === $name || '' === $slug ) {
				return null;
			}

			return array(
				'id'           => $id,
				'name'         => $name,
				'slug'         => $slug,
				'description'  => $text( 'description' ),
				'channel_type' => $text( 'channel_type' ),
				'unique_id'    => $text( 'unique_id' ),
			);
		}

		/**
		 * Whether a channel's type is left out.
		 *
		 * @param array $channel A normalised channel.
		 * @return bool
		 */
		private static function excluded( array $channel ): bool {
			/**
			 * Filters the channel types the My Communities list leaves out.
			 * Default: none, every type Agend returns is shown.
			 *
			 * @param string[] $types Types to exclude: channel, committee, space or feed.
			 */
			$types = (array) apply_filters( 'agend_apps_loop_my_communities_excluded_types', array() );

			return in_array( (string) $channel['channel_type'], $types, true );
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
		 * How long a list Agend answered is kept: the My Communities time on
		 * the Cache tab, or {@see self::CACHE_TTL}.
		 *
		 * @return int Seconds.
		 */
		private static function answer_ttl(): int {
			// The option Agend_Apps_Settings::get_cache_ttl() reads for this key.
			return max( 0, (int) get_option( 'agend_apps_cache_loop_communities', self::CACHE_TTL ) );
		}

		/**
		 * Caches an entry and returns it.
		 *
		 * @param string $key   Transient key.
		 * @param array  $entry Entry to cache.
		 * @param int    $ttl   Seconds to keep it.
		 * @return array
		 */
		private static function remember( string $key, array $entry, int $ttl ): array {
			// A transient stored with 0 never expires: 0 on the Cache tab means
			// "do not keep it", so nothing is stored.
			if ( $ttl > 0 ) {
				set_transient( $key, $entry, $ttl );
			}

			return $entry;
		}

		/**
		 * The shape every answer has.
		 *
		 * @param string $state       One of the STATE_ constants.
		 * @param array  $communities The communities, for `ok`.
		 * @return array{state: string, communities: array, links: bool}
		 */
		private static function answer( string $state, array $communities = array() ): array {
			return array(
				'state'       => $state,
				'communities' => $communities,
				'links'       => self::links_configured(),
			);
		}
	}

endif;

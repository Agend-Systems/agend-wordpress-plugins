<?php
/**
 * Community (Agend Loop) site settings and links.
 *
 * Loop's "public" channel means public to every member of the association, not
 * public to the internet. A website that shows Loop posts to anonymous visitors
 * therefore needs a deliberate list of the channels the association is happy to
 * publish, and this file owns it: the option, its reading, and the links from a
 * page into Loop. The reads that enforce the list are in
 * `api/loop-public.php`.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/app-urls.php';

/**
 * Option holding the ids of the channels the site publishes. Admin only,
 * default empty, which publishes nothing.
 */
const AGEND_APPS_COMMUNITY_OPTION_CHANNELS = 'agend_apps_community_channels';

/**
 * Option holding the ids of single posts to keep off every Community surface.
 * Read here, with the `agend_apps_community_exclude_post` filter beside it; it
 * has no screen of its own.
 */
const AGEND_APPS_COMMUNITY_OPTION_HIDDEN = 'agend_apps_community_hidden_posts';

/**
 * Option holding an explicit address for the Agend Loop app, for an association
 * whose Loop is not on the platform's usual host. Read by
 * {@see agend_apps_app_url()}, which names it in its app directory.
 */
const AGEND_APPS_COMMUNITY_OPTION_LOOP_URL = 'agend_apps_loop_url';

/**
 * The most channels a surface reads posts from. Each channel is one request to
 * the gateway, so this is also the bound on the page's calls.
 */
const AGEND_APPS_COMMUNITY_MAX_CHANNEL_READS = 6;

/**
 * Ids as a clean list: the checkboxes' array, or a comma or line separated
 * string. Anything but the characters an id is made of is dropped, and a repeat
 * keeps its first place.
 *
 * Also the option's sanitiser, so what is stored is always this shape.
 *
 * @param mixed $value Raw value.
 * @return string[]
 */
function agend_apps_community_clean_ids( $value ): array {
	if ( is_string( $value ) ) {
		$value = preg_split( '/[\s,]+/', $value ) ?: array();
	}

	if ( ! is_array( $value ) ) {
		return array();
	}

	$ids = array();

	foreach ( $value as $id ) {
		$id = is_scalar( $id ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $id ) : '';

		if ( '' !== $id && ! in_array( $id, $ids, true ) ) {
			$ids[] = $id;
		}
	}

	return $ids;
}

/**
 * The ids of the channels this site publishes.
 *
 * @return string[] Empty when the association has allowed nothing yet.
 */
function agend_apps_community_allowed_ids(): array {
	return agend_apps_community_clean_ids( get_option( AGEND_APPS_COMMUNITY_OPTION_CHANNELS, array() ) );
}

/**
 * A short fingerprint of the allowed list, for cache keys: changing the list
 * changes the key, so a channel that was just removed is never served from an
 * entry written while it was allowed.
 *
 * @return string
 */
function agend_apps_community_allowlist_hash(): string {
	$ids = agend_apps_community_allowed_ids();
	sort( $ids );

	return substr( md5( implode( ',', $ids ) ), 0, 8 );
}

/**
 * Whether a post is on the hide list.
 *
 * @param array $post A normalised post {@see agend_apps_community_normalise_post()}.
 * @return bool
 */
function agend_apps_community_post_hidden( array $post ): bool {
	$hidden = in_array( (string) ( $post['id'] ?? '' ), agend_apps_community_clean_ids( get_option( AGEND_APPS_COMMUNITY_OPTION_HIDDEN, array() ) ), true );

	/**
	 * Filters whether a post is kept off every Community surface.
	 *
	 * @param bool  $hidden Whether the post is on the hide list option.
	 * @param array $post   The post: id, title, kind, channel, created_at and so on.
	 */
	return (bool) apply_filters( 'agend_apps_community_exclude_post', $hidden, $post );
}

/**
 * The address of the Agend Loop app.
 *
 * Found the way every Agend app is ({@see agend_apps_app_url()}): an explicit
 * `agend_apps_loop_url` option wins, otherwise the API root's leading `api.`
 * host becomes `loop.` (api.agend.com.au to loop.agend.com.au) and the local
 * gateway's port 3072 becomes Loop's 3063, and the `agend_apps_app_url` filter
 * has the last word. The portal address is not used: that can be an
 * association's own domain, while Loop stays on the platform.
 *
 * @return string Without a trailing slash; '' when nothing can be worked out.
 */
function agend_apps_community_loop_url(): string {
	return agend_apps_app_url( 'loop' );
}

/**
 * Whether the pretty sign-in links are usable on this site: they go through the
 * Agend account's SAML connection, which exists when this site is the identity
 * provider (WordPress sign-in mode).
 *
 * @return bool
 */
function agend_apps_community_uses_sso_links(): bool {
	return class_exists( 'Agend_Apps_Settings' ) && method_exists( 'Agend_Apps_Settings', 'wordpress_idp_enabled' ) && Agend_Apps_Settings::wordpress_idp_enabled();
}

/**
 * The address that opens a Loop channel, for any visitor.
 *
 * In WordPress sign-in mode this is the platform's pretty link, which signs a
 * member in through this site and drops them in the channel. In any other mode
 * it is the channel's own address, where Loop shows its own sign-in. Both are
 * fixed per channel, so the page stays cacheable. There is no link to a single
 * post: Loop cannot open one.
 *
 * @param string $channel_slug The channel's slug.
 * @return string '' when the account or the Loop address is not set up.
 */
function agend_apps_community_channel_link( string $channel_slug ): string {
	$channel_slug = trim( $channel_slug );
	$account      = class_exists( 'Agend_Apps_Settings' ) ? Agend_Apps_Settings::get_account_slug() : '';

	if ( '' === $channel_slug || '' === $account ) {
		return '';
	}

	if ( agend_apps_community_uses_sso_links() ) {
		$root = Agend_Apps_Settings::get_root_url();

		return '' === $root ? '' : rtrim( $root, '/' ) . '/sso/' . rawurlencode( $account ) . '/loop-channel/' . rawurlencode( $channel_slug );
	}

	$loop = agend_apps_community_loop_url();

	return '' === $loop ? '' : $loop . '/home/' . rawurlencode( $account ) . '/loop/' . rawurlencode( $channel_slug );
}

/**
 * The address that opens the community as a whole.
 *
 * @return string '' when the account or the Loop address is not set up.
 */
function agend_apps_community_home_link(): string {
	$account = class_exists( 'Agend_Apps_Settings' ) ? Agend_Apps_Settings::get_account_slug() : '';

	if ( '' === $account ) {
		return '';
	}

	if ( agend_apps_community_uses_sso_links() ) {
		$root = Agend_Apps_Settings::get_root_url();

		return '' === $root ? '' : rtrim( $root, '/' ) . '/sso/' . rawurlencode( $account ) . '/loop-home';
	}

	$loop = agend_apps_community_loop_url();

	return '' === $loop ? '' : $loop . '/home/' . rawurlencode( $account ) . '/loop';
}

/**
 * Where an association turns on Community channels.
 *
 * @return string
 */
function agend_apps_community_settings_url(): string {
	return admin_url( 'options-general.php?page=agend-apps' );
}

/**
 * The words that tell an editor where to allow channels, for every empty state
 * that only an editor sees.
 *
 * @return string
 */
function agend_apps_community_allow_channels_hint(): string {
	return __( 'No channels are allowed on the website yet. An administrator can allow them under Settings > Agend Apps > Community. Until then, visitors see nothing from your community.', 'agend-apps-core' );
}

/**
 * Tells the block editor's placeholder when a Community block has nothing it
 * may show yet, since the block editor draws a placeholder rather than the
 * surface.
 *
 * @param array<int, array{status: string, text: string}> $notices The notices so far.
 * @param string                                          $surface The surface id.
 * @return array<int, array{status: string, text: string}>
 */
function agend_apps_community_block_notices( array $notices, string $surface ): array {
	if ( 0 !== strpos( $surface, 'community-' ) ) {
		return $notices;
	}

	if ( array() === agend_apps_community_allowed_ids() ) {
		$notices[] = array(
			'status' => 'warning',
			'text'   => agend_apps_community_allow_channels_hint(),
		);
	}

	return $notices;
}
add_filter( 'agend_apps_records_block_surface_notices', 'agend_apps_community_block_notices', 10, 2 );

/**
 * Keeps the text of public posts out of the redacted API log: the log is for
 * diagnosing the connection, and what members wrote does not belong in it.
 *
 * @param string[] $paths Paths whose bodies are never stored.
 * @return string[]
 */
function agend_apps_community_log_exclusions( array $paths ): array {
	return array_merge( $paths, array( '/loop/public/activity', '/loop/public/announcements' ) );
}
add_filter( 'agend_apps_log_body_excluded_paths', 'agend_apps_community_log_exclusions' );

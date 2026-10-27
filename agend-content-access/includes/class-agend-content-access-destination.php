<?php
/**
 * Where a denied visitor may be sent instead of being shown the placeholder.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Works out the address a page that redirects its denied visitors sends them to,
 * or says there is none.
 *
 * A page's author can have it redirect to another page of their choosing, or send
 * the visitor back to the page they came from. Both are a redirect to an address
 * somebody else influences (the author's choice may have been restricted since, and
 * the referrer is whatever the visitor's browser says), so this class exists to
 * hold the checks in one place and to make the answer "no" for anything it cannot
 * be sure of. The page then shows its placeholder instead, which discloses nothing
 * and goes nowhere.
 *
 * THE RULES. An address is returned only when ALL of these hold, and every
 * uncertain case is a "no":
 *
 *   - It is on THIS site. For a chosen page that is its permalink. For a referrer
 *     it is rebuilt from this site's own scheme and host plus the referrer's path
 *     and query, after the referrer's host has been compared with this site's, so
 *     no part of the string the visitor's browser sent decides where the redirect
 *     goes. A host that merely resembles ours, user information in front of the
 *     host, a backslash or a control character, a scheme other than http or https,
 *     and a host `allowed_redirect_hosts` adds, are all refused: that filter is for
 *     redirects the site chooses, and a referrer is not one.
 *   - It is not the page being left. A redirect to the current URL is a loop. The
 *     comparison ignores scheme, case, a trailing slash, the query string and the
 *     fragment, so `http://Site/page`, `https://site/page/` and `https://site/page/?x=1`
 *     are all the same page. Only a site with no pretty permalinks puts the identity
 *     of the page in the query, and for a path-less address the identifying variables
 *     (`p`, `page_id`, ...) are compared too, so two different pages are not mistaken
 *     for one.
 *   - A chosen page is published, has no password, is of a type the rule panel is
 *     offered on (so "no rules" means something) and is not a pattern or a template,
 *     which have no address, and its own rules admit THIS visitor. A page the visitor
 *     could not open would only be a second denial, and could send them round a loop
 *     between two pages that each redirect to the other.
 *
 * Nothing here decides who is admitted to the page being left. It is only asked
 * once that has been decided.
 */
class Agend_Content_Access_Destination {

	/**
	 * Post types that have an address of their own on the front end only by
	 * accident. A pattern and a template are for embedding, never for visiting.
	 *
	 * @var string[]
	 */
	private const NOT_A_DESTINATION = array( 'wp_block', 'elementor_library' );

	/**
	 * The query variables that say which page a plain-permalink address is.
	 *
	 * @var string[]
	 */
	private const IDENTITY_VARS = array( 'p', 'page_id', 'attachment_id', 'name', 'pagename', 'post_type' );

	/**
	 * The address a denied visitor is sent to, or an empty string for "show the
	 * placeholder".
	 *
	 * @param WP_Post $post   The page the visitor is denied.
	 * @param array   $denied The page's denial settings: `{action, target_id, fallback_id}`.
	 * @return string An absolute address on this site, or ''.
	 */
	public static function for_denied( WP_Post $post, array $denied ): string {
		$action = isset( $denied['action'] ) ? (string) $denied['action'] : '';

		if ( Agend_Content_Access_Rules::DENIED_REDIRECT === $action ) {
			return self::page_url( isset( $denied['target_id'] ) ? (int) $denied['target_id'] : 0, $post );
		}

		if ( Agend_Content_Access_Rules::DENIED_BACK === $action ) {
			$back = self::referer_url( $post );

			// No usable referrer: the page the author named for that, and the
			// placeholder when that is no use either.
			return '' !== $back
				? $back
				: self::page_url( isset( $denied['fallback_id'] ) ? (int) $denied['fallback_id'] : 0, $post );
		}

		return '';
	}

	/**
	 * Whether a post type is one a visitor can be sent to: a type the rule panel is
	 * offered on, other than a pattern or a template.
	 *
	 * One answer for the page that is chosen when a setting is saved and for the page
	 * that is checked when somebody is sent there, so the two cannot disagree.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function is_page_type( string $post_type ): bool {
		return ! in_array( $post_type, self::NOT_A_DESTINATION, true )
			&& in_array( $post_type, Agend_Content_Access_Meta_Box::supported_post_types(), true );
	}

	/**
	 * The address of a chosen page, when this visitor may be sent to it.
	 *
	 * @param int     $id      Chosen page.
	 * @param WP_Post $current The page being left.
	 * @return string The permalink, or '' when the page is not a valid destination.
	 */
	public static function page_url( int $id, WP_Post $current ): string {
		if ( $id <= 0 || $id === (int) $current->ID ) {
			return '';
		}

		$target = get_post( $id );

		if ( ! $target instanceof WP_Post || 'publish' !== $target->post_status || '' !== (string) $target->post_password ) {
			return '';
		}

		if ( ! self::is_page_type( (string) $target->post_type ) ) {
			return '';
		}

		// This visitor, the same evaluator as the page itself. A page they would be
		// denied is not somewhere to send them, and a chosen page whose rules cannot
		// be read denies.
		$verdict = Agend_Content_Access_Access::verdict( Agend_Content_Access_Rules::from_post( $id ) );

		if ( ! $verdict['allowed'] ) {
			return '';
		}

		$url = get_permalink( $target );

		return is_string( $url ) ? self::checked( $url, $current ) : '';
	}

	/**
	 * The page the visitor came from, when it is on this site and is not this page.
	 *
	 * @param WP_Post $current The page being left.
	 * @return string An address rebuilt on this site, or ''.
	 */
	public static function referer_url( WP_Post $current ): string {
		$referer = wp_get_referer();

		if ( ! is_string( $referer ) || '' === $referer ) {
			return '';
		}

		$url = self::on_this_site( $referer );

		return '' === $url ? '' : self::checked( $url, $current );
	}

	/**
	 * The last two checks, shared by both kinds of address: it validates as a local
	 * redirect, and it is not the page being left.
	 *
	 * `wp_validate_redirect()` is asked directly, with an empty fallback, because
	 * `wp_safe_redirect()` answers an address it dislikes by redirecting to wp-admin.
	 *
	 * @param string  $url     An address already believed to be on this site.
	 * @param WP_Post $current The page being left.
	 * @return string The address, or ''.
	 */
	private static function checked( string $url, WP_Post $current ): string {
		$valid = wp_validate_redirect( $url, '' );

		if ( ! is_string( $valid ) || '' === $valid ) {
			return '';
		}

		return self::is_current_page( $valid, $current ) ? '' : $valid;
	}

	/**
	 * A referrer rebuilt on this site's own scheme and host, or '' when it is not on
	 * this site.
	 *
	 * @param string $url The referrer.
	 * @return string
	 */
	private static function on_this_site( string $url ): string {
		// A backslash is read as a slash by browsers and as nothing special by the
		// parser here, which is how `https://evil.test\@site.test/` gets through a
		// host check. Whitespace and control characters have no place in an address.
		if ( preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url( '/' ) );

		if ( ! is_array( $parts ) || ! is_array( $home ) || empty( $parts['host'] ) || empty( $home['host'] ) ) {
			return '';
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}

		$scheme = strtolower( isset( $parts['scheme'] ) ? (string) $parts['scheme'] : '' );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		if ( strtolower( (string) $parts['host'] ) !== strtolower( (string) $home['host'] ) ) {
			return '';
		}

		$home_port = isset( $home['port'] ) ? (int) $home['port'] : 0;

		if ( ( isset( $parts['port'] ) ? (int) $parts['port'] : 0 ) !== $home_port ) {
			return '';
		}

		$path = isset( $parts['path'] ) && '' !== $parts['path'] ? (string) $parts['path'] : '/';

		if ( '/' !== $path[0] ) {
			return '';
		}

		$home_scheme = isset( $home['scheme'] ) ? (string) $home['scheme'] : 'https';

		return $home_scheme . '://' . self::authority( $home ) . $path
			. ( isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '' );
	}

	/**
	 * Whether an address is the page being left.
	 *
	 * Asked of the request as it was made and of the page's permalink, because a
	 * page can be reached by more than one address. An address this cannot read is
	 * treated as the current page: when in doubt there is no redirect.
	 *
	 * @param string  $url     The candidate address.
	 * @param WP_Post $current The page being left.
	 * @return bool
	 */
	private static function is_current_page( string $url, WP_Post $current ): bool {
		$key = self::key( $url );

		if ( '' === $key ) {
			return true;
		}

		$permalink = get_permalink( $current );

		if ( is_string( $permalink ) && self::key( $permalink ) === $key ) {
			return true;
		}

		$request = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			? wp_unslash( $_SERVER['REQUEST_URI'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared, never output or redirected to.
			: '';

		if ( '' === $request ) {
			return false;
		}

		$home = wp_parse_url( home_url( '/' ) );

		if ( ! is_array( $home ) || empty( $home['host'] ) ) {
			return true;
		}

		$home_scheme = isset( $home['scheme'] ) ? (string) $home['scheme'] : 'https';

		return self::key( $home_scheme . '://' . self::authority( $home ) . $request ) === $key;
	}

	/**
	 * The part of an address that says which page it is.
	 *
	 * Host and port, then the path with case, percent-encoding and slashes at either
	 * end ignored. The scheme, the query and the fragment are left out. For an
	 * address with no path the query decides the page on a site without pretty
	 * permalinks, so the variables that name one are kept.
	 *
	 * @param string $url An address.
	 * @return string The key, or '' when the address cannot be read.
	 */
	private static function key( string $url ): string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$key  = strtolower( self::authority( $parts ) ) . '/';
		$path = strtolower( trim( rawurldecode( isset( $parts['path'] ) ? (string) $parts['path'] : '' ), '/' ) );
		$key .= $path;

		if ( '' === $path && isset( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $query );

			foreach ( self::IDENTITY_VARS as $var ) {
				if ( isset( $query[ $var ] ) && is_scalar( $query[ $var ] ) ) {
					$key .= '?' . $var . '=' . strtolower( (string) $query[ $var ] );
				}
			}
		}

		return $key;
	}

	/**
	 * Host and, when there is one, port.
	 *
	 * @param array $parts From `wp_parse_url()`.
	 * @return string
	 */
	private static function authority( array $parts ): string {
		return (string) $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}
}

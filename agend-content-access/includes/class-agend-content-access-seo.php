<?php
/**
 * SEO plugin descriptions for a page the reader may not read.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps a restricted page's body text out of the `<head>` of a reader who is denied it.
 *
 * The page gate replaces the template and the body, but the `<head>` is printed by
 * `wp_head()`, and an SEO plugin builds its meta description, `og:description` and
 * `twitter:description` from the post itself: Rank Math's `%excerpt%` and Yoast's
 * `%%excerpt%%` fall back to the opening of `post_content` when no excerpt was written,
 * Jetpack's Open Graph tags do the same, and AIOSEO generates a description from the
 * content. So a denied page's first words went to every anonymous reader, and to every
 * crawler and link preview, in the head of the placeholder page.
 *
 * WHAT IS DONE. For a post the reader is denied, each plugin's description hooks return
 * the teaser (`Agend_Content_Access_Frontend::teaser_for()`: the manual excerpt, or
 * nothing), with tags stripped. The post is the one the plugin says it is describing
 * when it says (Yoast passes its presentation), else the page this request denied, else
 * the queried singular post. It is judged as every other gate is: a post with no rules,
 * an editor of the post and a reader the rules admit keep the plugin's own text. The
 * cached `WP_Post` is only read, never changed.
 *
 * COVERED, by the hooks each plugin documents:
 *   - Yoast SEO: `wpseo_metadesc`, `wpseo_opengraph_desc`, `wpseo_twitter_description`.
 *   - Rank Math: `rank_math/frontend/description`, and the Open Graph and Twitter
 *     filters `rank_math/opengraph/facebook/og_description` and
 *     `rank_math/opengraph/twitter/twitter_description`.
 *   - All in One SEO: `aioseo_description`, and the tag lists `aioseo_facebook_tags`
 *     (`og:description`) and `aioseo_twitter_tags` (`twitter:description`).
 *   - Jetpack: `jetpack_open_graph_tags` (`og:description` and `twitter:description`,
 *     which Jetpack's Twitter cards copy from it).
 *
 * NOT COVERED: any other SEO plugin or theme that prints a description of its own, and
 * the structured data (JSON-LD) descriptions these plugins print. A plugin's own custom
 * description for a restricted page is replaced as well, because a hook cannot tell it
 * from one built out of the body: write the manual excerpt to say what a visitor sees.
 *
 * THE COST. Registering a callback on a hook its plugin never applies costs nothing, so
 * a site without one of these plugins pays nothing, and the first thing each callback
 * does is find the post, which a request that is not about one post does not have. A post
 * with no rules is judged before any identity lookup.
 */
class Agend_Content_Access_Seo {

	/**
	 * Registers hooks. Last on each, so nothing that runs after can put the text back.
	 */
	public function __construct() {
		add_filter( 'wpseo_metadesc', array( $this, 'filter_text' ), PHP_INT_MAX, 2 );
		add_filter( 'wpseo_opengraph_desc', array( $this, 'filter_text' ), PHP_INT_MAX, 2 );
		add_filter( 'wpseo_twitter_description', array( $this, 'filter_text' ), PHP_INT_MAX, 2 );

		add_filter( 'rank_math/frontend/description', array( $this, 'filter_text' ), PHP_INT_MAX );
		add_filter( 'rank_math/opengraph/facebook/og_description', array( $this, 'filter_text' ), PHP_INT_MAX );
		add_filter( 'rank_math/opengraph/twitter/twitter_description', array( $this, 'filter_text' ), PHP_INT_MAX );

		add_filter( 'aioseo_description', array( $this, 'filter_text' ), PHP_INT_MAX );
		add_filter( 'aioseo_facebook_tags', array( $this, 'filter_tags' ), PHP_INT_MAX );
		add_filter( 'aioseo_twitter_tags', array( $this, 'filter_tags' ), PHP_INT_MAX );

		add_filter( 'jetpack_open_graph_tags', array( $this, 'filter_tags' ), PHP_INT_MAX );
	}

	/**
	 * Replaces a description with the teaser when the post it describes is denied.
	 *
	 * @param mixed $description The plugin's description.
	 * @param mixed $context     What the plugin says it is describing, when it says.
	 * @return mixed
	 */
	public function filter_text( $description, $context = null ) {
		$post = self::denied_post( $context );

		return null === $post ? $description : self::teaser_text( $post );
	}

	/**
	 * Replaces the description tags of a list of meta tags, or drops them when there is
	 * no teaser, so no empty tag is printed.
	 *
	 * @param mixed $tags Meta tags, keyed by property.
	 * @return mixed
	 */
	public function filter_tags( $tags ) {
		if ( ! is_array( $tags ) ) {
			return $tags;
		}

		$keys = array_intersect( array( 'og:description', 'twitter:description' ), array_keys( $tags ) );

		if ( array() === $keys ) {
			return $tags;
		}

		$post = self::denied_post();

		if ( null === $post ) {
			return $tags;
		}

		$teaser = self::teaser_text( $post );

		foreach ( $keys as $key ) {
			if ( '' === $teaser ) {
				unset( $tags[ $key ] );
			} else {
				$tags[ $key ] = $teaser;
			}
		}

		return $tags;
	}

	/**
	 * The teaser as plain text: tags and shortcodes stripped, on one line.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function teaser_text( WP_Post $post ): string {
		$teaser = Agend_Content_Access_Frontend::teaser_for( $post );

		if ( function_exists( 'strip_shortcodes' ) ) {
			$teaser = strip_shortcodes( $teaser );
		}

		return trim( function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $teaser, true ) : strip_tags( $teaser ) );
	}

	/**
	 * The post being described, when the reader is denied it.
	 *
	 * @param mixed $context What the plugin says it is describing, when it says.
	 * @return WP_Post|null Null when the post is readable, or when there is none.
	 */
	private static function denied_post( $context = null ): ?WP_Post {
		$post = self::post_in( $context );

		if ( null === $post ) {
			$gate = Agend_Content_Access_Frontend::current_gate();
			$post = null !== $gate ? get_post( (int) $gate['post_id'] ) : null;
		}

		if ( ! $post instanceof WP_Post && is_singular() ) {
			$post = get_queried_object();
		}

		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$gate = Agend_Content_Access_Frontend::current_gate();

		// The page this request denied: the gate's own verdict stands.
		if ( null !== $gate && (int) $gate['post_id'] === (int) $post->ID ) {
			return $post;
		}

		if ( ! Agend_Content_Access_Frontend::renders_for_visitors( (int) $post->ID ) ) {
			return null;
		}

		$decision = Agend_Content_Access_Decision::for_post( (int) $post->ID, is_feed() );

		return Agend_Content_Access_Decision::STATE_GRANTED === $decision['state'] ? null : $post;
	}

	/**
	 * The post a plugin's context object (Yoast's presentation) says it describes.
	 *
	 * @param mixed $context Candidate object.
	 * @return WP_Post|null
	 */
	private static function post_in( $context ): ?WP_Post {
		$source = self::property( $context, 'source' );

		if ( $source instanceof WP_Post ) {
			return $source;
		}

		$model = self::property( $context, 'model' );

		if ( 'post' === self::property( $model, 'object_type' ) && is_numeric( self::property( $model, 'object_id' ) ) ) {
			$post = get_post( (int) self::property( $model, 'object_id' ) );

			return $post instanceof WP_Post ? $post : null;
		}

		return null;
	}

	/**
	 * Reads a property of an object that may not have it, or may build it on demand.
	 *
	 * @param mixed  $object Candidate object.
	 * @param string $name   Property.
	 * @return mixed Null when it cannot be read.
	 */
	private static function property( $object, string $name ) {
		if ( ! is_object( $object ) || ( ! property_exists( $object, $name ) && ! method_exists( $object, '__get' ) ) ) {
			return null;
		}

		try {
			return $object->$name;
		} catch ( Throwable $e ) {
			return null;
		}
	}
}

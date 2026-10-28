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
 * A PAGE THE READER MAY READ, THAT CARRIES BLOCK OR SECTION RULES, has the same leak by
 * another road: with no manual excerpt these plugins build the description from the raw
 * `post_content`, so a members-only block near the top is quoted in the `<head>` of a page
 * everyone can open. For a singular page that carries fragment rules and has no manual
 * excerpt, the description is the SAFE generated excerpt
 * (`Agend_Content_Access_Frontend::safe_generated_excerpt_for()`: the block tree rebuilt
 * without its ruled subtrees, and nothing for an Elementor document), as plain text of
 * about 160 characters. Where the plugin stores a description the author wrote, which is
 * detected cheaply for Yoast (`_yoast_wpseo_metadesc`) and Rank Math
 * (`rank_math_description`), a non-empty one is left alone: the author wrote it
 * deliberately. All in One SEO and Jetpack always get the safe excerpt on such a page. A
 * page with no fragment rules is not touched, and an author editing the page is not
 * filtered, as for the excerpt.
 *
 * NOT COVERED: any other SEO plugin or theme that prints a description of its own, and
 * the structured data (JSON-LD) descriptions these plugins print. A plugin's own custom
 * description for a restricted PAGE is replaced as well, because a hook cannot tell it
 * from one built out of the body: write the manual excerpt to say what a visitor sees.
 *
 * THE COST. Registering a callback on a hook its plugin never applies costs nothing, so
 * a site without one of these plugins pays nothing, and the first thing each callback
 * does is find the post, which a request that is not about one post does not have. A post
 * with no rules is judged before any identity lookup. Whether a post carries fragment
 * rules can need a scan of its blocks, so the answer is kept for the request.
 */
class Agend_Content_Access_Seo {

	/**
	 * How long a description built from a safe excerpt is, in characters, give or take a word.
	 */
	private const LENGTH = 160;

	/**
	 * The safe description of a post, for the request, keyed by post and content. Null for a
	 * post this does not apply to.
	 *
	 * @var array<string, string|null>
	 */
	private static array $safe = array();

	/**
	 * Registers hooks. Last on each, so nothing that runs after can put the text back.
	 */
	public function __construct() {
		add_filter( 'wpseo_metadesc', array( $this, 'filter_yoast' ), PHP_INT_MAX, 2 );
		add_filter( 'wpseo_opengraph_desc', array( $this, 'filter_yoast' ), PHP_INT_MAX, 2 );
		add_filter( 'wpseo_twitter_description', array( $this, 'filter_yoast' ), PHP_INT_MAX, 2 );

		add_filter( 'rank_math/frontend/description', array( $this, 'filter_rank_math' ), PHP_INT_MAX );
		add_filter( 'rank_math/opengraph/facebook/og_description', array( $this, 'filter_rank_math' ), PHP_INT_MAX );
		add_filter( 'rank_math/opengraph/twitter/twitter_description', array( $this, 'filter_rank_math' ), PHP_INT_MAX );

		add_filter( 'aioseo_description', array( $this, 'filter_text' ), PHP_INT_MAX );
		add_filter( 'aioseo_facebook_tags', array( $this, 'filter_tags' ), PHP_INT_MAX );
		add_filter( 'aioseo_twitter_tags', array( $this, 'filter_tags' ), PHP_INT_MAX );

		add_filter( 'jetpack_open_graph_tags', array( $this, 'filter_tags' ), PHP_INT_MAX );
	}

	/**
	 * Resets what was worked out for the request. Tests only: a request is one page.
	 */
	public static function reset(): void {
		self::$safe = array();
	}

	/**
	 * A Yoast description: the author's own is kept on a page with block or section rules.
	 *
	 * @param mixed $description The plugin's description.
	 * @param mixed $context     Yoast's presentation.
	 * @return mixed
	 */
	public function filter_yoast( $description, $context = null ) {
		return $this->describe( $description, $context, '_yoast_wpseo_metadesc' );
	}

	/**
	 * A Rank Math description: the author's own is kept on a page with block or section rules.
	 *
	 * @param mixed $description The plugin's description.
	 * @return mixed
	 */
	public function filter_rank_math( $description ) {
		return $this->describe( $description, null, 'rank_math_description' );
	}

	/**
	 * A description from a plugin with nothing of the author's own to keep.
	 *
	 * @param mixed $description The plugin's description.
	 * @param mixed $context     What the plugin says it is describing, when it says.
	 * @return mixed
	 */
	public function filter_text( $description, $context = null ) {
		return $this->describe( $description, $context, '' );
	}

	/**
	 * Replaces the description tags of a list of meta tags, or drops them when there is
	 * nothing to say, so no empty tag is printed.
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

		$replacement = $this->replacement( self::target_post(), '' );

		if ( null === $replacement ) {
			return $tags;
		}

		foreach ( $keys as $key ) {
			if ( '' === $replacement ) {
				unset( $tags[ $key ] );
			} else {
				$tags[ $key ] = $replacement;
			}
		}

		return $tags;
	}

	/**
	 * The description to give, or the plugin's own when there is nothing to change.
	 *
	 * @param mixed  $description The plugin's description.
	 * @param mixed  $context     What the plugin says it is describing, when it says.
	 * @param string $custom_key  Post meta the plugin keeps the author's own description in.
	 * @return mixed
	 */
	private function describe( $description, $context, string $custom_key ) {
		$replacement = $this->replacement( self::target_post( $context ), $custom_key );

		return null === $replacement ? $description : $replacement;
	}

	/**
	 * What replaces a post's description: the teaser when the reader is denied it, the safe
	 * generated excerpt when the page has block or section rules and no manual excerpt, and
	 * null when the plugin's own text stands.
	 *
	 * @param WP_Post|null $post       The post described.
	 * @param string       $custom_key Post meta of the author's own description, or ''.
	 * @return string|null
	 */
	private function replacement( ?WP_Post $post, string $custom_key ): ?string {
		if ( null === $post ) {
			return null;
		}

		if ( self::is_denied( $post ) ) {
			return self::plain( Agend_Content_Access_Frontend::teaser_for( $post ) );
		}

		if ( ! Agend_Content_Access_Frontend::renders_for_visitors( (int) $post->ID ) ) {
			return null;
		}

		if ( '' !== $custom_key && '' !== trim( (string) get_post_meta( (int) $post->ID, $custom_key, true ) ) ) {
			return null;
		}

		return self::safe_description( $post );
	}

	/**
	 * A description from the safe generated excerpt, for a post that carries fragment rules.
	 *
	 * @param WP_Post $post Post.
	 * @return string|null Null when the post is not one this applies to.
	 */
	private static function safe_description( WP_Post $post ): ?string {
		$key = (int) $post->ID . ':' . md5( (string) $post->post_content . "\0" . (string) $post->post_excerpt );

		if ( ! array_key_exists( $key, self::$safe ) ) {
			$safe = Agend_Content_Access_Frontend::safe_generated_excerpt_for( $post );

			self::$safe[ $key ] = null === $safe ? null : self::shorten( self::plain( $safe ) );
		}

		return self::$safe[ $key ];
	}

	/**
	 * Text as plain text: tags and shortcodes stripped, on one line.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function plain( string $text ): string {
		if ( function_exists( 'strip_shortcodes' ) ) {
			$text = strip_shortcodes( $text );
		}

		return trim( function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $text, true ) : strip_tags( $text ) );
	}

	/**
	 * Text cut to about a description's length, at a word.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	private static function shorten( string $text ): string {
		if ( mb_strlen( $text ) <= self::LENGTH ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, self::LENGTH );
		$space = mb_strrpos( $cut, ' ' );

		return rtrim( false !== $space && $space > self::LENGTH / 2 ? mb_substr( $cut, 0, $space ) : $cut, " \t\n\r.,;:" ) . "\u{2026}";
	}

	/**
	 * The post being described: the one the plugin says, else the page this request denied,
	 * else the queried singular post.
	 *
	 * @param mixed $context What the plugin says it is describing, when it says.
	 * @return WP_Post|null
	 */
	private static function target_post( $context = null ): ?WP_Post {
		$post = self::post_in( $context );

		if ( null === $post ) {
			$gate = Agend_Content_Access_Frontend::current_gate();
			$post = null !== $gate ? get_post( (int) $gate['post_id'] ) : null;
		}

		if ( ! $post instanceof WP_Post && is_singular() ) {
			$post = get_queried_object();
		}

		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * Whether the reader is denied the post.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	private static function is_denied( WP_Post $post ): bool {
		$gate = Agend_Content_Access_Frontend::current_gate();

		// The page this request denied: the gate's own verdict stands.
		if ( null !== $gate && (int) $gate['post_id'] === (int) $post->ID ) {
			return true;
		}

		if ( ! Agend_Content_Access_Frontend::renders_for_visitors( (int) $post->ID ) ) {
			return false;
		}

		$decision = Agend_Content_Access_Decision::for_post( (int) $post->ID, is_feed() );

		return Agend_Content_Access_Decision::STATE_GRANTED !== $decision['state'];
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

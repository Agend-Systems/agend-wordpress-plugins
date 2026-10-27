<?php
/**
 * Direct WordPress request gating.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stops a restricted document being served over its own WordPress URL.
 *
 * Without this, a policy would only govern the CMS projection and the original
 * permalink would remain an unguarded copy of the same content.
 *
 * The protected body is never rendered and then hidden. The decision happens on
 * `template_redirect`, before any template runs, and the body is REPLACED
 * rather than styled away (US-3.4 criterion 7). CSS and JavaScript hiding is
 * not access control: the content is still in the payload, and view-source
 * defeats it.
 *
 * WHAT A DENIED VISITOR GETS, for the page's own URL, is the page's choice: a
 * placeholder in place of the body (the default, on the same URL, answered with
 * 200 and kept out of every cache and search index), a redirect to another page,
 * or a redirect back to where they came from. Lists, search, feeds and REST are
 * not affected: they keep showing the summary only. See `gate_request()`.
 *
 * `gate_request()` only ever sees the QUERIED post. A restricted post that
 * renders anywhere else, in a blog index, an archive, a search result, a
 * widget, a feed, or a block theme's query loop, is gated by the render filters
 * further down, at the point its body or auto-generated excerpt is produced.
 */
class Agend_Content_Access_Frontend {

	/**
	 * Priority of the `the_content` pass that withholds a body before anything
	 * renders it. Lower than core's `do_blocks` (9), `wpautop` (10) and
	 * `do_shortcode` (11).
	 */
	private const WITHHOLD_PRIORITY = 1;

	/**
	 * Priority of the generated excerpt filter: straight after core's
	 * `wp_trim_excerpt` (10), which is what builds an excerpt from the body, and
	 * before the gate at PHP_INT_MAX, which may still replace the result.
	 */
	private const GENERATED_EXCERPT_PRIORITY = 11;

	/**
	 * The REST route of the block editor's server-side block renderer.
	 *
	 * `ServerSideRender` and every dynamic block's editor preview call it.
	 *
	 * @var string
	 */
	const BLOCK_RENDERER_ROUTE = '/wp/v2/block-renderer/';

	/**
	 * Post meta flag: the document carries rules on a block or an Elementor
	 * section.
	 *
	 * `'1'` when it does. Written by `Agend_Content_Access_Blocks` on save, read by
	 * its search exclusion and by the generated excerpt filter below. Defined here
	 * because this class must not depend on the block driver to know the key.
	 * Protected (underscore) and never registered for REST.
	 *
	 * @var string
	 */
	const FRAGMENT_RULES_META_KEY = '_agend_access_fragment_rules';

	/**
	 * The post meta key core stores a post's footnotes under, and registers for
	 * REST as `meta.footnotes`. Defined here, and aliased by
	 * `Agend_Content_Access_Blocks`, which filters the value a viewer reads.
	 *
	 * @var string
	 */
	const FOOTNOTES_META_KEY = 'footnotes';

	/**
	 * Decision for the current singular request, resolved once.
	 *
	 * @var array|null
	 */
	private ?array $decision = null;

	/**
	 * The denial of the page this request is for, once `gate_request()` has made one.
	 *
	 * A static because its reader is the `[agend_access_message]` shortcode, which
	 * runs somewhere inside the page's own content and has no handle on the object
	 * that gated it, and because there is one request and so one denial. It holds
	 * the decision only: the state and the rules, which say what to tell the visitor
	 * and never what the page says. Cleared at the top of every `gate_request()` so
	 * a long-lived PHP worker cannot show one request's denial on the next.
	 *
	 * @var array{post_id: int, decision: array}|null
	 */
	private static ?array $current_gate = null;

	/**
	 * The page this request is for, when it is denied.
	 *
	 * The placeholder is built for THIS post, not for whichever post
	 * `the_content` is rendering when it asks: the main loop of a singular
	 * request holds one, but a theme can print others inside it.
	 *
	 * @var WP_Post|null
	 */
	private ?WP_Post $gated_post = null;

	/**
	 * The page, pattern or template the author chose as the placeholder, 0 for the
	 * built-in one. Only the placeholder action sets it: a page that redirects and
	 * cannot has no chosen placeholder to fall back to.
	 *
	 * @var int
	 */
	private int $placeholder_target = 0;

	/**
	 * Whether the placeholder is being rendered right now.
	 *
	 * A chosen page is rendered through `the_content`, which is the filter that
	 * builds the placeholder, so without this a chosen page would ask for its own
	 * placeholder and never return.
	 *
	 * @var bool
	 */
	private bool $rendering_placeholder = false;

	/**
	 * Gate markup produced for posts whose body was withheld from a render
	 * other than the gated singular, keyed by post id.
	 *
	 * Holding it lets the closing `the_content` pass restore the gate without
	 * deciding again, and lets `suppress_builder_data()` know which documents
	 * are withheld.
	 *
	 * @var array<int, string>
	 */
	private array $withheld = array();

	/**
	 * Posts whose outage denial has already been reported this request, keyed by
	 * post id. A post can be gated for its body and again for its excerpt, and
	 * one outage should count once.
	 *
	 * @var array<int, true>
	 */
	private array $degraded_reported = array();

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'gate_request' ), 1 );

		// Public REST representations must not carry what the page will not
		// (US-3.4 criterion 8). Registered unconditionally: a REST request never
		// reaches template_redirect.
		add_action( 'rest_api_init', array( $this, 'register_rest_filters' ) );

		$this->register_render_filters();
	}

	/**
	 * Gates a restricted post wherever its body or excerpt is produced.
	 *
	 * Registered on every request and decided when a filter runs, not here. Who
	 * is looking, and whether this is an authoring context, are not known at
	 * `plugins_loaded`, and a filter that is not registered cannot fail closed.
	 * `renders_for_visitors()` keeps wp-admin screens out.
	 *
	 * `the_content` is registered at BOTH ends of the chain because no single
	 * priority is safe.
	 *
	 * Early, at priority 1: the body is replaced before core's `do_blocks` (9),
	 * `wpautop` (10) and `do_shortcode` (11), or any page builder, sees it. So
	 * nothing with a side effect runs on content the visitor may not read: no
	 * enqueued script, no counter, no gateway call from a dynamic block. Hiding
	 * the result afterwards would leave all of that behind.
	 *
	 * An early replacement alone is not enough. Elementor re-derives a
	 * document's body from its own stored data, at a priority in the millions,
	 * and ignores whatever earlier filters left in `$content`. So a second pass
	 * at the very end restores the gate for every post the first pass withheld,
	 * whatever ran in between. A filter that only REPLACES content must come
	 * last to be the word that stands, and first so the body is never rendered.
	 * The withheld post's builder data is also blanked, so the builder is not run
	 * only to have its output thrown away.
	 *
	 * Excerpts and feeds run last, at PHP_INT_MAX. `wp_trim_excerpt()` (priority
	 * 10 on `get_the_excerpt`) is what builds an excerpt out of the body when
	 * none was written, so a gate that ran before it would hand it an empty
	 * string to fill with the opening of the protected post.
	 *
	 * The generated excerpt of a document that carries fragment rules is the
	 * other half: see `filter_generated_excerpt()`, at 11, which runs between the
	 * two because it replaces what `wp_trim_excerpt()` built and must be in place
	 * before the gate decides whether to keep it.
	 *
	 * The Latest Posts block is handled before it renders, on `render_block_data`:
	 * its "full post" display prints `$post->post_content` itself, so none of the
	 * filters above is ever offered the body. See `withhold_latest_posts_content()`.
	 * That filter is skipped by some paths that render a block (a navigation's
	 * children read from a `ref` post, widget-group children after a third party
	 * short-circuits), so the block's own render callback is wrapped as well, at
	 * registration: see `wrap_latest_posts_render()`.
	 */
	private function register_render_filters(): void {
		add_filter( 'the_content', array( $this, 'withhold_in_loop' ), self::WITHHOLD_PRIORITY );
		add_filter( 'the_content', array( $this, 'enforce_withheld' ), PHP_INT_MAX );
		add_filter( 'get_the_excerpt', array( $this, 'filter_excerpt' ), PHP_INT_MAX, 2 );

		// The ONE registration of the generated excerpt filter, and the one method
		// behind it. The guard is for a second copy of this class's wiring: the
		// same filter added twice would run the safe excerpt builder twice per
		// excerpt. Anything that needs this behaviour calls the method.
		$generated = array( $this, 'filter_generated_excerpt' );

		if ( false === has_filter( 'get_the_excerpt', $generated ) ) {
			add_filter( 'get_the_excerpt', $generated, self::GENERATED_EXCERPT_PRIORITY, 2 );
		}

		add_filter( 'the_content_feed', array( $this, 'filter_feed_content' ), PHP_INT_MAX );
		add_filter( 'the_excerpt_rss', array( $this, 'filter_feed_excerpt' ), PHP_INT_MAX );
		add_filter( 'render_block_data', array( $this, 'withhold_latest_posts_content' ), PHP_INT_MAX, 3 );
		add_filter( 'register_block_type_args', array( $this, 'wrap_latest_posts_render' ), PHP_INT_MAX, 2 );
	}

	/**
	 * Evaluates the current request and, when the visitor is denied, does what the
	 * page says to do with them.
	 *
	 * Runs at priority 1 so the decision is made before themes, page builders
	 * and caching plugins get a chance to render or store anything.
	 *
	 * The page's `denied` setting says what, and the default is the placeholder, so
	 * a page that only has a 0.1.x policy, or no setting at all, shows one:
	 *
	 *   - `placeholder`: the body is replaced on the same URL by the built-in
	 *     placeholder, or by the page, pattern or template the author chose
	 *     (`Agend_Content_Access_Placeholder`). Answered with 200, `noindex` and no
	 *     caching. This is what the 403 and the teaser page used to be.
	 *   - `redirect`: a 302 to the chosen page, before any output.
	 *   - `back`: a 302 to the page the visitor came from, when that is on this site
	 *     and not this page, else to the page the author chose for that.
	 *
	 * A redirect that cannot be made safely is the placeholder instead, never the
	 * body: no destination, a destination the visitor could not open, one that is
	 * this page, or a referrer that is off-site, missing or this page
	 * (`Agend_Content_Access_Destination`). The action applies to whoever the rules
	 * deny, so a page that is for people who do NOT match (Reverse) redirects the
	 * people who do.
	 *
	 * ONE EXCEPTION, AN OUTAGE. A denial that is down to something that could not be
	 * evaluated (`degraded`: Agend unreachable, a lookup that failed, a provider that
	 * threw) always shows the placeholder, whatever the page says to do. The visitor
	 * may well be a member whom Agend simply could not confirm, and redirecting or
	 * sending them back would bounce real members off the site for the length of the
	 * outage. A page that chose a placeholder shows that one, and any other page shows
	 * the built-in one. The body is still never shown.
	 */
	public function gate_request(): void {
		// A denial is per request. Forgotten first, before every early return, so a
		// long-lived worker cannot show the last request's on this one.
		self::$current_gate = null;

		if ( self::is_authoring_context() ) {
			return;
		}

		if ( ! is_singular() || is_embed() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$decision = Agend_Content_Access_Decision::for_post( (int) $post->ID );

		if ( Agend_Content_Access_Decision::STATE_GRANTED === $decision['state'] ) {
			return;
		}

		$this->report_degraded( (int) $post->ID, $decision );

		$this->decision           = $decision;
		$this->gated_post         = $post;
		$this->placeholder_target = 0;
		self::$current_gate       = array(
			'post_id'  => (int) $post->ID,
			'decision' => $decision,
		);

		$denied = $decision['rules'] instanceof Agend_Content_Access_Rules
			? $decision['rules']->denied()
			: array();

		$action = isset( $denied['action'] ) ? (string) $denied['action'] : Agend_Content_Access_Rules::DENIED_PLACEHOLDER;

		if ( Agend_Content_Access_Rules::DENIED_PLACEHOLDER === $action ) {
			$this->placeholder_target = isset( $denied['target_id'] ) ? (int) $denied['target_id'] : 0;
		} elseif ( ! empty( $decision['degraded'] ) ) {
			// An outage: the built-in placeholder, not a redirect. See the docblock.
			$this->placeholder_target = 0;
		} elseif ( class_exists( 'Agend_Content_Access_Destination', false ) ) {
			$url = Agend_Content_Access_Destination::for_denied( $post, $denied );

			if ( '' !== $url ) {
				$this->redirect( $url, (int) $post->ID );
				return;
			}
		}

		$this->serve_placeholder( $post );
	}

	/**
	 * The denial of the page this request is for.
	 *
	 * Null when the request is not a denied page: it was granted, it is not a single
	 * page, or somebody is authoring it. Read by the `[agend_access_message]`
	 * shortcode, which then shows its neutral preview instead of a message for a
	 * visitor who is not there.
	 *
	 * @return array{post_id: int, decision: array}|null `decision` is the result of
	 *         `Agend_Content_Access_Decision::for_post()`.
	 */
	public static function current_gate(): ?array {
		return self::$current_gate;
	}

	/**
	 * Forgets the denial of the current request. Tests only: a request makes one.
	 */
	public static function reset_gate(): void {
		self::$current_gate = null;
	}

	/**
	 * Whether this request is somebody AUTHORING the page it serves, rather than
	 * reading it.
	 *
	 * Gating never applies here. An editor building a page must see every
	 * section, including ones they have just restricted, or they cannot lay out
	 * or edit the very content the policy protects. A page builder that hides
	 * blocks from their author is not a security feature, it is a broken editor.
	 *
	 * This is safe because an authoring context requires an edit capability on the
	 * post the request is for: it is `is_authoring_for_post()` asked about the
	 * requested page, so a wp-admin screen, the block editor's own REST renders and
	 * Elementor's edit mode count only for a post the user may edit, and never on
	 * the strength of the request's shape alone. It exists as an explicit early
	 * return so the intent is legible and so no later filter can accidentally reach
	 * a builder request.
	 *
	 * Note the boundary: this covers the EDITOR and its preview iframe, and the
	 * block editor's own REST renders. A public pageload of an Elementor page is a
	 * normal frontend request and is gated like any other, which is what
	 * `suppress_builder_data()` exists for.
	 *
	 * The preview iframe is recognised only on the page it is previewing, and its
	 * carve-out covers only that page. The `elementor-preview` query argument is
	 * attacker-controlled, and what makes it safe to honour is that the id it
	 * carries is the post this request serves. This answers the one decision made
	 * about the requested page itself. Anything that decides about a post rendered
	 * DURING the request, a loop, a widget, a template, a block, must ask
	 * `is_authoring_for_post()` about that post instead.
	 *
	 * @return bool
	 */
	public static function is_authoring_context(): bool {
		return self::is_authoring_for_post( self::requested_object_id() );
	}

	/**
	 * Whether this request is authoring the given post, as distinct from rendering
	 * it on the way past.
	 *
	 * THE RULE: a carve-out is never granted for a post the current user cannot
	 * edit. A wp-admin screen, the block editor's own renders and Elementor's real
	 * edit mode describe the request, and a request is not a capability. Each of
	 * them is authoring for a post only when the user holds `edit_post` on THAT
	 * post. Otherwise a contributor, who may edit one draft, could open the block
	 * renderer or the REST edit context with that draft as the post and read every
	 * restricted body, section and generated excerpt of every other post the
	 * request renders, and a contributor's wp-admin list screen would print the
	 * excerpts of members-only posts.
	 *
	 * A post that is not known, zero, is one the request chose for itself: a
	 * template part, a widget, a list of posts. The user must be able to
	 * `edit_others_posts` then, which is the capability to edit a post that is
	 * anyone's. Callers that list posts of their own choosing, and so cannot name
	 * them, are judged for each post when each is rendered (an excerpt, a body) and
	 * treated as a visitor's where they cannot be.
	 *
	 * Elementor's PREVIEW is authoring for one post only, the one named by the flag
	 * and served by the request, and needs no editing session. Every other post
	 * rendered during that request, a query loop listing restricted posts, a
	 * related posts widget, a template pulled in by a shortcode, a block of any
	 * other post, is something a visitor to the same page would be shown, and is
	 * gated like it. Otherwise anybody who can edit one post could print any
	 * restricted post into it and open the preview to read it.
	 *
	 * @param int $post_id Post being rendered. Zero when it is not known, which is
	 *                     never the previewed post.
	 * @return bool
	 */
	public static function is_authoring_for_post( int $post_id ): bool {
		if ( self::is_editing_session() && self::may_edit_for_authoring( $post_id ) ) {
			return true;
		}

		return $post_id > 0
			&& self::is_previewing_requested_post()
			&& self::requested_object_id() === $post_id;
	}

	/**
	 * Whether the request is an editing session: a wp-admin screen, the block
	 * editor's own server-side render, or Elementor's editor itself.
	 *
	 * Only the SHAPE of the request. It says that somebody is editing something,
	 * not what, so it grants nothing on its own: `is_authoring_for_post()` also
	 * asks whether the user may edit the post being rendered.
	 *
	 * @return bool
	 */
	private static function is_editing_session(): bool {
		return is_admin()
			|| self::is_block_editor_session()
			|| self::is_elementor_edit_mode();
	}

	/**
	 * Whether the user may edit the given post, the one capability every
	 * editing-session carve-out rests on.
	 *
	 * @param int $post_id Post, or zero for "a post that is anyone's".
	 * @return bool
	 */
	private static function may_edit_for_authoring( int $post_id ): bool {
		return $post_id > 0
			? current_user_can( 'edit_post', $post_id )
			: current_user_can( 'edit_others_posts' );
	}

	/**
	 * Whether Elementor says the editor is open on a document for this user.
	 *
	 * @return bool
	 */
	private static function is_elementor_edit_mode(): bool {
		if ( ! agend_content_access_has_elementor() ) {
			return false;
		}

		$plugin = \Elementor\Plugin::$instance;

		return isset( $plugin->editor ) && $plugin->editor->is_edit_mode();
	}

	/**
	 * Whether this request is Elementor's preview of the page it serves.
	 *
	 * @return bool
	 */
	private static function is_previewing_requested_post(): bool {
		if ( ! agend_content_access_has_elementor() ) {
			return false;
		}

		$plugin = \Elementor\Plugin::$instance;

		// The preview iframe is a normal frontend pageload flagged with
		// `?elementor-preview=<id>`, so `is_edit_mode()` is false there even
		// though it is exactly where the author is looking.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only mode check on an Elementor-set query var.
		$raw_preview = isset( $_GET['elementor-preview'] ) ? wp_unslash( $_GET['elementor-preview'] ) : 0;

		// An array, or anything that is not a plain number, is not a preview id.
		$preview_id = is_scalar( $raw_preview ) ? absint( $raw_preview ) : 0;

		if ( $preview_id <= 0 || ! isset( $plugin->preview ) ) {
			return false;
		}

		// The flag is only trusted on the page it names. Elementor's own
		// `is_preview_mode( $id )` checks that the user may edit `$id`, then
		// compares `$id` with the very query-string value it was just handed, so
		// it answers true for ANY URL once the visitor appends the id of a post
		// of theirs. A contributor could then open a restricted page with
		// `?elementor-preview=<their own draft>` and be treated as authoring it:
		// no page gate, no loop or excerpt gate, no section suppression. Requiring
		// the id to be the post this request actually serves ties the flag to the
		// one document the capability check was made against.
		if ( self::requested_object_id() !== $preview_id ) {
			return false;
		}

		return (bool) $plugin->preview->is_preview_mode( $preview_id );
	}

	/**
	 * The id of the post or page the request is for.
	 *
	 * Read from the MAIN query, not the global one: Elementor switches the global
	 * query while it renders dynamic content in a preview, and a check that
	 * followed it would stop recognising the very page being previewed. The main
	 * query is fixed by the URL, so nothing a visitor appends can change it.
	 *
	 * @return int Zero when the request is not for a single post.
	 */
	private static function requested_object_id(): int {
		$main = $GLOBALS['wp_the_query'] ?? null;

		if ( is_object( $main ) && method_exists( $main, 'get_queried_object_id' ) ) {
			return (int) $main->get_queried_object_id();
		}

		return (int) get_queried_object_id();
	}

	/**
	 * Whether this request is the block editor's OWN server-side render, for the
	 * post the request is about.
	 *
	 * The block editor never renders through wp-admin: it asks the REST API, where
	 * `is_admin()` is false. Without this an author would see their own restricted
	 * blocks removed from the editor's previews and, worse, from the post body the
	 * editor loads, so they could neither lay out nor repair the content the policy
	 * protects. The rule is deliberately narrow, because this is the one place a
	 * request can talk itself out of block enforcement. ALL of these must hold:
	 *
	 *   1. WordPress is serving a REST request. A front-end URL with `?context=edit`
	 *      on it is a front-end request, and never qualifies.
	 *   2. The user can `edit_posts`. A query arg, a header or an unauthenticated
	 *      REST call cannot supply this: REST cookie authentication drops the user
	 *      to signed-out when the nonce is missing, before dispatch.
	 *   3. The request is for the block renderer route, or asks for `context=edit`,
	 *      which is what the editor sends and what WordPress already refuses to a
	 *      user who may not edit.
	 *   4. If a post is being rendered, the user may edit THAT post. A contributor
	 *      listing other people's posts with `context=edit` is not authoring them,
	 *      and without this clause would read every restricted block in them.
	 *      With NO post in the request the user must be able to `edit_others_posts`.
	 *      A block renderer call with no `post_id` renders a template part, a
	 *      pattern or a block of any other post's content, and a contributor, who
	 *      can `edit_posts`, may not read those: it is the one request that would
	 *      otherwise hand them every restricted block on the site. The cost is that
	 *      a user who can only write their own posts sees restricted blocks removed
	 *      from a server-side preview that carries no post id.
	 *
	 * This answers for the request's own post, the one WordPress set up for it. It
	 * is not what decides whether a post rendered ALONG THE WAY is authored: the
	 * request names one post and renders many, and the post it names says nothing
	 * about the others. Every decision about a rendered post goes through
	 * `is_authoring_for_post()`, which uses clauses 1 to 3 and then asks clause 4
	 * of the post it was given.
	 *
	 * Reading `$_GET` and `$GLOBALS['wp']` here, rather than capturing the
	 * dispatched `WP_REST_Request`, can only miss a case (a batched sub-request,
	 * which then renders as a visitor's would), never invent one.
	 *
	 * @return bool
	 */
	public static function is_block_editor_request(): bool {
		if ( ! self::is_block_editor_session() ) {
			return false;
		}

		$post = get_post();

		return self::may_edit_for_authoring( $post instanceof WP_Post ? (int) $post->ID : 0 );
	}

	/**
	 * Whether the request has the SHAPE of the block editor's own server-side
	 * render: clauses 1 to 3 of `is_block_editor_request()`.
	 *
	 * Says nothing about which post. A request of this shape is authoring a post
	 * only if the user may edit that post: see `is_authoring_for_post()`.
	 *
	 * @return bool
	 */
	private static function is_block_editor_session(): bool {
		if ( ! self::is_rest_request() ) {
			return false;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		return 0 === strpos( self::rest_route(), self::BLOCK_RENDERER_ROUTE ) || self::rest_context_is_edit();
	}

	/**
	 * Whether WordPress is serving a REST API request.
	 *
	 * `wp_is_serving_rest_request()` arrived in 6.5; before that the constant
	 * `rest_api_loaded()` defines is the only signal, and is what the function
	 * reads. An internal `rest_do_request()` from a theme does not define it, so
	 * it is not mistaken for one.
	 *
	 * @return bool
	 */
	private static function is_rest_request(): bool {
		if ( function_exists( 'wp_is_serving_rest_request' ) ) {
			return wp_is_serving_rest_request();
		}

		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * The REST route being served, as `rest_api_loaded()` dispatches it.
	 *
	 * @return string The route with a leading slash, or '' when there is none.
	 */
	private static function rest_route(): string {
		$wp    = isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) ? $GLOBALS['wp'] : null;
		$route = null !== $wp && isset( $wp->query_vars['rest_route'] ) ? $wp->query_vars['rest_route'] : '';

		return is_string( $route ) && '' !== $route ? '/' . ltrim( $route, '/' ) : '';
	}

	/**
	 * Whether the REST request asked for the `edit` context.
	 *
	 * @return bool
	 */
	private static function rest_context_is_edit(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only mode check. The capability check beside it is what authorises.
		return isset( $_GET['context'] ) && is_string( $_GET['context'] ) && 'edit' === $_GET['context'];
	}

	/**
	 * The public teaser for a restricted post.
	 *
	 * The manually written excerpt only. An AUTO-generated excerpt is the first
	 * 55 words of the protected body, so falling back to one would publish the
	 * opening of the very content being protected.
	 *
	 * @param WP_Post $post Post.
	 * @return string Teaser text, or '' when none is configured.
	 */
	public static function teaser_for( $post ): string {
		$teaser = has_excerpt( $post ) ? (string) $post->post_excerpt : '';

		/**
		 * Filters the teaser shown in place of restricted content.
		 *
		 * Shown above the message of a denied page's placeholder, in a list, and in a
		 * feed. Whatever is returned is public: it is shown to anonymous visitors, so
		 * it must not contain protected material.
		 *
		 * @param string  $teaser Teaser text.
		 * @param WP_Post $post   Post being gated.
		 */
		return (string) apply_filters( 'agend_content_access_teaser', $teaser, $post );
	}

	/**
	 * Sends the visitor to another page of this site and stops.
	 *
	 * The response depends on who asked, so it is marked uncacheable first: a page
	 * cache that stored one visitor's redirect would send the next visitor, who may
	 * be allowed, away from a page they can read.
	 *
	 * @param string $url     An address `Agend_Content_Access_Destination` approved.
	 * @param int    $post_id The page being left.
	 */
	private function redirect( string $url, int $post_id ): void {
		self::mark_uncacheable( $post_id );

		wp_safe_redirect( $url, 302, 'Agend Content Access' );

		$this->terminate();
	}

	/**
	 * Ends the request. Its own method so a test can stand in for the one thing
	 * that cannot be run twice.
	 */
	protected function terminate(): void {
		exit;
	}

	/**
	 * Sends one response header, when headers can still be sent.
	 *
	 * The one place this class sends a header, and its own method for the same
	 * reason as `terminate()`: PHP's command line keeps no list of the headers a
	 * script set, so a test can only see them if somebody records them. Resolved
	 * with `static::` for that.
	 *
	 * @param string $line A complete header line, such as `Cache-Control: private, no-store`.
	 */
	protected static function send_header( string $line ): void {
		if ( ! headers_sent() ) {
			header( $line, true );
		}
	}

	/**
	 * Swaps the rendered body for the placeholder and makes the response one that
	 * says so.
	 *
	 * @param WP_Post $post The page being denied.
	 */
	private function serve_placeholder( WP_Post $post ): void {
		add_filter( 'the_content', array( $this, 'filter_the_content' ), 1 );

		// Page builders render from their own stored data rather than
		// `the_content`, so the raw document has to be emptied too or an
		// Elementor layout would render the protected sections regardless.
		add_filter( 'get_post_metadata', array( $this, 'suppress_builder_data' ), 10, 4 );

		// The placeholder is a different page from the article. Telling search
		// engines it is the article would index the placeholder under the real
		// content's terms.
		add_filter( 'wp_robots', array( $this, 'robots_noindex' ) );

		// A real page answering 200, not a 403 and not a 404: the visitor is on the
		// page they asked for and is told what to do. `status_header()` does nothing
		// once output has begun, so it is not guarded.
		status_header( 200 );

		static::send_header( 'X-Robots-Tag: noindex, nofollow' );

		// What the placeholder says depends on who is asking, and the URL is one a
		// member sees the real page on. A cache that kept the placeholder would show
		// it to the next member.
		self::mark_uncacheable( (int) $post->ID );

		// In the head, where it is needed, when the built-in placeholder is what will
		// be shown. A chosen page, pattern or template brings its own styling, and
		// enqueues this too if it carries the message shortcode.
		if ( $this->placeholder_target <= 0 && class_exists( 'Agend_Content_Access_Placeholder', false ) ) {
			Agend_Content_Access_Placeholder::enqueue_style();
		}
	}

	/**
	 * Replaces the post body with the placeholder.
	 *
	 * Without the class that renders the placeholder it returns nothing, never the
	 * body: a deployment that is missing a file shows a blank page, not the page.
	 *
	 * @param string $content Original content.
	 * @return string
	 */
	public function filter_the_content( $content ): string {
		if ( ! in_the_loop() || ! is_main_query() || null === $this->decision ) {
			return $content;
		}

		// A chosen page is rendered through this very filter: see the property.
		if ( $this->rendering_placeholder ) {
			return $content;
		}

		if ( ! $this->gated_post instanceof WP_Post || ! class_exists( 'Agend_Content_Access_Placeholder', false ) ) {
			return '';
		}

		$this->rendering_placeholder = true;

		try {
			return Agend_Content_Access_Placeholder::for_page( $this->gated_post, $this->decision, $this->placeholder_target );
		} finally {
			$this->rendering_placeholder = false;
		}
	}

	/**
	 * Adds `noindex, nofollow` to the page's robots meta tag.
	 *
	 * Through core's `wp_robots`, so there is one tag and not two, and an SEO plugin
	 * that builds on it agrees.
	 *
	 * @param mixed $robots The directives so far.
	 * @return array
	 */
	public function robots_noindex( $robots ): array {
		$robots             = is_array( $robots ) ? $robots : array();
		$robots['noindex']  = true;
		$robots['nofollow'] = true;

		return $robots;
	}

	/**
	 * Blanks page-builder document data for a gated post.
	 *
	 * @param mixed  $value     Existing short-circuit value.
	 * @param int    $object_id Post id.
	 * @param string $meta_key  Meta key.
	 * @param bool   $single    Whether a single value was requested.
	 * @return mixed Empty data for builder keys, otherwise the untouched value.
	 */
	public function suppress_builder_data( $value, $object_id, $meta_key, $single ) {
		// Elementor stores its document here. Returning an empty array stops the
		// builder rendering the protected layout from its own copy.
		if ( '_elementor_data' !== $meta_key ) {
			return $value;
		}

		// A post withheld from a loop render is gated as firmly as the queried
		// one. Otherwise the builder would render a restricted post's layout from
		// its own copy inside every archive that lists it.
		if ( isset( $this->withheld[ (int) $object_id ] ) ) {
			return $single ? array() : array( array() );
		}

		if ( null === $this->decision ) {
			return $value;
		}

		$queried = get_queried_object_id();

		if ( (int) $object_id !== (int) $queried ) {
			return $value;
		}

		return $single ? array() : array( array() );
	}

	/**
	 * Records an outage that denied a visitor who may well be entitled.
	 *
	 * An operational error, because a spike here is a broken gateway, not a
	 * surge of lapsed memberships.
	 *
	 * @param int   $post_id  Post id.
	 * @param array $decision Result of `Agend_Content_Access_Decision::for_post()`.
	 */
	private function report_degraded( int $post_id, array $decision ): void {
		if ( empty( $decision['degraded'] ) || isset( $this->degraded_reported[ $post_id ] ) ) {
			return;
		}

		$this->degraded_reported[ $post_id ] = true;

		do_action( 'agend_content_access_degraded', $post_id, $decision['state'] );
	}

	/**
	 * Whether the current render is one a visitor can end up reading.
	 *
	 * The opposite of `is_authoring_for_post()` except for one case. WordPress
	 * reports `is_admin()` for admin-ajax.php, but "load more" buttons, filters
	 * and infinite scroll print posts to ANONYMOUS visitors through it, so an
	 * is_admin() exclusion would leave exactly those renders open. wp-admin
	 * screens proper are authoring only for a post the user may edit: an editor
	 * there needs to see what they are editing, and a contributor's list screen
	 * is not a licence to read the excerpts of posts that are not theirs.
	 *
	 * Asked about a POST, because an editing session is authoring for the posts
	 * the user may edit and Elementor's preview for the one post it previews, and
	 * for nothing else on the request. Called without one, it answers for a post
	 * that is anyone's, which needs `edit_others_posts`: the right answer for a
	 * render that lists posts of its own choosing and cannot name them.
	 *
	 * Public so the block driver and the Elementor driver ask the same question and
	 * cannot disagree with the loop gates about what an admin-ajax render is, or
	 * about which post a preview covers.
	 *
	 * @param int $post_id Post being rendered, or zero when it is not known.
	 * @return bool
	 */
	public static function renders_for_visitors( int $post_id = 0 ): bool {
		if ( is_admin() && wp_doing_ajax() ) {
			return true;
		}

		return ! self::is_authoring_for_post( $post_id );
	}

	/**
	 * Whether this is an AJAX request made by Elementor's own editor.
	 *
	 * The one admin-ajax request that is authoring rather than reading: the editor
	 * rendering a widget to refresh its canvas, or fetching a template. Elementor
	 * puts itself in edit mode for those, and only for those: its edit mode is
	 * keyed on its own action names, so another plugin's AJAX handler never
	 * reports it, and a visitor cannot switch it on for one, because it also
	 * requires the edit capability on the document the editor names.
	 *
	 * That capability is on the ONE document the editor names. The request can
	 * still render others (a Loop Grid, a Template widget), so this is the shape of
	 * the request only: whoever relies on it must also ask whether the user may edit
	 * the document being rendered, as `Agend_Content_Access_Elementor` does.
	 *
	 * @return bool
	 */
	public static function is_elementor_editor_ajax(): bool {
		return wp_doing_ajax() && self::is_elementor_edit_mode();
	}

	/**
	 * The post currently being rendered, when it should be gated at all.
	 *
	 * @return WP_Post|null
	 */
	private static function post_being_rendered(): ?WP_Post {
		$post = get_post();

		if ( ! $post instanceof WP_Post || ! self::renders_for_visitors( (int) $post->ID ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * The decision for a post rendered outside the gated singular.
	 *
	 * A feed is evaluated as an anonymous reader (see `Decision::for_post()`).
	 * Everything else is evaluated for the actual visitor.
	 *
	 * @param int $post_id Post id.
	 * @return array{state: string, policy: array|null, rules: Agend_Content_Access_Rules, preview: bool, degraded: bool}
	 */
	private static function decision_for_render( int $post_id ): array {
		return Agend_Content_Access_Decision::for_post( $post_id, is_feed() );
	}

	/**
	 * Withholds a restricted post's body wherever it renders outside the gated
	 * singular: a blog index, an archive, search results, a widget, a related
	 * posts list, or a block theme's query loop.
	 *
	 * `gate_request()` bails unless the request is singular, and
	 * `filter_the_content()` only acts for the main query's own loop, so before
	 * this filter any other place a restricted post rendered printed its full
	 * body to whoever asked. Acts on the post WordPress is currently rendering
	 * (`get_post()`), which is how a `the_content` filter learns whose content
	 * it was handed.
	 *
	 * Posts with no rules cost nothing: `for_post()` returns for them before any
	 * identity lookup.
	 *
	 * @param string $content Original content.
	 * @return string
	 */
	public function withhold_in_loop( $content ) {
		$post = self::post_being_rendered();

		if ( null === $post ) {
			return $content;
		}

		// The queried post is already handled by `gate_request()` whenever
		// `filter_the_content()` will act, which is exactly this condition. It
		// is skipped here so that path is not changed and nothing is gated twice.
		// The same post rendered anywhere ELSE on the request (a sidebar, a
		// related list) does not meet it, and is gated here.
		if ( null !== $this->decision && in_the_loop() && is_main_query() ) {
			return $content;
		}

		$post_id  = (int) $post->ID;
		$decision = self::decision_for_render( $post_id );

		if ( Agend_Content_Access_Decision::STATE_GRANTED === $decision['state'] ) {
			self::note_granted_render( $post_id, $decision );
			return $content;
		}

		$this->report_degraded( $post_id, $decision );

		$gate = self::render_gate(
			self::teaser_for( $post ),
			$decision['state'],
			$decision['policy']
		);

		$this->withheld[ $post_id ] = $gate;

		// Registered on first use: it sits on every `get_post_meta()` call, so a
		// request that withholds nothing should not pay for it. Adding the same
		// callback again is a no-op.
		add_filter( 'get_post_metadata', array( $this, 'suppress_builder_data' ), 10, 4 );

		return $gate;
	}

	/**
	 * Restores the gate over a withheld post after every other filter has run.
	 *
	 * See `register_render_filters()` for why this second pass exists. It does
	 * not decide again: it only re-asserts what `withhold_in_loop()` already
	 * decided, so a post that was granted is untouched.
	 *
	 * @param string $content Content as the rest of the chain left it.
	 * @return string
	 */
	public function enforce_withheld( $content ) {
		if ( array() === $this->withheld ) {
			return $content;
		}

		$post = get_post();

		if ( $post instanceof WP_Post && isset( $this->withheld[ (int) $post->ID ] ) ) {
			return $this->withheld[ (int) $post->ID ];
		}

		return $content;
	}

	/**
	 * Gates a post's excerpt.
	 *
	 * An excerpt is not harmless. When none was written, `wp_trim_excerpt()`
	 * builds one from the first 55 words of the BODY, so every archive, search
	 * result, widget and SEO description would publish the opening of the
	 * protected post. A denied post therefore yields its manual excerpt only:
	 * that is the editor's deliberate public teaser, the same rule `teaser_for()`
	 * applies to the singular gate. With none written the excerpt is empty.
	 *
	 * @param string       $excerpt Excerpt as the chain built it.
	 * @param WP_Post|null $post    Post the excerpt is for.
	 * @return string
	 */
	public function filter_excerpt( $excerpt, $post = null ) {
		if ( ! $post instanceof WP_Post || ! self::renders_for_visitors( (int) $post->ID ) ) {
			return $excerpt;
		}

		$post_id  = (int) $post->ID;
		$decision = self::decision_for_render( $post_id );

		if ( Agend_Content_Access_Decision::STATE_GRANTED === $decision['state'] ) {
			self::note_granted_render( $post_id, $decision );
			return $excerpt;
		}

		$this->report_degraded( $post_id, $decision );

		return self::teaser_for( $post );
	}

	/**
	 * Stops the Latest Posts block printing the raw body of the posts it lists.
	 *
	 * Its "full post" display does not use the content filters. It prints
	 * `wp_kses_post( $post->post_content )` for each listed post, straight from the
	 * database row, so the body of every restricted post it lists went to an
	 * anonymous visitor and nothing in the chain above had a chance to object.
	 * Its other display, "excerpt", calls `get_the_excerpt()`, which is gated.
	 *
	 * So the block is rendered in excerpt mode instead, for anyone who is not
	 * authoring. THE FIDELITY COST IS REAL AND DELIBERATE: a Latest Posts block set
	 * to show full posts shows excerpts to every visitor, restricted or not. Doing
	 * better means predicting which posts the block will list, which means running
	 * the query core builds from the block's attributes, and that query is core's
	 * to change between releases. A copy of it that drifted would fail OPEN, back
	 * to printing raw bodies, silently. Switching the mode needs no knowledge of
	 * the query and cannot drift. A site that needs full posts in a list can print
	 * them with a Query Loop block, whose `the_content` is gated.
	 *
	 * Any value other than `excerpt` is switched, not only `full_post`, so a mode a
	 * later core adds is withheld until someone has decided what it prints.
	 *
	 * Only the display mode is touched. The block's `agendAccess` attribute, which
	 * the block driver judges on `pre_render_block` and again on `render_block`, is
	 * left as it was, and this runs before both, so a Latest Posts block carrying
	 * its own rule is still removed for a visitor who fails it, and the verdict the
	 * two passes reach is made on the same attribute.
	 *
	 * This is the first of two places the switch is made, and not enough alone: a
	 * block that is rendered without passing through `render_block_data` keeps its
	 * mode. See `wrap_latest_posts_render()`, which makes it inside the callback.
	 *
	 * @param array      $parsed_block Block about to render.
	 * @param array|null $source_block Block as parsed. Unused.
	 * @param mixed      $parent_block Parent block, when nested. Unused.
	 * @return array
	 */
	public function withhold_latest_posts_content( $parsed_block, $source_block = null, $parent_block = null ) {
		if ( ! is_array( $parsed_block ) || 'core/latest-posts' !== ( $parsed_block['blockName'] ?? null ) ) {
			return $parsed_block;
		}

		if ( ! self::latest_posts_renders_for_visitors() ) {
			return $parsed_block;
		}

		$attributes = isset( $parsed_block['attrs'] ) && is_array( $parsed_block['attrs'] )
			? $parsed_block['attrs']
			: array();

		$switched = self::latest_posts_in_excerpt_mode( $attributes );

		if ( $switched !== $attributes ) {
			$parsed_block['attrs'] = $switched;
		}

		return $parsed_block;
	}

	/**
	 * Makes the Latest Posts block's own render callback withhold full posts.
	 *
	 * The second place the switch is made, and the one that cannot be skipped.
	 * `render_block_data` runs inside `render_block()`, and some paths render a
	 * block without it: the children of a navigation read from a `ref` post, and
	 * the children of a widget group once a third party has short-circuited the
	 * render. A block that reached its callback with `full_post` there would print
	 * every restricted body. Wrapping the callback, at registration, puts the
	 * switch at the one place every render of the block goes through.
	 *
	 * Registered last, so the wrapper is the outermost and nothing added to the
	 * block type after it can unwrap it. The block's other registration arguments
	 * are untouched, and a callback that is not callable is left as it was, since
	 * wrapping it would only move the failure.
	 *
	 * @param array  $args       Block type arguments.
	 * @param string $block_type Block type name.
	 * @return array
	 */
	public function wrap_latest_posts_render( $args, $block_type = '' ) {
		if (
			'core/latest-posts' !== $block_type
			|| ! is_array( $args )
			|| ! isset( $args['render_callback'] )
			|| ! is_callable( $args['render_callback'] )
		) {
			return $args;
		}

		$render = $args['render_callback'];

		$args['render_callback'] = static function ( $attributes = array(), ...$rest ) use ( $render ) {
			if ( is_array( $attributes ) && self::latest_posts_renders_for_visitors() ) {
				$attributes = self::latest_posts_in_excerpt_mode( $attributes );
			}

			return call_user_func( $render, $attributes, ...$rest );
		};

		return $args;
	}

	/**
	 * Whether a Latest Posts block is rendering for somebody who may be a visitor.
	 *
	 * The block lists posts of its own choosing, so no post can be named and the
	 * question is the one for a post that is anyone's: `edit_others_posts`, in an
	 * editing session. A user who may edit only some posts is a visitor here, and
	 * the excerpts the block then prints are judged for each post it lists, which
	 * is the per post rule. It is switched in a preview too: none of the posts it
	 * lists is the previewed one by being in it.
	 *
	 * @return bool
	 */
	private static function latest_posts_renders_for_visitors(): bool {
		return self::renders_for_visitors( 0 );
	}

	/**
	 * The block's attributes with a full post display switched to excerpts.
	 *
	 * Left alone when the block shows no content, or already shows excerpts.
	 *
	 * @param array $attributes The block's attributes.
	 * @return array
	 */
	private static function latest_posts_in_excerpt_mode( array $attributes ): array {
		if (
			empty( $attributes['displayPostContent'] )
			|| ! isset( $attributes['displayPostContentRadio'] )
			|| 'excerpt' === $attributes['displayPostContentRadio']
		) {
			return $attributes;
		}

		$attributes['displayPostContentRadio'] = 'excerpt';

		return $attributes;
	}

	/**
	 * Replaces a generated excerpt that could carry text a viewer was not meant
	 * to read, for a document with fragment rules.
	 *
	 * WHY IT EXISTS. `wp_trim_excerpt()` builds an excerpt when none was written,
	 * and it does it by rendering the BODY through `excerpt_remove_blocks()`.
	 * That function renders the children of a Group, Columns or Column block one
	 * by one and never renders the wrapper itself, so a restriction on the wrapper
	 * is never asked about and its text reaches every archive, search result, feed
	 * description and `excerpt.rendered` in REST. An Elementor document's body is
	 * worse: Elementor stores a flattened copy of the rendered page in
	 * `post_content`, restricted sections included.
	 *
	 * WHAT IT DOES. For a post with no manual excerpt whose document carries
	 * rules on a block or a section, the generated
	 * excerpt is whatever `agend_content_access_safe_generated_excerpt` returns,
	 * starting from ''. A block document gets an excerpt rebuilt from its own tree
	 * with every ruled subtree removed (`Agend_Content_Access_Blocks`); anything
	 * else, an Elementor document included, keeps '' because no rule-free text can
	 * be recovered from it. The result is the same for every viewer. It does not
	 * depend on who is looking, so it is safe for the caches excerpts end up in.
	 *
	 * A MANUAL excerpt is untouched: it is the editor's deliberate public text,
	 * the same rule `teaser_for()` applies. Judged as `wp_trim_excerpt()` judges
	 * it, by `trim()`, so a whitespace-only excerpt, which core treats as empty and
	 * generates over, is generated over here too.
	 *
	 * Authors are not filtered (`renders_for_visitors()`), so the editor and the
	 * preview of the post itself show the whole excerpt. Another post's excerpt
	 * rendered inside a preview is a visitor's.
	 *
	 * @param string       $excerpt Excerpt as `wp_trim_excerpt()` left it.
	 * @param WP_Post|null $post    Post the excerpt is for.
	 * @return string
	 */
	public function filter_generated_excerpt( $excerpt, $post = null ) {
		if ( ! $post instanceof WP_Post || ! self::renders_for_visitors( (int) $post->ID ) ) {
			return $excerpt;
		}

		if ( '' !== trim( (string) $post->post_excerpt ) || ! self::carries_fragment_rules( $post ) ) {
			return $excerpt;
		}

		/**
		 * Filters the generated excerpt of a document that carries fragment rules.
		 *
		 * The block driver rebuilds it from the block tree without the ruled
		 * blocks. Left alone it is the empty string, which is the answer for a
		 * document nothing can rebuild, an Elementor one. Whatever is returned is
		 * public and shared by every viewer, so it must not contain text from a
		 * ruled block or section.
		 *
		 * @param string  $excerpt The safe generated excerpt so far. Starts empty.
		 * @param WP_Post $post    Post the excerpt is for.
		 */
		return (string) apply_filters( 'agend_content_access_safe_generated_excerpt', '', $post );
	}

	/**
	 * Whether a post's document carries fragment rules, for the readers of its raw
	 * body: the generated excerpt and the REST authoring source.
	 *
	 * The saved flag first, which is a single meta read and covers both editors.
	 * Then, when the block driver is loaded, a live scan, because the flag can be
	 * stale in the unsafe direction: a synced pattern edited after the page was
	 * saved changes what the page holds without touching the page's flag, and a
	 * post saved before the flag existed has none. The scan is cheap next to the
	 * excerpt it guards, which renders the body.
	 *
	 * The block driver is looked up, not required, so this class stays usable
	 * without it.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	private static function carries_fragment_rules( WP_Post $post ): bool {
		if ( '1' === (string) get_post_meta( (int) $post->ID, self::FRAGMENT_RULES_META_KEY, true ) ) {
			return true;
		}

		return class_exists( 'Agend_Content_Access_Blocks', false )
			&& Agend_Content_Access_Blocks::document_carries_rules( $post );
	}

	/**
	 * Gates a feed item's full content.
	 *
	 * A feed reader is anonymous, so a restricted item carries its teaser and
	 * nothing else: no gate prompt either, because its sign-in link would point
	 * back at the feed. Always evaluated as anonymous, never for whoever fetched
	 * it, because a feed response is shared and cached.
	 *
	 * @param string $content Feed content.
	 * @return string
	 */
	public function filter_feed_content( $content ) {
		$post = get_post();

		if ( ! $post instanceof WP_Post ) {
			return $content;
		}

		$decision = Agend_Content_Access_Decision::for_post( (int) $post->ID, true );

		if ( Agend_Content_Access_Decision::STATE_GRANTED === $decision['state'] ) {
			return $content;
		}

		$teaser = self::teaser_for( $post );

		return '' === $teaser ? '' : wpautop( esc_html( $teaser ) );
	}

	/**
	 * Gates a feed item's summary.
	 *
	 * @param string $output Feed excerpt.
	 * @return string
	 */
	public function filter_feed_excerpt( $output ) {
		$post = get_post();

		if ( ! $post instanceof WP_Post ) {
			return $output;
		}

		$decision = Agend_Content_Access_Decision::for_post( (int) $post->ID, true );

		if ( Agend_Content_Access_Decision::STATE_GRANTED === $decision['state'] ) {
			return $output;
		}

		return self::teaser_for( $post );
	}

	/**
	 * Keeps a page that carries a member's render of restricted content out of
	 * page caches.
	 *
	 * Only when the body was granted to a visitor the rules actually admitted, who
	 * is not merely previewing. Posts with no rules never reach a cache problem, and an
	 * editor's preview is a logged-in request that caches already bypass.
	 *
	 * @param int   $post_id  Post id.
	 * @param array $decision Result of `Agend_Content_Access_Decision::for_post()`.
	 */
	private static function note_granted_render( int $post_id, array $decision ): void {
		if ( ! empty( $decision['preview'] ) || $decision['rules']->is_empty() ) {
			return;
		}

		self::mark_uncacheable( $post_id );
	}

	/**
	 * Marks the current page as one no shared cache may store.
	 *
	 * A page cache keys on the URL, not on who asked. If a member's render of a
	 * listing is stored, the next anonymous visitor to that URL is served the
	 * member's copy, restricted bodies included, and the gate never runs. The same
	 * goes the other way for a denied visitor's placeholder or redirect, which would
	 * be served to the next visitor, who may be allowed. So the page is excluded two
	 * ways:
	 *
	 * - `DONOTCACHEPAGE`, the constant WP Super Cache, W3 Total Cache, WP Rocket
	 *   and LiteSpeed Cache all honour. Defined while the page renders, and caches
	 *   read it after it has rendered, so setting it mid-page works.
	 * - `Cache-Control: private, no-store`, for caches and proxies in front of
	 *   WordPress that ignore the constant. `nocache_headers()` is not enough on
	 *   its own: its `no-cache` forbids reuse without revalidation but still
	 *   PERMITS a cache to store the response, so a member's copy could sit in a
	 *   shared store. `no-store` forbids storing it, and `private` forbids shared
	 *   caches from keeping it at all. The header can only be sent while headers
	 *   are still unsent. In a classic theme output usually began before the loop,
	 *   so the constant is the dependable half and the header is best effort.
	 *
	 * Public so the block driver marks a page through the same code, and the same
	 * action, as a gated post does.
	 *
	 * @param int $post_id Post whose render caused this.
	 */
	public static function mark_uncacheable( int $post_id ): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		static::send_header( 'Cache-Control: private, no-store' );

		/**
		 * Fires when a page carries a member's render of a restricted post, or a
		 * placeholder or redirect in place of one.
		 *
		 * For cache integrations that do not read `DONOTCACHEPAGE`, such as a CDN
		 * bypass rule or a host's own page cache.
		 *
		 * @param int $post_id Post whose granted render caused this.
		 */
		do_action( 'agend_content_access_uncacheable_render', $post_id );
	}

	/**
	 * Names the plans a selected-plans policy admits.
	 *
	 * Returns an empty array when the catalogue is unreachable or none of the
	 * policy's tiers resolve, so the caller falls back to generic copy. Naming
	 * nothing is better than naming "Unknown plan".
	 *
	 * Disclosure was considered and is not a concern: the tier catalogue is
	 * already served unauthenticated by Agend Apps Core at
	 * `/agend-apps/v1/crm/tiers`, so plan names are public by a route that
	 * predates this plugin. Withholding them here would have cost a member the
	 * one piece of information they need to act, and protected nothing.
	 *
	 * @param array|null $policy Canonical policy.
	 * @return string[] Plan names, possibly empty.
	 */
	public static function plan_names_for( ?array $policy ): array {
		$tier_ids = Agend_Content_Access_Policy::tier_ids( $policy );

		if ( array() === $tier_ids ) {
			return array();
		}

		$catalogue = Agend_Content_Access_Catalogue::get();
		$annotated = Agend_Content_Access_Catalogue::annotate_selection(
			$tier_ids,
			$catalogue['plans']
		);

		$names = array();

		foreach ( $annotated as $entry ) {
			// A tier the catalogue cannot name is skipped rather than rendered
			// as a placeholder: a visitor cannot act on "Unknown plan", and it
			// advertises that the policy is broken.
			if ( '' !== $entry['name'] ) {
				$names[] = $entry['name'];
			}
		}

		return $names;
	}

	/**
	 * Names the entitlements an entitlement policy admits.
	 *
	 * The sibling of `plan_names_for()`, for the same reason and with the same
	 * degradation: an empty array when the catalogue is unreachable, the key is
	 * not in it, or the type has no name of its own, so the caller falls back to
	 * generic copy. An entitlement an editor chose is something a member can
	 * acquire, which is why a visitor may be told its name. A row with no name is
	 * skipped rather than shown by its gate key: a key such as `lms.certifications`
	 * is not something a visitor can act on, and it advertises a configuration
	 * detail.
	 *
	 * Deliberately has no counterpart for segments. A segment's name can describe
	 * the visitor ("Overdue renewals"), which is not theirs to be told, so no code
	 * path reads the segment catalogue for a gate.
	 *
	 * @param array|null $policy Canonical policy.
	 * @return string[] Entitlement names, possibly empty.
	 */
	public static function entitlement_names_for( ?array $policy ): array {
		$keys = Agend_Content_Access_Policy::entitlement_keys( $policy );

		if ( array() === $keys ) {
			return array();
		}

		$by_key = array();

		foreach ( Agend_Content_Access_Catalogue::entitlements()['items'] as $entitlement ) {
			$by_key[ $entitlement['gate_key'] ] = $entitlement['name'];
		}

		$names = array();

		foreach ( $keys as $key ) {
			if ( isset( $by_key[ $key ] ) && '' !== $by_key[ $key ] && $key !== $by_key[ $key ] ) {
				$names[] = $by_key[ $key ];
			}
		}

		return $names;
	}

	/**
	 * The words a denied visitor is told, and the one action that suits them.
	 *
	 * The one source of the gate copy, so the loop gate (`render_gate()`) and the
	 * placeholder a denied page shows in place of its body say the same thing about
	 * the same denial. Only the words depend on the state, never who is admitted.
	 *
	 * @param string     $state  Decision state.
	 * @param array|null $policy The rules as the policy arm that says them (`Decision::for_post()`'s
	 *                           `policy`), used to name the qualifying plans or entitlements. Rules
	 *                           no arm can say are named nothing. A segment is never named.
	 * @return array{message: string, cta: string} `cta` is the label of the call to action, or
	 *                           an empty string when the denial has none.
	 */
	public static function gate_copy( string $state, ?array $policy = null ): array {
		switch ( $state ) {
			case Agend_Content_Access_Decision::STATE_MEMBERSHIP:
				$message = __( 'This content is for members. An active membership is required to read it.', 'agend-content-access' );
				$cta     = __( 'View membership options', 'agend-content-access' );
				break;
			case Agend_Content_Access_Decision::STATE_PLAN:
				$names = self::plan_names_for( $policy );

				// Name the plans. A member on the wrong plan otherwise has no
				// way to know what they need and ends up contacting support.
				$message = array() === $names
					? __( 'This content is available to members on another plan.', 'agend-content-access' )
					: sprintf(
						/* translators: %s: comma-separated membership plan names. */
						__( 'This content is available to members on: %s.', 'agend-content-access' ),
						implode( ', ', $names )
					);
				$cta     = __( 'View membership options', 'agend-content-access' );
				break;
			case Agend_Content_Access_Decision::STATE_RESTRICTED:
				// A segment or an entitlement is not something the visitor can
				// join or upgrade into, so there is deliberately no call to
				// action: "View membership options" would point at a purchase
				// that does not unlock this.
				//
				// An entitlement policy names the entitlements, as a plan policy
				// names plans, because an entitlement is something a member can
				// acquire and the name is the one fact they need to act on. A
				// segment policy names NOTHING and does not read the segment
				// catalogue at all: a segment's name can describe the visitor
				// ("Overdue renewals") and is not theirs to be told.
				$names = self::entitlement_names_for( $policy );

				$message = array() === $names
					? __( 'This content is limited to selected members.', 'agend-content-access' )
					: sprintf(
						/* translators: %s: comma-separated entitlement names. */
						__( 'This content is limited to people who hold: %s.', 'agend-content-access' ),
						implode( ', ', $names )
					);
				$cta     = '';
				break;
			default:
				$message = __( 'This content is for members. Please sign in to read it.', 'agend-content-access' );
				$cta     = __( 'Sign in', 'agend-content-access' );
				break;
		}

		return array(
			'message' => $message,
			'cta'     => $cta,
		);
	}

	/**
	 * Where a denied visitor is sent to sign in or join: the member portal, with
	 * this page as the place to come back to.
	 *
	 * Agend Apps Core's portal setting, and nothing else, so there is no second
	 * place to configure it. Core has no separate "join" or "membership" address,
	 * so signing in and joining are the same destination, as the loop gate has
	 * always had them. An empty string when Core is too old to have the setting or
	 * none is configured, and the caller then omits the button rather than point it
	 * nowhere.
	 *
	 * @return string URL, or an empty string.
	 */
	public static function portal_link(): string {
		$portal = method_exists( 'Agend_Apps_Settings', 'get_portal_url' )
			? (string) Agend_Apps_Settings::get_portal_url()
			: '';

		if ( '' === $portal ) {
			return '';
		}

		$return = home_url( add_query_arg( array() ) );

		return add_query_arg( 'return_to', rawurlencode( $return ), $portal );
	}

	/**
	 * Renders the gate markup.
	 *
	 * Used for a restricted post that renders anywhere but its own page: the loop,
	 * a widget, a related list. A page's own URL shows the placeholder instead
	 * (`Agend_Content_Access_Placeholder`).
	 *
	 * @param string     $teaser Teaser text.
	 * @param string     $state  Decision state.
	 * @param array|null $policy The rules as the policy arm that says them (`Decision::for_post()`'s
	 *                           `policy`), used to name the qualifying plans or entitlements. Rules
	 *                           no arm can say are named nothing. A segment is never named.
	 * @return string
	 */
	public static function render_gate( string $teaser, string $state, ?array $policy = null ): string {
		$copy = self::gate_copy( $state, $policy );

		$html = '<div class="agend-access-gate">';

		if ( '' !== $teaser ) {
			$html .= '<div class="agend-access-teaser">' . wpautop( esc_html( $teaser ) ) . '</div>';
		}

		$html .= '<div class="agend-access-gate-prompt">';
		$html .= '<p>' . esc_html( $copy['message'] ) . '</p>';

		$url = self::portal_link();

		if ( '' !== $url && '' !== $copy['cta'] ) {
			$html .= '<p><a class="agend-access-gate-cta" href="' . esc_url( $url ) . '">'
				. esc_html( $copy['cta'] ) . '</a></p>';
		}

		$html .= '</div></div>';

		return $html;
	}

	/**
	 * Strips protected fields from public REST representations.
	 */
	public function register_rest_filters(): void {
		foreach ( Agend_Content_Access_Meta_Box::supported_post_types() as $post_type ) {
			add_filter(
				"rest_prepare_{$post_type}",
				array( $this, 'filter_rest_response' ),
				10,
				3
			);
		}
	}

	/**
	 * Removes the body from a REST item the requester cannot access, and the
	 * authoring source from one they may read but not edit.
	 *
	 * The public REST representation is a second copy of the same content, so a
	 * policy that governs only the rendered page leaves it readable through
	 * `/wp-json/wp/v2/posts`.
	 *
	 * `context=edit` is not a safe context in itself. The block editor loads a
	 * post for EDITING through this same route, so stripping the body for the
	 * people who edit it would empty the editor, and an edit capability on THAT
	 * post is the one thing that exempts a request. But WordPress allows
	 * `context=edit` on a COLLECTION to anybody holding `edit_posts`, which a
	 * contributor does. Without the per-post check, `/wp/v2/posts?context=edit`
	 * returned `content.raw` for every published post, restricted ones included.
	 * So the edit context is trusted per item, on `edit_post` for that post, and
	 * every other request is treated as the reader it is.
	 *
	 * This is the same per-post rule `is_block_editor_request()` applies to the
	 * blocks of a post, so the body, its footnotes (`meta.footnotes`, filtered by
	 * the block driver while the response is built) and the raw source all agree
	 * on who is authoring which post.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_Post          $post     Post.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_REST_Response
	 */
	public function filter_rest_response( $response, $post, $request ) {
		$post_id = (int) $post->ID;

		if ( 'edit' === $request->get_param( 'context' ) && current_user_can( 'edit_post', $post_id ) ) {
			return $response;
		}

		$decision = Agend_Content_Access_Decision::for_post( $post_id );
		$data     = $response->get_data();

		if ( ! is_array( $data ) ) {
			return $response;
		}

		if ( Agend_Content_Access_Decision::STATE_GRANTED === $decision['state'] ) {
			if ( ! self::exposes_authoring_source( $data ) || ! self::source_reveals_more_than_render( $post, $decision ) ) {
				return $response;
			}

			// Readable, but not editable by this requester: the rendered body
			// stays, the raw one goes.
			foreach ( array( 'content', 'excerpt' ) as $field ) {
				if ( isset( $data[ $field ] ) && is_array( $data[ $field ] ) ) {
					unset( $data[ $field ]['raw'], $data[ $field ]['block_version'] );
				}
			}

			$response->set_data( $data );

			return $response;
		}

		foreach ( array( 'content', 'excerpt' ) as $field ) {
			if ( isset( $data[ $field ] ) && is_array( $data[ $field ] ) ) {
				$data[ $field ]['rendered']  = '';
				$data[ $field ]['protected'] = true;
				unset( $data[ $field ]['raw'], $data[ $field ]['block_version'] );
			}
		}

		// Never expose the rules themselves: their plan, segment and entitlement
		// ids are the association's configuration. Neither the 0.1.x policy nor the
		// rule list, whichever the post holds.
		unset( $data['meta'][ Agend_Content_Access_Policy::META_KEY ], $data['meta'][ Agend_Content_Access_Rules::META_KEY ] );

		// A footnote is the text of a note on the body, kept in post meta that
		// core registers for REST. The body is withheld above; its notes go with it.
		unset( $data['meta'][ self::FOOTNOTES_META_KEY ] );

		$data['agend_access'] = array( 'state' => $decision['state'] );

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Whether a REST item carries the authoring source of its body.
	 *
	 * Only the `edit` context does. Asked first so a public listing, which never
	 * carries it, pays for no further check.
	 *
	 * @param array $data Response data.
	 * @return bool
	 */
	private static function exposes_authoring_source( array $data ): bool {
		foreach ( array( 'content', 'excerpt' ) as $field ) {
			if (
				isset( $data[ $field ] )
				&& is_array( $data[ $field ] )
				&& ( array_key_exists( 'raw', $data[ $field ] ) || array_key_exists( 'block_version', $data[ $field ] ) )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a post's raw source says more than its rendered body does.
	 *
	 * True for a post with rules, because a visitor entitled to read it is
	 * not thereby entitled to its authoring source. True for a post carrying
	 * section rules too, even when the post itself is public: a block's rule is
	 * written into the block comment and its content stays in the raw body, and a
	 * page builder stores the rendered text of EVERY section there, restricted ones
	 * included, so the raw body of such a page is a copy of what the sections were
	 * hiding. Only a post that is plainly public and carries nothing keeps its raw
	 * body, since there is nothing in it the rendered page withholds.
	 *
	 * Blocks and Elementor are asked the same way, through `carries_fragment_rules()`.
	 *
	 * @param WP_Post $post     Post.
	 * @param array   $decision Result of `Agend_Content_Access_Decision::for_post()`.
	 * @return bool
	 */
	private static function source_reveals_more_than_render( $post, array $decision ): bool {
		if ( ! $decision['rules']->is_empty() ) {
			return true;
		}

		return self::carries_fragment_rules( $post );
	}
}

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
 */
class Agend_Content_Access_Frontend {

	/**
	 * Decision for the current singular request, resolved once.
	 *
	 * @var array|null
	 */
	private ?array $decision = null;

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'gate_request' ), 1 );

		// Public REST representations must not carry what the page will not
		// (US-3.4 criterion 8). Registered unconditionally: a REST request never
		// reaches template_redirect.
		add_action( 'rest_api_init', array( $this, 'register_rest_filters' ) );
	}

	/**
	 * Evaluates the current request and replaces the body when denied.
	 *
	 * Runs at priority 1 so the decision is made before themes, page builders
	 * and caching plugins get a chance to render or store anything.
	 */
	public function gate_request(): void {
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

		if ( ! empty( $decision['degraded'] ) ) {
			// An outage denied a visitor who may well be entitled. Record it as
			// an operational error, because a spike here is a broken gateway,
			// not a surge of lapsed memberships.
			do_action(
				'agend_content_access_degraded',
				(int) $post->ID,
				$decision['state']
			);
		}

		$this->decision = $decision;

		$teaser = self::teaser_for( $post );

		if ( '' === $teaser ) {
			$this->send_forbidden();
			return;
		}

		$this->replace_body_with_teaser();
	}

	/**
	 * Whether this request is somebody AUTHORING the page rather than reading it.
	 *
	 * Gating never applies here. An editor building a page must see every
	 * section, including ones they have just restricted, or they cannot lay out
	 * or edit the very content the policy protects. A page builder that hides
	 * blocks from their author is not a security feature, it is a broken editor.
	 *
	 * This is safe because an authoring context requires an edit capability that
	 * WordPress has already checked: Elementor refuses to open its editor or
	 * preview for a user without `edit_post` on the target. So this is not a
	 * second, weaker gate; it is a short-circuit in front of a check that would
	 * grant anyway via `can_preview()`. It exists as an explicit early return so
	 * the intent is legible and so no later filter can accidentally reach a
	 * builder request.
	 *
	 * Note the boundary: this covers the EDITOR and its preview iframe. A public
	 * pageload of an Elementor page is a normal frontend request and is gated
	 * like any other, which is what `suppress_builder_data()` exists for.
	 *
	 * @return bool
	 */
	public static function is_authoring_context(): bool {
		if ( is_admin() ) {
			return true;
		}

		if ( ! agend_content_access_has_elementor() ) {
			return false;
		}

		$plugin = \Elementor\Plugin::$instance;

		if ( isset( $plugin->editor ) && $plugin->editor->is_edit_mode() ) {
			return true;
		}

		// The preview iframe is a normal frontend pageload flagged with
		// `?elementor-preview=<id>`, so `is_edit_mode()` is false there even
		// though it is exactly where the author is looking. Elementor
		// capability-checks the id itself.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only mode check on an Elementor-set query var.
		$preview_id = isset( $_GET['elementor-preview'] )
			? absint( wp_unslash( $_GET['elementor-preview'] ) )
			: 0;

		return 0 !== $preview_id
			&& isset( $plugin->preview )
			&& $plugin->preview->is_preview_mode( $preview_id );
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
		 * Returning a non-empty string turns a 403 into a teaser page. Whatever
		 * is returned is public: it is shown to anonymous visitors and is
		 * indexable, so it must not contain protected material.
		 *
		 * @param string  $teaser Teaser text.
		 * @param WP_Post $post   Post being gated.
		 */
		return (string) apply_filters( 'agend_content_access_teaser', $teaser, $post );
	}

	/**
	 * Swaps the rendered body for the teaser and a portal call to action.
	 */
	private function replace_body_with_teaser(): void {
		add_filter( 'the_content', array( $this, 'filter_the_content' ), 1 );

		// Page builders render from their own stored data rather than
		// `the_content`, so the raw document has to be emptied too or an
		// Elementor layout would render the protected sections regardless.
		add_filter( 'get_post_metadata', array( $this, 'suppress_builder_data' ), 10, 4 );

		// A teaser page is a different page from the article. Telling search
		// engines it is the article would index the teaser under the real
		// content's terms.
		add_action( 'wp_head', array( $this, 'print_noindex' ), 1 );
	}

	/**
	 * Replaces the post body with the teaser and gate.
	 *
	 * @param string $content Original content.
	 * @return string
	 */
	public function filter_the_content( $content ): string {
		if ( ! in_the_loop() || ! is_main_query() || null === $this->decision ) {
			return $content;
		}

		$post = get_post();

		return self::render_gate(
			self::teaser_for( $post ),
			$this->decision['state']
		);
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
		if ( null === $this->decision ) {
			return $value;
		}

		$queried = get_queried_object_id();

		if ( (int) $object_id !== (int) $queried ) {
			return $value;
		}

		// Elementor stores its document here. Returning an empty array stops the
		// builder rendering the protected layout from its own copy.
		if ( '_elementor_data' === $meta_key ) {
			return $single ? array() : array( array() );
		}

		return $value;
	}

	/**
	 * Renders the gate markup.
	 *
	 * @param string $teaser Teaser text.
	 * @param string $state  Decision state.
	 * @return string
	 */
	public static function render_gate( string $teaser, string $state ): string {
		$portal = method_exists( 'Agend_Apps_Settings', 'get_portal_url' )
			? (string) Agend_Apps_Settings::get_portal_url()
			: '';

		switch ( $state ) {
			case Agend_Content_Access_Decision::STATE_MEMBERSHIP:
				$message = __( 'This content is for members. An active membership is required to read it.', 'agend-content-access' );
				$cta     = __( 'View membership options', 'agend-content-access' );
				break;
			case Agend_Content_Access_Decision::STATE_PLAN:
				// Deliberately vague: naming the qualifying plans would publish
				// the association's plan configuration to everybody who is not
				// on one.
				$message = __( 'This content is available to eligible members.', 'agend-content-access' );
				$cta     = __( 'View membership options', 'agend-content-access' );
				break;
			default:
				$message = __( 'This content is for members. Please sign in to read it.', 'agend-content-access' );
				$cta     = __( 'Sign in', 'agend-content-access' );
				break;
		}

		$html = '<div class="agend-access-gate">';

		if ( '' !== $teaser ) {
			$html .= '<div class="agend-access-teaser">' . wpautop( esc_html( $teaser ) ) . '</div>';
		}

		$html .= '<div class="agend-access-gate-prompt">';
		$html .= '<p>' . esc_html( $message ) . '</p>';

		if ( '' !== $portal ) {
			$return = home_url( add_query_arg( array() ) );
			$url    = add_query_arg( 'return_to', rawurlencode( $return ), $portal );

			$html .= '<p><a class="agend-access-gate-cta" href="' . esc_url( $url ) . '">'
				. esc_html( $cta ) . '</a></p>';
		}

		$html .= '</div></div>';

		return $html;
	}

	/**
	 * Sends a non-indexable 403 with no body.
	 */
	private function send_forbidden(): void {
		if ( ! headers_sent() ) {
			status_header( 403 );
			header( 'X-Robots-Tag: noindex, nofollow', true );
			nocache_headers();
		}

		wp_die(
			esc_html__( 'This content is for members.', 'agend-content-access' ),
			esc_html__( 'Members only', 'agend-content-access' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * Marks a teaser page non-indexable.
	 */
	public function print_noindex(): void {
		echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
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
	 * Removes the body from a REST item the requester cannot access.
	 *
	 * The public REST representation is a second copy of the same content, so a
	 * policy that governs only the rendered page leaves it readable through
	 * `/wp-json/wp/v2/posts`.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_Post          $post     Post.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_REST_Response
	 */
	public function filter_rest_response( $response, $post, $request ) {
		// The block editor loads a post for EDITING through this same REST
		// route, so stripping the body here would empty the editor. `edit` is
		// the context WordPress uses for authoring, and reaching it already
		// required an edit capability.
		if ( 'edit' === $request->get_param( 'context' ) ) {
			return $response;
		}

		$decision = Agend_Content_Access_Decision::for_post( (int) $post->ID );

		if ( Agend_Content_Access_Decision::STATE_GRANTED === $decision['state'] ) {
			return $response;
		}

		$data = $response->get_data();

		foreach ( array( 'content', 'excerpt' ) as $field ) {
			if ( isset( $data[ $field ] ) && is_array( $data[ $field ] ) ) {
				$data[ $field ]['rendered']  = '';
				$data[ $field ]['protected'] = true;
				unset( $data[ $field ]['raw'] );
			}
		}

		// Never expose the policy itself: its tier ids are the association's
		// plan configuration.
		unset( $data['meta'][ Agend_Content_Access_Policy::META_KEY ] );

		$data['agend_access'] = array( 'state' => $decision['state'] );

		$response->set_data( $data );

		return $response;
	}
}

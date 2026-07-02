<?php
/**
 * The `[agend_embed]` shortcode.
 *
 * Renders the Agend embed iframe together with the host-side SSO kick-off
 * listener. The embed and this listener speak a small postMessage protocol
 * (documented in the plugin README): the embed asks for SSO, the host acks
 * and navigates the iframe through the site's IdP-initiated SSO flow.
 *
 * @package Agend_Embed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the `[agend_embed]` shortcode.
 */
class Agend_Embed_Shortcode {

	/**
	 * Message type posted by the Agend embed when it needs an SSO kick-off.
	 */
	const KICKOFF_MESSAGE_TYPE = 'agend:embed:sso-initiate';

	/**
	 * Acknowledgement posted back to the embed before navigating, so it
	 * cancels its self-navigate fallback while the IdP round-trip runs.
	 */
	const KICKOFF_ACK_MESSAGE_TYPE = 'agend:embed:sso-initiate-ack';

	/**
	 * Registers the shortcode.
	 */
	public static function register() {
		add_shortcode( 'agend_embed', array( __CLASS__, 'render_shortcode' ) );
	}

	/**
	 * Renders the embed iframe plus the SSO kick-off listener.
	 *
	 * @param array $atts {
	 *     Shortcode attributes.
	 *
	 *     @type string $src         Required. Full https URL of the Agend embed surface.
	 *     @type string $sp          Optional. IdP-specific SP selector (see drivers).
	 *     @type string $kickoff_url Optional. Explicit kick-off URL template override.
	 *     @type string $height      Optional. Initial iframe height in px. Default 600.
	 *     @type string $title       Optional. Iframe title. Default 'Agend embed'.
	 * }
	 * @return string Shortcode output.
	 */
	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'src'         => '',
				'sp'          => '',
				'kickoff_url' => '',
				'height'      => '600',
				'title'       => 'Agend embed',
			),
			$atts,
			'agend_embed'
		);

		$src = esc_url_raw( $atts['src'] );

		if ( empty( $src ) || 0 !== strpos( $src, 'https://' ) ) {
			return '<!-- agend_embed: a https src attribute is required -->';
		}

		$iframe_id = 'agend-embed-' . substr( md5( $src ), 0, 8 );

		$output = sprintf(
			'<iframe id="%s" src="%s" title="%s" loading="lazy" style="width:100%%;min-height:%dpx;border:0"></iframe>',
			esc_attr( $iframe_id ),
			esc_url( $src ),
			esc_attr( $atts['title'] ),
			absint( $atts['height'] )
		);

		// The kick-off only helps members with a live WordPress session; for
		// anonymous visitors the embed's own fallback flow handles sign-in.
		if ( is_user_logged_in() ) {
			$kickoff_template = Agend_Embed_SSO_Drivers::resolve( $atts );

			if ( null !== $kickoff_template ) {
				$output .= self::render_kickoff_script( $iframe_id, $src, $kickoff_template );
			}
		}

		return $output;
	}

	/**
	 * Renders the one-shot listener that performs the SSO navigation.
	 *
	 * The listener only honours a message that (a) comes from this iframe's
	 * window, (b) originates from the embed's own origin, and (c) carries the
	 * expected type. The return URL is accepted only when its origin equals
	 * the embed origin, so the iframe cannot be steered elsewhere; the Agend
	 * ACS additionally validates the redirect against its origin allowlist.
	 *
	 * @param string $iframe_id        DOM id of the iframe.
	 * @param string $src              Embed URL (its origin gates the listener).
	 * @param string $kickoff_template Kick-off URL template with %RETURN_URL%.
	 * @return string Inline script tag.
	 */
	private static function render_kickoff_script( $iframe_id, $src, $kickoff_template ) {
		$config = wp_json_encode(
			array(
				'iframeId'        => $iframe_id,
				'embedOrigin'     => self::url_origin( $src ),
				'kickoffTemplate' => $kickoff_template,
				'placeholder'     => Agend_Embed_SSO_Drivers::RETURN_URL_PLACEHOLDER,
				'messageType'     => self::KICKOFF_MESSAGE_TYPE,
				'ackMessageType'  => self::KICKOFF_ACK_MESSAGE_TYPE,
			)
		);

		$script = <<<'JS'
(function () {
	var cfg = %CONFIG%;
	var done = false;
	window.addEventListener('message', function (event) {
		if (done || event.origin !== cfg.embedOrigin) {
			return;
		}
		var frame = document.getElementById(cfg.iframeId);
		if (!frame || event.source !== frame.contentWindow) {
			return;
		}
		var data = event.data;
		if (!data || data.type !== cfg.messageType || typeof data.returnUrl !== 'string') {
			return;
		}
		var returnUrl;
		try {
			returnUrl = new URL(data.returnUrl);
		} catch (e) {
			return;
		}
		if (returnUrl.origin !== cfg.embedOrigin) {
			return;
		}
		done = true;
		// Ack BEFORE navigating: the embed cancels its self-navigate fallback
		// on this, so a slow IdP round-trip is not aborted mid-flight.
		try {
			event.source.postMessage({ type: cfg.ackMessageType }, event.origin);
		} catch (e) {
			// A failed ack only means the embed may race its fallback.
		}
		frame.src = cfg.kickoffTemplate.replace(
			cfg.placeholder,
			encodeURIComponent(data.returnUrl)
		);
	});
})();
JS;

		$script = str_replace( '%CONFIG%', $config, $script );

		return '<script>' . $script . '</script>';
	}

	/**
	 * Extracts the origin (scheme://host[:port]) from a URL.
	 *
	 * @param string $url URL to reduce.
	 * @return string Origin string.
	 */
	private static function url_origin( $url ) {
		$parts  = wp_parse_url( $url );
		$origin = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' );

		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}

		return $origin;
	}
}

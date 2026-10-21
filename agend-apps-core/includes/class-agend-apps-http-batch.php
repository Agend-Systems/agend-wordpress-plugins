<?php
/**
 * Sends several HTTP requests at once.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends independent requests together through the Requests library bundled
 * with WordPress, each exactly as wp_remote_request() would send it alone.
 *
 * WordPress has no public parallel request API, so the adapter repeats what
 * WP_Http::request() does for each request (wp-includes/class-wp-http.php):
 * the same defaults, `http_request_args` and `pre_http_request` filters,
 * URL checks, blocked hosts, SSL and proxy options, `http_api_debug` and
 * `http_response`.
 *
 * Requests itself treats a batch differently from a single request, and the
 * adapter makes up the difference:
 *
 * - a batch dispatches `curl.before_multi_add` where a single request
 *   dispatches `curl.before_send`, and WordPress fires `http_api_curl` only
 *   from the latter (class-wp-http-requests-hooks.php), so the adapter fires
 *   it from the former;
 * - Requests::request() dispatches `requests.before_request` and
 *   `requests.before_parse`, and request_multiple() dispatches neither, so the
 *   adapter dispatches both for each request.
 *
 * Keep this in step with core when WP_Http::request() changes.
 *
 * When the `agend_apps_api_parallel` filter returns false, or the library is
 * missing, the same requests go one after another through wp_remote_request().
 */
final class Agend_Apps_Http_Batch {

	/**
	 * Sends the requests and returns each one's answer, as wp_remote_request()
	 * returns it, with the milliseconds from the start of the batch until it
	 * completed.
	 *
	 * @param array<string|int, array{url: string, args: array}> $requests Keyed by caller id.
	 * @return array<string|int, array{response: array|WP_Error, elapsed: int}> Keyed as given.
	 */
	public static function send( array $requests ): array {
		$library = self::library();

		/**
		 * Filters whether the API client sends independent gateway requests at
		 * once. Return false to send them one after another.
		 *
		 * @param bool $parallel Default true.
		 */
		if ( count( $requests ) < 2 || null === $library || ! apply_filters( 'agend_apps_api_parallel', true ) ) {
			return self::send_in_turn( $requests );
		}

		return self::send_together( $requests, $library );
	}

	/**
	 * @param array<string|int, array{url: string, args: array}> $requests Keyed by caller id.
	 * @return array<string|int, array{response: array|WP_Error, elapsed: int}>
	 */
	private static function send_in_turn( array $requests ): array {
		$sent = array();

		foreach ( $requests as $id => $request ) {
			$started     = microtime( true );
			$response    = wp_remote_request( $request['url'], $request['args'] );
			$sent[ $id ] = array(
				'response' => $response,
				'elapsed'  => self::since( $started ),
			);
		}

		return $sent;
	}

	/**
	 * The Requests class names of the bundled library: version 2 from
	 * WordPress 6.2, version 1 before it. Null without either.
	 *
	 * @return array{requests: string, proxy: string, debug: string}|null
	 */
	private static function library(): ?array {
		if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
			return array(
				'requests'  => '\WpOrg\Requests\Requests',
				'proxy'     => '\WpOrg\Requests\Proxy\Http',
				'debug'     => 'WpOrg\Requests\Requests',
			);
		}

		if ( class_exists( 'Requests' ) && method_exists( 'Requests', 'request_multiple' ) ) {
			return array(
				'requests'  => 'Requests',
				'proxy'     => 'Requests_Proxy_HTTP',
				'debug'     => 'Requests',
			);
		}

		return null;
	}

	/**
	 * @param array<string|int, array{url: string, args: array}> $requests Keyed by caller id.
	 * @param array                                               $library See library().
	 * @return array<string|int, array{response: array|WP_Error, elapsed: int}>
	 */
	private static function send_together( array $requests, array $library ): array {
		$started   = microtime( true );
		$sent      = array();
		$multi     = array();
		$contexts  = array();
		$completed = array();

		foreach ( $requests as $id => $request ) {
			$prepared = self::prepare( (string) $request['url'], (array) $request['args'], $library );

			if ( isset( $prepared['response'] ) ) {
				$sent[ $id ] = array(
					'response' => $prepared['response'],
					'elapsed'  => self::since( $started ),
				);
				continue;
			}

			$multi[ $id ]    = $prepared['request'];
			$contexts[ $id ] = $prepared;
		}

		if ( empty( $multi ) ) {
			return self::in_order( $requests, $sent );
		}

		$options = array(
			'complete' => static function ( &$response, $id ) use ( &$completed, $started ) {
				$completed[ $id ] = self::since( $started );
			},
		);

		/**
		 * Filters the Requests transport a batch is sent with. Null uses the
		 * library's own choice. For tests.
		 *
		 * @param object|string|null $transport Default null.
		 */
		$transport = apply_filters( 'agend_apps_api_batch_transport', null );
		if ( null !== $transport ) {
			$options['transport'] = $transport;
		}

		foreach ( $multi as $id => &$request ) {
			$request['options']['hooks']->dispatch( 'requests.before_request', array( &$request['url'], &$request['headers'], &$request['data'], &$request['type'], &$request['options'] ) );
		}
		unset( $request );

		$requests_class = $library['requests'];

		// Avoid issues where mbstring.func_overload is enabled.
		mbstring_binary_safe_encoding();

		try {
			$responses = $requests_class::request_multiple( $multi, $options );
		} catch ( \Throwable $e ) {
			$responses = array_fill_keys( array_keys( $multi ), $e );
		}

		reset_mbstring_encoding();

		foreach ( $contexts as $id => $context ) {
			$sent[ $id ] = array(
				'response' => self::finish( $responses[ $id ] ?? null, $context, $library ),
				'elapsed'  => $completed[ $id ] ?? self::since( $started ),
			);
		}

		return self::in_order( $requests, $sent );
	}

	/**
	 * WP_Http::request() up to the point of sending: either the request for
	 * Requests::request_multiple() with what finish() needs, or the response
	 * when WordPress would not send it.
	 *
	 * @param string $url     Request URL.
	 * @param array  $args    wp_remote_request() args.
	 * @param array  $library See library().
	 * @return array{response: array|WP_Error}|array{request: array, url: string, parsed_args: array}
	 */
	private static function prepare( string $url, array $args, array $library ): array {
		$defaults = array(
			'method'              => 'GET',
			/** This filter is documented in wp-includes/class-wp-http.php */
			'timeout'             => apply_filters( 'http_request_timeout', 5, $url ),
			/** This filter is documented in wp-includes/class-wp-http.php */
			'redirection'         => apply_filters( 'http_request_redirection_count', 5, $url ),
			/** This filter is documented in wp-includes/class-wp-http.php */
			'httpversion'         => apply_filters( 'http_request_version', '1.0', $url ),
			/** This filter is documented in wp-includes/class-wp-http.php */
			'user-agent'          => apply_filters( 'http_headers_useragent', 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ), $url ),
			/** This filter is documented in wp-includes/class-wp-http.php */
			'reject_unsafe_urls'  => apply_filters( 'http_request_reject_unsafe_urls', false, $url ),
			'blocking'            => true,
			'headers'             => array(),
			'cookies'             => array(),
			'body'                => null,
			'compress'            => false,
			'decompress'          => true,
			'sslverify'           => true,
			'sslcertificates'     => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
			'stream'              => false,
			'filename'            => null,
			'limit_response_size' => null,
		);

		$args = wp_parse_args( $args );

		if ( isset( $args['method'] ) && 'HEAD' === $args['method'] ) {
			$defaults['redirection'] = 0;
		}

		$parsed_args = wp_parse_args( $args, $defaults );
		/** This filter is documented in wp-includes/class-wp-http.php */
		$parsed_args = apply_filters( 'http_request_args', $parsed_args, $url );

		if ( ! isset( $parsed_args['_redirection'] ) ) {
			$parsed_args['_redirection'] = $parsed_args['redirection'];
		}

		/** This filter is documented in wp-includes/class-wp-http.php */
		$pre = apply_filters( 'pre_http_request', false, $parsed_args, $url );

		if ( false !== $pre ) {
			return array( 'response' => $pre );
		}

		if ( function_exists( 'wp_kses_bad_protocol' ) ) {
			if ( $parsed_args['reject_unsafe_urls'] ) {
				$url = wp_http_validate_url( $url );
			}
			if ( $url ) {
				$url = wp_kses_bad_protocol( $url, array( 'http', 'https', 'ssl' ) );
			}
		}

		$parsed_url = wp_parse_url( (string) $url );

		if ( empty( $url ) || empty( $parsed_url['scheme'] ) ) {
			return array( 'response' => self::refused( new WP_Error( 'http_request_failed', __( 'A valid URL was not provided.' ) ), $parsed_args, (string) $url, $library ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own message.
		}

		if ( _wp_http_get_object()->block_request( $url ) ) {
			/* translators: %s: URL to which the HTTP request was blocked. */
			return array( 'response' => self::refused( new WP_Error( 'http_request_not_executed', sprintf( __( 'User has blocked requests through HTTP to the URL: %s.' ), $url ) ), $parsed_args, $url, $library ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own message.
		}

		if ( $parsed_args['stream'] ) {
			if ( empty( $parsed_args['filename'] ) ) {
				$parsed_args['filename'] = get_temp_dir() . basename( $url );
			}

			$parsed_args['blocking'] = true;
			if ( ! wp_is_writable( dirname( $parsed_args['filename'] ) ) ) {
				return array( 'response' => self::refused( new WP_Error( 'http_request_failed', __( 'Destination directory for file streaming does not exist or is not writable.' ) ), $parsed_args, $url, $library ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own message.
			}
		}

		if ( is_null( $parsed_args['headers'] ) ) {
			$parsed_args['headers'] = array();
		}

		if ( ! is_array( $parsed_args['headers'] ) ) {
			$processed_headers      = WP_Http::processHeaders( $parsed_args['headers'] );
			$parsed_args['headers'] = $processed_headers['headers'];
		}

		$type    = $parsed_args['method'];
		$options = array(
			'timeout'   => $parsed_args['timeout'],
			'useragent' => $parsed_args['user-agent'],
			'blocking'  => $parsed_args['blocking'],
			'hooks'     => new WP_HTTP_Requests_Hooks( $url, $parsed_args ),
		);

		$options['hooks']->register( 'requests.before_redirect', array( 'WP_Http', 'browser_redirect_compatibility' ) );

		if ( function_exists( 'wp_kses_bad_protocol' ) && $parsed_args['reject_unsafe_urls'] ) {
			$options['hooks']->register( 'requests.before_redirect', array( 'WP_Http', 'validate_redirects' ) );
		}

		// What WP_HTTP_Requests_Hooks does for `curl.before_send`.
		$options['hooks']->register(
			'curl.before_multi_add',
			static function ( &$handle ) use ( $parsed_args, $url ) {
				/** This action is documented in wp-includes/class-wp-http-curl.php */
				do_action_ref_array( 'http_api_curl', array( &$handle, $parsed_args, $url ) );
			}
		);

		// Ahead of the library's own parse_multiple() at priority 0, where
		// Requests::request() dispatches it for a single request.
		$options['hooks']->register(
			'transport.internal.parse_response',
			static function ( &$response, $request ) {
				$request['options']['hooks']->dispatch( 'requests.before_parse', array( &$response, $request['url'], $request['headers'], $request['data'], $request['type'], $request['options'] ) );
			},
			-1
		);

		if ( $parsed_args['stream'] ) {
			$options['filename'] = $parsed_args['filename'];
		}
		if ( empty( $parsed_args['redirection'] ) ) {
			$options['follow_redirects'] = false;
		} else {
			$options['redirects'] = $parsed_args['redirection'];
		}

		if ( isset( $parsed_args['limit_response_size'] ) ) {
			$options['max_bytes'] = $parsed_args['limit_response_size'];
		}

		if ( ! empty( $parsed_args['cookies'] ) ) {
			$options['cookies'] = WP_Http::normalize_cookies( $parsed_args['cookies'] );
		}

		if ( ! $parsed_args['sslverify'] ) {
			$options['verify']     = false;
			$options['verifyname'] = false;
		} else {
			$options['verify'] = $parsed_args['sslcertificates'];
		}

		if ( 'HEAD' !== $type && 'GET' !== $type ) {
			$options['data_format'] = 'body';
		}

		/** This filter is documented in wp-includes/class-wp-http.php */
		$options['verify'] = apply_filters( 'https_ssl_verify', $options['verify'], $url );

		$proxy = new WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			$proxy_class      = $library['proxy'];
			$options['proxy'] = new $proxy_class( $proxy->host() . ':' . $proxy->port() );

			if ( $proxy->use_authentication() ) {
				$options['proxy']->use_authentication = true;
				$options['proxy']->user               = $proxy->username();
				$options['proxy']->pass               = $proxy->password();
			}
		}

		return array(
			'request'     => array(
				'url'     => $url,
				'headers' => $parsed_args['headers'],
				'data'    => $parsed_args['body'],
				'type'    => $type,
				'options' => $options,
			),
			'url'         => $url,
			'parsed_args' => $parsed_args,
		);
	}

	/**
	 * WP_Http::request() after Requests has answered.
	 *
	 * @param mixed $answer  A Requests response, or the exception the request failed with.
	 * @param array $context What prepare() returned.
	 * @param array $library See library().
	 * @return array|WP_Error
	 */
	private static function finish( $answer, array $context, array $library ) {
		$parsed_args = $context['parsed_args'];
		$url         = $context['url'];

		if ( $answer instanceof \Throwable ) {
			$response = new WP_Error( 'http_request_failed', $answer->getMessage() );
		} elseif ( is_object( $answer ) ) {
			$http_response             = new WP_HTTP_Requests_Response( $answer, (string) $parsed_args['filename'] );
			$response                  = $http_response->to_array();
			$response['http_response'] = $http_response;
		} else {
			$response = new WP_Error( 'http_request_failed', __( 'The request received no response.', 'agend-apps-core' ) );
		}

		/** This action is documented in wp-includes/class-wp-http.php */
		do_action( 'http_api_debug', $response, 'response', $library['debug'], $parsed_args, $url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! $parsed_args['blocking'] ) {
			return array(
				'headers'       => array(),
				'body'          => '',
				'response'      => array(
					'code'    => false,
					'message' => false,
				),
				'cookies'       => array(),
				'http_response' => null,
			);
		}

		/** This filter is documented in wp-includes/class-wp-http.php */
		return apply_filters( 'http_response', $response, $parsed_args, $url );
	}

	/**
	 * A request WordPress refuses before sending, announced as core does.
	 *
	 * @param WP_Error $error       The refusal.
	 * @param array    $parsed_args HTTP request arguments.
	 * @param string   $url         The request URL.
	 * @param array    $library     See library().
	 * @return WP_Error
	 */
	private static function refused( WP_Error $error, array $parsed_args, string $url, array $library ): WP_Error {
		/** This action is documented in wp-includes/class-wp-http.php */
		do_action( 'http_api_debug', $error, 'response', $library['debug'], $parsed_args, $url );
		return $error;
	}

	/**
	 * @param array $requests The requests, in the caller's order.
	 * @param array $sent     Their answers, in any order.
	 * @return array
	 */
	private static function in_order( array $requests, array $sent ): array {
		$ordered = array();
		foreach ( array_keys( $requests ) as $id ) {
			$ordered[ $id ] = $sent[ $id ];
		}
		return $ordered;
	}

	private static function since( float $started ): int {
		return (int) round( ( microtime( true ) - $started ) * 1000 );
	}
}

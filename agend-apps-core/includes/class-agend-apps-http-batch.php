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
 * adapter makes up the difference for each request:
 *
 * - Curl::request() dispatches `curl.before_send`, where the proxy, basic
 *   authentication and WordPress's `http_api_curl` attach, and then applies
 *   the SSL options; a batch dispatches only `curl.before_multi_add`, so the
 *   adapter dispatches `curl.before_send` and applies the SSL options there;
 * - Requests::request() dispatches `requests.before_request`, whose first
 *   listener is the cookie jar, and `requests.before_parse`;
 *   request_multiple() dispatches neither, so the adapter runs the jar and
 *   dispatches both;
 * - Requests::request() picks a transport that can do SSL and refuses a
 *   scheme other than HTTP(S); request_multiple() does neither, so a batch
 *   is sent through cURL only where cURL can do SSL, and another scheme is
 *   answered with core's refusal before sending;
 * - a cURL failure is worded "cURL error <code>: <reason>", as alone.
 *
 * What still differs: `requests.before_request` runs before the library
 *   applies its defaults rather than after; every request's filters run
 *   before any is sent, and every answer's actions after all have finished; a
 *   redirect or a slow listener inside the batch holds up the others while
 *   their timeouts run; the single path's retry without content encoding on
 *   a bad compressed body does not happen; and an exception a listener throws
 *   fails every request in the batch.
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

		/**
		 * Filters the Requests transport a batch is sent with. Null sends it
		 * through cURL. For tests.
		 *
		 * @param object|string|null $transport Default null.
		 */
		$transport = apply_filters( 'agend_apps_api_batch_transport', null );

		// A single request picks a transport that can do SSL; request_multiple()
		// does not ask, so the batch uses cURL only where cURL can.
		if ( null === $transport ) {
			$curl = $library['curl'];
			if ( ! $curl::test( array( 'ssl' => true ) ) ) {
				return self::send_in_turn( $requests );
			}
			$transport = $curl;
		}

		return self::send_together( $requests, $library, $transport );
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
	 * @return array{requests: string, proxy: string, debug: string, exception: string, curl_exception: string, curl: string, jar: string}|null
	 */
	private static function library(): ?array {
		if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
			return array(
				'requests'       => '\WpOrg\Requests\Requests',
				'proxy'          => '\WpOrg\Requests\Proxy\Http',
				'debug'          => 'WpOrg\Requests\Requests',
				'exception'      => '\WpOrg\Requests\Exception',
				'curl_exception' => '\WpOrg\Requests\Exception\Transport\Curl',
				'curl'           => '\WpOrg\Requests\Transport\Curl',
				'jar'            => '\WpOrg\Requests\Cookie\Jar',
			);
		}

		if ( class_exists( 'Requests' ) && method_exists( 'Requests', 'request_multiple' ) ) {
			return array(
				'requests'       => 'Requests',
				'proxy'          => 'Requests_Proxy_HTTP',
				'debug'          => 'Requests',
				'exception'      => 'Requests_Exception',
				'curl_exception' => 'Requests_Exception_Transport_cURL',
				'curl'           => 'Requests_Transport_cURL',
				'jar'            => 'Requests_Cookie_Jar',
			);
		}

		return null;
	}

	/**
	 * @param array<string|int, array{url: string, args: array}> $requests Keyed by caller id.
	 * @param array                                               $library   See library().
	 * @param object|string                                       $transport The Requests transport.
	 * @return array<string|int, array{response: array|WP_Error, elapsed: int}>
	 */
	private static function send_together( array $requests, array $library, $transport ): array {
		$started   = microtime( true );
		$sent      = array();
		$multi     = array();
		$contexts  = array();
		$completed = array();

		foreach ( $requests as $id => $request ) {
			$prepared = self::prepare( (string) $request['url'], (array) $request['args'], $library );

			// A pre_http_request answer can be null, which core returns as is.
			if ( array_key_exists( 'response', $prepared ) ) {
				$sent[ $id ] = array(
					'response' => $prepared['response'],
					'elapsed'  => self::since( $started ),
				);
				continue;
			}

			$multi[ $id ]    = $prepared['request'];
			$contexts[ $id ] = $prepared;
		}

		// Requests::request() dispatches `requests.before_request` after its
		// own defaults, where the cookie jar adds the Cookie header as the
		// hook's first listener. request_multiple() dispatches neither, so the
		// jar runs first here and the hook follows, before those defaults.
		foreach ( $multi as $id => &$request ) {
			try {
				if ( isset( $request['options']['cookies'] ) && $request['options']['cookies'] instanceof $library['jar'] ) {
					$request['options']['cookies']->before_request( $request['url'], $request['headers'], $request['data'], $request['type'], $request['options'] );
				}
				$request['options']['hooks']->dispatch( 'requests.before_request', array( &$request['url'], &$request['headers'], &$request['data'], &$request['type'], &$request['options'] ) );
			} catch ( \Exception $e ) {
				if ( ! $e instanceof $library['exception'] ) {
					throw $e;
				}
				$sent[ $id ] = array(
					'response' => self::finish( $e, $contexts[ $id ], $library ),
					'elapsed'  => self::since( $started ),
				);
				unset( $multi[ $id ], $contexts[ $id ] );
			}
		}
		unset( $request );

		if ( empty( $multi ) ) {
			return self::in_order( $requests, $sent );
		}

		$options = array(
			'transport' => $transport,
			'complete'  => static function ( &$response, $id ) use ( &$completed, $started ) {
				$completed[ $id ] = self::since( $started );
			},
		);

		$requests_class = $library['requests'];

		// Avoid issues where mbstring.func_overload is enabled.
		mbstring_binary_safe_encoding();

		try {
			$responses = $requests_class::request_multiple( $multi, $options );
		} catch ( \Exception $e ) {
			if ( ! $e instanceof $library['exception'] ) {
				reset_mbstring_encoding();
				throw $e;
			}
			// Each request's own failures are answered per request; one that
			// escapes request_multiple() has no request to belong to.
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

		// Requests::request() refuses any other scheme for a single request,
		// and WP_Http::request() answers with its message. In a batch the
		// refusal would fail every request, so it is answered here.
		if ( 1 !== preg_match( '/^http(s)?:\/\//i', (string) $url ) ) {
			return array( 'response' => self::refused( new WP_Error( 'http_request_failed', 'Only HTTP(S) requests are handled.' ), $parsed_args, $url, $library ) );
		}

		$options['type'] = $type;
		$hooks           = $options['hooks'];
		$verify          = $options['verify'];
		$verifyname      = $options['verifyname'] ?? null;

		// Curl::request() dispatches `curl.before_send`, where the proxy and
		// WP_HTTP_Requests_Hooks (`http_api_curl`) attach, then applies the SSL
		// options; Curl::request_multiple() does neither, so both happen here.
		$hooks->register(
			'curl.before_multi_add',
			static function ( &$handle ) use ( $hooks, $verify, $verifyname ) {
				$hooks->dispatch( 'curl.before_send', array( &$handle ) );

				if ( ! is_resource( $handle ) && ! $handle instanceof \CurlHandle ) {
					return;
				}
				if ( false === $verify ) {
					curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, 0 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
					curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, 0 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
				} elseif ( is_string( $verify ) ) {
					curl_setopt( $handle, CURLOPT_CAINFO, $verify ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
				}
				if ( false === $verifyname ) {
					curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, 0 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
				}
			}
		);

		// Requests::request() dispatches `requests.before_parse` before it
		// parses; request_multiple() does not. Priority -1 runs it ahead of the
		// library's own parse_multiple() at 0: Requests 2 sorts by priority,
		// and Requests 1, which does not, runs it first because it is
		// registered before request_multiple() registers parse_multiple().
		$hooks->register(
			'transport.internal.parse_response',
			static function ( &$response, $request ) {
				$request['options']['hooks']->dispatch( 'requests.before_parse', array( &$response, $request['url'], $request['headers'], $request['data'], $request['type'], $request['options'] ) );
			},
			-1
		);

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

		if ( $answer instanceof $library['curl_exception'] ) {
			// Curl::request() words a cURL failure this way; the exception a
			// batch receives words it as "<code> <reason>".
			$response = new WP_Error( 'http_request_failed', sprintf( 'cURL error %s: %s', $answer->getCode(), $answer->getReason() ) );
		} elseif ( $answer instanceof \Throwable ) {
			$response = new WP_Error( 'http_request_failed', $answer->getMessage() );
		} elseif ( is_object( $answer ) ) {
			$http_response             = new WP_HTTP_Requests_Response( $answer, $parsed_args['filename'] );
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

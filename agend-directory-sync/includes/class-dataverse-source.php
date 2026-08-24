<?php
/**
 * Microsoft Dataverse (Dynamics 365) source: a FetchXML-paged Web API client.
 *
 * A dedicated source rather than a mode of the Custom HTTP API source, because
 * Dataverse pages differently from every REST API that source models. Dataverse
 * has no page/offset query parameters: the page selection lives INSIDE the
 * query document, as `page`, `count` and `paging-cookie` attributes on the
 * FetchXML `<fetch>` element. Following `@odata.nextLink` is the alternative,
 * and it is opaque — the caller cannot say "give me page 4", cannot re-request
 * a page after a failure, and cannot cap the page size independently of what
 * the server decided. FetchXML paging is explicit on all three counts, which is
 * why this source exists.
 *
 * The operator supplies the FetchXML; this source owns the paging attributes on
 * its root element and overwrites whatever the template carried, so a run's
 * page window is a plugin setting and never a hand-edit of the query. Column
 * selection is therefore the operator's, expressed the FetchXML way — an
 * `<attribute name="..."/>` per field — which is exactly the point of choosing
 * this interface over `$select`: link-entity columns, filters and orders come
 * along in the same document.
 *
 * Auth is OAuth 2.0 client credentials against Microsoft Entra ID, delegated to
 * Agend_Directory_Sync_Oauth_Token_Manager with this source's own secret-store
 * key, so a Dataverse client secret and a Custom-HTTP-API client secret are
 * never the same stored value. Everything else about the connection —
 * environment URL, entity set, page size, page window, headers, variables — is
 * configuration under a single option, sanitised as a unit on save.
 *
 * @package Agend_Directory_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Directory_Sync_Dataverse_Source' ) ) :
	final class Agend_Directory_Sync_Dataverse_Source implements Agend_Directory_Sync_Source {

		public const SOURCE_KEY = 'dataverse';

		/**
		 * Dataverse returns FetchXML results in the OData `value` array. This
		 * is a property of the Web API, not of the operator's configuration, so
		 * unlike the Custom HTTP API source there is no data-path setting to
		 * get wrong.
		 */
		public const DATA_PATH = 'value';

		/**
		 * Response annotations that carry the paging state. Present only when
		 * the request asked for annotations to be included, which this source
		 * always does for these two by name.
		 */
		public const ANNOTATION_MORE_RECORDS = '@Microsoft.Dynamics.CRM.morerecords';
		public const ANNOTATION_PAGING_COOKIE = '@Microsoft.Dynamics.CRM.fetchxmlpagingcookie';

		public const DEFAULT_API_VERSION = '9.2';

		/**
		 * Microsoft Entra ID v2 token endpoint. `{tenant}` is substituted with
		 * the configured tenant id (or `organizations` / a domain, both of
		 * which Entra accepts in the same position).
		 */
		public const TOKEN_URL_TEMPLATE = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

		/**
		 * Dataverse issues tokens per environment, so the scope is the
		 * environment URL plus `/.default`. Derived from the environment URL
		 * unless the operator overrides it.
		 */
		public const SCOPE_SUFFIX = '/.default';

		public const DEFAULT_TIMEOUT = 60;
		public const MIN_TIMEOUT     = 5;
		public const MAX_TIMEOUT     = 300;

		/**
		 * `count` on the `<fetch>` element. Dataverse rejects a page size above
		 * 5000, so that is the ceiling rather than a plugin preference.
		 */
		public const DEFAULT_PAGE_SIZE = 500;
		public const MIN_PAGE_SIZE     = 1;
		public const MAX_PAGE_SIZE     = 5000;

		public const DEFAULT_START_PAGE = 1;

		/**
		 * Hard safety cap on pages fetched in one run, mirroring the Custom
		 * HTTP API source: guards against an environment that keeps reporting
		 * `morerecords` forever. Hitting it throws rather than returning a
		 * truncated set, because a silently short sync marks every unfetched
		 * member as absent downstream.
		 */
		public const MAX_PAGES = 500;

		public const RESPONSE_EXCERPT_LENGTH = 500;

		public const PREVIEW_RECORD_LIMIT = 5;

		/**
		 * Rows resolved from `value` that were not JSON objects, accumulated
		 * across the most recent fetch. The runner reads this (duck-typed) and
		 * folds it into the run summary as the `row_not_an_object` skip reason.
		 */
		private int $skipped_non_associative_count = 0;

		/**
		 * Pages actually fetched, and whether the run stopped because it hit
		 * the configured page window rather than because Dataverse said there
		 * was nothing left. Read by the admin preview and the run summary so a
		 * deliberately partial fetch is never mistaken for a complete one.
		 */
		private int $pages_fetched = 0;
		private bool $stopped_at_page_limit = false;

		public function get_key(): string {
			return self::SOURCE_KEY;
		}

		public function get_label(): string {
			return __( 'Microsoft Dataverse (FetchXML)', 'agend-directory-sync' );
		}

		public function is_available(): bool {
			return '' === $this->get_unavailable_reason();
		}

		/**
		 * Unavailable when any of the four things a request cannot be built
		 * without is missing, or when no client secret is stored. Never
		 * performs a network request.
		 */
		public function get_unavailable_reason(): string {
			$settings = self::resolve_settings();

			if ( '' === $settings['environment_url'] ) {
				return __( 'No Dataverse environment URL is configured.', 'agend-directory-sync' );
			}

			if ( '' === $settings['entity_set'] ) {
				return __( 'No Dataverse entity set name is configured (for example "contacts").', 'agend-directory-sync' );
			}

			if ( '' === $settings['fetch_xml'] ) {
				return __( 'No FetchXML query is configured.', 'agend-directory-sync' );
			}

			if ( '' === $settings['tenant_id'] && '' === $settings['token_url'] ) {
				return __( 'No Entra ID tenant is configured, and no token endpoint override is set.', 'agend-directory-sync' );
			}

			if ( '' === $settings['client_id'] ) {
				return __( 'No application (client) ID is configured.', 'agend-directory-sync' );
			}

			if ( '' === Agend_Directory_Sync_Secret_Store::source_of(
				Agend_Directory_Sync_Secret_Store::KEY_DATAVERSE_CLIENT_SECRET,
				'AGEND_DIRECTORY_SYNC_DATAVERSE_CLIENT_SECRET'
			) ) {
				return __( 'No Dataverse client secret is set. Enter it under the Dataverse connection settings (stored encrypted), or define AGEND_DIRECTORY_SYNC_DATAVERSE_CLIENT_SECRET in wp-config.php.', 'agend-directory-sync' );
			}

			return '';
		}

		public function get_skipped_non_associative_count(): int {
			return $this->skipped_non_associative_count;
		}

		public function get_pages_fetched(): int {
			return $this->pages_fetched;
		}

		public function stopped_at_page_limit(): bool {
			return $this->stopped_at_page_limit;
		}

		/**
		 * Page through the configured FetchXML query and aggregate the rows in
		 * fetch order.
		 *
		 * Each iteration rewrites the paging attributes on the query's root
		 * element and re-sends the whole document, so the page being requested
		 * is always stated explicitly rather than inherited from a server-side
		 * cursor. Where Dataverse hands back a paging cookie the next request
		 * carries it, which is what keeps deep pages cheap; without one the
		 * `page` attribute alone still selects the page, just at the cost of
		 * the server re-walking the earlier rows.
		 *
		 * @return array<int, array<string, mixed>>
		 *
		 * @throws RuntimeException When the source is unavailable, a request
		 *                          fails, the response is not the expected
		 *                          envelope, or the hard page cap is hit.
		 */
		public function fetch_all(): array {
			if ( ! $this->is_available() ) {
				throw new RuntimeException( $this->get_unavailable_reason() );
			}

			$settings = $this->runtime_settings();

			$this->skipped_non_associative_count = 0;
			$this->pages_fetched                 = 0;
			$this->stopped_at_page_limit         = false;

			$records = array();
			$page    = $settings['start_page'];
			$cookie  = '';

			while ( true ) {
				if ( $this->pages_fetched >= self::MAX_PAGES ) {
					throw new RuntimeException(
						sprintf(
							/* translators: %d: the pagination hard cap (pages). */
							__( 'Dataverse paging reached the %d page safety cap without completing; aborting instead of returning a truncated set.', 'agend-directory-sync' ),
							self::MAX_PAGES
						)
					);
				}

				$fetch_xml = self::build_page_fetch_xml( $settings['fetch_xml'], $page, $settings['page_size'], $cookie );
				$response  = $this->request_page( $settings, $fetch_xml );
				$rows      = $this->resolve_records( $response['decoded'] );

				$records = array_merge( $records, $rows );
				$this->pages_fetched++;

				// A page window is an operator instruction, not a failure: stop
				// quietly, but record that the set is partial so the summary can
				// say so.
				if ( $settings['max_pages'] > 0 && $this->pages_fetched >= $settings['max_pages'] ) {
					$this->stopped_at_page_limit = self::has_more_records( $response['decoded'], count( $rows ), $settings['page_size'] );
					break;
				}

				if ( ! self::has_more_records( $response['decoded'], count( $rows ), $settings['page_size'] ) ) {
					break;
				}

				$cookie = $settings['use_paging_cookie']
					? self::extract_paging_cookie( $response['decoded'] )
					: '';

				$page++;
			}

			return $records;
		}

		/**
		 * Fetch the configured start page only and report the query that was
		 * sent alongside what came back, for the admin preview. Mirrors the
		 * Custom HTTP API source's preview contract (the `data_path` key is
		 * what routes the rendering), with the FetchXML and paging state added.
		 *
		 * Deliberately reports rather than throws when the envelope has no
		 * `value` array: seeing the raw response is the whole reason an
		 * operator presses this button.
		 *
		 * @return array<string, mixed>
		 *
		 * @throws RuntimeException When the source is unavailable, the FetchXML
		 *                          will not parse, or the request itself fails.
		 */
		public function preview_first_page(): array {
			if ( ! $this->is_available() ) {
				throw new RuntimeException( $this->get_unavailable_reason() );
			}

			$settings = $this->runtime_settings();

			$this->skipped_non_associative_count = 0;

			$fetch_xml = self::build_page_fetch_xml(
				$settings['fetch_xml'],
				$settings['start_page'],
				$settings['page_size'],
				''
			);

			$response = $this->request_page( $settings, $fetch_xml );
			$decoded  = $response['decoded'];

			$result = array(
				'http_status'   => $response['status'],
				'decoded'       => $decoded,
				'data_path'     => self::DATA_PATH,
				'fetch_xml'     => $fetch_xml,
				'page'          => $settings['start_page'],
				'page_size'     => $settings['page_size'],
				'more_records'  => self::has_more_records( $decoded, is_array( $decoded[ self::DATA_PATH ] ?? null ) ? count( $decoded[ self::DATA_PATH ] ) : 0, $settings['page_size'] ),
				'paging_cookie' => '' !== self::extract_paging_cookie( $decoded ),
			);

			if ( ! is_array( $decoded[ self::DATA_PATH ] ?? null ) ) {
				$result['path_resolved']  = false;
				$result['failed_at']      = self::DATA_PATH;
				$result['available_keys'] = array_map( 'strval', array_keys( $decoded ) );
				return $result;
			}

			$records = $this->resolve_records( $decoded );

			$result['path_resolved']           = true;
			$result['resolved_count']          = count( $records );
			$result['resolved_records']        = array_slice( $records, 0, self::PREVIEW_RECORD_LIMIT );
			$result['skipped_non_associative'] = $this->skipped_non_associative_count;

			return $result;
		}

		/**
		 * Same external_metadata shape the Custom HTTP API source produces, so
		 * a directory that moves between the two keeps one metadata contract
		 * rather than gaining a Dataverse-specific one.
		 *
		 * @param array<string, mixed>  $contact
		 * @param array<string, string> $core_map
		 *
		 * @return array<string, mixed>
		 */
		public function get_external_metadata( array $contact, array $core_map ): array {
			$metadata = array(
				'source_unique_id' => Agend_Directory_Sync_Listing_Transformer::resolve_source_field( $contact, $core_map, 'external_id' ),
			);

			if ( '' !== (string) ( $core_map['date_modified'] ?? '' ) ) {
				$metadata['source_date_modified'] = Agend_Directory_Sync_Listing_Transformer::resolve_source_field( $contact, $core_map, 'date_modified' );
			}

			$metadata['synced_at'] = gmdate( 'c' );

			return $metadata;
		}

		/**
		 * The saved option merged over defaults, with every numeric setting
		 * clamped defensively (the sanitiser already clamps on save; this
		 * guards a value written by any other path).
		 *
		 * @return array<string, mixed>
		 */
		public static function resolve_settings(): array {
			$saved = get_option( Agend_Directory_Sync::OPTION_DATAVERSE, array() );
			$saved = is_array( $saved ) ? $saved : array();

			return array(
				'environment_url'     => (string) ( $saved['environment_url'] ?? '' ),
				'api_version'         => Agend_Directory_Sync_Config::non_blank( $saved['api_version'] ?? '', self::DEFAULT_API_VERSION ),
				'entity_set'          => self::sanitize_entity_set( (string) ( $saved['entity_set'] ?? '' ) ),
				'fetch_xml'           => trim( (string) ( $saved['fetch_xml'] ?? '' ) ),
				'tenant_id'           => (string) ( $saved['tenant_id'] ?? '' ),
				'token_url'           => (string) ( $saved['token_url'] ?? '' ),
				'client_id'           => (string) ( $saved['client_id'] ?? '' ),
				'scope'               => trim( (string) ( $saved['scope'] ?? '' ) ),
				'timeout'             => Agend_Directory_Sync_Config::clamp_int( $saved['timeout'] ?? self::DEFAULT_TIMEOUT, self::MIN_TIMEOUT, self::MAX_TIMEOUT, self::DEFAULT_TIMEOUT ),
				'page_size'           => Agend_Directory_Sync_Config::clamp_int( $saved['page_size'] ?? self::DEFAULT_PAGE_SIZE, self::MIN_PAGE_SIZE, self::MAX_PAGE_SIZE, self::DEFAULT_PAGE_SIZE ),
				'start_page'          => Agend_Directory_Sync_Config::clamp_int( $saved['start_page'] ?? self::DEFAULT_START_PAGE, 1, PHP_INT_MAX, self::DEFAULT_START_PAGE ),
				'max_pages'           => Agend_Directory_Sync_Config::clamp_int( $saved['max_pages'] ?? 0, 0, self::MAX_PAGES, 0 ),
				'use_paging_cookie'   => self::truthy( $saved['use_paging_cookie'] ?? true ),
				'include_annotations' => self::truthy( $saved['include_annotations'] ?? true ),
				'variables'           => Agend_Directory_Sync_Config::sanitize_variables( $saved['variables'] ?? array() ),
				'headers'             => Agend_Directory_Sync_Config::sanitize_headers( $saved['headers'] ?? array() ),
			);
		}

		/**
		 * Sanitise a posted settings array into the persisted shape, as a
		 * single unit. URLs must parse as absolute HTTPS; the entity set is
		 * restricted to the characters a Dataverse entity-set name can contain;
		 * numeric settings are clamped. The FetchXML is validated as parseable
		 * XML with a `<fetch>` root and dropped if it is not, so an
		 * unrunnable query is never persisted in a state that fails every run
		 * with an XML error instead of a configuration message.
		 *
		 * The client secret is never part of this array.
		 *
		 * @param array<string, mixed> $raw
		 *
		 * @return array<string, mixed>
		 */
		public static function sanitize_settings( array $raw ): array {
			return array(
				'environment_url'     => self::sanitize_environment_url( isset( $raw['environment_url'] ) ? (string) $raw['environment_url'] : '' ),
				'api_version'         => self::sanitize_api_version( isset( $raw['api_version'] ) ? (string) $raw['api_version'] : '' ),
				'entity_set'          => self::sanitize_entity_set( isset( $raw['entity_set'] ) ? (string) $raw['entity_set'] : '' ),
				'fetch_xml'           => self::sanitize_fetch_xml( isset( $raw['fetch_xml'] ) ? (string) $raw['fetch_xml'] : '' ),
				'tenant_id'           => self::sanitize_tenant_id( isset( $raw['tenant_id'] ) ? (string) $raw['tenant_id'] : '' ),
				'token_url'           => Agend_Directory_Sync_Config::sanitize_url_setting( isset( $raw['token_url'] ) ? (string) $raw['token_url'] : '' ),
				'client_id'           => sanitize_text_field( isset( $raw['client_id'] ) ? (string) $raw['client_id'] : '' ),
				'scope'               => sanitize_text_field( isset( $raw['scope'] ) ? (string) $raw['scope'] : '' ),
				'timeout'             => Agend_Directory_Sync_Config::clamp_int( $raw['timeout'] ?? self::DEFAULT_TIMEOUT, self::MIN_TIMEOUT, self::MAX_TIMEOUT, self::DEFAULT_TIMEOUT ),
				'page_size'           => Agend_Directory_Sync_Config::clamp_int( $raw['page_size'] ?? self::DEFAULT_PAGE_SIZE, self::MIN_PAGE_SIZE, self::MAX_PAGE_SIZE, self::DEFAULT_PAGE_SIZE ),
				'start_page'          => Agend_Directory_Sync_Config::clamp_int( $raw['start_page'] ?? self::DEFAULT_START_PAGE, 1, PHP_INT_MAX, self::DEFAULT_START_PAGE ),
				'max_pages'           => Agend_Directory_Sync_Config::clamp_int( $raw['max_pages'] ?? 0, 0, self::MAX_PAGES, 0 ),
				'use_paging_cookie'   => ! empty( $raw['use_paging_cookie'] ),
				'include_annotations' => ! empty( $raw['include_annotations'] ),
				'variables'           => Agend_Directory_Sync_Config::sanitize_variables( $raw['variables'] ?? array() ),
				'headers'             => Agend_Directory_Sync_Config::sanitize_headers( $raw['headers'] ?? array() ),
			);
		}

		/**
		 * Set the paging attributes on the FetchXML root element for one page.
		 *
		 * The operator's `page`, `count` and `paging-cookie` attributes are
		 * overwritten, not merged: the page window is a plugin setting, and a
		 * stale `page="3"` left in a pasted query would otherwise silently
		 * pin every request to page 3. Attributes are set through DOM rather
		 * than string-built, so the cookie is XML-escaped by the writer and a
		 * query containing quotes or a comment cannot be corrupted by a regex.
		 *
		 * Pure and static: the paging contract is the part of this source most
		 * worth testing, and it is testable without a request.
		 *
		 * @param string $fetch_xml Operator FetchXML, variables already substituted.
		 * @param int    $page      1-based page number to request.
		 * @param int    $page_size `count` attribute for the page.
		 * @param string $cookie    Paging cookie from the previous page's
		 *                          response, already decoded by
		 *                          `extract_paging_cookie()`; blank for a
		 *                          cold page.
		 *
		 * @throws RuntimeException When the FetchXML will not parse, or its
		 *                          root element is not `<fetch>`.
		 */
		public static function build_page_fetch_xml( string $fetch_xml, int $page, int $page_size, string $cookie ): string {
			$root = self::parse_fetch_element( $fetch_xml );

			$root->setAttribute( 'page', (string) $page );
			$root->setAttribute( 'count', (string) $page_size );

			if ( '' !== $cookie ) {
				$root->setAttribute( 'paging-cookie', $cookie );
			} else {
				$root->removeAttribute( 'paging-cookie' );
			}

			$doc = $root->ownerDocument;

			return (string) $doc->saveXML( $root );
		}

		/**
		 * Whether the FetchXML parses with a `<fetch>` root, for save-time
		 * validation. Same parser as the run path, so the two can never
		 * disagree about what is valid.
		 */
		public static function is_valid_fetch_xml( string $fetch_xml ): bool {
			try {
				self::parse_fetch_element( $fetch_xml );
				return true;
			} catch ( RuntimeException $e ) {
				return false;
			}
		}

		/**
		 * Parse the FetchXML and return its root element.
		 *
		 * libxml's global error state is captured and restored around the load
		 * so a parse failure here reports the XML error rather than emitting a
		 * PHP warning, and so this never changes error handling for anything
		 * else in the request.
		 *
		 * @throws RuntimeException When the document will not parse, or its
		 *                          root element is not `<fetch>`.
		 */
		private static function parse_fetch_element( string $fetch_xml ): DOMElement {
			$fetch_xml = trim( $fetch_xml );

			if ( '' === $fetch_xml ) {
				throw new RuntimeException( __( 'No FetchXML query is configured.', 'agend-directory-sync' ) );
			}

			if ( ! class_exists( 'DOMDocument' ) ) {
				throw new RuntimeException( __( 'The Dataverse source needs the PHP DOM extension to build a FetchXML page request.', 'agend-directory-sync' ) );
			}

			$previous = libxml_use_internal_errors( true );
			libxml_clear_errors();

			$doc                     = new DOMDocument();
			$doc->preserveWhiteSpace = false;

			// LIBXML_NONET: a FetchXML query is data from the admin form, and
			// nothing about parsing it should ever cause a network fetch.
			$loaded = $doc->loadXML( $fetch_xml, LIBXML_NONET );
			$errors = libxml_get_errors();

			libxml_clear_errors();
			libxml_use_internal_errors( $previous );

			if ( ! $loaded || ! $doc->documentElement instanceof DOMElement ) {
				$first = ! empty( $errors ) ? trim( (string) $errors[0]->message ) : __( 'unknown parse error', 'agend-directory-sync' );

				throw new RuntimeException(
					sprintf(
						/* translators: %s: the XML parser's error message. */
						__( 'The FetchXML query is not valid XML: %s', 'agend-directory-sync' ),
						$first
					)
				);
			}

			if ( 'fetch' !== strtolower( $doc->documentElement->nodeName ) ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: the root element name found instead of "fetch". */
						__( 'The FetchXML query must have a <fetch> root element; found <%s>.', 'agend-directory-sync' ),
						$doc->documentElement->nodeName
					)
				);
			}

			return $doc->documentElement;
		}

		/**
		 * Decode the paging cookie from a page's response into the form the
		 * next request's `paging-cookie` attribute takes.
		 *
		 * Dataverse returns the cookie as URL-encoded XML. Once decoded it is a
		 * `<cookie>...</cookie>` fragment that has to travel back as an
		 * ATTRIBUTE VALUE, so the XML writer escapes it on the way out
		 * (`build_page_fetch_xml`) and the server unescapes it on the way in,
		 * arriving byte-identical to what was issued. Do not "simplify" this by
		 * escaping here as well: escaping twice sends a cookie the server reads
		 * as literal text, and it responds by silently restarting at page 1 —
		 * a sync that loops over the first page forever.
		 *
		 * @param array<string, mixed> $decoded
		 */
		public static function extract_paging_cookie( array $decoded ): string {
			$raw = (string) ( $decoded[ self::ANNOTATION_PAGING_COOKIE ] ?? '' );

			if ( '' === $raw ) {
				return '';
			}

			return urldecode( $raw );
		}

		/**
		 * Whether to request another page.
		 *
		 * `morerecords` is authoritative when Dataverse sends it. When it is
		 * absent — an environment or an aggregate query that omits the
		 * annotation — fall back to the short-page test the Custom HTTP API
		 * source uses: a page smaller than the requested count is the last one.
		 * Treating an absent annotation as "keep going" would page until the
		 * hard cap threw on every complete sync.
		 *
		 * @param array<string, mixed> $decoded
		 */
		public static function has_more_records( array $decoded, int $row_count, int $page_size ): bool {
			if ( 0 === $row_count ) {
				return false;
			}

			if ( array_key_exists( self::ANNOTATION_MORE_RECORDS, $decoded ) ) {
				$more = $decoded[ self::ANNOTATION_MORE_RECORDS ];

				if ( is_bool( $more ) ) {
					return $more;
				}

				return in_array( strtolower( trim( (string) $more ) ), array( '1', 'true' ), true );
			}

			return $row_count >= $page_size;
		}

		/**
		 * The Web API URL for one FetchXML request.
		 *
		 * The query document is passed as the `fetchXml` query-string
		 * parameter, url-encoded here rather than by `add_query_arg()`, which
		 * would not encode it correctly for a value containing raw XML.
		 */
		public static function build_request_url( string $environment_url, string $api_version, string $entity_set, string $fetch_xml ): string {
			return sprintf(
				'%s/api/data/v%s/%s?fetchXml=%s',
				rtrim( $environment_url, '/' ),
				$api_version,
				$entity_set,
				rawurlencode( $fetch_xml )
			);
		}

		/**
		 * The headers every Dataverse data request carries.
		 *
		 * `Prefer: odata.include-annotations` is not cosmetic here: the paging
		 * cookie and the `morerecords` flag ARE annotations, so a request that
		 * suppresses them gets a response this source cannot page from. When
		 * the operator asks for formatted values the preference widens to `*`
		 * (which brings the `@OData...FormattedValue` fields option-set labels
		 * and lookups live in); otherwise it names only the two paging
		 * annotations, keeping the response small.
		 *
		 * @return array<string, string>
		 */
		public static function build_odata_headers( bool $include_annotations ): array {
			return array(
				'Accept'           => 'application/json',
				'OData-MaxVersion' => '4.0',
				'OData-Version'    => '4.0',
				'Prefer'           => $include_annotations
					? 'odata.include-annotations="*"'
					: 'odata.include-annotations="' . self::ANNOTATION_MORE_RECORDS . ',' . self::ANNOTATION_PAGING_COOKIE . '"',
			);
		}

		/**
		 * The Entra ID token endpoint: the operator's override when set,
		 * otherwise derived from the tenant id.
		 */
		public static function resolve_token_url( string $token_url, string $tenant_id ): string {
			if ( '' !== trim( $token_url ) ) {
				return trim( $token_url );
			}

			return sprintf( self::TOKEN_URL_TEMPLATE, rawurlencode( trim( $tenant_id ) ) );
		}

		/**
		 * The OAuth scope: the operator's override when set, otherwise the
		 * environment URL plus `/.default`, which is the only scope a Dataverse
		 * environment accepts for a client-credentials app.
		 */
		public static function resolve_scope( string $scope, string $environment_url ): string {
			if ( '' !== trim( $scope ) ) {
				return trim( $scope );
			}

			if ( '' === trim( $environment_url ) ) {
				return '';
			}

			return rtrim( trim( $environment_url ), '/' ) . self::SCOPE_SUFFIX;
		}

		/**
		 * The resolved settings with connection variables substituted into
		 * every template-bearing setting, and the derived token URL and scope
		 * filled in. Substitution happens here, at run time, so the stored
		 * settings stay templates and a variables edit takes effect without
		 * re-saving the query.
		 *
		 * @return array<string, mixed>
		 *
		 * @throws RuntimeException When a placeholder cannot be resolved, or
		 *                          the environment URL is not HTTPS.
		 */
		private function runtime_settings(): array {
			$settings  = self::resolve_settings();
			$variables = $settings['variables'];

			$templated = array(
				'environment_url' => __( 'Environment URL', 'agend-directory-sync' ),
				'token_url'       => __( 'Token endpoint URL', 'agend-directory-sync' ),
				'scope'           => __( 'Scope', 'agend-directory-sync' ),
				'tenant_id'       => __( 'Tenant ID', 'agend-directory-sync' ),
				'client_id'       => __( 'Application (client) ID', 'agend-directory-sync' ),
				'fetch_xml'       => __( 'FetchXML query', 'agend-directory-sync' ),
			);

			foreach ( $templated as $key => $label ) {
				$settings[ $key ] = Agend_Directory_Sync_Config::substitute_variables( (string) $settings[ $key ], $variables );
				Agend_Directory_Sync_Config::assert_no_unresolved_placeholders( (string) $settings[ $key ], $label );
			}

			foreach ( $settings['headers'] as $header_name => $header_value ) {
				$header_value = Agend_Directory_Sync_Config::substitute_variables( $header_value, $variables );
				Agend_Directory_Sync_Config::assert_no_unresolved_placeholders(
					$header_value,
					sprintf(
						/* translators: %s: the custom request header name. */
						__( 'Header "%s"', 'agend-directory-sync' ),
						$header_name
					)
				);
				$settings['headers'][ $header_name ] = $header_value;
			}

			if ( ! Agend_Directory_Sync_Config::is_https_url( $settings['environment_url'] ) ) {
				throw new RuntimeException( __( 'The Dataverse environment URL must be HTTPS.', 'agend-directory-sync' ) );
			}

			$settings['token_url'] = self::resolve_token_url( $settings['token_url'], $settings['tenant_id'] );
			$settings['scope']     = self::resolve_scope( $settings['scope'], $settings['environment_url'] );

			return $settings;
		}

		/**
		 * Request and decode one page, retrying once with a fresh token on a
		 * 401 (a token that expired mid-run is the expected case on a long
		 * sync, not an error), then failing loudly on any other non-2xx status
		 * or a non-JSON body.
		 *
		 * @return array{status: int, decoded: array<string, mixed>}
		 *
		 * @throws RuntimeException On transport failure, non-2xx status, or an
		 *                          invalid JSON body.
		 */
		private function request_page( array $settings, string $fetch_xml ): array {
			$url = self::build_request_url(
				$settings['environment_url'],
				$settings['api_version'],
				$settings['entity_set'],
				$fetch_xml
			);

			$response = $this->perform_get( $url, $settings, false );

			if ( 401 === $response['status'] ) {
				Agend_Directory_Sync_Oauth_Token_Manager::invalidate( $settings['token_url'], $settings['client_id'] );
				$response = $this->perform_get( $url, $settings, true );
			}

			if ( $response['status'] < 200 || $response['status'] >= 300 ) {
				throw new RuntimeException(
					sprintf(
						/* translators: 1: HTTP status code, 2: first 500 characters of the response body. */
						__( 'Dataverse request failed with HTTP %1$d: %2$s', 'agend-directory-sync' ),
						$response['status'],
						Agend_Directory_Sync_Config::excerpt( $response['body'], self::RESPONSE_EXCERPT_LENGTH )
					)
				);
			}

			$decoded = json_decode( $response['body'], true );

			if ( ! is_array( $decoded ) ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: the response Content-Type header, or "unknown" when absent. */
						__( 'Dataverse response was not valid JSON (content type: %s).', 'agend-directory-sync' ),
						'' !== $response['content_type'] ? $response['content_type'] : __( 'unknown', 'agend-directory-sync' )
					)
				);
			}

			return array(
				'status'  => $response['status'],
				'decoded' => $decoded,
			);
		}

		/**
		 * Perform the raw GET with the OData and auth headers, never throwing
		 * on a non-2xx status (the caller decides whether to retry or fail).
		 *
		 * @return array{status: int, body: string, content_type: string}
		 *
		 * @throws RuntimeException On transport failure or token acquisition
		 *                          failure.
		 */
		private function perform_get( string $url, array $settings, bool $force_fresh_token ): array {
			$access_token = Agend_Directory_Sync_Oauth_Token_Manager::get_access_token(
				$settings['token_url'],
				$settings['client_id'],
				$settings['scope'],
				Agend_Directory_Sync_Oauth_Token_Manager::CLIENT_AUTH_BODY,
				$force_fresh_token,
				Agend_Directory_Sync_Secret_Store::KEY_DATAVERSE_CLIENT_SECRET,
				'AGEND_DIRECTORY_SYNC_DATAVERSE_CLIENT_SECRET'
			);

			$headers = Agend_Directory_Sync_Config::merge_request_headers(
				$settings['headers'],
				array_merge(
					self::build_odata_headers( (bool) $settings['include_annotations'] ),
					array( 'Authorization' => 'Bearer ' . $access_token )
				)
			);

			$response = wp_remote_get(
				$url,
				array(
					'timeout' => $settings['timeout'],
					'headers' => $headers,
				)
			);

			if ( is_wp_error( $response ) ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: underlying WP HTTP transport error message. */
						__( 'Dataverse request failed: %s', 'agend-directory-sync' ),
						$response->get_error_message()
					)
				);
			}

			return array(
				'status'       => (int) wp_remote_retrieve_response_code( $response ),
				'body'         => (string) wp_remote_retrieve_body( $response ),
				'content_type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
			);
		}

		/**
		 * The `value` array from a page's decoded body, counting (and skipping)
		 * rows that are not JSON objects.
		 *
		 * @param array<string, mixed> $decoded
		 *
		 * @return array<int, array<string, mixed>>
		 *
		 * @throws RuntimeException When the response has no `value` array.
		 */
		private function resolve_records( array $decoded ): array {
			$value = $decoded[ self::DATA_PATH ] ?? null;

			if ( ! is_array( $value ) ) {
				throw new RuntimeException(
					sprintf(
						/* translators: 1: the expected response key, 2: comma-separated keys the response actually had. */
						__( 'Dataverse response had no "%1$s" array. Keys present: %2$s.', 'agend-directory-sync' ),
						self::DATA_PATH,
						! empty( $decoded ) ? implode( ', ', array_map( 'strval', array_keys( $decoded ) ) ) : __( '(none)', 'agend-directory-sync' )
					)
				);
			}

			$records = array();
			foreach ( $value as $row ) {
				if ( Agend_Directory_Sync_Config::is_associative_array( $row ) ) {
					$records[] = $row;
				} else {
					$this->skipped_non_associative_count++;
				}
			}

			return $records;
		}

		/**
		 * Sanitise the environment URL: an absolute HTTPS origin with any path
		 * and trailing slash removed, since the API path is appended to it.
		 */
		private static function sanitize_environment_url( string $value ): string {
			$value = Agend_Directory_Sync_Config::sanitize_url_setting( $value );

			return '' !== $value ? rtrim( $value, '/' ) : '';
		}

		/**
		 * Sanitise the API version to the `9.2` / `9` shape that goes into the
		 * `v{version}` path segment.
		 */
		private static function sanitize_api_version( string $value ): string {
			$value = (string) preg_replace( '/[^0-9.]/', '', trim( $value ) );

			return '' !== $value ? $value : self::DEFAULT_API_VERSION;
		}

		/**
		 * Sanitise the entity set name. It goes into the URL path, so it is
		 * restricted to the characters a Dataverse entity-set name can hold
		 * rather than escaped: anything else is a configuration mistake, not a
		 * value to pass through.
		 */
		private static function sanitize_entity_set( string $value ): string {
			return (string) preg_replace( '/[^A-Za-z0-9_]/', '', trim( $value ) );
		}

		/**
		 * Sanitise the tenant id: a GUID, or a verified domain name, either of
		 * which Entra accepts in the token URL's tenant position.
		 */
		private static function sanitize_tenant_id( string $value ): string {
			return (string) preg_replace( '/[^A-Za-z0-9._{}\-]/', '', trim( $value ) );
		}

		/**
		 * Store the FetchXML verbatim once it parses with a `<fetch>` root, and
		 * drop it entirely when it does not. Nothing is escaped or stripped: a
		 * FetchXML query is XML, and `sanitize_text_field()` would mangle it
		 * into an unrunnable string that still looked plausible in the form.
		 *
		 * A query containing `{name}` placeholders is validated after
		 * substituting a placeholder-shaped stand-in, so a template is not
		 * rejected for the braces alone.
		 */
		private static function sanitize_fetch_xml( string $value ): string {
			$value = trim( $value );

			if ( '' === $value ) {
				return '';
			}

			$probe = (string) preg_replace( '/\{[A-Za-z0-9_]+\}/', 'placeholder', $value );

			return self::is_valid_fetch_xml( $probe ) ? $value : '';
		}

		/**
		 * Coerce a stored checkbox value. An option written before the setting
		 * existed reads as its default rather than as false, so upgrading does
		 * not silently turn paging cookies off.
		 *
		 * @param mixed $value
		 */
		private static function truthy( $value ): bool {
			if ( is_bool( $value ) ) {
				return $value;
			}

			return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}
	}
endif;

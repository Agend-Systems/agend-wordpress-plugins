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
		 * Filter hook through which a caller supplies, for one run, the
		 * secondary FetchXML filter fragment applied on top of the saved
		 * query: `apply_filters( self::SECONDARY_FILTER_HOOK, string $saved )`
		 * returning the fragment to use ('' for none). The WP-CLI command's
		 * `--secondary-filter` flag uses it so a grouped upload can be
		 * scripted without touching the saved settings.
		 */
		public const SECONDARY_FILTER_HOOK = 'agend_directory_sync_dataverse_secondary_filter';

		/**
		 * Secondary filter configuration modes: a hand-written FetchXML
		 * fragment, or a field + list of values the admin picked by label and
		 * this source builds into a fragment.
		 */
		public const SECONDARY_FILTER_MODE_RAW    = 'raw';
		public const SECONDARY_FILTER_MODE_GUIDED = 'guided';

		/**
		 * How long a field's discovered values are cached, and how many are
		 * returned before the admin UI is told to offer a search box instead
		 * of an ever-growing list. Both are properties of "how big can a
		 * picklist reasonably get before this stops being a dropdown", not
		 * configuration an operator should need to tune.
		 */
		public const FIELD_VALUES_CACHE_TTL = 300;
		public const FIELD_VALUES_LIMIT     = 200;

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

				// With a cookie in hand, the page it belongs to is the server's
				// to state; the server rejects a pair it thinks disagrees.
				$next_page = '' !== $cookie ? self::extract_next_page_number( $response['decoded'] ) : null;
				$page      = null !== $next_page ? $next_page : $page + 1;
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
				'secondary_filter' => $settings['secondary_filter'],
				'secondary_filter_description' => $settings['secondary_filter_description'],
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
		 * The possible values for a Dataverse field, for the guided secondary
		 * filter builder: values actually in use on records (preferred,
		 * since they are what an admin will actually be filtering among),
		 * falling back to (or merging in) the field's metadata options.
		 *
		 * Cached in a transient keyed on the environment, entity, field and
		 * search term, since this is a lookup the admin page can trigger
		 * repeatedly while an operator is building a filter.
		 *
		 * @return array{entity: string, field: string, type: string, source: string, options: array<int, array{value: string, label: string}>, has_more: bool}
		 *
		 * @throws RuntimeException When the source is unavailable, the field
		 *                          name is invalid, the main query has no
		 *                          entity, or every discovery path failed.
		 */
		public function fetch_field_values( string $field, string $search = '', bool $refresh = false ): array {
			if ( ! $this->is_available() ) {
				throw new RuntimeException( $this->get_unavailable_reason() );
			}

			$field = self::sanitize_secondary_filter_field( $field );

			if ( '' === $field ) {
				throw new RuntimeException( __( 'That is not a valid Dataverse field logical name.', 'agend-directory-sync' ) );
			}

			$settings = $this->runtime_settings();
			$entity   = self::extract_entity_name( $settings['fetch_xml'] );
			$search   = trim( $search );

			$cache_key = self::field_values_cache_key( $settings['environment_url'], $entity, $field, $search );

			if ( $refresh ) {
				delete_transient( $cache_key );
			} else {
				$cached = get_transient( $cache_key );
				if ( is_array( $cached ) ) {
					// A payload stored before `cached_at` existed has no stamp
					// to measure from. Report an unknown age rather than one
					// counted from the epoch: the admin UI turns this into
					// words, and "cached 56 years ago" is worse than silence.
					$cached_at = (int) ( $cached['cached_at'] ?? 0 );

					$cached['cached_at']  = $cached_at;
					$cached['from_cache'] = true;
					$cached['cache_age']  = $cached_at > 0 ? max( 0, time() - $cached_at ) : 0;

					return $cached;
				}
			}

			$result = $this->discover_field_values( $settings, $entity, $field, $search );

			// Stamped before storing, so the age reported on a later hit is
			// measured from the discovery, not from the hit.
			$result['cached_at']  = time();
			$result['from_cache'] = false;
			$result['cache_age']  = 0;

			set_transient( $cache_key, $result, self::FIELD_VALUES_CACHE_TTL );

			return $result;
		}

		/**
		 * Transient key for a cached field-values discovery, following
		 * `Agend_Directory_Sync_Oauth_Token_Manager::transient_key()`'s md5
		 * style: distinct environments, entities, fields and search terms
		 * never collide.
		 */
		private static function field_values_cache_key( string $environment_url, string $entity, string $field, string $search ): string {
			return 'agend_dsync_dvfields_' . md5( $environment_url . '|' . $entity . '|' . $field . '|' . $search );
		}

		/**
		 * Orchestrate the discovery steps: attribute type, values in use,
		 * metadata options (merged in as appropriate), search filtering,
		 * sort, and the display cap. A failure in the type lookup or the
		 * in-use/metadata queries is not fatal here on its own; only ending
		 * up with no options at all is.
		 *
		 * @return array{entity: string, field: string, type: string, source: string, options: array<int, array{value: string, label: string}>, has_more: bool}
		 *
		 * @throws RuntimeException When every discovery path failed.
		 */
		private function discover_field_values( array $settings, string $entity, string $field, string $search ): array {
			$type = $this->discover_field_type( $settings, $entity, $field );

			try {
				$in_use = $this->discover_in_use_values( $settings, $entity, $field, $type, $search );
			} catch ( Throwable $e ) {
				$in_use = array();
			}

			$options = $in_use;
			$source  = ! empty( $in_use ) ? 'in_use' : '';

			// Lookup, customer and owner have no metadata option source to
			// fall back to or merge in: their only values come from what is
			// actually on a record.
			$metadata_types = array( 'picklist', 'multiselectpicklist', 'status', 'state', 'boolean' );

			if ( in_array( $type, $metadata_types, true ) ) {
				try {
					$metadata_options = $this->discover_metadata_values( $settings, $entity, $field, $type );
				} catch ( Throwable $e ) {
					$metadata_options = array();
				}

				if ( ! empty( $metadata_options ) ) {
					$existing = array_column( $options, 'value' );

					foreach ( $metadata_options as $metadata_option ) {
						if ( ! in_array( $metadata_option['value'], $existing, true ) ) {
							$options[]  = $metadata_option;
							$existing[] = $metadata_option['value'];
						}
					}

					$source = '' === $source ? 'metadata' : 'in_use+metadata';
				}
			}

			if ( empty( $options ) ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: the Dataverse field logical name. */
						__( 'Could not discover any values for field "%s". Check the field name, and that the environment is reachable.', 'agend-directory-sync' ),
						$field
					)
				);
			}

			// A lookup field's search is already applied at the query level
			// (in discover_in_use_values(), narrowed on the target's primary
			// name) when that narrowing succeeds; re-filtering by substring
			// here on a formatted lookup label would only risk dropping a
			// legitimate match whose label does not literally contain the
			// search text. Every other type has no query-level narrowing, so
			// it is filtered here instead.
			if ( '' !== $search && 'lookup' !== $type ) {
				$options = self::filter_options_by_search( $options, $search );
			}

			usort(
				$options,
				static function ( array $a, array $b ): int {
					return strcasecmp( $a['label'], $b['label'] );
				}
			);

			$has_more = count( $options ) > self::FIELD_VALUES_LIMIT;

			if ( $has_more ) {
				$options = array_slice( $options, 0, self::FIELD_VALUES_LIMIT );
			}

			return array(
				'entity'   => $entity,
				'field'    => $field,
				'type'     => $type,
				'source'   => '' === $source ? 'metadata' : $source,
				'options'  => array_values( $options ),
				'has_more' => $has_more,
			);
		}

		/**
		 * The field's Dataverse attribute type, lowercased and restricted to
		 * the types this source knows how to build a filter for. A failure
		 * here (a typo'd field name, an unreachable environment) is not
		 * fatal: the caller carries on with an empty type, which still lets
		 * the in-use values query run.
		 */
		private function discover_field_type( array $settings, string $entity, string $field ): string {
			try {
				$url = sprintf(
					"%s/api/data/v%s/EntityDefinitions(LogicalName='%s')/Attributes(LogicalName='%s')?\$select=LogicalName,AttributeType,DisplayName",
					rtrim( $settings['environment_url'], '/' ),
					$settings['api_version'],
					rawurlencode( $entity ),
					rawurlencode( $field )
				);

				$decoded = $this->request_json_url( $url, $settings );

				return self::sanitize_secondary_filter_field_type( (string) ( $decoded['AttributeType'] ?? '' ) );
			} catch ( Throwable $e ) {
				return '';
			}
		}

		/**
		 * The values actually in use on records for this field: a distinct
		 * FetchXML aggregate grouped on the field, run against the MAIN
		 * query's entity (never the operator's own filters, so discovery
		 * always sees the whole entity's values rather than whatever the
		 * currently-configured filter already narrows to).
		 *
		 * A lookup field with a search term is narrowed at the query level,
		 * joined to the target entity's primary name; when that narrowing
		 * fails for any reason, this falls back to the unnarrowed aggregate
		 * with the search applied to labels in PHP instead, rather than
		 * failing the whole lookup.
		 */
		private function discover_in_use_values( array $settings, string $entity, string $field, string $type, string $search ): array {
			$narrowed = false;
			$rows     = null;

			if ( 'lookup' === $type && '' !== $search ) {
				try {
					$rows     = $this->fetch_in_use_rows_narrowed( $settings, $entity, $field, $search );
					$narrowed = true;
				} catch ( Throwable $e ) {
					$rows = null;
				}
			}

			if ( null === $rows ) {
				$rows = $this->fetch_in_use_rows( $settings, $entity, $field, null );
			}

			$options = self::rows_to_options( $rows );

			if ( 'lookup' === $type && ! $narrowed && '' !== $search ) {
				$options = self::filter_options_by_search( $options, $search );
			}

			return $options;
		}

		/**
		 * Resolve a lookup field's single target entity and the attribute to
		 * narrow a search on: its primary name field, and the logical name
		 * to join through. The target's primary key attribute is assumed to
		 * be `{logical name}id`, the Dataverse convention for every
		 * out-of-box and custom entity; fetching it explicitly would need a
		 * second metadata call this source has no other use for.
		 *
		 * @return array{entity: string, from: string, primary_name: string}
		 *
		 * @throws RuntimeException When the lookup has no target metadata, or
		 *                          the target has no primary name attribute.
		 */
		private function resolve_lookup_target( array $settings, string $entity, string $field ): array {
			$url = sprintf(
				"%s/api/data/v%s/EntityDefinitions(LogicalName='%s')/Attributes(LogicalName='%s')/Microsoft.Dynamics.CRM.LookupAttributeMetadata?\$select=Targets",
				rtrim( $settings['environment_url'], '/' ),
				$settings['api_version'],
				rawurlencode( $entity ),
				rawurlencode( $field )
			);

			$decoded = $this->request_json_url( $url, $settings );
			$targets = $decoded['Targets'] ?? null;

			if ( ! is_array( $targets ) || empty( $targets ) ) {
				throw new RuntimeException( __( 'The lookup field has no target entity metadata.', 'agend-directory-sync' ) );
			}

			$target = (string) reset( $targets );

			$entity_url = sprintf(
				"%s/api/data/v%s/EntityDefinitions(LogicalName='%s')?\$select=PrimaryNameAttribute,LogicalName",
				rtrim( $settings['environment_url'], '/' ),
				$settings['api_version'],
				rawurlencode( $target )
			);

			$entity_decoded = $this->request_json_url( $entity_url, $settings );

			$primary_name = trim( (string) ( $entity_decoded['PrimaryNameAttribute'] ?? '' ) );
			$logical_name = trim( (string) ( $entity_decoded['LogicalName'] ?? $target ) );

			if ( '' === $primary_name || '' === $logical_name ) {
				throw new RuntimeException( __( 'The lookup target entity has no primary name attribute.', 'agend-directory-sync' ) );
			}

			return array(
				'entity'       => $logical_name,
				'from'         => $logical_name . 'id',
				'primary_name' => $primary_name,
			);
		}

		/**
		 * Run the in-use aggregate narrowed to rows whose lookup target's
		 * primary name contains the search term, via an inner `<link-entity>`
		 * built with DOM so the search text is escaped by the writer.
		 */
		private function fetch_in_use_rows_narrowed( array $settings, string $entity, string $field, string $search ): array {
			$target = $this->resolve_lookup_target( $settings, $entity, $field );

			return $this->fetch_in_use_rows(
				$settings,
				$entity,
				$field,
				array(
					'entity'       => $target['entity'],
					'from'         => $target['from'],
					'primary_name' => $target['primary_name'],
					'search'       => $search,
				)
			);
		}

		/**
		 * Send the distinct/aggregate group-by query for one field, with the
		 * `Prefer` header that brings back each row's formatted value
		 * alongside its raw one. Built with DOM throughout, like
		 * `build_page_fetch_xml()`, so a search term or field name containing
		 * XML specials cannot corrupt the document.
		 *
		 * @param array{entity: string, from: string, primary_name: string, search: string}|null $link_spec
		 *
		 * @return array<int, array<string, mixed>>
		 */
		private function fetch_in_use_rows( array $settings, string $entity, string $field, ?array $link_spec ): array {
			if ( ! class_exists( 'DOMDocument' ) ) {
				throw new RuntimeException( __( 'The Dataverse source needs the PHP DOM extension to discover field values.', 'agend-directory-sync' ) );
			}

			$doc = new DOMDocument();

			$fetch = $doc->createElement( 'fetch' );
			$fetch->setAttribute( 'distinct', 'true' );
			$fetch->setAttribute( 'aggregate', 'true' );
			$doc->appendChild( $fetch );

			$entity_el = $doc->createElement( 'entity' );
			$entity_el->setAttribute( 'name', $entity );
			$fetch->appendChild( $entity_el );

			$attribute_el = $doc->createElement( 'attribute' );
			$attribute_el->setAttribute( 'name', $field );
			$attribute_el->setAttribute( 'alias', 'agend_value' );
			$attribute_el->setAttribute( 'groupby', 'true' );
			$entity_el->appendChild( $attribute_el );

			if ( null !== $link_spec ) {
				$link = $doc->createElement( 'link-entity' );
				$link->setAttribute( 'name', $link_spec['entity'] );
				$link->setAttribute( 'from', $link_spec['from'] );
				$link->setAttribute( 'to', $field );
				$link->setAttribute( 'link-type', 'inner' );

				$link_filter    = $doc->createElement( 'filter' );
				$link_condition = $doc->createElement( 'condition' );
				$link_condition->setAttribute( 'attribute', $link_spec['primary_name'] );
				$link_condition->setAttribute( 'operator', 'like' );
				$link_condition->setAttribute( 'value', '%' . $link_spec['search'] . '%' );
				$link_filter->appendChild( $link_condition );
				$link->appendChild( $link_filter );

				$entity_el->appendChild( $link );
			}

			$fetch_xml = (string) $doc->saveXML( $fetch );

			$url = self::build_request_url( $settings['environment_url'], $settings['api_version'], $settings['entity_set'], $fetch_xml );

			$decoded = $this->request_json_url(
				$url,
				$settings,
				array( 'Prefer' => 'odata.include-annotations="OData.Community.Display.V1.FormattedValue"' )
			);

			$value = $decoded[ self::DATA_PATH ] ?? null;

			return is_array( $value ) ? $value : array();
		}

		/**
		 * Pair each row's raw grouped value with its formatted-value
		 * annotation (falling back to the raw value as its own label), and
		 * drop a row whose raw value is null, empty, or not one of the three
		 * shapes the values sanitiser trusts.
		 *
		 * @param array<int, mixed> $rows
		 *
		 * @return array<int, array{value: string, label: string}>
		 */
		private static function rows_to_options( array $rows ): array {
			$options = array();

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$raw = $row['agend_value'] ?? null;

				if ( null === $raw || '' === $raw ) {
					continue;
				}

				$raw_string = is_bool( $raw ) ? ( $raw ? 'true' : 'false' ) : (string) $raw;
				$normalized = self::normalize_secondary_filter_value( $raw_string );

				if ( null === $normalized || array_key_exists( $normalized, $options ) ) {
					continue;
				}

				$formatted = $row['agend_value@OData.Community.Display.V1.FormattedValue'] ?? null;
				$label     = is_string( $formatted ) && '' !== trim( $formatted ) ? $formatted : $normalized;

				$options[ $normalized ] = array(
					'value' => $normalized,
					'label' => sanitize_text_field( $label ),
				);
			}

			return array_values( $options );
		}

		/**
		 * The field's metadata-declared options, for the types that have
		 * them. A boolean's true/false options are read from
		 * `TrueOption`/`FalseOption` rather than an `Options` array, which is
		 * why it is handled as its own branch rather than folded into the
		 * type => cast map.
		 *
		 * @return array<int, array{value: string, label: string}>
		 */
		private function discover_metadata_values( array $settings, string $entity, string $field, string $type ): array {
			if ( 'boolean' === $type ) {
				return $this->discover_boolean_metadata_values( $settings, $entity, $field );
			}

			$casts = array(
				'picklist'            => 'PicklistAttributeMetadata',
				'multiselectpicklist' => 'MultiSelectPicklistAttributeMetadata',
				'status'              => 'StatusAttributeMetadata',
				'state'               => 'StateAttributeMetadata',
			);

			if ( ! isset( $casts[ $type ] ) ) {
				return array();
			}

			$url = sprintf(
				"%s/api/data/v%s/EntityDefinitions(LogicalName='%s')/Attributes(LogicalName='%s')/Microsoft.Dynamics.CRM.%s?\$select=LogicalName&\$expand=OptionSet(\$select=Options),GlobalOptionSet(\$select=Options)",
				rtrim( $settings['environment_url'], '/' ),
				$settings['api_version'],
				rawurlencode( $entity ),
				rawurlencode( $field ),
				$casts[ $type ]
			);

			$decoded    = $this->request_json_url( $url, $settings );
			$option_set = self::pick_populated_option_set( $decoded['OptionSet'] ?? null, $decoded['GlobalOptionSet'] ?? null );
			$raw_options = is_array( $option_set ) && is_array( $option_set['Options'] ?? null ) ? $option_set['Options'] : array();

			$options = array();

			foreach ( $raw_options as $raw_option ) {
				if ( ! is_array( $raw_option ) || ! isset( $raw_option['Value'] ) ) {
					continue;
				}

				$value = self::normalize_secondary_filter_value( (string) $raw_option['Value'] );

				if ( null === $value ) {
					continue;
				}

				$options[] = array(
					'value' => $value,
					'label' => sanitize_text_field( self::extract_metadata_option_label( $raw_option, $value ) ),
				);
			}

			return $options;
		}

		/**
		 * A boolean field's two options, from `TrueOption`/`FalseOption`
		 * rather than an `Options` array.
		 *
		 * @return array<int, array{value: string, label: string}>
		 */
		private function discover_boolean_metadata_values( array $settings, string $entity, string $field ): array {
			$url = sprintf(
				"%s/api/data/v%s/EntityDefinitions(LogicalName='%s')/Attributes(LogicalName='%s')/Microsoft.Dynamics.CRM.BooleanAttributeMetadata?\$select=LogicalName&\$expand=OptionSet(\$select=TrueOption,FalseOption),GlobalOptionSet(\$select=TrueOption,FalseOption)",
				rtrim( $settings['environment_url'], '/' ),
				$settings['api_version'],
				rawurlencode( $entity ),
				rawurlencode( $field )
			);

			$decoded    = $this->request_json_url( $url, $settings );
			$option_set = self::pick_populated_option_set( $decoded['OptionSet'] ?? null, $decoded['GlobalOptionSet'] ?? null );

			if ( ! is_array( $option_set ) ) {
				return array();
			}

			$options = array();

			foreach ( array( 'TrueOption' => 'true', 'FalseOption' => 'false' ) as $key => $value ) {
				$entry = $option_set[ $key ] ?? null;

				if ( is_array( $entry ) ) {
					$options[] = array(
						'value' => $value,
						'label' => sanitize_text_field( self::extract_metadata_option_label( $entry, $value ) ),
					);
				}
			}

			return $options;
		}

		/**
		 * Metadata options may sit under `OptionSet` (an entity-local option
		 * set) or `GlobalOptionSet` (a shared one); exactly one of the two is
		 * ever populated for a given attribute, so the first non-empty one
		 * wins.
		 *
		 * @param mixed $option_set
		 * @param mixed $global_option_set
		 *
		 * @return array<string, mixed>|null
		 */
		private static function pick_populated_option_set( $option_set, $global_option_set ): ?array {
			if ( is_array( $option_set ) && ! empty( $option_set ) ) {
				return $option_set;
			}

			return is_array( $global_option_set ) ? $global_option_set : null;
		}

		/**
		 * A metadata option's display label: the current UI language's
		 * label, falling back to the first localized label, falling back to
		 * the value itself when the option carries no label at all.
		 *
		 * @param array<string, mixed> $entry
		 */
		private static function extract_metadata_option_label( array $entry, string $fallback ): string {
			$label = $entry['Label']['UserLocalizedLabel']['Label'] ?? null;

			if ( is_string( $label ) && '' !== trim( $label ) ) {
				return $label;
			}

			$localized = $entry['Label']['LocalizedLabels'][0]['Label'] ?? null;

			return ( is_string( $localized ) && '' !== trim( $localized ) ) ? $localized : $fallback;
		}

		/**
		 * Filter a list of `{value, label}` options to those whose label or
		 * value contains the search term, case-insensitively.
		 *
		 * @param array<int, array{value: string, label: string}> $options
		 *
		 * @return array<int, array{value: string, label: string}>
		 */
		private static function filter_options_by_search( array $options, string $search ): array {
			if ( '' === $search ) {
				return $options;
			}

			$needle = strtolower( $search );

			return array_values(
				array_filter(
					$options,
					static function ( array $option ) use ( $needle ): bool {
						return false !== strpos( strtolower( $option['label'] ), $needle )
							|| false !== strpos( strtolower( $option['value'] ), $needle );
					}
				)
			);
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
				'secondary_filter'    => trim( (string) ( $saved['secondary_filter'] ?? '' ) ),
				'secondary_filter_mode'       => self::sanitize_secondary_filter_mode( $saved['secondary_filter_mode'] ?? self::SECONDARY_FILTER_MODE_RAW ),
				'secondary_filter_field'      => self::sanitize_secondary_filter_field( $saved['secondary_filter_field'] ?? '' ),
				'secondary_filter_field_type' => self::sanitize_secondary_filter_field_type( $saved['secondary_filter_field_type'] ?? '' ),
				'secondary_filter_values'     => self::sanitize_secondary_filter_values( $saved['secondary_filter_values'] ?? array() ),
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
				'secondary_filter'    => self::sanitize_secondary_filter( isset( $raw['secondary_filter'] ) ? (string) $raw['secondary_filter'] : '' ),
				'secondary_filter_mode'       => self::sanitize_secondary_filter_mode( $raw['secondary_filter_mode'] ?? '' ),
				'secondary_filter_field'      => self::sanitize_secondary_filter_field( $raw['secondary_filter_field'] ?? '' ),
				'secondary_filter_field_type' => self::sanitize_secondary_filter_field_type( $raw['secondary_filter_field_type'] ?? '' ),
				'secondary_filter_values'     => self::sanitize_secondary_filter_values(
					$raw['secondary_filter_values'] ?? array(),
					isset( $raw['secondary_filter_value_labels'] ) ? (string) $raw['secondary_filter_value_labels'] : ''
				),
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
		 * The secondary filter fragment in force for this run: the saved
		 * fragment, unless a caller overrides it through
		 * SECONDARY_FILTER_HOOK. A non-string filter return is treated as
		 * "no filter" rather than trusted.
		 *
		 * @param string $saved The fragment from the saved settings.
		 */
		public static function effective_secondary_filter( string $saved ): string {
			$filtered = apply_filters( self::SECONDARY_FILTER_HOOK, $saved );

			return is_string( $filtered ) ? trim( $filtered ) : '';
		}

		/**
		 * Coerce a posted secondary filter mode to one of the two known
		 * values, defaulting to raw: an unrecognised or missing mode should
		 * never silently switch an operator's hand-written fragment for a
		 * guided one built from stale or absent field/values settings.
		 *
		 * @param mixed $raw
		 */
		/**
		 * The five secondary-filter keys, sanitised, and nothing else.
		 *
		 * The filter has its own panel and its own save action, separate from
		 * the connection settings form. This exists so that save writes the
		 * filter without touching a single connection setting: handing the
		 * whole settings array to `sanitize_settings()` from a panel that
		 * renders none of those fields would blank the environment URL and
		 * the FetchXML query on every filter save.
		 *
		 * @param mixed $raw The posted `agend_dataverse` array, or anything at all.
		 *
		 * @return array<string, mixed>
		 */
		public static function sanitize_secondary_filter_input( $raw ): array {
			$raw = is_array( $raw ) ? $raw : array();

			return array(
				'secondary_filter'            => self::sanitize_secondary_filter( isset( $raw['secondary_filter'] ) ? (string) $raw['secondary_filter'] : '' ),
				'secondary_filter_mode'       => self::sanitize_secondary_filter_mode( $raw['secondary_filter_mode'] ?? '' ),
				'secondary_filter_field'      => self::sanitize_secondary_filter_field( $raw['secondary_filter_field'] ?? '' ),
				'secondary_filter_field_type' => self::sanitize_secondary_filter_field_type( $raw['secondary_filter_field_type'] ?? '' ),
				'secondary_filter_values'     => self::sanitize_secondary_filter_values(
					$raw['secondary_filter_values'] ?? array(),
					isset( $raw['secondary_filter_value_labels'] ) ? (string) $raw['secondary_filter_value_labels'] : ''
				),
			);
		}

		/**
		 * Carry the saved secondary filter through a settings save that does
		 * not post it.
		 *
		 * The filter's fields left the connection settings form when the
		 * filter moved to its own panel, so a settings save posts none of
		 * them. Without this, `sanitize_settings()` would read them as absent,
		 * sanitise them to their defaults, and silently drop a configured
		 * group filter the moment somebody saved an unrelated connection
		 * change.
		 *
		 * Only an ABSENT key is carried. A key that is present but empty is a
		 * real edit, clearing the filter on purpose, and is left alone.
		 *
		 * @param array<string, mixed> $raw   The posted settings array.
		 * @param array<string, mixed> $saved The currently stored settings array.
		 *
		 * @return array<string, mixed>
		 */
		public static function carry_secondary_filter( array $raw, array $saved ): array {
			$keys = array(
				'secondary_filter',
				'secondary_filter_mode',
				'secondary_filter_field',
				'secondary_filter_field_type',
				'secondary_filter_values',
				'secondary_filter_value_labels',
			);

			foreach ( $keys as $key ) {
				if ( ! array_key_exists( $key, $raw ) && array_key_exists( $key, $saved ) ) {
					$raw[ $key ] = $saved[ $key ];
				}
			}

			return $raw;
		}

		public static function sanitize_secondary_filter_mode( $raw ): string {
			return self::SECONDARY_FILTER_MODE_GUIDED === trim( (string) $raw )
				? self::SECONDARY_FILTER_MODE_GUIDED
				: self::SECONDARY_FILTER_MODE_RAW;
		}

		/**
		 * Sanitise a Dataverse field logical name for the guided filter:
		 * lowercased, and restricted to the character set a Dataverse
		 * logical name can hold (a leading letter or underscore, then
		 * letters/digits/underscores). Anything else is a typo or an
		 * attribute path, neither of which this builder can use, so it is
		 * dropped rather than half-applied.
		 *
		 * @param mixed $raw
		 */
		public static function sanitize_secondary_filter_field( $raw ): string {
			$value = strtolower( trim( (string) $raw ) );

			return 1 === preg_match( '/^[a-z_][a-z0-9_]*$/', $value ) ? $value : '';
		}

		/**
		 * Sanitise a Dataverse attribute type to the small set this source
		 * knows how to build a guided filter for. An unrecognised type is
		 * dropped rather than stored, so the operator field can carry
		 * whatever Dataverse reports without this source having to keep a
		 * mapping for a type it has no operator behaviour for.
		 *
		 * @param mixed $raw
		 */
		public static function sanitize_secondary_filter_field_type( $raw ): string {
			$value = strtolower( trim( (string) $raw ) );

			$known = array( 'picklist', 'multiselectpicklist', 'boolean', 'status', 'state', 'lookup', 'customer', 'owner' );

			return in_array( $value, $known, true ) ? $value : '';
		}

		/**
		 * Sanitise the guided filter's chosen values into a reindexed list of
		 * `array{value, label}` rows.
		 *
		 * Accepts either the raw scalars a field-values response hands back,
		 * or the `{value, label}` rows the stored option itself holds, so the
		 * same sanitiser round-trips a saved option and a freshly posted
		 * form. Only three value shapes are trusted onto a FetchXML
		 * condition without further escaping concern: an (optionally signed)
		 * integer, a GUID (brace-wrapped or not, normalised to lowercase
		 * without braces), or a literal true/false. Anything else is a value
		 * this source did not offer, so it is dropped rather than passed
		 * through to the query.
		 *
		 * @param mixed  $raw_values
		 * @param string $raw_labels JSON-encoded map of raw value => label,
		 *                           posted separately because a <select> only
		 *                           gives back selected values, not the label
		 *                           text that was showing.
		 *
		 * @return array<int, array{value: string, label: string}>
		 */
		public static function sanitize_secondary_filter_values( $raw_values, $raw_labels = '' ): array {
			if ( ! is_array( $raw_values ) ) {
				return array();
			}

			$label_map = array();
			if ( is_string( $raw_labels ) && '' !== trim( $raw_labels ) ) {
				$decoded = json_decode( $raw_labels, true );
				if ( is_array( $decoded ) ) {
					foreach ( $decoded as $raw_key => $raw_label ) {
						$label_map[ (string) $raw_key ] = (string) $raw_label;
					}
				}
			}

			$values = array();

			foreach ( $raw_values as $row ) {
				if ( is_array( $row ) ) {
					$raw_value = isset( $row['value'] ) ? (string) $row['value'] : '';
					$own_label = isset( $row['label'] ) ? (string) $row['label'] : null;
				} elseif ( is_scalar( $row ) ) {
					$raw_value = (string) $row;
					$own_label = null;
				} else {
					continue;
				}

				$normalized = self::normalize_secondary_filter_value( $raw_value );

				if ( null === $normalized || array_key_exists( $normalized, $values ) ) {
					// Duplicate collapsing keeps the first occurrence, which is
					// also why this check happens before the label lookup below.
					continue;
				}

				$label = $own_label ?? ( $label_map[ $raw_value ] ?? ( $label_map[ $normalized ] ?? $normalized ) );

				$values[ $normalized ] = array(
					'value' => $normalized,
					'label' => sanitize_text_field( $label ),
				);
			}

			return array_values( $values );
		}

		/**
		 * Normalise a single candidate value to the exact shape this source
		 * trusts on a FetchXML condition: an integer string kept as-is
		 * (sign included), a GUID lowercased and unwrapped of braces, or a
		 * boolean literal lowercased. Returns null for anything else.
		 */
		private static function normalize_secondary_filter_value( string $value ): ?string {
			$value = trim( $value );

			if ( '' === $value ) {
				return null;
			}

			if ( 1 === preg_match( '/^[+-]?\d+$/', $value ) ) {
				return $value;
			}

			if ( 1 === preg_match( '/^\{?([0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12})\}?$/', $value, $matches ) ) {
				return strtolower( $matches[1] );
			}

			$lower = strtolower( $value );

			return ( 'true' === $lower || 'false' === $lower ) ? $lower : null;
		}

		/**
		 * Build a `<condition>` fragment from a field and a set of chosen
		 * values, the same shape `inject_secondary_filter()` accepts as a
		 * secondary filter fragment. Sanitises its own inputs so a caller
		 * (the AJAX preview, the CLI flags, `resolve_secondary_filter_fragment()`)
		 * never has to sanitise twice or risk the two disagreeing.
		 *
		 * A half-configured guided filter (a field with no values, or values
		 * with no field) returns '' rather than throwing: guided mode with
		 * nothing chosen means "no filter yet", not a broken run.
		 *
		 * Built with DOMDocument, like `build_page_fetch_xml()`, so a value
		 * containing XML specials is escaped by the writer rather than
		 * spliced in as a string.
		 *
		 * @param array<int, mixed> $values
		 */
		public static function build_guided_filter_fragment( string $field, array $values, string $field_type = '' ): string {
			$field  = self::sanitize_secondary_filter_field( $field );
			$values = self::sanitize_secondary_filter_values( $values );
			$type   = self::sanitize_secondary_filter_field_type( $field_type );

			if ( '' === $field || empty( $values ) ) {
				return '';
			}

			// A single true/false value is an unambiguous boolean condition
			// whatever the declared type says (or when there is no declared
			// type at all, as on the WP-CLI path).
			$is_single_boolean = 1 === count( $values ) && in_array( $values[0]['value'], array( 'true', 'false' ), true );

			if ( 'multiselectpicklist' === $type ) {
				$operator = 'contain-values';
			} elseif ( 'boolean' === $type || $is_single_boolean ) {
				$operator = 'eq';
			} else {
				$operator = 'in';
			}

			if ( ! class_exists( 'DOMDocument' ) ) {
				throw new RuntimeException( __( 'The Dataverse source needs the PHP DOM extension to build a guided secondary filter.', 'agend-directory-sync' ) );
			}

			$doc       = new DOMDocument();
			$condition = $doc->createElement( 'condition' );
			$condition->setAttribute( 'attribute', $field );
			$condition->setAttribute( 'operator', $operator );

			if ( 'eq' === $operator ) {
				$condition->setAttribute( 'value', $values[0]['value'] );
			} else {
				foreach ( $values as $value_row ) {
					$condition->appendChild( $doc->createElement( 'value', $value_row['value'] ) );
				}
			}

			$doc->appendChild( $condition );

			return (string) $doc->saveXML( $condition );
		}

		/**
		 * The secondary filter fragment the current settings would produce:
		 * built from the guided field/values in guided mode, or the raw
		 * fragment as saved otherwise. This is what `runtime_settings()`
		 * feeds to `effective_secondary_filter()`, so a run-scoped CLI
		 * override still replaces whichever mode is configured.
		 *
		 * @param array<string, mixed> $settings
		 */
		public static function resolve_secondary_filter_fragment( array $settings ): string {
			if ( self::SECONDARY_FILTER_MODE_GUIDED === ( $settings['secondary_filter_mode'] ?? '' ) ) {
				return self::build_guided_filter_fragment(
					(string) ( $settings['secondary_filter_field'] ?? '' ),
					is_array( $settings['secondary_filter_values'] ?? null ) ? $settings['secondary_filter_values'] : array(),
					(string) ( $settings['secondary_filter_field_type'] ?? '' )
				);
			}

			return trim( (string) ( $settings['secondary_filter'] ?? '' ) );
		}

		/**
		 * A plain-language summary of the guided filter for the run summary
		 * and the admin preview, e.g. "pca_membergroup limited to Region
		 * North, Region South". Blank in raw mode (the raw fragment is
		 * already shown verbatim, and it can be arbitrary FetchXML this
		 * source has no vocabulary to describe) or when nothing is chosen
		 * yet.
		 *
		 * @param array<string, mixed> $settings
		 */
		public static function describe_secondary_filter( array $settings ): string {
			if ( self::SECONDARY_FILTER_MODE_GUIDED !== ( $settings['secondary_filter_mode'] ?? '' ) ) {
				return '';
			}

			$field  = self::sanitize_secondary_filter_field( (string) ( $settings['secondary_filter_field'] ?? '' ) );
			$values = self::sanitize_secondary_filter_values(
				is_array( $settings['secondary_filter_values'] ?? null ) ? $settings['secondary_filter_values'] : array()
			);

			if ( '' === $field || empty( $values ) ) {
				return '';
			}

			$labels = array_map(
				static function ( array $value ): string {
					return $value['label'];
				},
				$values
			);

			return sprintf(
				/* translators: 1: the Dataverse field logical name, 2: comma-separated chosen labels. */
				__( '%1$s limited to %2$s', 'agend-directory-sync' ),
				$field,
				implode( ', ', $labels )
			);
		}

		/**
		 * The logical name of the main query's top-level `<entity>`, read
		 * from the MAIN FetchXML (never the composed one, which may not
		 * exist yet): field-value discovery needs an entity to query
		 * metadata and rows against before a secondary filter has anything
		 * to compose onto.
		 *
		 * @throws RuntimeException When the query will not parse, has no
		 *                          `<entity>`, or that entity has no `name`.
		 */
		public static function extract_entity_name( string $fetch_xml ): string {
			$root = self::parse_fetch_element( $fetch_xml );

			foreach ( $root->childNodes as $child ) {
				if ( $child instanceof DOMElement && 'entity' === strtolower( $child->nodeName ) ) {
					$name = trim( $child->getAttribute( 'name' ) );

					if ( '' === $name ) {
						throw new RuntimeException( __( 'The FetchXML query\'s <entity> element has no "name" attribute.', 'agend-directory-sync' ) );
					}

					return $name;
				}
			}

			throw new RuntimeException( __( 'The FetchXML query has no <entity> element to read the entity name from.', 'agend-directory-sync' ) );
		}

		/**
		 * Build a run-scoped secondary filter fragment from WP-CLI flags:
		 * either the raw `--secondary-filter` fragment, or the guided
		 * `--secondary-filter-field` + `--secondary-filter-values` pair.
		 *
		 * The two styles are mutually exclusive, and each fails loudly
		 * rather than falling back to "no filter": on the command line,
		 * silently building an empty fragment would upload the whole
		 * directory when the operator asked for one group, which is a worse
		 * failure than the command simply refusing to run.
		 *
		 * @param array<string, mixed> $args WP-CLI associative args.
		 *
		 * @throws RuntimeException When the raw and guided styles are mixed,
		 *                          only one half of the guided pair is
		 *                          given, the raw fragment is invalid, the
		 *                          field name is invalid, or no value
		 *                          survives sanitising.
		 */
		public static function build_secondary_filter_from_args( array $args ): string {
			$has_raw    = array_key_exists( 'secondary-filter', $args );
			$has_field  = array_key_exists( 'secondary-filter-field', $args );
			$has_values = array_key_exists( 'secondary-filter-values', $args );

			if ( $has_raw && ( $has_field || $has_values ) ) {
				throw new RuntimeException( __( '--secondary-filter cannot be combined with --secondary-filter-field or --secondary-filter-values; use one style or the other.', 'agend-directory-sync' ) );
			}

			if ( $has_raw ) {
				$fragment = trim( (string) $args['secondary-filter'] );

				if ( '' === $fragment ) {
					return '';
				}

				if ( ! self::is_valid_secondary_filter( $fragment ) ) {
					throw new RuntimeException( __( '--secondary-filter must be a valid FetchXML <filter> or <condition> element.', 'agend-directory-sync' ) );
				}

				return $fragment;
			}

			if ( ! $has_field && ! $has_values ) {
				return '';
			}

			if ( $has_field !== $has_values ) {
				throw new RuntimeException( __( '--secondary-filter-field and --secondary-filter-values must be given together.', 'agend-directory-sync' ) );
			}

			$field = self::sanitize_secondary_filter_field( (string) $args['secondary-filter-field'] );

			if ( '' === $field ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: the invalid --secondary-filter-field value supplied. */
						__( '--secondary-filter-field "%s" is not a valid Dataverse field logical name.', 'agend-directory-sync' ),
						(string) $args['secondary-filter-field']
					)
				);
			}

			$raw_values = array_values(
				array_filter(
					array_map( 'trim', explode( ',', (string) $args['secondary-filter-values'] ) ),
					static function ( string $value ): bool {
						return '' !== $value;
					}
				)
			);

			$values = self::sanitize_secondary_filter_values( $raw_values );

			if ( empty( $values ) ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: the --secondary-filter-values value supplied. */
						__( '--secondary-filter-values "%s" contained no valid value (an integer, a GUID, or true/false).', 'agend-directory-sync' ),
						(string) $args['secondary-filter-values']
					)
				);
			}

			$fragment = self::build_guided_filter_fragment( $field, $values );

			if ( '' === $fragment ) {
				throw new RuntimeException( __( 'The guided secondary filter could not be built from --secondary-filter-field and --secondary-filter-values.', 'agend-directory-sync' ) );
			}

			return $fragment;
		}

		/**
		 * Compose the secondary filter onto the main query without editing the
		 * main query's own text.
		 *
		 * The fragment is parsed as its own document and imported as an
		 * additional `<filter>` child of the query's top-level `<entity>`.
		 * FetchXML combines sibling `<filter>` elements under an entity with
		 * AND, so the saved query's own filters keep applying and the group
		 * narrows the result on top of them. A fragment whose root is a bare
		 * `<condition>` is wrapped in `<filter type="and">` first, so the
		 * common one-condition case does not need the wrapper typed by hand.
		 *
		 * A blank fragment returns the query unchanged, byte for byte, so a
		 * site that never configures a secondary filter sends exactly the
		 * FetchXML it did before this existed.
		 *
		 * DOM-based like build_page_fetch_xml(), for the same reason: a
		 * string splice on a query containing comments, CDATA or a quoted
		 * `</entity>` would corrupt it; a DOM import cannot.
		 *
		 * @param string $fetch_xml Main FetchXML, variables already substituted.
		 * @param string $fragment  `<filter>` (or `<condition>`) fragment,
		 *                          variables already substituted; blank for none.
		 *
		 * @throws RuntimeException When either document will not parse, the
		 *                          fragment's root is not `<filter>` or
		 *                          `<condition>`, or the query has no
		 *                          top-level `<entity>` to attach to.
		 */
		public static function inject_secondary_filter( string $fetch_xml, string $fragment ): string {
			$fragment = trim( $fragment );

			if ( '' === $fragment ) {
				return $fetch_xml;
			}

			$root   = self::parse_fetch_element( $fetch_xml );
			$entity = null;

			foreach ( $root->childNodes as $child ) {
				if ( $child instanceof DOMElement && 'entity' === strtolower( $child->nodeName ) ) {
					$entity = $child;
					break;
				}
			}

			if ( null === $entity ) {
				throw new RuntimeException( __( 'The FetchXML query has no <entity> element to attach the secondary filter to.', 'agend-directory-sync' ) );
			}

			$filter   = self::parse_filter_fragment( $fragment );
			$imported = $root->ownerDocument->importNode( $filter, true );
			$entity->appendChild( $imported );

			return (string) $root->ownerDocument->saveXML( $root );
		}

		/**
		 * Whether a secondary filter fragment parses as a `<filter>` or
		 * `<condition>` element, for save-time validation. Same parser as the
		 * run path, so the two can never disagree about what is valid.
		 */
		public static function is_valid_secondary_filter( string $fragment ): bool {
			try {
				self::parse_filter_fragment( $fragment );
				return true;
			} catch ( RuntimeException $e ) {
				return false;
			}
		}

		/**
		 * Parse a secondary filter fragment into a `<filter>` element,
		 * wrapping a bare `<condition>` root in `<filter type="and">`.
		 *
		 * @throws RuntimeException When the fragment is blank, will not
		 *                          parse, or has some other root element.
		 */
		private static function parse_filter_fragment( string $fragment ): DOMElement {
			$fragment = trim( $fragment );

			if ( '' === $fragment ) {
				throw new RuntimeException( __( 'The secondary filter is blank.', 'agend-directory-sync' ) );
			}

			if ( ! class_exists( 'DOMDocument' ) ) {
				throw new RuntimeException( __( 'The Dataverse source needs the PHP DOM extension to apply a secondary filter.', 'agend-directory-sync' ) );
			}

			$previous = libxml_use_internal_errors( true );
			libxml_clear_errors();

			$doc                     = new DOMDocument();
			$doc->preserveWhiteSpace = false;

			$loaded = $doc->loadXML( $fragment, LIBXML_NONET );
			$errors = libxml_get_errors();

			libxml_clear_errors();
			libxml_use_internal_errors( $previous );

			if ( ! $loaded || ! $doc->documentElement instanceof DOMElement ) {
				$first = ! empty( $errors ) ? trim( (string) $errors[0]->message ) : __( 'unknown parse error', 'agend-directory-sync' );

				throw new RuntimeException(
					sprintf(
						/* translators: %s: the XML parser's error message. */
						__( 'The secondary filter is not valid XML: %s', 'agend-directory-sync' ),
						$first
					)
				);
			}

			$element = $doc->documentElement;
			$name    = strtolower( $element->nodeName );

			if ( 'condition' === $name ) {
				$wrapper = $doc->createElement( 'filter' );
				$wrapper->setAttribute( 'type', 'and' );
				$wrapper->appendChild( $element );
				return $wrapper;
			}

			if ( 'filter' !== $name ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: the root element name found instead of "filter". */
						__( 'The secondary filter must be a <filter> or <condition> element; found <%s>.', 'agend-directory-sync' ),
						$element->nodeName
					)
				);
			}

			return $element;
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
		 * The Web API does not hand back the cookie directly. It hands back a
		 * WRAPPER element carrying the real cookie in an attribute, itself
		 * URL-encoded twice:
		 *
		 *     <cookie pagenumber="2"
		 *             pagingcookie="%253ccookie%2520page%253d%25221%2522%253e..."
		 *             istracking="False" />
		 *
		 * What belongs in `paging-cookie` is the INNER fragment, i.e.
		 * `<cookie page="1"><pca_assetid last="{...}" first="{...}" /></cookie>`.
		 * Sending the wrapper instead earns HTTP 400 `0x80041129`, "Paging
		 * Cookie And Query Do Not Match" — which is what the PCA staging
		 * environment returned on page two before this handled the wrapper.
		 *
		 * The inner fragment then travels as an ATTRIBUTE VALUE, so the XML
		 * writer escapes it on the way out (`build_page_fetch_xml`) and the
		 * server unescapes it on the way in. Do not escape it here as well:
		 * escaping twice sends a cookie the server reads as literal text, and it
		 * responds by silently restarting at page 1 — a sync that loops over the
		 * first page forever.
		 *
		 * An annotation that is already a bare `<cookie>` fragment (the shape
		 * the SDK produces) is passed through, decoded if it arrived encoded.
		 *
		 * @param array<string, mixed> $decoded
		 */
		public static function extract_paging_cookie( array $decoded ): string {
			$raw = trim( (string) ( $decoded[ self::ANNOTATION_PAGING_COOKIE ] ?? '' ) );

			if ( '' === $raw ) {
				return '';
			}

			$wrapper = self::parse_cookie_wrapper( $raw );

			if ( null !== $wrapper && '' !== $wrapper['cookie'] ) {
				return $wrapper['cookie'];
			}

			return self::url_decode_until_plain( $raw );
		}

		/**
		 * The page number the wrapper says its cookie is for.
		 *
		 * Preferred over incrementing locally: the error this replaced was the
		 * server rejecting a cookie/page pair it considered mismatched, so where
		 * Dataverse states the pairing, use its number rather than a second
		 * opinion. Null when the annotation is absent or carries no
		 * `pagenumber`, in which case the caller increments.
		 *
		 * @param array<string, mixed> $decoded
		 */
		public static function extract_next_page_number( array $decoded ): ?int {
			$raw = trim( (string) ( $decoded[ self::ANNOTATION_PAGING_COOKIE ] ?? '' ) );

			if ( '' === $raw ) {
				return null;
			}

			$wrapper = self::parse_cookie_wrapper( $raw );

			return null !== $wrapper ? $wrapper['page'] : null;
		}

		/**
		 * Pull the inner cookie and the page number out of the Web API wrapper.
		 * Returns null when the annotation is not that wrapper, so the caller
		 * can fall back rather than fail: a malformed cookie should cost the run
		 * its paging efficiency, not the run itself.
		 *
		 * @return array{cookie: string, page: int|null}|null
		 */
		private static function parse_cookie_wrapper( string $raw ): ?array {
			if ( false === strpos( $raw, 'pagingcookie' ) || ! class_exists( 'DOMDocument' ) ) {
				return null;
			}

			$previous = libxml_use_internal_errors( true );
			libxml_clear_errors();

			$doc    = new DOMDocument();
			$loaded = $doc->loadXML( $raw, LIBXML_NONET );

			libxml_clear_errors();
			libxml_use_internal_errors( $previous );

			if ( ! $loaded || ! $doc->documentElement instanceof DOMElement ) {
				return null;
			}

			$root = $doc->documentElement;

			if ( ! $root->hasAttribute( 'pagingcookie' ) ) {
				return null;
			}

			$page = $root->hasAttribute( 'pagenumber' ) ? (int) $root->getAttribute( 'pagenumber' ) : 0;

			return array(
				'cookie' => self::url_decode_until_plain( $root->getAttribute( 'pagingcookie' ) ),
				'page'   => $page > 0 ? $page : null,
			);
		}

		/**
		 * URL-decode until no percent escapes remain. The Web API encodes the
		 * inner cookie twice and the SDK encodes it once, so the number of
		 * passes is a property of the response rather than something to
		 * hardcode. Stopping on "no `%XX` left" cannot over-decode a fragment
		 * that is already plain, and the bound stops a pathological value from
		 * looping.
		 */
		private static function url_decode_until_plain( string $value ): string {
			for ( $pass = 0; $pass < 3; $pass++ ) {
				if ( ! preg_match( '/%[0-9A-Fa-f]{2}/', $value ) ) {
					break;
				}

				$decoded = urldecode( $value );

				if ( $decoded === $value ) {
					break;
				}

				$value = $decoded;
			}

			return $value;
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
				'secondary_filter' => __( 'Secondary filter', 'agend-directory-sync' ),
			);

			// A run-scoped override (the CLI flag) replaces the saved fragment
			// (guided or raw) before variable substitution, so a scripted
			// filter can use the same {name} placeholders the saved one can.
			$resolved_secondary_filter = self::resolve_secondary_filter_fragment( $settings );
			$settings['secondary_filter'] = self::effective_secondary_filter( $resolved_secondary_filter );

			// The description names the field and labels an operator chose, so it is
			// only true while the guided settings are what actually went out: a
			// run-scoped override (the CLI flag) replaces the fragment and clears it.
			$settings['secondary_filter_description'] = $settings['secondary_filter'] === $resolved_secondary_filter
				? self::describe_secondary_filter( $settings )
				: '';

			foreach ( $templated as $key => $label ) {
				$settings[ $key ] = Agend_Directory_Sync_Config::substitute_variables( (string) $settings[ $key ], $variables );
				Agend_Directory_Sync_Config::assert_no_unresolved_placeholders( (string) $settings[ $key ], $label );
			}

			// The saved query is never edited: the secondary filter is composed
			// onto it here, per run, so the same main query serves a full sync
			// and a grouped one.
			$settings['fetch_xml'] = self::inject_secondary_filter( $settings['fetch_xml'], $settings['secondary_filter'] );

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
				throw new RuntimeException( self::format_http_failure_message( $response['status'], $response['body'] ) );
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
		 * GET a Dataverse Web API URL and return its decoded JSON body,
		 * retrying once with a fresh token on a 401 and throwing on any
		 * other non-2xx status or a non-JSON body, exactly like
		 * `request_page()`. Metadata and field-value discovery use this
		 * directly rather than the FetchXML-specific `request_page()`,
		 * since they call plain Web API URLs, not `fetchXml=` requests.
		 *
		 * @param array<string, string> $header_overrides
		 *
		 * @return array<string, mixed>
		 *
		 * @throws RuntimeException On transport failure, non-2xx status, or an
		 *                          invalid JSON body.
		 */
		private function request_json_url( string $url, array $settings, array $header_overrides = array() ): array {
			$response = $this->perform_get( $url, $settings, false, $header_overrides );

			if ( 401 === $response['status'] ) {
				Agend_Directory_Sync_Oauth_Token_Manager::invalidate( $settings['token_url'], $settings['client_id'] );
				$response = $this->perform_get( $url, $settings, true, $header_overrides );
			}

			if ( $response['status'] < 200 || $response['status'] >= 300 ) {
				throw new RuntimeException( self::format_http_failure_message( $response['status'], $response['body'] ) );
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

			return $decoded;
		}

		/**
		 * Perform the raw GET with the OData and auth headers, never throwing
		 * on a non-2xx status (the caller decides whether to retry or fail).
		 *
		 * `$header_overrides` lets a caller replace a computed OData header
		 * for one request (field-value discovery asks for the formatted-value
		 * annotation specifically, rather than the run path's `*`) without a
		 * second header-merge implementation.
		 *
		 * @param array<string, string> $header_overrides
		 *
		 * @return array{status: int, body: string, content_type: string}
		 *
		 * @throws RuntimeException On transport failure or token acquisition
		 *                          failure.
		 */
		private function perform_get( string $url, array $settings, bool $force_fresh_token, array $header_overrides = array() ): array {
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
					$header_overrides,
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
		 * Report a failed request as the diagnosis Dataverse actually sent.
		 *
		 * A Dataverse error body puts a usable sentence in `error.message` and a
		 * searchable code in `error.code`, then follows them with several
		 * hundred bytes of plugin-trace keys. Excerpting the raw body therefore
		 * truncates mid-key and buries the sentence: the first report of the
		 * paging-cookie bug arrived as a message cut off inside
		 * `@Microsoft.PowerApps.CDS.ErrorDetails`. Surface the message whole and
		 * fall back to an excerpt only when the body is not a Dataverse error.
		 */
		public static function format_http_failure_message( int $status, string $body ): string {
			$decoded = json_decode( $body, true );
			$error   = is_array( $decoded ) && isset( $decoded['error'] ) && is_array( $decoded['error'] )
				? $decoded['error']
				: null;

			$message = null !== $error ? trim( (string) ( $error['message'] ?? '' ) ) : '';

			if ( '' === $message ) {
				return sprintf(
					/* translators: 1: HTTP status code, 2: first 500 characters of the response body. */
					__( 'Dataverse request failed with HTTP %1$d: %2$s', 'agend-directory-sync' ),
					$status,
					Agend_Directory_Sync_Config::excerpt( $body, self::RESPONSE_EXCERPT_LENGTH )
				);
			}

			$code = trim( (string) ( $error['code'] ?? '' ) );

			if ( '' === $code ) {
				return sprintf(
					/* translators: 1: HTTP status code, 2: the Dataverse error message. */
					__( 'Dataverse request failed with HTTP %1$d: %2$s', 'agend-directory-sync' ),
					$status,
					$message
				);
			}

			return sprintf(
				/* translators: 1: HTTP status code, 2: the Dataverse error message, 3: the Dataverse error code. */
				__( 'Dataverse request failed with HTTP %1$d: %2$s (Dataverse code %3$s)', 'agend-directory-sync' ),
				$status,
				$message,
				$code
			);
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
		 * Same treatment for the secondary filter fragment: stored verbatim
		 * when it parses as a `<filter>` or `<condition>` (placeholders
		 * probed like the main query), dropped otherwise so a broken fragment
		 * cannot fail every run.
		 */
		private static function sanitize_secondary_filter( string $value ): string {
			$value = trim( $value );

			if ( '' === $value ) {
				return '';
			}

			$probe = (string) preg_replace( '/\{[A-Za-z0-9_]+\}/', 'placeholder', $value );

			return self::is_valid_secondary_filter( $probe ) ? $value : '';
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

<?php
/**
 * Atlas API functions.
 *
 * Server-side PHP wrappers for the Agend gateway's `/v1/atlas/*` endpoints that
 * the Resources surfaces read, plus the link builders that take a visitor or a
 * member from the website into Atlas. Sibling plugins MUST call these helpers
 * rather than building gateway paths or calling `agend_apps_api()` directly, so
 * transport and path knowledge stay centralised here.
 *
 * Two kinds of read live here and must not be mixed:
 *
 * - Public reads (the collection list, a visitor's search) are made as an
 *   anonymous visitor would make them and held in the shared cache. Atlas
 *   authorises each search hit in the database, so a key-only call sees public
 *   content only.
 * - Member reads (search with a member's view, the member's own collections,
 *   Ask) carry the member's token and are never stored: a member's view includes
 *   pages their entitlements allow and nobody else's must see them.
 *
 * Every function returns the decoded response array on success or a WP_Error on
 * failure (transport error, non-2xx, or invalid JSON).
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How long a "not entitled" verdict from Ask is believed, in seconds. Atlas
 * answers it before it spends anything, so asking again after this is free for
 * a site that is still not entitled.
 *
 * @var int
 */
const AGEND_APPS_ATLAS_ASK_NOT_ENTITLED_TTL = 6 * 3600;

/**
 * How long an answer, refusal or degraded verdict from Ask is believed.
 *
 * @var int
 */
const AGEND_APPS_ATLAS_ASK_AVAILABLE_TTL = 3600;

/**
 * Option that remembers what the last Ask verdict said. Not autoloaded: it is
 * only read by a page that places the Ask surface.
 *
 * @var string
 */
const AGEND_APPS_ATLAS_ASK_STATE_OPTION = 'agend_apps_atlas_ask_state';

// -----------------------------------------------------------------------
// Where Atlas lives, and how to link into it
// -----------------------------------------------------------------------

/**
 * The root URL of the Atlas app, without a trailing slash.
 *
 * The gateway does not say where Atlas is hosted, so it is worked out from the
 * API root the site is already configured with: the platform keeps each app on
 * its own subdomain of the platform root (`atlas.` beside `api.`, on both the
 * production and staging domains), and on its own port locally. The portal URL
 * is not used, because it can be a white-label host while Atlas stays on the
 * platform.
 *
 * Order: the `agend_apps_atlas_url` option wins, then the derived address, then
 * the `agend_apps_app_url` filter has the last word.
 *
 * @return string The Atlas root URL, or '' when there is none to derive (a
 *                custom API host that does not start with `api.`).
 */
function agend_apps_atlas_app_url(): string {
	$explicit = trim( (string) get_option( 'agend_apps_atlas_url', '' ) );

	if ( 1 === preg_match( '#^https?://#i', $explicit ) ) {
		$url = rtrim( $explicit, '/' );
	} else {
		$url = agend_apps_atlas_derive_url( class_exists( 'Agend_Apps_Settings' ) ? Agend_Apps_Settings::get_root_url() : '' );
	}

	/**
	 * Filters the root URL of an Agend app.
	 *
	 * @param string $url The resolved root URL, without a trailing slash.
	 * @param string $app The app: `atlas`.
	 */
	return rtrim( (string) apply_filters( 'agend_apps_app_url', $url, 'atlas' ), '/' );
}

/**
 * The Atlas root URL that goes with an API root URL.
 *
 * @param string $root The API root URL, as `Agend_Apps_Settings::get_root_url()` gives it.
 * @return string The Atlas root URL, or '' when the API host has no `api.` or local form to swap.
 */
function agend_apps_atlas_derive_url( string $root ): string {
	$root = rtrim( $root, '/' );

	// Local development: the gateway and Atlas are two ports on one host.
	if ( 1 === preg_match( '#^(https?://[^/:]+):3072$#i', $root, $match ) ) {
		return $match[1] . ':3066';
	}

	if ( 1 === preg_match( '#^(https?://)api\.(.+)$#i', $root, $match ) ) {
		return $match[1] . 'atlas.' . $match[2];
	}

	return '';
}

/**
 * The connected account's slug, as the Atlas addresses carry it.
 *
 * @return string
 */
function agend_apps_atlas_account_slug(): string {
	return class_exists( 'Agend_Apps_Settings' ) ? trim( Agend_Apps_Settings::get_account_slug() ) : '';
}

/**
 * Whether Atlas can be linked to from this site: the account and the app
 * address are both known.
 *
 * @return bool
 */
function agend_apps_atlas_linkable(): bool {
	return '' !== agend_apps_atlas_account_slug() && '' !== agend_apps_atlas_app_url();
}

/**
 * The address of a page in Atlas's public reader.
 *
 * Readable without signing in for a public page only; for anyone else it is a
 * not-found page until Atlas has a session for them. A search hit's document id
 * is already the page's lineage id the reader takes.
 *
 * @param string $lineage_id The page's lineage id (a search hit's `document_id`).
 * @return string The reader URL, or '' when there is no account or app address or the id is not one.
 */
function agend_apps_atlas_read_url( string $lineage_id ): string {
	$lineage_id = trim( $lineage_id );

	if ( 1 !== preg_match( '/^[A-Za-z0-9-]{8,64}$/', $lineage_id ) || ! agend_apps_atlas_linkable() ) {
		return '';
	}

	return agend_apps_atlas_app_url() . '/read/' . rawurlencode( agend_apps_atlas_account_slug() ) . '/' . rawurlencode( $lineage_id );
}

/**
 * The address of Atlas's own public search page.
 *
 * The page works for anyone, runs a keyword search only and costs the
 * association nothing, so it is where a visitor is sent for a search and where
 * "see all results" goes.
 *
 * @param string $query Optional search words to open it with.
 * @return string The search page URL, or '' when there is no account or app address.
 */
function agend_apps_atlas_public_search_url( string $query = '' ): string {
	if ( ! agend_apps_atlas_linkable() ) {
		return '';
	}

	$url = agend_apps_atlas_app_url() . '/knowledge/' . rawurlencode( agend_apps_atlas_account_slug() ) . '/search';

	return '' === $query ? $url : $url . '?' . http_build_query( array( 'q' => $query ), '', '&', PHP_QUERY_RFC3986 );
}

/**
 * Where a page in Atlas should link to, for a visitor or a signed-in member.
 *
 * - A page a visitor found (visitor search results are public by
 *   construction): the reader address, direct.
 * - A page only a member can see, for a member of a site that is its own
 *   identity provider: the reader address wrapped in the sign-in link, so Atlas
 *   has a session by the time it opens.
 * - A page only a member can see, on any other sign-in mode: no link. Atlas
 *   would answer a direct link with a not-found page until the member has
 *   signed in to Atlas, so the caller shows the title and says to sign in.
 *
 * @param string $lineage_id The page's lineage id.
 * @param bool   $public     True for a page a visitor could find; false for one found through a member's view.
 * @return array{url: string, via: string} `via` is `reader` for a direct link,
 *         `sign_in` for the wrapped one and '' when there is no link.
 */
function agend_apps_atlas_page_link( string $lineage_id, bool $public ): array {
	$none   = array(
		'url' => '',
		'via' => '',
	);
	$reader = agend_apps_atlas_read_url( $lineage_id );

	if ( '' === $reader ) {
		return $none;
	}

	if ( $public ) {
		return array(
			'url' => $reader,
			'via' => 'reader',
		);
	}

	if ( class_exists( 'Agend_Apps_Settings' ) && Agend_Apps_Settings::wordpress_idp_enabled() && function_exists( 'agend_apps_account_link_initiate_url_to' ) ) {
		$wrapped = agend_apps_account_link_initiate_url_to( $reader );

		if ( '' !== $wrapped ) {
			return array(
				'url' => $wrapped,
				'via' => 'sign_in',
			);
		}
	}

	return $none;
}

// -----------------------------------------------------------------------
// Public reads
// -----------------------------------------------------------------------

/**
 * Lists the account's collections.
 *
 * Scope: `atlas.collections.browse`. Cached. Read as an anonymous visitor.
 *
 * The list carries no visibility: every collection comes back, whoever it is
 * meant for. It is for an editor's picker and for showing the titles an editor
 * has hand-picked, never for printing as it stands to visitors.
 *
 * @param array $query Optional. Query parameters: `page`, `limit`. Default empty.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_atlas_get_collections( array $query = array() ) {
	/**
	 * Filters the collections list request args before the request is sent.
	 *
	 * @param array $args  Request args.
	 * @param array $query Original query parameters.
	 */
	$args = (array) apply_filters(
		'agend_apps_atlas_get_collections_args',
		array(
			'query'     => $query,
			'anonymous' => true,
		),
		$query
	);

	$cache_key = Agend_Apps_Cache::build_key( 'atlas_collections', $query );
	$ttl       = Agend_Apps_Settings::get_cache_ttl( 'atlas_collections' );

	return agend_apps_api()->get_cached( '/atlas/collections', $args, $cache_key, $ttl );
}

/**
 * Normalises search words: whitespace collapsed, trimmed, held to 200
 * characters.
 *
 * @param string $q The words as typed.
 * @return string
 */
function agend_apps_atlas_normalise_query( string $q ): string {
	$q = trim( (string) preg_replace( '/\s+/u', ' ', $q ) );

	return function_exists( 'mb_substr' ) ? mb_substr( $q, 0, 200 ) : substr( $q, 0, 200 );
}

/**
 * Searches Atlas.
 *
 * Scope: `atlas.pages.search`.
 *
 * A visitor's search (`$as_member` false) is made as an anonymous visitor and
 * held in the shared cache per normalised query, so repeated words cost the
 * gateway one call. A member's search carries their token, sees what their
 * entitlements allow, and is never stored.
 *
 * Both set `isolated_rate_limit`: the proxy has its own per-visitor and
 * site-wide limits, and a refusal of one search must not stop every other
 * Agend call on the site.
 *
 * @param string $q         The words to search for.
 * @param int    $limit     Most hits to ask for (1 to 100). Several hits can come from one page.
 * @param bool   $as_member True to search with the member's view. The caller has checked there is a token.
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_atlas_search( string $q, int $limit = 20, bool $as_member = false ) {
	$q     = agend_apps_atlas_normalise_query( $q );
	$limit = max( 1, min( 100, $limit ) );

	if ( '' === $q ) {
		return new WP_Error( 'agend_apps_atlas_empty_query', __( 'Enter something to search for.', 'agend-apps-core' ), array( 'status_code' => 400 ) );
	}

	$query = array(
		'q'     => $q,
		'limit' => $limit,
	);

	/**
	 * Filters the Atlas search request args before the request is sent.
	 *
	 * @param array $args      Request args.
	 * @param array $query     The search query parameters.
	 * @param bool  $as_member Whether this is a member's search.
	 */
	$args = (array) apply_filters(
		'agend_apps_atlas_search_args',
		array(
			'query'               => $query,
			'isolated_rate_limit' => true,
		),
		$query,
		$as_member
	);

	if ( $as_member ) {
		return agend_apps_api()->request( 'GET', '/atlas/search', $args );
	}

	$args['anonymous'] = true;

	$ttl = Agend_Apps_Settings::get_cache_ttl( 'atlas_search' );

	return agend_apps_api()->get_cached( '/atlas/search', $args, agend_apps_atlas_search_cache_key( $q, $limit ), $ttl );
}

/**
 * The shared cache key a visitor's search is held under: the normalised words,
 * lower-cased, and the number of hits asked for.
 *
 * @param string $q     The search words, normalised.
 * @param int    $limit Hits asked for.
 * @return string
 */
function agend_apps_atlas_search_cache_key( string $q, int $limit ): string {
	return Agend_Apps_Cache::build_key( 'atlas_search', array( function_exists( 'mb_strtolower' ) ? mb_strtolower( $q ) : strtolower( $q ), $limit ) );
}

/**
 * Whether a visitor's search is already held, so repeating it makes no gateway
 * call. The search route counts only the searches that would reach the gateway
 * against the site-wide cap.
 *
 * @param string $q     The search words.
 * @param int    $limit Hits that would be asked for.
 * @return bool
 */
function agend_apps_atlas_visitor_search_is_cached( string $q, int $limit ): bool {
	$q     = agend_apps_atlas_normalise_query( $q );
	$limit = max( 1, min( 100, $limit ) );

	return '' !== $q
		&& Agend_Apps_Settings::get_cache_ttl( 'atlas_search' ) > 0
		&& false !== get_transient( 'agend_apps_' . agend_apps_atlas_search_cache_key( $q, $limit ) );
}

// -----------------------------------------------------------------------
// Member reads
// -----------------------------------------------------------------------

/**
 * The error a member read returns when there is no member to read for.
 *
 * @return WP_Error
 */
function agend_apps_atlas_not_signed_in_error(): WP_Error {
	return new WP_Error(
		'agend_apps_atlas_not_signed_in',
		__( 'Sign in with your member account to use this.', 'agend-apps-core' ),
		array( 'status_code' => 401 )
	);
}

/**
 * Lists the collections the signed-in member can open, with the pages in each.
 *
 * Scope: `atlas.collections.browse`. Needs the member's token and answers
 * without calling the gateway when there is none. Never stored, because the
 * answer is the member's own view.
 *
 * A collection's description is always empty here; the shared collection list
 * carries it, matched by id.
 *
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_atlas_get_my_collections() {
	if ( '' === agend_apps_get_bearer_token() ) {
		return agend_apps_atlas_not_signed_in_error();
	}

	/**
	 * Filters the member collections request args before the request is sent.
	 *
	 * @param array $args Request args.
	 */
	$args = (array) apply_filters( 'agend_apps_atlas_get_my_collections_args', array( 'isolated_rate_limit' => true ) );

	return agend_apps_api()->request( 'GET', '/atlas/me/collections', $args );
}

/**
 * Reads the tenant's Ask allowance for the month.
 *
 * Scope: `atlas.questions.browse`. Cached briefly and read as an anonymous
 * visitor: it is one pool for the whole account. It does NOT say whether the
 * account is entitled to Ask (a free account gets the same default numbers), so
 * it must not be used to decide whether to show the surface.
 *
 * @return array|WP_Error Decoded response array on success, or WP_Error on failure.
 */
function agend_apps_atlas_get_ask_budget() {
	$args = (array) apply_filters( 'agend_apps_atlas_get_ask_budget_args', array( 'anonymous' => true ) );

	return agend_apps_api()->get_cached( '/atlas/ask/budget', $args, Agend_Apps_Cache::build_key( 'atlas_ask_budget' ), 60 );
}

/**
 * Asks Atlas a question on behalf of the signed-in member.
 *
 * Scope: `atlas.questions.create`. Needs the member's token: the answer is
 * metered to the verified member and the member controls fail closed, which an
 * anonymous call would skip. Answers without calling the gateway when there is
 * no token. Not cached.
 *
 * Whatever Atlas says is remembered as the account's Ask state
 * ({@see agend_apps_atlas_record_ask_verdict()}), so the widget stops offering a
 * box that cannot answer.
 *
 * @param string $question The question, 1 to 2000 characters.
 * @return array|WP_Error Decoded response array (`data.kind` is `answer`,
 *                        `refusal`, `degraded` or `not_entitled`), or WP_Error on failure.
 */
function agend_apps_atlas_ask( string $question ) {
	$question = trim( $question );

	if ( '' === $question ) {
		return new WP_Error( 'agend_apps_atlas_empty_question', __( 'Type a question first.', 'agend-apps-core' ), array( 'status_code' => 400 ) );
	}

	if ( '' === agend_apps_get_bearer_token() ) {
		return agend_apps_atlas_not_signed_in_error();
	}

	$response = agend_apps_api()->request(
		'POST',
		'/atlas/me/ask',
		(array) apply_filters(
			'agend_apps_atlas_ask_args',
			array(
				'body'                => array( 'question' => $question ),
				// Retrieval and a written answer: the gateway allows a minute.
				'timeout'             => 45,
				'isolated_rate_limit' => true,
			)
		)
	);

	if ( ! is_wp_error( $response ) ) {
		agend_apps_atlas_record_ask_verdict( (string) ( $response['data']['kind'] ?? '' ) );
	}

	return $response;
}

// -----------------------------------------------------------------------
// What the site has learned about Ask
// -----------------------------------------------------------------------

/**
 * Whether the site can currently be assumed able to answer questions.
 *
 * There is no cheap way to ask Atlas whether the account is entitled to Ask:
 * the budget read gives default numbers to every tier. The only verdict is the
 * one an ask itself returns, and `not_entitled` comes back before anything is
 * spent. So the answer is learned from the last verdict, and forgotten after a
 * while so a plan change is noticed.
 *
 * @return string `not_entitled` (within six hours of a not-entitled verdict),
 *                `available` (within an hour of an answer, refusal or degraded
 *                answer) or `unknown`.
 */
function agend_apps_atlas_ask_state(): string {
	$stored = get_option( AGEND_APPS_ATLAS_ASK_STATE_OPTION, array() );

	if ( ! is_array( $stored ) || ! isset( $stored['state'], $stored['checked_at'] ) ) {
		return 'unknown';
	}

	$age = time() - (int) $stored['checked_at'];

	if ( 'not_entitled' === $stored['state'] && $age >= 0 && $age < AGEND_APPS_ATLAS_ASK_NOT_ENTITLED_TTL ) {
		return 'not_entitled';
	}

	if ( 'available' === $stored['state'] && $age >= 0 && $age < AGEND_APPS_ATLAS_ASK_AVAILABLE_TTL ) {
		return 'available';
	}

	return 'unknown';
}

/**
 * Remembers what an ask's verdict said.
 *
 * @param string $kind The response's `kind`: `not_entitled`, or `answer`,
 *                     `refusal` or `degraded`. Anything else (an error) says
 *                     nothing about entitlement and is not remembered.
 * @return void
 */
function agend_apps_atlas_record_ask_verdict( string $kind ): void {
	if ( 'not_entitled' === $kind ) {
		$state = 'not_entitled';
	} elseif ( in_array( $kind, array( 'answer', 'refusal', 'degraded' ), true ) ) {
		$state = 'available';
	} else {
		return;
	}

	update_option(
		AGEND_APPS_ATLAS_ASK_STATE_OPTION,
		array(
			'state'      => $state,
			'checked_at' => time(),
		),
		false
	);
}

/**
 * Asks Atlas one fixed question, as the site and not a member, to learn
 * whether the account is entitled to Ask.
 *
 * A site that is not entitled pays nothing for this. An entitled one pays for
 * one short answer, so it runs only when an admin asks for it (a button on the
 * settings screen), never on a page view.
 *
 * @return string The learned state afterwards: `not_entitled`, `available` or
 *                `unknown` (the call failed, which says nothing either way).
 */
function agend_apps_atlas_check_ask(): string {
	$response = agend_apps_api()->request(
		'POST',
		'/atlas/ask',
		array(
			'body'                => array( 'question' => 'What can I find here?' ),
			'timeout'             => 45,
			'unattended'          => true,
			'isolated_rate_limit' => true,
		)
	);

	if ( ! is_wp_error( $response ) ) {
		agend_apps_atlas_record_ask_verdict( (string) ( $response['data']['kind'] ?? '' ) );
	}

	return agend_apps_atlas_ask_state();
}

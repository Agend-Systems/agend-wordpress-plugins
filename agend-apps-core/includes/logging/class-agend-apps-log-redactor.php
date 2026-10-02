<?php
/**
 * Personal-data redaction for the API log.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes personal data and secrets from everything the API log stores.
 *
 * Pure: no WordPress I/O, so it is exercised directly by the unit suite and
 * the PII leak test. Two independent layers apply to every value:
 *
 *   1. Key rules. A value whose key names personal data (an email, a name, an
 *      address, a date of birth...) is replaced outright. A rule matched on a
 *      key holding an array or object applies to every leaf beneath it, so a
 *      nested `addresses` list or a `custom_fields` map is covered at any
 *      depth, which is the defect in the Agend Pro logger this replaces: it
 *      only looked at top-level keys.
 *   2. Value scanners. Every surviving string, whatever its key, is scanned
 *      for emails, phone numbers, card numbers, JWTs, bearer tokens and API
 *      keys, so personal data under a key nobody anticipated is still caught.
 *   3. Known values. Names have no pattern, so a gateway error echoing one
 *      ("Zelphine is too short") would pass the scanners. Before a row is
 *      redacted, every value the exchange carries under a personal key is
 *      remembered (see remember()), and free text anywhere in that row is
 *      scrubbed of those values and of the words in names and addresses.
 *
 * Replaced values carry a short keyed hash (`[redacted:email#a1b2c3]`) so the
 * same value can be correlated across rows without being recoverable.
 * Secrets carry no hash: even a truncated digest of a token is not worth
 * keeping.
 */
final class Agend_Apps_Log_Redactor {

	/**
	 * Normalised keys that are redacted exactly, mapped to their kind.
	 *
	 * Derived from the property names in the gateway's OpenAPI contract
	 * (agend-dashboard apps/api/openapi.json) that carry personal data.
	 *
	 * @var array<string, string>
	 */
	private const EXACT_KEYS = array(
		// Email.
		'email'               => 'email',
		'emailaddress'        => 'email',
		// Phone.
		'phone'               => 'phone',
		'mobile'              => 'phone',
		'fax'                 => 'phone',
		'phonedialcode'       => 'phone',
		'phones'              => 'phone',
		'phonenumbers'        => 'phone',
		// Date of birth and demographics.
		'dob'                 => 'dob',
		'dateofbirth'         => 'dob',
		'birthdate'           => 'dob',
		'birthday'            => 'dob',
		'gender'              => 'pii',
		'pronouns'            => 'pii',
		'salutation'          => 'pii',
		// Address and precise location.
		'address'             => 'address',
		'addresses'           => 'address',
		'street'              => 'address',
		'suburb'              => 'address',
		'city'                => 'address',
		'town'                => 'address',
		'postcode'            => 'address',
		'zip'                 => 'address',
		'zipcode'             => 'address',
		'venueaddress'        => 'address',
		'venuecity'           => 'address',
		'venuepostcode'       => 'address',
		'locationcity'        => 'address',
		'location'            => 'address',
		'primarylocation'     => 'address',
		'defaultlocation'     => 'address',
		'locations'           => 'address',
		'latitude'            => 'address',
		'longitude'           => 'address',
		'lat'                 => 'address',
		'lng'                 => 'address',
		'line1'               => 'address',
		'line2'               => 'address',
		'line3'               => 'address',
		'lon'                 => 'address',
		'coordinates'         => 'address',
		// Names.
		'name'                => 'name',
		'nickname'            => 'name',
		'jobtitle'            => 'name',
		'organisation'        => 'name',
		'organization'        => 'name',
		'company'             => 'name',
		'employer'            => 'name',
		'username'            => 'name',
		'userlogin'           => 'name',
		'login'               => 'name',
		// Government and business identifiers.
		'abn'                 => 'id',
		'acn'                 => 'id',
		'taxid'               => 'id',
		'tfn'                 => 'id',
		// Member and external identifiers. Hashed rather than dropped: they
		// are what a support query correlates on.
		'membershipnumber'    => 'ref',
		'membernumber'        => 'ref',
		'externalid'          => 'ref',
		'externaluserid'      => 'ref',
		'memberexternalids'   => 'ref',
		'chairexternalid'     => 'ref',
		'secretaryexternalid' => 'ref',
		'ip'                  => 'ref',
		'ipaddress'           => 'ref',
		'clientip'            => 'ref',
		'remoteip'            => 'ref',
		'userip'              => 'ref',
		'forwardedfor'        => 'ref',
		// Free text that routinely carries personal detail.
		'bio'                 => 'text',
		'note'                => 'text',
		'notes'               => 'text',
		'comment'             => 'text',
		'comments'            => 'text',
		'message'             => 'text',
		'messagepreview'      => 'text',
		'snippet'             => 'text',
		'cancellationreason'  => 'text',
		// Search input: an admin searching contacts types a member's name.
		'search'              => 'text',
		'searchterm'          => 'text',
		'searchquery'         => 'text',
		'keyword'             => 'text',
		'keywords'            => 'text',
		'q'                   => 'text',
		'near'                => 'address',
		// Profile images and social profiles.
		'website'             => 'url',
		'companywebsite'      => 'url',
		'linkedinurl'         => 'url',
		'facebookurl'         => 'url',
		'instagramurl'        => 'url',
		'twitterurl'          => 'url',
		'youtubeurl'          => 'url',
		// Secrets.
		'password'            => 'secret',
		'currentpassword'     => 'secret',
		'newpassword'         => 'secret',
		'secret'              => 'secret',
		'apikey'              => 'secret',
		'otp'                 => 'secret',
		'mfacode'             => 'secret',
		'verificationcode'    => 'secret',
		'signature'           => 'secret',
		'xsignature'          => 'secret',
		'authorization'       => 'secret',
		'cookie'              => 'secret',
		// Payment.
		'cvv'                 => 'card',
		'cvc'                 => 'card',
		'bsb'                 => 'card',
		'accountnumber'       => 'card',
		// Admin-defined data: anything can be in here, so every leaf goes.
		'customfields'        => 'custom',
		'customfieldvalues'   => 'custom',
		'externalmetadata'    => 'custom',
		'metadata'            => 'custom',
		'answers'             => 'custom',
		'attendeefields'      => 'custom',
	);

	/**
	 * Normalised key suffixes, checked after the exact keys.
	 *
	 * @var array<string, string>
	 */
	private const SUFFIX_KEYS = array(
		'email'       => 'email',
		'phone'       => 'phone',
		'name'        => 'name',
		'token'       => 'secret',
		'password'    => 'secret',
		'secret'      => 'secret',
		'signature'   => 'secret',
		'credential'  => 'secret',
		'securitytoken' => 'secret',
		'sessionid'   => 'secret',
		'session'     => 'secret',
		'address'     => 'address',
		'postcode'    => 'address',
		'reason'      => 'text',
		'content'     => 'text',
		'body'        => 'text',
		'description' => 'text',
		'answer'      => 'text',
		'comment'     => 'text',
		'comments'    => 'text',
		'note'        => 'text',
		'notes'       => 'text',
	);

	/**
	 * Normalised key prefixes, checked after the suffixes.
	 *
	 * @var array<string, string>
	 */
	private const PREFIX_KEYS = array(
		'address'     => 'address',
		'street'      => 'address',
		'postal'      => 'address',
		'geo'         => 'address',
		'medicare'    => 'id',
		'passport'    => 'id',
		'licence'     => 'id',
		'license'     => 'id',
		'avatar'      => 'url',
		'photo'       => 'url',
		'card'        => 'card',
		'bank'        => 'card',
	);

	/**
	 * Keys kept verbatim even though a suffix or prefix rule would match:
	 * names of things, not people. Their string values are still scanned.
	 *
	 * @var string[]
	 */
	private const SAFE_KEYS = array(
		'tiername',
		'eventname',
		'eventtitle',
		'coursename',
		'coursetitle',
		'badgename',
		'categoryname',
		'typename',
		'groupname',
		'spacename',
		'productname',
		'activityname',
		'venuename',
		'botname',
		'filename',
		'bucketname',
		'objectname',
		'metatitle',
		'title',
		'tokentype',
		'nameidformat',
		'state',
		'country',
		'locationstate',
		'locationcountry',
		'locationtype',
		'locationfilters',
		'geocodingavailable',
	);

	/**
	 * Parent keys under which a `message` is a gateway diagnostic rather than
	 * user content. Kept readable (the scanners still run) because an error
	 * message is the first thing anyone debugging a failure needs.
	 *
	 * @var string[]
	 */
	private const DIAGNOSTIC_PARENTS = array( 'error', 'errors', 'issues', 'details' );

	/**
	 * Query-string parameters that hold free search text. Kept out of the
	 * body rules because `query` and `term` mean other things in JSON.
	 *
	 * @var array<string, string>
	 */
	private const QUERY_ONLY_KEYS = array(
		'query'     => 'text',
		'term'      => 'text',
		// OAuth codes, signed-URL signatures and access keys.
		'code'      => 'secret',
		'sig'       => 'secret',
		'key'       => 'secret',
		'accesskey' => 'secret',
	);

	/**
	 * Lower-cased paths, without the version prefix, whose bodies are never
	 * stored. Each carries a credential or a token in one direction or the
	 * other, and none is worth the risk of a redaction miss.
	 *
	 * @var string[]
	 */
	public const BODY_EXCLUDED_PATHS = array(
		'/sso/tokens',
		'/auth/login',
		'/auth/refresh',
		'/auth/mfa/verify',
		'/auth/register',
		'/auth/reset-password',
		'/auth/change-password',
		'/auth/session-handoff',
	);

	/**
	 * Response headers worth keeping. Everything else is dropped.
	 *
	 * @var string[]
	 */
	public const SAFE_RESPONSE_HEADERS = array(
		'content-type',
		'content-length',
		'retry-after',
		'x-request-id',
		'x-ratelimit-limit',
		'x-ratelimit-remaining',
		'x-ratelimit-reset',
		'x-vercel-id',
		'x-vercel-cache',
	);

	/** Key for the correlation hashes. Never leaves the options table. */
	private string $salt;

	/** @var array<string, string> */
	private array $exact_keys;

	/** @var array<string, true> */
	private array $safe_keys;

	/**
	 * Personal values seen in the exchange being redacted, mapped to their
	 * kind. Longest first when applied, so a full name is replaced before
	 * its parts.
	 *
	 * @var array<string, string>
	 */
	private array $known = array();

	/**
	 * The known values compiled into one pattern; null when stale, false
	 * when compiling failed (cached, so the failure is paid once).
	 *
	 * @var string|false|null
	 */
	private $known_pattern = null;

	/** Bound on remembered values, so one huge payload cannot build a huge pattern. */
	private const MAX_KNOWN = 2000;

	/**
	 * Longest value remembered. An identifying value (a name, an email, a
	 * slug, a token) is short; a 2 KB description is not worth matching
	 * whole, and its key rule has already removed it where it sat.
	 */
	private const MAX_KNOWN_LENGTH = 100;

	/** Budget for the compiled pattern, well under PCRE's compile limit. */
	private const MAX_PATTERN_BYTES = 30000;

	/** Kinds remembered only when long enough not to collide with ordinary words ("Yes", "Other"). */
	private const LOOSE_KINDS = array( 'text', 'custom', 'url', 'pii' );

	/**
	 * Inside a custom-field subtree, the admin-defined description of a field
	 * is kept: `{"key": "dob", "label": "Date of birth", "value": "..."}`
	 * loses only its value, so a failure can still be traced to its field.
	 *
	 * @var string[]
	 */
	private const CUSTOM_METADATA_KEYS = array( 'id', 'key', 'label', 'type', 'fieldid', 'fieldkey', 'fieldtype', 'fieldlabel' );

	/** Kinds whose values are split into words for the known-value pass. */
	private const TOKENISED_KINDS = array( 'name', 'address', 'pii' );

	/** Shortest word remembered from a name or address. */
	private const MIN_TOKEN_LENGTH = 4;

	/**
	 * @param string   $salt           Secret key for correlation hashes.
	 * @param string[] $sensitive_keys Extra keys to redact as `pii`.
	 * @param string[] $safe_keys      Extra keys to keep verbatim.
	 */
	public function __construct( string $salt, array $sensitive_keys = array(), array $safe_keys = array() ) {
		$this->salt       = $salt;
		$this->exact_keys = self::EXACT_KEYS;
		$this->safe_keys  = array_fill_keys( self::SAFE_KEYS, true );

		foreach ( $sensitive_keys as $key ) {
			$this->exact_keys[ self::normalise_key( (string) $key ) ] = 'pii';
		}

		foreach ( $safe_keys as $key ) {
			$normalised = self::normalise_key( (string) $key );

			// A site may widen what is redacted, never narrow it for the
			// classes the plugin guarantees.
			if ( ! isset( self::EXACT_KEYS[ $normalised ] ) ) {
				$this->safe_keys[ $normalised ] = true;
			}
		}
	}

	/**
	 * Lower-cases a key and strips separators, so `first_name`, `firstName`
	 * and `First-Name` all match `firstname`.
	 */
	public static function normalise_key( string $key ): string {
		return (string) preg_replace( '/[^a-z0-9]/', '', strtolower( $key ) );
	}

	/**
	 * The redaction kind a key carries, or null when the key is not personal.
	 */
	public function kind_for_key( string $key ): ?string {
		$normalised = self::normalise_key( $key );

		if ( '' === $normalised || isset( $this->safe_keys[ $normalised ] ) ) {
			return null;
		}

		if ( isset( $this->exact_keys[ $normalised ] ) ) {
			return $this->exact_keys[ $normalised ];
		}

		foreach ( self::SUFFIX_KEYS as $suffix => $kind ) {
			if ( str_ends_with( $normalised, $suffix ) ) {
				return $kind;
			}
		}

		// `billing_address_id` and `addressId` name a record, not an
		// address: a reference key matched only by a prefix keeps its id.
		// Judged on the raw key, so `address_valid` is not mistaken for one.
		$is_reference = 1 === preg_match( '/(?:[_\-][iI][dD][sS]?|[a-z0-9]Ids?)$/', $key );

		foreach ( self::PREFIX_KEYS as $prefix => $kind ) {
			if ( str_starts_with( $normalised, $prefix ) ) {
				return $is_reference && in_array( $kind, array( 'address', 'url' ), true ) ? null : $kind;
			}
		}

		return null;
	}

	/**
	 * Remembers every personal value in a decoded payload, so free text in
	 * the same row can be scrubbed of it. Call forget() between rows.
	 *
	 * @param mixed       $value  Decoded JSON value.
	 * @param string|null $forced Kind inherited from an ancestor key.
	 */
	public function remember( $value, ?string $forced = null, string $parent_key = '' ): void {
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				$kind = $forced ?? ( is_string( $key ) ? $this->key_kind_in( $key, $parent_key ) : null );

				if ( 'custom' === $kind && $this->is_custom_metadata( $key, $child ) ) {
					continue;
				}

				$this->remember( $child, $kind, is_string( $key ) ? self::normalise_key( $key ) : $parent_key );
			}

			return;
		}

		if ( null !== $forced && ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) ) {
			$this->remember_value( (string) $value, $forced );
		}
	}

	/**
	 * Remembers one personal value, and for names and addresses its words.
	 */
	public function remember_value( string $value, string $kind ): void {
		$value = trim( $value );

		$minimum = 'secret' === $kind ? 8 : ( in_array( $kind, self::LOOSE_KINDS, true ) ? 6 : 3 );

		// Short values ("SA", "4", "Mr") would scrub ordinary words; long
		// ones are not identifiers. Invalid UTF-8 (a forged path segment)
		// would make the `/u` pattern fail to compile for the whole row.
		if (
			strlen( $value ) < $minimum
			|| strlen( $value ) > self::MAX_KNOWN_LENGTH
			|| 1 === preg_match( '/^\d{1,3}$/', $value )
			|| ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $value, 'UTF-8' ) )
		) {
			return;
		}

		$this->add_known( strtolower( $value ), $kind );

		if ( in_array( $kind, self::TOKENISED_KINDS, true ) ) {
			foreach ( preg_split( '/[^\p{L}\p{N}\'\-]+/u', $value ) ?: array() as $word ) {
				if ( strlen( $word ) >= self::MIN_TOKEN_LENGTH && 1 !== preg_match( '/^\d+$/', $word ) ) {
					$this->add_known( strtolower( $word ), $kind );
				}
			}
		}
	}

	private function add_known( string $value, string $kind ): void {
		if ( count( $this->known ) >= self::MAX_KNOWN && ! isset( $this->known[ $value ] ) ) {
			return;
		}

		$this->known[ $value ] = $kind;
		$this->known_pattern   = null;
	}

	public function forget(): void {
		$this->known         = array();
		$this->known_pattern = null;
	}

	/**
	 * Replaces remembered values in free text, in one pass: every value is
	 * compiled into a single alternation, longest first so a full name wins
	 * over its parts, and the pattern is rebuilt only when a value is added.
	 */
	private function scrub_known( string $text ): string {
		if ( empty( $this->known ) || '' === $text ) {
			return $text;
		}

		if ( null === $this->known_pattern ) {
			$this->known_pattern = $this->compile_known();
		}

		if ( false === $this->known_pattern ) {
			// The values could not be compiled: fail closed, quietly, once.
			return '[redacted:text, unscannable]';
		}

		$result = @preg_replace_callback( // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a failure is handled below.
			$this->known_pattern,
			function ( array $m ): string {
				$kind = $this->known[ strtolower( $m[0] ) ] ?? 'pii';

				return 'secret' === $kind ? '[redacted:secret]' : '[redacted:' . $kind . '#' . $this->correlation_hash( $m[0] ) . ']';
			},
			$text
		);

		// A null result is a PCRE failure (bad UTF-8, backtrack limit): fail
		// closed rather than return the text unscrubbed.
		return null === $result ? '[redacted:text, unscannable]' : $result;
	}

	/**
	 * Compiles the known values, longest first, within the size budget.
	 * Values past the budget are dropped, shortest first: the key rules and
	 * scanners still apply to them where they appear.
	 *
	 * @return string|false The pattern, or false when it does not compile.
	 */
	private function compile_known() {
		$values = array_keys( $this->known );
		usort( $values, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

		$parts = array();
		$bytes = 0;

		foreach ( $values as $value ) {
			$quoted = preg_quote( (string) $value, '/' );
			$bytes += strlen( $quoted ) + 1;

			if ( $bytes > self::MAX_PATTERN_BYTES ) {
				break;
			}

			$parts[] = $quoted;
		}

		$pattern = '/(?<![\p{L}\p{N}])(?:' . implode( '|', $parts ) . ')(?![\p{L}\p{N}])/iu';

		return false === @preg_match( $pattern, '' ) ? false : $pattern; // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a failure is handled by the caller.
	}

	/**
	 * Whether a key inside a custom-field subtree describes the field rather
	 * than holding the member's answer.
	 *
	 * @param int|string $key   Array key.
	 * @param mixed      $value Its value.
	 */
	private function is_custom_metadata( $key, $value ): bool {
		return is_string( $key ) && is_scalar( $value ) && in_array( self::normalise_key( $key ), self::CUSTOM_METADATA_KEYS, true );
	}

	/**
	 * A key's kind in context: a `message` under an error object is a
	 * diagnostic, kept readable and scanned, not user-written text.
	 */
	private function key_kind_in( string $key, string $parent_key ): ?string {
		$kind = $this->kind_for_key( $key );

		if ( 'text' === $kind && 'message' === self::normalise_key( $key ) && in_array( $parent_key, self::DIAGNOSTIC_PARENTS, true ) ) {
			return null;
		}

		return $kind;
	}

	/**
	 * Redacts a decoded value at every depth.
	 *
	 * @param mixed       $value      Decoded JSON value.
	 * @param string|null $forced     Kind inherited from an ancestor key.
	 * @param string      $parent_key Normalised key of the containing object.
	 * @return mixed
	 */
	public function redact_value( $value, ?string $forced = null, string $parent_key = '' ) {
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}

		if ( is_array( $value ) ) {
			$out = array();

			foreach ( $value as $key => $child ) {
				$kind = $forced;

				if ( null === $kind && is_string( $key ) ) {
					$kind = $this->key_kind_in( $key, $parent_key );
				} elseif ( 'custom' === $kind && $this->is_custom_metadata( $key, $child ) ) {
					$kind = null;
				}

				$child_parent = is_string( $key ) ? self::normalise_key( $key ) : $parent_key;
				$out[ $key ]  = $this->redact_value( $child, $kind, $child_parent );
			}

			return $out;
		}

		if ( null !== $forced ) {
			return $this->replace_scalar( $value, $forced );
		}

		if ( is_string( $value ) ) {
			return $this->redact_text( $value );
		}

		return $value;
	}

	/**
	 * Replaces one scalar with its redaction marker. Booleans and nulls carry
	 * no personal data and are kept, so `"email_sent": true` stays readable.
	 *
	 * @param mixed  $value Scalar value.
	 * @param string $kind  Redaction kind.
	 * @return mixed
	 */
	private function replace_scalar( $value, string $kind ) {
		if ( null === $value || is_bool( $value ) ) {
			return $value;
		}

		$string = (string) $value;

		if ( '' === $string ) {
			return '';
		}

		switch ( $kind ) {
			case 'secret':
				return '[redacted:secret]';
			case 'card':
				return '[redacted:card]';
			case 'url':
				return '[redacted:url]';
			case 'custom':
				return '[redacted:custom]';
			case 'text':
				return sprintf( '[redacted:text, %d chars]', function_exists( 'mb_strlen' ) ? mb_strlen( $string ) : strlen( $string ) );
			default:
				return '[redacted:' . $kind . '#' . $this->correlation_hash( $string ) . ']';
		}
	}

	/**
	 * Six hex characters of a keyed hash, enough to tell values apart in one
	 * site's log and far too few to brute-force back to the value.
	 */
	private function correlation_hash( string $value ): string {
		return substr( hash_hmac( 'sha256', strtolower( trim( $value ) ), $this->salt ), 0, 6 );
	}

	/**
	 * Applies the value scanners to free text: error messages, URLs, and any
	 * string under a key no rule matched.
	 */
	public function redact_text( string $text ): string {
		if ( '' === $text ) {
			return '';
		}

		return $this->scan( $this->scrub_known( $text ) );
	}

	/**
	 * The value scanners alone, without the known-value pass. Used for URL
	 * paths, whose fixed route words (`/events/registrations`) must not be
	 * scrubbed because a body happened to carry the same word.
	 */
	private function scan( string $text ): string {
		if ( '' === $text ) {
			return '';
		}

		$text = (string) preg_replace( '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i', 'Bearer [redacted:secret]', $text );
		$text = (string) preg_replace( '/eyJ[A-Za-z0-9_\-]{8,}(?:\.[A-Za-z0-9_\-]*){0,2}/', '[redacted:secret]', $text );
		$text = (string) preg_replace( '/\b[a-z]{2,8}_(?:live|test|prod|staging|dev)_[A-Za-z0-9]{8,}\b/', '[redacted:secret]', $text );

		// A URL inside a value (a checkout link, a signed download) carries
		// its secrets in the query string and fragment.
		$text = (string) preg_replace_callback(
			'#((?:https?:)?//[^\s?\#"\'<>]+|\bwww\.[^\s?\#"\'<>]+)([?\#][^\s"\'<>]*)#i',
			static fn( array $m ): string => $m[1] . '?[redacted:query]',
			$text
		);

		$text = (string) preg_replace_callback(
			'/[A-Za-z0-9._+\-]+(?:@|%40)[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/',
			fn( array $m ): string => '[redacted:email#' . $this->correlation_hash( rawurldecode( $m[0] ) ) . ']',
			$text
		);

		$text = (string) preg_replace_callback(
			'/(?<![\w-])(?:\d[ -]?){12,18}\d(?![\w-])/',
			static function ( array $m ): string {
				$digits = (string) preg_replace( '/\D/', '', $m[0] );

				return self::passes_luhn( $digits ) ? '[redacted:card]' : $m[0];
			},
			$text
		);

		$phone_patterns = array(
			// +61 4xx xxx xxx, +61 2 xxxx xxxx, 61412345678.
			'/(?<![\w-])\+?61[ .-]?\(?0?[2-478]\)?(?:[ .-]?\d){8}(?![\w-])/',
			// 04xx xxx xxx, 0412.345.678, 02 xxxx xxxx, (02) xxxx xxxx.
			'/(?<![\w-])\(?0[2-478]\)?(?:[ .-]?\d){8}(?![\w-])/',
			// North American (555) 123-4567 and 555-123-4567.
			'/(?<![\w-])(?:\(\d{3}\)\s?|\d{3}[.-])\d{3}[.-]\d{4}(?![\w-])/',
			// 13xx xxx and 1300/1800 numbers.
			'/(?<![\w-])1[38]00(?:[ -]?\d){6}(?![\w-])/',
			// Any other international number written with a leading plus.
			'/(?<![\w-])\+\d{1,3}[ -]?\(?\d{1,4}\)?(?:[ -]?\d){5,12}(?![\w-])/',
		);

		foreach ( $phone_patterns as $pattern ) {
			$text = (string) preg_replace_callback(
				$pattern,
				fn( array $m ): string => '[redacted:phone#' . $this->correlation_hash( (string) preg_replace( '/\D/', '', $m[0] ) ) . ']',
				$text
			);
		}

		return $text;
	}

	/**
	 * Luhn checksum, so a long numeric id is not mistaken for a card number
	 * nine times in ten.
	 */
	private static function passes_luhn( string $digits ): bool {
		$length = strlen( $digits );

		if ( $length < 13 || $length > 19 ) {
			return false;
		}

		$sum    = 0;
		$double = false;

		for ( $i = $length - 1; $i >= 0; $i-- ) {
			$digit = (int) $digits[ $i ];

			if ( $double ) {
				$digit *= 2;

				if ( $digit > 9 ) {
					$digit -= 9;
				}
			}

			$sum   += $digit;
			$double = ! $double;
		}

		return 0 === $sum % 10;
	}

	/**
	 * Redacts a raw HTTP body.
	 *
	 * JSON is decoded, redacted at every depth and re-encoded. Anything else
	 * (multipart uploads, file downloads, HTML error pages) is summarised
	 * rather than stored, because there is no structure to redact against.
	 */
	public function redact_body( string $raw, string $content_type = '' ): string {
		if ( '' === $raw ) {
			return '';
		}

		$decoded = json_decode( $raw, true );

		if ( null === $decoded && 'null' !== trim( $raw ) ) {
			return sprintf(
				'[non-JSON body: %d bytes%s]',
				strlen( $raw ),
				'' !== $content_type ? ', ' . strtolower( strtok( $content_type, ';' ) ) : ''
			);
		}

		return (string) wp_json_encode( $this->redact_value( $decoded ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Redacts a query string: each value by its parameter name, then by the
	 * scanners. Returns the encoded query without a leading `?`.
	 */
	public function redact_query( string $query ): string {
		if ( '' === $query ) {
			return '';
		}

		$pairs = array();

		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}

			$parts = explode( '=', $pair, 2 );
			$name  = rawurldecode( $parts[0] );
			$value = isset( $parts[1] ) ? rawurldecode( str_replace( '+', ' ', $parts[1] ) ) : '';

			// `filter[first_name]` is judged by its innermost key.
			$leaf = $name;

			if ( preg_match( '/\[([^\[\]]*)\]$/', $name, $m ) ) {
				$leaf = $m[1];
			}

			$kind = self::QUERY_ONLY_KEYS[ self::normalise_key( $leaf ) ] ?? $this->kind_for_key( $leaf );

			if ( null !== $kind ) {
				// Remembered so an error echoing the search term is scrubbed.
				$this->remember_value( $value, $kind );
			}

			$redacted = null !== $kind ? (string) $this->replace_scalar( $value, $kind ) : $this->redact_text( $value );
			$pairs[]  = rawurlencode( $name ) . '=' . $redacted;
		}

		return implode( '&', $pairs );
	}

	/**
	 * Path segments naming a collection of people or listings. The segment
	 * after one is a slug or identifier, and a listing slug is often a
	 * person's name (`/directory/listings/jane-smith-physio`).
	 *
	 * @var string[]
	 */
	private const PERSON_COLLECTIONS = array( 'listings', 'listing', 'profiles', 'profile', 'members', 'member', 'contacts', 'contact', 'users', 'user', 'people', 'attendees', 'speakers', 'directory', 'by-email', 'by-slug' );

	/**
	 * Fixed route words that may follow a person collection and are not
	 * identifiers.
	 *
	 * @var string[]
	 */
	private const TOKEN_COLLECTIONS = array( 'invitations', 'invitation', 'invites', 'verify', 'tokens', 'token', 'confirm', 'reset', 'magic-link', 'unsubscribe', 'claim' );

	private const ROUTE_WORDS = array( 'me', 'my', 'mine', 'search', 'export', 'exports', 'export-reports', 'bulk-upsert', 'bulk-sync', 'sync', 'status', 'geocode', 'map', 'reviews', 'invitations', 'listings', 'listing', 'categories', 'fields', 'count', 'batch', 'by-email', 'by-slug', 'markers', 'map-settings', 'facets', 'bulk', 'settings', 'filters', 'nearby', 'tags', 'types', 'stats', 'summary', 'import', 'imports', 'upload', 'uploads', 'featured', 'recent', 'pending' );

	/**
	 * Redacts a URL path. UUIDs and numeric ids are kept. A segment carrying
	 * an email or a phone number is scanned out, and a slug following a
	 * person collection is replaced with a correlation marker.
	 */
	public function redact_path( string $path ): string {
		$segments = explode( '/', $path );
		$previous = '';

		foreach ( $segments as $index => $segment ) {
			$decoded = rawurldecode( $segment );
			$lower   = strtolower( $decoded );

			if ( '' !== $decoded && in_array( $previous, self::TOKEN_COLLECTIONS, true ) && ! in_array( $lower, array( 'accept', 'decline', 'resend', 'status' ), true ) ) {
				// Whatever follows a token route is the token, UUID-shaped or not.
				$this->remember_value( $decoded, 'secret' );
				$segments[ $index ] = '[redacted:secret]';
			} elseif (
				'' !== $decoded
				&& in_array( $previous, self::PERSON_COLLECTIONS, true )
				&& ! in_array( $lower, self::ROUTE_WORDS, true )
				&& 1 !== preg_match( '/^(?:\d+|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i', $decoded )
			) {
				$segments[ $index ] = '[redacted:ref#' . $this->correlation_hash( $decoded ) . ']';

				// A 404 echoing the slug ("Listing jane-smith-physio not
				// found") is scrubbed by the known-value pass.
				$this->remember_value( $decoded, 'ref' );
				$this->remember_value( str_replace( array( '-', '_' ), ' ', $decoded ), 'name' );
			} else {
				$segments[ $index ] = $this->scan( $decoded );
			}

			$previous = $lower;
		}

		return implode( '/', $segments );
	}

	/**
	 * Groups a path for filtering: UUIDs, numeric ids and redaction markers
	 * become `:id`, so `/events/123/registrations` and
	 * `/events/456/registrations` share one template.
	 */
	public static function path_template( string $path ): string {
		$template = (string) preg_replace( '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', ':id', $path );
		$template = (string) preg_replace( '/\[redacted:[^\]]*\]/', ':id', $template );
		$template = (string) preg_replace( '#/\d+(?=/|$)#', '/:id', $template );

		return $template;
	}

	/**
	 * Keeps only the allowlisted response headers. Request headers are never
	 * passed in at all.
	 *
	 * @param array<string, mixed> $headers Header name => value.
	 * @return array<string, string>
	 */
	public function filter_response_headers( array $headers ): array {
		$kept = array();

		foreach ( $headers as $name => $value ) {
			$lower = strtolower( (string) $name );

			if ( in_array( $lower, self::SAFE_RESPONSE_HEADERS, true ) ) {
				$kept[ $lower ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			}
		}

		return $kept;
	}

	/**
	 * Whether a path's bodies must never be stored.
	 *
	 * @param string   $path           Request path, with or without `/v1`.
	 * @param string[] $extra_excluded Additional paths from the filter.
	 */
	public static function is_body_excluded( string $path, array $extra_excluded = array() ): bool {
		$path = strtolower( (string) preg_replace( '#^/v\d+(?=/)#', '', (string) strtok( $path, '?' ) ) );
		$path = rtrim( $path, '/' );

		foreach ( array_merge( self::BODY_EXCLUDED_PATHS, $extra_excluded ) as $excluded ) {
			$excluded = rtrim( strtolower( (string) $excluded ), '/' );

			if ( $path === $excluded || str_starts_with( $path, $excluded . '/' ) ) {
				return true;
			}
		}

		return false;
	}
}

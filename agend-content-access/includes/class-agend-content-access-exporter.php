<?php
/**
 * Builds the connector record Agend ingests.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts a WordPress post into the canonical record Agend stores.
 *
 * This is the SOURCE direction: Agend reads authored content and policies from
 * WordPress. It is not the catalogue proxy, which reads already-projected
 * content the other way (Decision 2.11). Conflating the two would either expose
 * source material through a public endpoint or apply the access projection
 * twice.
 *
 * The record is deliberately complete and unprojected. Everything here is
 * source material, including the full body of a restricted document: Agend is
 * the thing that will later decide who may see it, so withholding it here would
 * mean there was nothing to protect. That is exactly why the connector routes
 * are credential-gated and the public proxy is not.
 */
class Agend_Content_Access_Exporter {

	/**
	 * Builds the record for one post.
	 *
	 * @param WP_Post $post Post to export.
	 * @return array Connector record.
	 * @throws RuntimeException When the post's stored access policy cannot be read.
	 */
	public static function record( $post ): array {
		$post_id = (int) $post->ID;

		// Refuse to build a record at all for an unreadable policy. Exported as
		// null it would mean "none set", which Agend also reads as public, so the
		// page that WordPress hides from everyone would be projected openly. The
		// connector route turns this into a 422, which leaves Agend holding the
		// revision it already has.
		if ( Agend_Content_Access_Policy::STORED_INVALID === Agend_Content_Access_Policy::stored_state( $post_id ) ) {
			throw new RuntimeException(
				sprintf(
					'Post %d has a stored access policy that is unreadable, so it was not exported. Set the audience again in the editor.',
					$post_id
				)
			);
		}

		$policy = Agend_Content_Access_Policy::get_for_post( $post_id );

		return array(
			// Stable identity for upsert. `source_id` is the WordPress post id
			// and never changes; `revision` changes on every edit, so Agend can
			// recognise a replay of a revision it already holds and skip it.
			'source'      => array(
				'system'   => 'wordpress',
				'id'       => (string) $post_id,
				'revision' => self::revision_of( $post ),
				'url'      => (string) get_permalink( $post ),
			),

			'title'       => (string) get_the_title( $post ),
			'slug'        => (string) $post->post_name,
			'collection'  => (string) $post->post_type,
			// The manually written excerpt only. An auto-generated one is the
			// opening of the body, which for a restricted document would become
			// a public description of protected content.
			'description' => has_excerpt( $post ) ? (string) $post->post_excerpt : '',
			'status'      => (string) $post->post_status,
			'language'    => self::language_of( $post ),

			'published_at' => self::iso_date( $post->post_date_gmt ),
			'updated_at'   => self::iso_date( $post->post_modified_gmt ),

			'author'      => self::author_of( $post ),
			'image'       => self::featured_image_of( $post ),
			'taxonomy'    => self::taxonomy_of( $post ),

			// The document policy exactly as stored. Null means none was set,
			// which Agend interprets under its own capability flag rather than
			// having WordPress guess (Decision 2.9). An unreadable policy never
			// reaches here: it threw above.
			'access_policy' => $policy,

			'fragments'   => self::fragments_of( $post, $policy ),

			// Populated by the legacy migration (US-6.1) so an imported policy
			// can be traced back to the rule it came from.
			'provenance'  => self::provenance_of( $post_id ),
		);
	}

	/**
	 * A revision token that changes whenever the content or policy does.
	 *
	 * Includes the POLICY, not just the body. Changing a document from public
	 * to members-only edits no content, so a revision derived from the body
	 * alone would let Agend skip the sync and keep serving the record under its
	 * old audience.
	 *
	 * A block page also includes the synced patterns its export resolved. A
	 * pattern's blocks are not in the page's `post_content`, so editing one, to
	 * add a restriction say, leaves every field above untouched while changing
	 * the fragments. Without this the sync would be skipped and Agend would keep
	 * serving the fragments built before the edit. The patterns come from the
	 * block driver itself, so the revision watches exactly the ones the
	 * fragments were built from, under the same guards and budget. Elementor and
	 * classic posts add nothing, and neither does a block page that resolves no
	 * pattern, so their revisions are what they were before block pages existed.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function revision_of( $post ): string {
		$post_id = (int) $post->ID;

		// An unreadable policy gets a marker of its own rather than hashing as
		// null, which is what "none set" hashes as. Otherwise a post whose
		// policy became unreadable would keep its revision, Agend would never
		// re-fetch it, and the 422 that reports the problem would never be seen.
		// This does not throw: the listing walks every document, and one bad
		// policy must not stop the rest of the site syncing.
		$policy = Agend_Content_Access_Policy::STORED_INVALID === Agend_Content_Access_Policy::stored_state( $post_id )
			? 'unreadable'
			: wp_json_encode( Agend_Content_Access_Policy::get_for_post( $post_id ) );
		$parts  = array(
			(string) $post->post_modified_gmt,
			(string) $post->post_status,
			(string) $policy,
			(string) get_post_meta( (int) $post->ID, '_elementor_data', true ),
			(string) $post->post_content,
		);

		$patterns = self::patterns_digest_of( $post );

		if ( '' !== $patterns ) {
			$parts[] = $patterns;
		}

		return hash( 'sha256', implode( '|', $parts ) );
	}

	/**
	 * A digest of the synced patterns a block page's export resolved.
	 *
	 * Each pattern contributes its id, modification time, status and content, in
	 * id order, so the digest does not depend on the order the page references
	 * them and does not count a pattern used twice twice. JSON-encoded rather
	 * than joined, because pattern content can hold any character a separator
	 * could be.
	 *
	 * @param WP_Post $post Post.
	 * @return string Empty when the driver for the post is not the block driver
	 *                or it resolved no pattern.
	 */
	private static function patterns_digest_of( $post ): string {
		$driver = self::driver_for( $post );

		if ( ! $driver instanceof Agend_Content_Access_Block_Parser ) {
			return '';
		}

		$rows = array();

		foreach ( $driver->patterns( $post ) as $pattern ) {
			$rows[] = array(
				(int) $pattern->ID,
				(string) $pattern->post_modified_gmt,
				(string) $pattern->post_status,
				(string) $pattern->post_content,
			);
		}

		if ( array() === $rows ) {
			return '';
		}

		return 'patterns:' . hash( 'sha256', (string) wp_json_encode( $rows ) );
	}

	/**
	 * The driver that owns a post, or null when none does.
	 *
	 * A post holding an Elementor document is the built-in Elementor driver's
	 * whatever the filter returned (see `fragments_of()`); any other post is the
	 * first driver in the filtered list to recognise it. This is the one place
	 * that decides, and both the fragments and the revision ask it, so the
	 * revision is always computed against the driver the fragments are built by.
	 *
	 * @param WP_Post $post Post.
	 * @return Agend_Content_Access_Document_Parser|null
	 */
	private static function driver_for( $post ): ?Agend_Content_Access_Document_Parser {
		$elementor = new Agend_Content_Access_Elementor_Parser();

		if ( $elementor->recognises( $post ) ) {
			return $elementor;
		}

		foreach ( self::parsers() as $parser ) {
			if ( $parser->recognises( $post ) ) {
				return $parser;
			}
		}

		return null;
	}

	/**
	 * The document drivers, in priority order.
	 *
	 * Elementor first, then the block editor. The order matters little for the two
	 * of them, because the block driver declines any post Elementor owns, but it
	 * is what keeps an Elementor page on the Elementor driver if a site's filter
	 * ever swaps their places. A site or a later layer of this plugin adds
	 * another through `agend_content_access_document_parsers`; the list is
	 * walked in order and the first driver to recognise a post wins, so a driver
	 * placed earlier takes precedence over one placed later.
	 *
	 * ONE EXCEPTION: a post holding an Elementor document never reaches this list.
	 * `fragments_of()` hands it to the built-in Elementor parser first, whatever
	 * this filter returned, so the list decides only how every OTHER post is
	 * exported. See `fragments_of()` for why.
	 *
	 * Anything in the filtered list that is not a driver is dropped rather than
	 * trusted: this runs on the connector path, where a fatal would fail the
	 * sync for every post.
	 *
	 * @return Agend_Content_Access_Document_Parser[]
	 */
	public static function parsers(): array {
		$parsers = array(
			new Agend_Content_Access_Elementor_Parser(),
			new Agend_Content_Access_Block_Parser(),
		);

		/**
		 * Filters the document drivers the exporter asks, in order.
		 *
		 * Each driver implements `Agend_Content_Access_Document_Parser`. The
		 * first one whose `recognises()` returns true builds the post's
		 * fragments. A post no driver recognises is exported as one `body`
		 * fragment. A post holding an Elementor document is always parsed by the
		 * built-in Elementor parser and is never offered to this list, so
		 * dropping or reordering that driver here cannot change how it exports.
		 *
		 * @param Agend_Content_Access_Document_Parser[] $parsers Drivers, highest priority first.
		 */
		$filtered = apply_filters( 'agend_content_access_document_parsers', $parsers );

		if ( ! is_array( $filtered ) ) {
			return $parsers;
		}

		return array_values(
			array_filter(
				$filtered,
				static function ( $parser ): bool {
					return $parser instanceof Agend_Content_Access_Document_Parser;
				}
			)
		);
	}

	/**
	 * Ordered content fragments.
	 *
	 * A post holding an Elementor document (a non-blank `_elementor_data`) is
	 * ALWAYS parsed by the built-in Elementor parser, whatever
	 * `agend_content_access_document_parsers` returned. That is deliberate and not
	 * a shortcut. For such a post `post_content` holds the plain text of every
	 * widget, including the ones a policy restricts, and the only thing that says
	 * which widget carries which policy is the Elementor document. A filter that
	 * returns a list without the Elementor driver, or puts another driver ahead of
	 * it that claims the post, would leave the exporter with nothing that
	 * recognises the document, and it would fall through to the `body` fallback and
	 * ship `post_content` as one `inherit` fragment: every restricted widget
	 * published in the open, and the restriction itself gone. So the filter decides
	 * how other posts export and cannot opt an Elementor post out.
	 *
	 * Every other post goes to the first driver in the filtered list to recognise
	 * it. A block editor page becomes one fragment per block subtree that holds
	 * no policy of its own below it, split wherever one does. Anything no driver
	 * recognises, a classic post with no blocks, is a single fragment holding the
	 * whole body with policy `inherit`, the same shape Agend reads a legacy blob
	 * as (Decision 2.8).
	 *
	 * A structural parse error is thrown by the driver, not swallowed. The
	 * alternative is shipping a document whose restricted regions were quietly
	 * dropped, which the projection would then serve in full because it never
	 * learned they existed.
	 *
	 * @param WP_Post    $post   Post.
	 * @param array|null $policy Document policy.
	 * @return array<int, array<string, mixed>>
	 * @throws RuntimeException When a driver recognises the document but cannot parse it.
	 */
	public static function fragments_of( $post, ?array $policy ): array {
		$driver = self::driver_for( $post );

		if ( null !== $driver ) {
			return $driver->fragments( $post );
		}

		return array(
			array(
				'id'       => 'body',
				'position' => 0,
				'type'     => 'html',
				'payload'  => array( 'html' => (string) $post->post_content ),
				'policy'   => array( 'mode' => 'inherit' ),
			),
		);
	}

	/**
	 * Author summary. Name only: an author's email is not content.
	 *
	 * @param WP_Post $post Post.
	 * @return array{name: string}
	 */
	public static function author_of( $post ): array {
		$name = get_the_author_meta( 'display_name', (int) $post->post_author );

		return array( 'name' => (string) $name );
	}

	/**
	 * Featured image URL, or null.
	 *
	 * @param WP_Post $post Post.
	 * @return string|null
	 */
	public static function featured_image_of( $post ): ?string {
		$url = get_the_post_thumbnail_url( $post, 'full' );

		return is_string( $url ) && '' !== $url ? $url : null;
	}

	/**
	 * Public taxonomy terms, grouped by taxonomy.
	 *
	 * Private taxonomies are excluded: they are editorial workflow, not
	 * published metadata, and would become visible through the CMS surface.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string, string[]>
	 */
	public static function taxonomy_of( $post ): array {
		$out = array();

		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( empty( $taxonomy->public ) ) {
				continue;
			}

			$terms = get_the_terms( $post, $taxonomy->name );

			if ( ! is_array( $terms ) ) {
				continue;
			}

			$out[ $taxonomy->name ] = array_values(
				array_map(
					static function ( $term ) {
						return (string) $term->slug;
					},
					$terms
				)
			);
		}

		return $out;
	}

	/**
	 * Migration provenance, when the policy was converted from a legacy rule.
	 *
	 * @param int $post_id Post id.
	 * @return array|null
	 */
	public static function provenance_of( int $post_id ): ?array {
		$stored = get_post_meta( $post_id, '_agend_access_provenance', true );

		return is_array( $stored ) && array() !== $stored ? $stored : null;
	}

	/**
	 * Post language, defaulting to the site locale.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function language_of( $post ): string {
		/**
		 * Filters the language reported for an exported post.
		 *
		 * Multilingual plugins hook this; there is no single core field for it.
		 *
		 * @param string  $language Two-letter language code.
		 * @param WP_Post $post     Post.
		 */
		return (string) apply_filters(
			'agend_content_access_export_language',
			substr( (string) get_locale(), 0, 2 ),
			$post
		);
	}

	/**
	 * Normalises a WordPress GMT date to ISO 8601.
	 *
	 * @param string $gmt_date Date in `Y-m-d H:i:s`, GMT.
	 * @return string|null
	 */
	private static function iso_date( $gmt_date ): ?string {
		$gmt_date = (string) $gmt_date;

		if ( '' === $gmt_date || '0000-00-00 00:00:00' === $gmt_date ) {
			return null;
		}

		return str_replace( ' ', 'T', $gmt_date ) . 'Z';
	}
}

<?php
/**
 * The contract a page-builder driver implements to be exported as fragments.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns one post into the ordered fragments Agend ingests, for one editor.
 *
 * The exporter used to sniff the `_elementor_data` post meta and call the
 * Elementor parser by name, so a second editor had nowhere to plug in. It now
 * walks a list of drivers and asks each whether it recognises the post. The
 * first one that does produces the fragments; a post no driver recognises is
 * exported as a single `body` fragment, which is not a driver but the fallback.
 * The one post the list does not decide is an Elementor document: the built-in
 * Elementor parser always takes it, because `post_content` holds the text of
 * every widget and the fallback would ship it in the open.
 *
 * A driver owns two decisions and nothing else:
 *
 *   - `recognises()`: does this post hold a document in my editor's format?
 *     It must be cheap and must not throw, because the exporter asks every
 *     driver in turn. Answering true for a post that is only PARTLY in the
 *     editor's format is a mistake: the remainder would be dropped silently.
 *
 *   - `fragments()`: flatten that document into fragments, each carrying the
 *     policy folded down its ancestor chain, in the canonical shape
 *     `{id, position, type, payload, policy}`.
 *
 * A document the driver recognises but cannot decompose MUST throw a
 * RuntimeException. The connector turns that into a 422 and Agend keeps the
 * revision it already holds. The alternative, returning the fragments it could
 * parse, ships a document whose restricted regions were quietly dropped, which
 * the projection would then serve in full because it never learned they
 * existed.
 */
interface Agend_Content_Access_Document_Parser {

	/**
	 * Whether this driver owns the post's document.
	 *
	 * @param WP_Post $post Post being exported.
	 * @return bool
	 */
	public function recognises( $post ): bool;

	/**
	 * The post's document as ordered fragments.
	 *
	 * @param WP_Post $post Post being exported.
	 * @return array<int, array<string, mixed>>
	 * @throws RuntimeException When the document cannot be parsed.
	 */
	public function fragments( $post ): array;
}

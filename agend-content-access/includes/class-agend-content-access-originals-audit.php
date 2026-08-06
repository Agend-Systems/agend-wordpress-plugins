<?php
/**
 * Legacy public-original remediation audit.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds migrated protected files whose public WordPress original is still
 * reachable (SPEC-CMS-20260727 US-5.3).
 *
 * The forward path deletes the WordPress copy as part of promotion, so a file
 * attached through the editor leaves nothing behind. This audit exists for the
 * BULK migration path (US-6.1), which converts historical content where the
 * original may have been referenced from several places, hard-linked, copied to
 * a CDN, or simply left in place by a cautious operator.
 *
 * Until that original is gone, the protected copy is decorative: the file is
 * still retrievable by anyone with the old URL, and the access policy governs
 * only the new route. That is the failure this reports.
 *
 * It DELETES NOTHING (criterion 5). A migration that also deleted originals
 * would be irreversible on the strength of a URL check, and a false positive
 * would destroy a file that was legitimately public. Reporting leaves the
 * judgement with a person, and re-running clears the flag once they have acted.
 */
class Agend_Content_Access_Originals_Audit {

	/** Post meta recording the pre-migration public URL, written by US-6.1. */
	const ORIGINAL_URL_META = '_agend_access_original_url';

	/**
	 * Builds the audit rows.
	 *
	 * The reachability probe is injected so the decision logic is testable
	 * without a network, and so a caller can substitute a HEAD request, a
	 * filesystem check, or a stub.
	 *
	 * @param array    $records   Migrated records: each with post_id, fragment_id,
	 *                            asset_id and original_url.
	 * @param callable $reachable fn(string $url): bool
	 * @return array{rows: array<int, array<string, mixed>>, flagged: int, cleared: int}
	 */
	public static function audit( array $records, callable $reachable ): array {
		$rows    = array();
		$flagged = 0;
		$cleared = 0;

		foreach ( $records as $record ) {
			$url = isset( $record['original_url'] ) ? trim( (string) $record['original_url'] ) : '';

			// No recorded original means nothing to remediate. This is the
			// normal state for anything attached through the editor, where
			// promotion already removed the WordPress copy.
			if ( '' === $url ) {
				continue;
			}

			$still_public = (bool) $reachable( $url );

			if ( $still_public ) {
				++$flagged;
			} else {
				++$cleared;
			}

			$rows[] = array(
				'post_id'      => isset( $record['post_id'] ) ? (int) $record['post_id'] : 0,
				'post_title'   => isset( $record['post_title'] ) ? (string) $record['post_title'] : '',
				'fragment_id'  => isset( $record['fragment_id'] ) ? (string) $record['fragment_id'] : '',
				'asset_id'     => isset( $record['asset_id'] ) ? (string) $record['asset_id'] : '',
				'original_url' => $url,
				// `flagged` is the actionable state: the private copy exists AND
				// the public one is still being served, so the protection is not
				// yet real for this file.
				'status'       => $still_public ? 'flagged' : 'clear',
			);
		}

		return array( 'rows' => $rows, 'flagged' => $flagged, 'cleared' => $cleared );
	}

	/**
	 * Whether a URL still serves content.
	 *
	 * A HEAD request, because the audit only needs to know whether the file is
	 * being served, not what it contains. Downloading historical media to
	 * answer that would be wasteful and, on a large library, slow enough that
	 * an operator would stop running it.
	 *
	 * Any transport failure counts as REACHABLE, not clear. A DNS blip or a
	 * timeout is not evidence the file is gone, and treating it as such would
	 * silently clear a flag that still needs acting on. The audit errs toward
	 * telling somebody there is work to do.
	 *
	 * @param string $url URL to probe.
	 * @return bool
	 */
	public static function is_reachable( string $url ): bool {
		$response = wp_remote_head(
			$url,
			array( 'timeout' => 10, 'redirection' => 3 )
		);

		if ( is_wp_error( $response ) ) {
			return true;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		// 2xx and 3xx both mean something is still being served there.
		return $code >= 200 && $code < 400;
	}

	/**
	 * Collects the migrated records the audit runs over.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function collect(): array {
		$posts = get_posts(
			array(
				'post_type'      => Agend_Content_Access_Meta_Box::supported_post_types(),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_key'       => self::ORIGINAL_URL_META,
				'fields'         => 'ids',
			)
		);

		$records = array();

		foreach ( $posts as $post_id ) {
			$originals = get_post_meta( (int) $post_id, self::ORIGINAL_URL_META, true );

			if ( ! is_array( $originals ) ) {
				continue;
			}

			foreach ( $originals as $fragment_id => $entry ) {
				$records[] = array(
					'post_id'      => (int) $post_id,
					'post_title'   => (string) get_the_title( (int) $post_id ),
					'fragment_id'  => (string) $fragment_id,
					'asset_id'     => isset( $entry['asset_id'] ) ? (string) $entry['asset_id'] : '',
					'original_url' => isset( $entry['url'] ) ? (string) $entry['url'] : '',
				);
			}
		}

		return $records;
	}

	/**
	 * Renders the human-readable form (criterion 4).
	 *
	 * @param array $result Audit result.
	 * @return string
	 */
	public static function render_text( array $result ): string {
		$lines = array();

		$lines[] = 'Agend protected originals audit';
		$lines[] = sprintf(
			'%d migrated file(s) checked: %d still public, %d clear.',
			count( $result['rows'] ),
			$result['flagged'],
			$result['cleared']
		);

		if ( 0 === $result['flagged'] ) {
			$lines[] = '';
			$lines[] = 'No public originals remain. Every migrated file is reachable only through Agend.';

			return implode( "\n", $lines ) . "\n";
		}

		$lines[] = '';
		$lines[] = 'STILL PUBLIC. Until these are removed or blocked, the access policy on';
		$lines[] = 'each file is not being enforced: the old URL still serves it to anyone.';
		$lines[] = '';

		foreach ( $result['rows'] as $row ) {
			if ( 'flagged' !== $row['status'] ) {
				continue;
			}

			$lines[] = sprintf(
				'  post %d "%s" / fragment %s / asset %s',
				$row['post_id'],
				$row['post_title'],
				$row['fragment_id'],
				$row['asset_id']
			);
			$lines[] = sprintf( '    %s', $row['original_url'] );
		}

		return implode( "\n", $lines ) . "\n";
	}
}

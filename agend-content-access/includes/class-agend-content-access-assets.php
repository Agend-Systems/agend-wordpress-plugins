<?php
/**
 * Protected file lifecycle: WordPress upload to Agend private storage.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moves a protected file out of WordPress and into Agend
 * (SPEC-CMS-20260727 US-5.1).
 *
 * The editor picks a file with the ordinary WordPress media picker, which is
 * the familiar interface and the reason not to build a bespoke one. That
 * choice has a consequence this class exists to undo: the picker writes the
 * file into `wp-content/uploads`, where it is retrievable by anyone with the
 * URL. A protected download that stays there is not protected, however careful
 * the rest of the system is.
 *
 * So promotion is a MOVE, not a copy: upload the bytes to Agend, record the
 * opaque id, then delete the WordPress attachment. Leaving the original behind
 * would mean two copies with different access rules and only one of them
 * enforced, which is the failure the whole epic is about.
 *
 * The deletion is deliberate and destructive, so it happens only after Agend
 * has confirmed the upload. A failed upload leaves WordPress exactly as it was
 * and reports the error, because losing a customer's file to a network blip
 * would be far worse than briefly having a public copy the editor can retry.
 */
class Agend_Content_Access_Assets {

	/** Widget setting holding the Agend asset id once promoted. */
	const ASSET_ID_KEY = 'agend_asset_id';

	/** Widget settings holding safe display metadata. */
	const FILE_NAME_KEY = 'agend_asset_file_name';
	const MIME_TYPE_KEY = 'agend_asset_mime_type';
	const SIZE_KEY      = 'agend_asset_size_bytes';

	/** Widget setting holding the not-yet-promoted WordPress attachment. */
	const ATTACHMENT_KEY = 'agend_asset_attachment_id';

	/**
	 * Promotes one WordPress attachment into Agend private storage.
	 *
	 * Injected rather than calling the Core function and `wp_delete_attachment`
	 * directly, so the ORDER of operations, which is the part that can lose a
	 * file, is testable without WordPress or a network.
	 *
	 * @param int      $attachment_id WordPress attachment id.
	 * @param callable $read_meta     fn(int): array{path:string,name:string,mime:string}|null
	 * @param callable $upload        fn(string,string,string): array|WP_Error
	 * @param callable $delete        fn(int): mixed
	 * @return array|WP_Error Safe metadata for the widget, or an error.
	 */
	public static function promote(
		int $attachment_id,
		callable $read_meta,
		callable $upload,
		callable $delete
	) {
		$meta = $read_meta( $attachment_id );

		if ( ! is_array( $meta ) || empty( $meta['path'] ) ) {
			return new WP_Error(
				'agend_content_access_attachment_missing',
				__( 'That file could not be found in the media library.', 'agend-content-access' )
			);
		}

		$uploaded = $upload(
			(string) $meta['path'],
			(string) ( $meta['name'] ?? 'download' ),
			(string) ( $meta['mime'] ?? 'application/octet-stream' )
		);

		if ( is_wp_error( $uploaded ) ) {
			// Nothing is deleted. The editor keeps their file and can retry.
			return $uploaded;
		}

		$asset = self::read_asset( $uploaded );

		if ( null === $asset ) {
			// A malformed success is treated as a failure, and again nothing is
			// deleted: without a usable id the upload is unreachable, so
			// removing the local copy would destroy the only retrievable one.
			return new WP_Error(
				'agend_content_access_upload_malformed',
				__( 'Agend accepted the file but did not return an asset id.', 'agend-content-access' )
			);
		}

		// Only now, with a confirmed id in hand, does the public copy go.
		$delete( $attachment_id );

		return $asset;
	}

	/**
	 * Reads the gateway's registration response into the widget's shape.
	 *
	 * A field-by-field allow list. The endpoint returns only safe metadata
	 * today, and copying named fields means a future addition is excluded by
	 * default rather than stored in WordPress until somebody notices.
	 *
	 * @param mixed $response Decoded gateway response.
	 * @return array|null
	 */
	public static function read_asset( $response ): ?array {
		$data = is_array( $response ) && isset( $response['data'] ) && is_array( $response['data'] )
			? $response['data']
			: $response;

		if ( ! is_array( $data ) ) {
			return null;
		}

		$id = isset( $data['id'] ) ? trim( (string) $data['id'] ) : '';

		if ( '' === $id ) {
			return null;
		}

		return array(
			self::ASSET_ID_KEY  => $id,
			self::FILE_NAME_KEY => isset( $data['file_name'] ) ? (string) $data['file_name'] : '',
			self::MIME_TYPE_KEY => isset( $data['mime_type'] ) ? (string) $data['mime_type'] : '',
			self::SIZE_KEY      => isset( $data['size_bytes'] ) ? (int) $data['size_bytes'] : 0,
		);
	}

	/**
	 * The fragment payload for a promoted file.
	 *
	 * `asset_id` is the key ingestion links on, so its name is a cross-repo
	 * contract with `extractAssetLinks` in `@agend/cms`. Changing it here
	 * without changing it there stops every download working, and fails closed
	 * rather than loudly, so it is worth stating.
	 *
	 * No URL and no attachment id are emitted. The id alone is useless without
	 * the authorising download endpoint, which is exactly what makes it safe to
	 * put in a page.
	 *
	 * @param array $settings Widget settings.
	 * @return array|null Payload, or null when the widget holds no promoted file.
	 */
	public static function fragment_payload( array $settings ): ?array {
		$id = isset( $settings[ self::ASSET_ID_KEY ] )
			? trim( (string) $settings[ self::ASSET_ID_KEY ] )
			: '';

		if ( '' === $id ) {
			return null;
		}

		return array(
			'asset_id'   => $id,
			'file_name'  => isset( $settings[ self::FILE_NAME_KEY ] ) ? (string) $settings[ self::FILE_NAME_KEY ] : '',
			'mime_type'  => isset( $settings[ self::MIME_TYPE_KEY ] ) ? (string) $settings[ self::MIME_TYPE_KEY ] : '',
			'size_bytes' => isset( $settings[ self::SIZE_KEY ] ) ? (int) $settings[ self::SIZE_KEY ] : 0,
		);
	}

	/**
	 * Reads an attachment's local file details.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array|null
	 */
	public static function attachment_meta( int $attachment_id ): ?array {
		$path = get_attached_file( $attachment_id );

		if ( ! is_string( $path ) || '' === $path ) {
			return null;
		}

		return array(
			'path' => $path,
			'name' => basename( $path ),
			'mime' => (string) get_post_mime_type( $attachment_id ),
		);
	}

	/**
	 * The local URL a protected download is requested through.
	 *
	 * Deliberately a URL on THIS site, not the gateway and certainly not the
	 * file. The visitor's Agend bearer lives in their WordPress session, so the
	 * request has to pass through here for that bearer to be attached at all;
	 * a link straight to Agend would arrive anonymous and be refused.
	 *
	 * It also means the page contains no Agend hostname, no bucket, and nothing
	 * that survives being cached, copied, or archived.
	 *
	 * @param string $asset_id Agend asset id.
	 * @return string
	 */
	public static function download_url( string $asset_id ): string {
		return rest_url( 'agend-content-access/v1/download/' . rawurlencode( $asset_id ) );
	}

	/**
	 * Promotes any not-yet-promoted file on a saved Elementor document.
	 *
	 * Runs on save because that is the moment the editor has committed to the
	 * choice. Doing it on upload would promote files an editor was only
	 * previewing, and doing it lazily on first view would leave the public copy
	 * in place for however long that took.
	 *
	 * Failures are collected and surfaced rather than thrown: one unreachable
	 * upload must not abort the save of an otherwise-good page, and the
	 * un-promoted widget simply keeps its attachment and can be retried.
	 *
	 * @param int      $post_id Post being saved.
	 * @param callable $promote fn(int): array|WP_Error
	 * @return array{promoted:int,errors:string[]}
	 */
	public static function promote_document( int $post_id, callable $promote ): array {
		$raw = (string) get_post_meta( $post_id, '_elementor_data', true );

		if ( '' === trim( $raw ) ) {
			return array( 'promoted' => 0, 'errors' => array() );
		}

		$document = json_decode( $raw, true );

		if ( ! is_array( $document ) ) {
			return array( 'promoted' => 0, 'errors' => array() );
		}

		$state = array( 'promoted' => 0, 'errors' => array() );

		self::walk_and_promote( $document, $promote, $state );

		if ( $state['promoted'] > 0 ) {
			update_post_meta( $post_id, '_elementor_data', wp_slash( (string) wp_json_encode( $document ) ) );
		}

		return $state;
	}

	/**
	 * Walks a document promoting each pending protected file, by reference.
	 *
	 * @param array    $nodes   Node list, by reference.
	 * @param callable $promote Promotion callable.
	 * @param array    $state   Accumulator, by reference.
	 */
	private static function walk_and_promote( array &$nodes, callable $promote, array &$state ): void {
		foreach ( $nodes as &$node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			if ( isset( $node['settings'] ) && is_array( $node['settings'] ) ) {
				self::promote_node_settings( $node['settings'], $promote, $state );
			}

			if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
				self::walk_and_promote( $node['elements'], $promote, $state );
			}
		}

		unset( $node );
	}

	/**
	 * Promotes one node's pending attachment, if it has one.
	 *
	 * @param array    $settings Settings, by reference.
	 * @param callable $promote  Promotion callable.
	 * @param array    $state    Accumulator, by reference.
	 */
	private static function promote_node_settings( array &$settings, callable $promote, array &$state ): void {
		// Already promoted. The attachment is long gone; nothing to do.
		if ( ! empty( $settings[ self::ASSET_ID_KEY ] ) ) {
			return;
		}

		$attachment = $settings[ self::ATTACHMENT_KEY ] ?? null;

		// Elementor's MEDIA control stores `{ id, url }`.
		$attachment_id = is_array( $attachment ) && isset( $attachment['id'] )
			? (int) $attachment['id']
			: (int) ( is_scalar( $attachment ) ? $attachment : 0 );

		if ( $attachment_id <= 0 ) {
			return;
		}

		$result = $promote( $attachment_id );

		if ( is_wp_error( $result ) ) {
			$state['errors'][] = $result->get_error_message();
			return;
		}

		foreach ( $result as $key => $value ) {
			$settings[ $key ] = $value;
		}

		// Drop the pointer to a file that no longer exists, so a later save
		// cannot try to promote it again and report a spurious failure.
		unset( $settings[ self::ATTACHMENT_KEY ] );

		++$state['promoted'];
	}
}

<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Assets;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-assets.php';

/**
 * Promoting a protected file out of WordPress (SPEC-CMS-20260727 US-5.1).
 *
 * The ORDER of operations is the whole test surface here, because getting it
 * wrong loses a customer's file. Upload first, confirm an id came back, and
 * only then delete the public copy. Every failure path must leave WordPress
 * untouched so the editor can retry.
 */
#[CoversClass( Agend_Content_Access_Assets::class )]
final class AssetsTest extends TestCase {

	private const ASSET_ID = '22222222-2222-2222-2222-222222222222';

	/** @var int[] Attachment ids passed to the delete callback. */
	private array $deleted = array();

	protected function setUp(): void {
		parent::setUp();
		$this->deleted = array();
	}

	private function meta( ?array $override = null ): callable {
		return static function ( int $id ) use ( $override ) {
			if ( null !== $override ) {
				return $override;
			}

			return array(
				'path' => '/tmp/uploads/brief.pdf',
				'name' => 'brief.pdf',
				'mime' => 'application/pdf',
			);
		};
	}

	private function upload( $result ): callable {
		return static function () use ( $result ) {
			return $result;
		};
	}

	private function deleter(): callable {
		return function ( int $id ) {
			$this->deleted[] = $id;
			return true;
		};
	}

	private function okResponse(): array {
		return array(
			'data' => array(
				'id'         => self::ASSET_ID,
				'file_name'  => 'brief.pdf',
				'mime_type'  => 'application/pdf',
				'size_bytes' => 2048,
			),
		);
	}

	#[Test]
	public function a_successful_promotion_records_the_id_and_removes_the_public_copy(): void {
		$result = Agend_Content_Access_Assets::promote(
			99,
			$this->meta(),
			$this->upload( $this->okResponse() ),
			$this->deleter()
		);

		$this->assertSame( self::ASSET_ID, $result[ Agend_Content_Access_Assets::ASSET_ID_KEY ] );
		$this->assertSame( 2048, $result[ Agend_Content_Access_Assets::SIZE_KEY ] );

		// The whole point: no copy is left in wp-content/uploads.
		$this->assertSame( array( 99 ), $this->deleted );
	}

	/**
	 * A network blip must not cost the editor their file. Losing it would be a
	 * far worse outcome than briefly having a public copy they can retry.
	 */
	#[Test]
	public function a_failed_upload_deletes_nothing(): void {
		$result = Agend_Content_Access_Assets::promote(
			99,
			$this->meta(),
			$this->upload( new WP_Error( 'boom', 'Gateway unreachable' ) ),
			$this->deleter()
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array(), $this->deleted );
	}

	/**
	 * A success with no usable id is a failure. Without the id the uploaded
	 * copy is unreachable, so deleting the local one would destroy the only
	 * retrievable version of the file.
	 */
	#[Test]
	public function a_malformed_success_deletes_nothing(): void {
		foreach ( array( array( 'data' => array() ), array( 'data' => array( 'id' => '' ) ), 'not an array' ) as $bad ) {
			$this->deleted = array();

			$result = Agend_Content_Access_Assets::promote(
				99,
				$this->meta(),
				$this->upload( $bad ),
				$this->deleter()
			);

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( array(), $this->deleted );
		}
	}

	#[Test]
	public function a_missing_attachment_never_reaches_the_upload(): void {
		$uploaded = false;

		$result = Agend_Content_Access_Assets::promote(
			99,
			$this->meta( array() ),
			function () use ( &$uploaded ) {
				$uploaded = true;
				return $this->okResponse();
			},
			$this->deleter()
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertFalse( $uploaded );
		$this->assertSame( array(), $this->deleted );
	}

	#[Test]
	public function an_unwrapped_response_body_is_also_accepted(): void {
		// The gateway envelopes in `data`, but a filter may hand back the inner
		// object. Both are read rather than only the enveloped shape.
		$asset = Agend_Content_Access_Assets::read_asset(
			array( 'id' => self::ASSET_ID, 'file_name' => 'x.pdf' )
		);

		$this->assertSame( self::ASSET_ID, $asset[ Agend_Content_Access_Assets::ASSET_ID_KEY ] );
	}

	/**
	 * The allow list. A field the gateway adds later must not start being
	 * stored in WordPress just because it appeared.
	 */
	#[Test]
	public function unknown_response_fields_are_not_copied(): void {
		$asset = Agend_Content_Access_Assets::read_asset(
			array(
				'data' => array(
					'id'           => self::ASSET_ID,
					'file_name'    => 'x.pdf',
					'storage_path' => 'acct/asset/file',
					'bucket'       => 'cms-protected-assets',
				),
			)
		);

		$this->assertSame(
			array(
				Agend_Content_Access_Assets::ASSET_ID_KEY,
				Agend_Content_Access_Assets::FILE_NAME_KEY,
				Agend_Content_Access_Assets::MIME_TYPE_KEY,
				Agend_Content_Access_Assets::SIZE_KEY,
			),
			array_keys( $asset )
		);
	}
}

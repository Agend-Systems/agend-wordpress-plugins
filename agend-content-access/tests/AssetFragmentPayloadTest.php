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
 * The fragment payload a promoted file contributes.
 */
#[CoversClass( Agend_Content_Access_Assets::class )]
final class AssetFragmentPayloadTest extends TestCase {

	private const ASSET_ID = '22222222-2222-2222-2222-222222222222';

	#[Test]
	public function a_promoted_file_emits_the_asset_id_ingestion_links_on(): void {
		$payload = Agend_Content_Access_Assets::fragment_payload(
			array(
				Agend_Content_Access_Assets::ASSET_ID_KEY  => self::ASSET_ID,
				Agend_Content_Access_Assets::FILE_NAME_KEY => 'brief.pdf',
				Agend_Content_Access_Assets::MIME_TYPE_KEY => 'application/pdf',
				Agend_Content_Access_Assets::SIZE_KEY      => 2048,
			)
		);

		// `asset_id` is a cross-repo contract with extractAssetLinks in
		// @agend/cms. Renaming it here without renaming it there stops every
		// download working, and fails closed rather than loudly.
		$this->assertSame( self::ASSET_ID, $payload['asset_id'] );
		$this->assertSame( 'brief.pdf', $payload['file_name'] );
	}

	#[Test]
	public function no_url_or_attachment_id_is_ever_emitted(): void {
		$payload = Agend_Content_Access_Assets::fragment_payload(
			array(
				Agend_Content_Access_Assets::ASSET_ID_KEY   => self::ASSET_ID,
				Agend_Content_Access_Assets::ATTACHMENT_KEY => 4242,
				'url'                                       => 'https://site.test/wp-content/uploads/brief.pdf',
			)
		);

		$this->assertSame(
			array( 'asset_id', 'file_name', 'mime_type', 'size_bytes' ),
			array_keys( $payload )
		);
	}

	#[Test]
	public function a_widget_with_no_promoted_file_contributes_nothing(): void {
		$this->assertNull( Agend_Content_Access_Assets::fragment_payload( array() ) );
		$this->assertNull(
			Agend_Content_Access_Assets::fragment_payload(
				array( Agend_Content_Access_Assets::ASSET_ID_KEY => '   ' )
			)
		);
	}
}

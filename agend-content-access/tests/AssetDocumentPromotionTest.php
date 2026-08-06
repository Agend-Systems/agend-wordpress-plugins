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
 * Promoting every pending file on a saved document.
 */
#[CoversClass( Agend_Content_Access_Assets::class )]
final class AssetDocumentPromotionTest extends TestCase {

	private const ASSET_ID = '22222222-2222-2222-2222-222222222222';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['agend_test_post_meta'] = array();
	}

	private function store( int $post_id, array $document ): void {
		$GLOBALS['agend_test_post_meta'][ $post_id ] = array(
			'_elementor_data' => (string) json_encode( $document ),
		);
	}

	private function document( int $post_id ): array {
		return (array) json_decode(
			(string) $GLOBALS['agend_test_post_meta'][ $post_id ]['_elementor_data'],
			true
		);
	}

	private function fileWidget( string $id, $attachment ): array {
		return array(
			'id'         => $id,
			'elType'     => 'widget',
			'widgetType' => 'agend-protected-file',
			'settings'   => array( 'agend_asset_attachment_id' => $attachment ),
		);
	}

	private function promoter(): callable {
		return static function ( int $attachment_id ) {
			return array(
				Agend_Content_Access_Assets::ASSET_ID_KEY  => self::ASSET_ID,
				Agend_Content_Access_Assets::FILE_NAME_KEY => 'brief.pdf',
				Agend_Content_Access_Assets::MIME_TYPE_KEY => 'application/pdf',
				Agend_Content_Access_Assets::SIZE_KEY      => 2048,
			);
		};
	}

	#[Test]
	public function a_pending_file_is_promoted_and_the_attachment_pointer_dropped(): void {
		// Elementor's MEDIA control stores `{ id, url }`.
		$this->store(
			7,
			array(
				array(
					'id'       => 's1',
					'elType'   => 'section',
					'settings' => array(),
					'elements' => array(
						$this->fileWidget( 'w1', array( 'id' => 42, 'url' => 'https://x/brief.pdf' ) ),
					),
				),
			)
		);

		$result = Agend_Content_Access_Assets::promote_document( 7, $this->promoter() );

		$this->assertSame( 1, $result['promoted'] );
		$this->assertSame( array(), $result['errors'] );

		$settings = $this->document( 7 )[0]['elements'][0]['settings'];

		$this->assertSame( self::ASSET_ID, $settings[ Agend_Content_Access_Assets::ASSET_ID_KEY ] );

		// The pointer to a file that no longer exists is dropped, so a later
		// save cannot retry the promotion and report a spurious failure.
		$this->assertArrayNotHasKey(
			Agend_Content_Access_Assets::ATTACHMENT_KEY,
			$settings
		);
	}

	#[Test]
	public function an_already_promoted_widget_is_left_alone(): void {
		$this->store(
			7,
			array(
				array(
					'id'         => 'w1',
					'elType'     => 'widget',
					'widgetType' => 'agend-protected-file',
					'settings'   => array(
						Agend_Content_Access_Assets::ASSET_ID_KEY => self::ASSET_ID,
					),
				),
			)
		);

		$called = false;

		$result = Agend_Content_Access_Assets::promote_document(
			7,
			function () use ( &$called ) {
				$called = true;
				return array();
			}
		);

		$this->assertSame( 0, $result['promoted'] );
		$this->assertFalse( $called );
	}

	/**
	 * One unreachable upload must not abort the save of an otherwise-good page,
	 * and the un-promoted widget must keep its attachment so it can be retried.
	 */
	#[Test]
	public function a_failed_promotion_is_reported_and_leaves_the_widget_retryable(): void {
		$this->store(
			7,
			array( $this->fileWidget( 'w1', array( 'id' => 42 ) ) )
		);

		$result = Agend_Content_Access_Assets::promote_document(
			7,
			static function () {
				return new WP_Error( 'boom', 'Gateway unreachable' );
			}
		);

		$this->assertSame( 0, $result['promoted'] );
		$this->assertSame( array( 'Gateway unreachable' ), $result['errors'] );

		$settings = $this->document( 7 )[0]['settings'];

		$this->assertArrayHasKey(
			Agend_Content_Access_Assets::ATTACHMENT_KEY,
			$settings
		);
	}

	#[Test]
	public function nested_widgets_are_reached(): void {
		$this->store(
			7,
			array(
				array(
					'id'       => 'k1',
					'elType'   => 'container',
					'settings' => array(),
					'elements' => array(
						array(
							'id'       => 'k2',
							'elType'   => 'container',
							'settings' => array(),
							'elements' => array( $this->fileWidget( 'deep', array( 'id' => 42 ) ) ),
						),
					),
				),
			)
		);

		$result = Agend_Content_Access_Assets::promote_document( 7, $this->promoter() );

		$this->assertSame( 1, $result['promoted'] );
	}

	#[Test]
	public function a_document_with_nothing_pending_is_not_rewritten(): void {
		$original = array(
			array(
				'id'       => 'w1',
				'elType'   => 'widget',
				'settings' => array( 'editor' => '<p>text</p>' ),
			),
		);

		$this->store( 7, $original );
		$before = $GLOBALS['agend_test_post_meta'][7]['_elementor_data'];

		$result = Agend_Content_Access_Assets::promote_document( 7, $this->promoter() );

		$this->assertSame( 0, $result['promoted'] );
		// Untouched, so a save cannot churn `_elementor_data` and invalidate
		// Elementor's caches for nothing.
		$this->assertSame( $before, $GLOBALS['agend_test_post_meta'][7]['_elementor_data'] );
	}

	#[Test]
	public function an_absent_or_unparseable_document_is_a_no_op(): void {
		$this->assertSame(
			array( 'promoted' => 0, 'errors' => array() ),
			Agend_Content_Access_Assets::promote_document( 99, $this->promoter() )
		);

		$GLOBALS['agend_test_post_meta'][8] = array( '_elementor_data' => 'not json' );

		$this->assertSame(
			array( 'promoted' => 0, 'errors' => array() ),
			Agend_Content_Access_Assets::promote_document( 8, $this->promoter() )
		);
	}
}

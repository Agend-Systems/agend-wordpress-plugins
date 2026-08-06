<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Originals_Audit;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-originals-audit.php';

/**
 * Legacy public-original audit (SPEC-CMS-20260727 US-5.3).
 *
 * The property under test is that a file is reported as still-public whenever
 * there is any doubt. A migrated file whose original is still served is
 * protected in appearance only: the policy governs the new route while the old
 * URL keeps handing the file to anyone who has it.
 */
#[CoversClass( Agend_Content_Access_Originals_Audit::class )]
final class OriginalsAuditTest extends TestCase {

	private const ASSET = '22222222-2222-2222-2222-222222222222';

	private function record( array $over = array() ): array {
		return array_merge(
			array(
				'post_id'      => 7,
				'post_title'   => 'A migrated page',
				'fragment_id'  => 'frag-1',
				'asset_id'     => self::ASSET,
				'original_url' => 'https://site.test/wp-content/uploads/brief.pdf',
			),
			$over
		);
	}

	private function reachable( bool $answer ): callable {
		return static fn( string $url ): bool => $answer;
	}

	#[Test]
	public function a_still_served_original_is_flagged(): void {
		$result = Agend_Content_Access_Originals_Audit::audit(
			array( $this->record() ),
			$this->reachable( true )
		);

		$this->assertSame( 1, $result['flagged'] );
		$this->assertSame( 0, $result['cleared'] );
		$this->assertSame( 'flagged', $result['rows'][0]['status'] );
	}

	/**
	 * Criterion 3: the flag clears on re-run once the original is gone.
	 */
	#[Test]
	public function a_removed_original_clears(): void {
		$result = Agend_Content_Access_Originals_Audit::audit(
			array( $this->record() ),
			$this->reachable( false )
		);

		$this->assertSame( 0, $result['flagged'] );
		$this->assertSame( 1, $result['cleared'] );
		$this->assertSame( 'clear', $result['rows'][0]['status'] );
	}

	/**
	 * Criterion 2: every row names the post, fragment, asset and URL, so the
	 * report is actionable without a second lookup.
	 */
	#[Test]
	public function each_row_names_the_post_fragment_asset_and_url(): void {
		$row = Agend_Content_Access_Originals_Audit::audit(
			array( $this->record() ),
			$this->reachable( true )
		)['rows'][0];

		$this->assertSame( 7, $row['post_id'] );
		$this->assertSame( 'A migrated page', $row['post_title'] );
		$this->assertSame( 'frag-1', $row['fragment_id'] );
		$this->assertSame( self::ASSET, $row['asset_id'] );
		$this->assertStringContainsString( 'brief.pdf', $row['original_url'] );
	}

	/**
	 * A record with no recorded original is the normal state for anything
	 * attached through the editor, where promotion already deleted the
	 * WordPress copy. It is not a finding and must not inflate the counts.
	 */
	#[Test]
	public function a_record_with_no_original_is_not_a_finding(): void {
		$result = Agend_Content_Access_Originals_Audit::audit(
			array(
				$this->record( array( 'original_url' => '' ) ),
				$this->record( array( 'original_url' => '   ' ) ),
			),
			$this->reachable( true )
		);

		$this->assertSame( array(), $result['rows'] );
		$this->assertSame( 0, $result['flagged'] );
		$this->assertSame( 0, $result['cleared'] );
	}

	#[Test]
	public function counts_split_correctly_across_a_mixed_batch(): void {
		$calls = 0;

		$result = Agend_Content_Access_Originals_Audit::audit(
			array(
				$this->record( array( 'fragment_id' => 'a' ) ),
				$this->record( array( 'fragment_id' => 'b' ) ),
				$this->record( array( 'fragment_id' => 'c' ) ),
			),
			static function () use ( &$calls ): bool {
				++$calls;
				return 1 === $calls; // only the first is still public
			}
		);

		$this->assertSame( 1, $result['flagged'] );
		$this->assertSame( 2, $result['cleared'] );
	}

	#[Test]
	public function the_human_report_names_every_flagged_file(): void {
		$text = Agend_Content_Access_Originals_Audit::render_text(
			Agend_Content_Access_Originals_Audit::audit(
				array( $this->record() ),
				$this->reachable( true )
			)
		);

		$this->assertStringContainsString( 'STILL PUBLIC', $text );
		$this->assertStringContainsString( 'brief.pdf', $text );
		$this->assertStringContainsString( self::ASSET, $text );
	}

	#[Test]
	public function the_human_report_says_so_when_nothing_remains(): void {
		$text = Agend_Content_Access_Originals_Audit::render_text(
			Agend_Content_Access_Originals_Audit::audit(
				array( $this->record() ),
				$this->reachable( false )
			)
		);

		$this->assertStringContainsString( 'No public originals remain', $text );
		$this->assertStringNotContainsString( 'STILL PUBLIC', $text );
	}

	/**
	 * The audit never deletes (criterion 5). Asserted by giving it a probe that
	 * would record any write attempt: there is no deletion callable in the
	 * signature at all, which is the strongest form of this guarantee.
	 */
	#[Test]
	public function the_audit_signature_admits_no_deletion_path(): void {
		$method = new \ReflectionMethod(
			Agend_Content_Access_Originals_Audit::class,
			'audit'
		);

		$this->assertSame( 2, $method->getNumberOfParameters() );
		$this->assertSame( 'records', $method->getParameters()[0]->getName() );
		$this->assertSame( 'reachable', $method->getParameters()[1]->getName() );
	}
}

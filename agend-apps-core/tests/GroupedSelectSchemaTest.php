<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

/**
 * Five surfaces declare their most important control, the one answering "which
 * field is this?", as a `select` with `groups` rather than `options`. The block
 * inspector read only `options` at first, so every one of those rendered as an
 * empty dropdown and the block was unusable.
 *
 * The JS half of that fix has no test harness in this repo (there is no JS test
 * runner configured), so these tests pin the half that can be tested: that the
 * editor schema endpoint really does hand the inspector resolved, non-empty
 * groups in the shape it expects. A regression that emptied these lists
 * server-side would look identical to the original bug.
 */
#[RunTestsInSeparateProcesses]
final class GroupedSelectSchemaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/filters.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fragments.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/blocks.php';
	}

	/** @return array<string, array{string, string}> */
	public static function groupedSelects(): array {
		return array(
			'filter picker'             => array( 'filter', 'filter' ),
			'field picker on the field' => array( 'record-field', 'field' ),
			'field picker on pills'     => array( 'record-pills', 'field' ),
			'field picker on the image' => array( 'record-image', 'field' ),
			'panel picker'              => array( 'record-block', 'block' ),
		);
	}

	/**
	 * @param string $surface Surface id.
	 * @param string $name    The grouped select's field name.
	 */
	#[Test]
	#[DataProvider( 'groupedSelects' )]
	public function should_serve_resolved_non_empty_groups_to_the_block_inspector( string $surface, string $name ): void {
		$field = $this->fieldFromEditorSchema( $surface, $name );

		self::assertNotNull( $field, "the {$surface} schema should declare a '{$name}' field" );
		self::assertSame( 'select', $field['type'] );
		self::assertIsArray(
			$field['groups'] ?? null,
			"'{$name}' on {$surface} declares a callable option source; the editor schema must resolve it to an array"
		);
		self::assertNotEmpty(
			$field['groups'],
			"'{$name}' on {$surface} resolved to no groups, which renders an empty dropdown and makes the block unusable"
		);
	}

	/**
	 * Every group must carry the `{ label, options }` shape the inspector's
	 * optgroup rendering reads, with at least one option in it.
	 *
	 * @param string $surface Surface id.
	 * @param string $name    The grouped select's field name.
	 */
	#[Test]
	#[DataProvider( 'groupedSelects' )]
	public function should_shape_every_group_as_a_label_and_a_non_empty_option_map( string $surface, string $name ): void {
		$field = $this->fieldFromEditorSchema( $surface, $name );

		foreach ( $field['groups'] as $index => $group ) {
			self::assertArrayHasKey( 'label', $group, "group {$index} on {$surface}.{$name} has no label" );
			self::assertArrayHasKey( 'options', $group, "group {$index} on {$surface}.{$name} has no options" );
			self::assertIsArray( $group['options'] );
			self::assertNotEmpty( $group['options'], "group '{$group['label']}' on {$surface}.{$name} is empty" );
		}
	}

	/**
	 * The `groups` shape is mutually exclusive with `options` in the inspector:
	 * it renders one or the other, so a field carrying both would silently
	 * drop half its choices.
	 *
	 * @param string $surface Surface id.
	 * @param string $name    The grouped select's field name.
	 */
	#[Test]
	#[DataProvider( 'groupedSelects' )]
	public function should_not_also_declare_flat_options_alongside_groups( string $surface, string $name ): void {
		$field = $this->fieldFromEditorSchema( $surface, $name );

		self::assertArrayNotHasKey(
			'options',
			$field,
			"{$surface}.{$name} declares both groups and options; the inspector renders only one of the two"
		);
	}

	/**
	 * One field from a surface's editor schema, or null.
	 *
	 * @param string $surface Surface id.
	 * @param string $name    Field name.
	 * @return array|null
	 */
	private function fieldFromEditorSchema( string $surface, string $name ): ?array {
		foreach ( agend_apps_records_block_editor_schema( $surface )['sections'] ?? array() as $section ) {
			foreach ( $section['fields'] ?? array() as $field ) {
				if ( $name === ( $field['name'] ?? '' ) ) {
					return $field;
				}
			}
		}

		return null;
	}
}

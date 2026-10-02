<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Apps_Log_Store;

/**
 * Keeps written API log rows in memory instead of the database.
 */
final class MemoryLogStore extends Agend_Apps_Log_Store {

	/** @var array<int, array<string, mixed>> */
	public array $rows = array();

	public function insert_many( array $rows ): bool {
		array_push( $this->rows, ...$rows );
		return true;
	}
}

<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * No two plugin files may declare the same class, interface, trait or function.
 *
 * The unit suite loads the plugin file by file, each test requiring only what it
 * exercises, so two files that declare the same name are never loaded together
 * and the suite stays green. The plugin's bootstrap requires every one of them,
 * so the second declaration is a fatal error on a live site, at activation. This
 * reads the declarations instead of loading them, so it sees every file whether
 * or not a test happens to require it.
 *
 * It was written for a real collision: two branches of this plugin each added an
 * `Agend_Content_Access_Fragment_Rules` class, in different places, and nothing
 * in the suite could have said so.
 */
final class ClassDeclarationsTest extends TestCase {

	/** @return string[] Every PHP file the plugin ships, relative to the repository root. */
	private function pluginFiles(): array {
		$root  = AGEND_TESTS_ROOT . '/agend-content-access';
		$files = array( $root . '/agend-content-access.php' );

		foreach ( array( 'includes', 'admin' ) as $dir ) {
			if ( ! is_dir( $root . '/' . $dir ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );

			foreach ( $iterator as $file ) {
				if ( $file->isFile() && 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * What a file declares: classes, interfaces, traits and enums by name, and
	 * functions that are not methods.
	 *
	 * @return string[]
	 */
	private function declarationsIn( string $path ): array {
		return $this->declarationsInSource( (string) file_get_contents( $path ) );
	}

	/**
	 * @param string $source PHP source.
	 * @return string[]
	 */
	private function declarationsInSource( string $source ): array {
		$tokens = token_get_all( $source );
		$kinds  = array( T_CLASS, T_INTERFACE, T_TRAIT );

		if ( defined( 'T_ENUM' ) ) {
			$kinds[] = T_ENUM;
		}

		$found      = array();
		$depth      = 0;
		$class_body = array(); // Depths at which a class body was opened.
		$pending    = false;   // A class-like declaration was seen and its body is not open yet.
		$count      = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( is_array( $token ) ) {
				if ( in_array( $token[0], $kinds, true ) && ! $this->followsNewOrScope( $tokens, $i ) ) {
					$name = $this->nextName( $tokens, $i );

					if ( null !== $name ) {
						$found[] = $name;
						$pending = true;
					}

					continue;
				}

				if ( T_FUNCTION === $token[0] && array() === $class_body ) {
					$name = $this->nextName( $tokens, $i );

					if ( null !== $name ) {
						$found[] = $name . '()';
					}

					continue;
				}

				if ( in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
					++$depth;
				}

				continue;
			}

			if ( '{' === $token ) {
				++$depth;

				if ( $pending ) {
					$class_body[] = $depth;
					$pending      = false;
				}
			} elseif ( '}' === $token ) {
				if ( array() !== $class_body && end( $class_body ) === $depth ) {
					array_pop( $class_body );
				}

				--$depth;
			}
		}

		return $found;
	}

	/** `Foo::class` and `new class` are not declarations. */
	private function followsNewOrScope( array $tokens, int $index ): bool {
		for ( $i = $index - 1; $i >= 0; $i-- ) {
			if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			return is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_NEW, T_DOUBLE_COLON ), true );
		}

		return false;
	}

	/** The identifier after a declaring keyword, skipping the `&` of a function that returns by reference. */
	private function nextName( array $tokens, int $index ): ?string {
		$count = count( $tokens );

		for ( $i = $index + 1; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			if ( '&' === $token ) {
				continue;
			}

			return is_array( $token ) && T_STRING === $token[0] ? $token[1] : null;
		}

		return null;
	}

	#[Test]
	public function no_name_is_declared_by_two_files(): void {
		$declared = array();

		foreach ( $this->pluginFiles() as $path ) {
			foreach ( $this->declarationsIn( $path ) as $name ) {
				$declared[ strtolower( $name ) ][] = str_replace( AGEND_TESTS_ROOT . '/', '', $path );
			}
		}

		$twice = array();

		foreach ( $declared as $name => $files ) {
			if ( count( $files ) > 1 ) {
				$twice[] = $name . ' in ' . implode( ' and ', $files );
			}
		}

		$this->assertSame( array(), $twice, 'declared by more than one file: the plugin would fatal when it loads both' );
	}

	/** The scan is only worth anything if it finds the classes it exists to protect. */
	#[Test]
	public function the_scan_sees_the_classes_it_is_there_to_protect(): void {
		$all = array();

		foreach ( $this->pluginFiles() as $path ) {
			foreach ( $this->declarationsIn( $path ) as $name ) {
				$all[] = $name;
			}
		}

		$counts = array_count_values( $all );

		foreach ( array( 'Agend_Content_Access_Fragment_Rules', 'Agend_Content_Access_Blocks', 'Agend_Content_Access_Frontend', 'Agend_Content_Access_Elementor', 'agend_content_access_bootstrap()' ) as $name ) {
			$this->assertSame( 1, $counts[ $name ] ?? 0, $name . ' is declared exactly once' );
		}

		$this->assertGreaterThan( 20, count( $counts ) );
	}

	/** The scan's own rules, on source it is handed rather than on the plugin. */
	#[Test]
	public function methods_closures_and_class_constants_are_not_declarations(): void {
		$source = <<<'PHP'
<?php
class A {
	public function run() {
		return Foo::class . " {$x}" . array_map( function ( $v ) { return $v; }, array() );
	}
	public static function build() {
		return new class() {
			public function inside() {}
		};
	}
}
function helper() {}
if ( ! function_exists( 'guarded' ) ) {
	function guarded() {}
}
interface I {}
trait T {
	function inTrait() {}
}
final class B extends A {}
PHP;

		$this->assertSame( array( 'A', 'helper()', 'guarded()', 'I', 'T', 'B' ), $this->declarationsInSource( $source ) );
	}
}

<?php
/**
 * PHPUnit bootstrap for the Agend WordPress plugin collection.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

define( 'AGEND_TESTS_ROOT', dirname( __DIR__ ) );

// Plugin files guard on ABSPATH and exit when it is absent, so it has to be
// defined before any of them are required.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', AGEND_TESTS_ROOT . '/' );
}

require_once AGEND_TESTS_ROOT . '/vendor/autoload.php';
require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/doubles.php';
require_once __DIR__ . '/TestCase.php';

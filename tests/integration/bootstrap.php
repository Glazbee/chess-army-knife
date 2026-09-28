<?php
/**
 * Bootstrap for integration tests that run inside a real WordPress install.
 *
 * @package Chess_Army_Knife
 */

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$tests_dir = dirname( __DIR__, 2 ) . '/vendor/wp-phpunit/wp-phpunit';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . dirname( __DIR__ ) . '/wp-tests-config.php' );

require_once $tests_dir . '/includes/functions.php';

// Load the plugin before WordPress finishes booting.
tests_add_filter(
	'muplugins_loaded',
	function () {
		require dirname( __DIR__, 2 ) . '/chess-army-knife.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';

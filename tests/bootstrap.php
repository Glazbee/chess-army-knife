<?php
/**
 * PHPUnit bootstrap: loads the plugin classes without WordPress.
 * WordPress functions are mocked per-test with Brain Monkey.
 *
 * @package Chess_Army_Knife
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'Chess_Army_Knife_VERSION', '0.0.0-test' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

require_once __DIR__ . '/stubs.php';

// Class files register hooks at load time, so Brain Monkey must be active.
Brain\Monkey\setUp();
foreach ( array( 'cache', 'ecf-client', 'lms-client', 'settings-page', 'club-teams-page', 'templates', 'admin-refresh', 'events', 'events-display', 'events-import', 'events-rest', 'berger', 'standings', 'bracket', 'matching', 'swiss-dutch', 'tournament-store', 'tournaments', 'player-selector', 'tournaments-page', 'memberships', 'membership-store', 'member-photos', 'rating-refresh', 'membership-form', 'notification-preferences', 'mailer', 'renewal-reminders', 'member-stats' ) as $file ) {
	$name = 'cache' === $file ? 'chess-army-knife-cache' : $file;
	require_once dirname( __DIR__ ) . "/includes/class-{$name}.php";
}
Brain\Monkey\tearDown();
require_once __DIR__ . '/unit/TestCase.php';

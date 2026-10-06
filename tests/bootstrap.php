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
define( 'WEEK_IN_SECONDS', 604800 );
define( 'DAY_IN_SECONDS', 86400 );

require_once __DIR__ . '/stubs.php';

// Class files register hooks at load time, so Brain Monkey must be active.
Brain\Monkey\setUp();
foreach ( array( 'names', 'cache', 'contrast', 'headings', 'policies', 'form-state', 'rating-chart', 'ecf-client', 'lms-client', 'league-data', 'lms-test', 'settings-page', 'secrets', 'templates', 'admin-refresh', 'events', 'events-display', 'events-import', 'events-rest', 'berger', 'standings', 'bracket', 'matching', 'swiss-dutch', 'tournament-store', 'tournaments', 'player-selector', 'tournaments-page', 'memberships', 'membership-store', 'member-history', 'member-audit', 'member-export', 'access', 'block-help', 'menu', 'member-photos', 'member-requests', 'rating-refresh', 'membership-form', 'notification-preferences', 'mailer', 'renewal-reminders', 'member-stats', 'team-groups', 'teams', 'officers', 'officers-page', 'events-feed', 'squad-review', 'event-results', 'team-suggestions', 'league-games', 'lms-players', 'team-overview', 'leagues-page', 'setup', 'setup-checklist', 'clubs', 'tournament-summary', 'tournament-export', 'rotating-member', 'do-not-record' ) as $file ) {
	$name = 'cache' === $file ? 'chess-army-knife-cache' : $file;
	require_once dirname( __DIR__ ) . "/includes/class-{$name}.php";
}
Brain\Monkey\tearDown();
require_once __DIR__ . '/unit/TestCase.php';

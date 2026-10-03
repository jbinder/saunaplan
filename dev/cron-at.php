<?php
/**
 * Dev only: run the background jobs as if it were another time.
 *   docker compose run --rm -v "$PWD/dev:/dev-seed" cli wp eval-file /dev-seed/cron-at.php "2026-10-04 18:30"
 */

use Saunaplan\Core\Scheduler;
use Saunaplan\Core\Time;

$now       = new DateTimeImmutable( $args[0] ?? 'now', wp_timezone() );
Time::$now = $now;
WP_CLI::line( 'Time: ' . $now->format( 'Y-m-d H:i' ) );
WP_CLI::line( 'Reminders sent: ' . Scheduler::send_reminders( $now ) );
WP_CLI::line( 'Rating requests sent: ' . Scheduler::send_rating_requests( $now ) );

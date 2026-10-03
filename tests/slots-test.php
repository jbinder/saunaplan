<?php
/**
 * Slots, adjacency (consecutive rule) and recurring sessions.
 *
 * Run: php tests/slots-test.php
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../saunaplan/includes/core/class-time.php';
require __DIR__ . '/../saunaplan/includes/core/class-slots.php';
require __DIR__ . '/../saunaplan/includes/core/class-series.php';

use Saunaplan\Core\Series;
use Saunaplan\Core\Slots;

$hours = array();
for ( $d = 1; $d <= 7; $d++ ) {
	$hours[ $d ] = array( 'from' => '08:00', 'to' => '23:00' );
}
$hours[7] = array( 'from' => '', 'to' => '' ); // Sunday closed.

echo "Slots\n";
$slots = Slots::compute( '2026-10-05', $hours, 60 ); // Monday
check( 'hourly 8–23 gives 15 slots', 15, count( $slots ) );
check( 'first slot', '08:00', $slots[0] );
check( 'last slot ends at closing time', '22:00', end( $slots ) );
check( 'closed weekday', array(), Slots::compute( '2026-10-04', $hours, 60 ) );
check( 'closed date', array(), Slots::compute( '2026-10-05', $hours, 60, array( '2026-10-05' ) ) );
check( '90 minutes: last slot must fit', array( '08:00', '09:30' ), Slots::compute( '2026-10-05', array( 1 => array( 'from' => '08:00', 'to' => '12:00' ) ), 90 ) );
$late = Slots::compute( '2026-10-05', array( 1 => array( 'from' => '22:00', 'to' => '24:00' ) ), 60 );
check( 'until midnight', array( '22:00', '23:00' ), $late );

echo "Slots in a row\n";
check( 'single slot', 1, Slots::run_length( array(), '18:00', 60 ) );
check( 'next to one', 2, Slots::run_length( array( '17:00' ), '18:00', 60 ) );
check( 'between two', 3, Slots::run_length( array( '17:00', '19:00' ), '18:00', 60 ) );
check( 'gap breaks the run', 2, Slots::run_length( array( '15:00', '17:00' ), '18:00', 60 ) );
check( 'same slot counted once', 1, Slots::run_length( array( '18:00' ), '18:00', 60 ) );

echo "Recurring sessions\n";
$series = (object) array(
	'active'     => true,
	'weekdays'   => array( 2 ),
	'interval_n' => 1,
	'start_date' => '2026-10-01',
	'end_date'   => null,
	'start_time' => '19:00',
	'slots'      => 2,
);
check( 'Tuesday', true, Series::occurs_on( $series, '2026-10-06' ) );
check( 'Wednesday', false, Series::occurs_on( $series, '2026-10-07' ) );
check( 'before start', false, Series::occurs_on( $series, '2026-09-29' ) );
$series->end_date = '2026-10-10';
check( 'after end', false, Series::occurs_on( $series, '2026-10-13' ) );
$series->end_date   = null;
$series->interval_n = 2;
check( 'every 2 weeks: first week', true, Series::occurs_on( $series, '2026-10-06' ) );
check( 'every 2 weeks: off week', false, Series::occurs_on( $series, '2026-10-13' ) );
check( 'every 2 weeks: third week', true, Series::occurs_on( $series, '2026-10-20' ) );
$series->weekdays = array( 2, 5 ); // Tue + Fri, start Thu 1 Oct: first is Fri 2 Oct.
check( 'every 2 weeks, two weekdays: Friday of the start week', true, Series::occurs_on( $series, '2026-10-02' ) );
check( 'every 2 weeks, two weekdays: next week off', false, Series::occurs_on( $series, '2026-10-06' ) );
check( 'every 2 weeks, two weekdays: week after', true, Series::occurs_on( $series, '2026-10-13' ) );
$series->weekdays = array( 2 );
$series->active = false;
check( 'inactive', false, Series::occurs_on( $series, '2026-10-06' ) );
check( 'times of a 2-slot series', array( '19:00', '20:00' ), Series::times( $series, 60 ) );
$series->start_time = '23:00';
check( 'times stop at midnight', array( '23:00' ), Series::times( $series, 60 ) );

finish();

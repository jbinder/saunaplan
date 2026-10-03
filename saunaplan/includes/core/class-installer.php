<?php
namespace Saunaplan\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Creates/updates database tables and handles (de)activation.
 */
class Installer {

	const DB_VERSION = '1';

	public static function activate() {
		self::create_tables();
		Scheduler::schedule();
	}

	public static function deactivate() {
		Scheduler::unschedule();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'saunaplan_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$t       = array();
		foreach ( array( 'units', 'residents', 'sessions', 'bookings', 'participants', 'waitlist', 'ratings', 'series' ) as $name ) {
			$t[ $name ] = Repository::table( $name );
		}

		dbDelta(
			"CREATE TABLE {$t['units']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  stair varchar(50) NOT NULL DEFAULT '',
  door varchar(50) NOT NULL DEFAULT '',
  code_hash char(64) NULL DEFAULT NULL,
  code_set_at datetime NULL DEFAULT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY stair_door (stair,door),
  UNIQUE KEY code_hash (code_hash)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$t['residents']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  unit_id bigint(20) unsigned NOT NULL,
  provider varchar(30) NOT NULL DEFAULT '',
  external_id varchar(190) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  email_pending varchar(190) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  last_seen_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY unit_id (unit_id),
  KEY email (email),
  KEY external (provider,external_id)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$t['sessions']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  resident_id bigint(20) unsigned NOT NULL,
  token_hash char(64) NOT NULL,
  created_at datetime NOT NULL,
  last_seen_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY resident_id (resident_id)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$t['bookings']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slot_date date NOT NULL,
  slot_time time NOT NULL,
  duration_min smallint(5) unsigned NOT NULL DEFAULT 60,
  mode varchar(30) NOT NULL DEFAULT '',
  attributes text NULL,
  title varchar(100) NOT NULL DEFAULT '',
  owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
  unit_id bigint(20) unsigned NOT NULL DEFAULT 0,
  series_id bigint(20) unsigned NOT NULL DEFAULT 0,
  cancelled_at datetime NULL DEFAULT NULL,
  reminder_sent_at datetime NULL DEFAULT NULL,
  rating_request_sent_at datetime NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY slot (slot_date,slot_time),
  KEY unit_id (unit_id),
  KEY series_id (series_id)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$t['participants']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  booking_id bigint(20) unsigned NOT NULL,
  resident_id bigint(20) unsigned NOT NULL DEFAULT 0,
  unit_id bigint(20) unsigned NOT NULL DEFAULT 0,
  counts varchar(255) NOT NULL DEFAULT '',
  total smallint(5) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY booking_id (booking_id),
  KEY unit_id (unit_id),
  KEY resident_id (resident_id)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$t['waitlist']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slot_date date NOT NULL,
  slot_time time NOT NULL,
  resident_id bigint(20) unsigned NOT NULL,
  unit_id bigint(20) unsigned NOT NULL DEFAULT 0,
  notified_at datetime NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slot_resident (slot_date,slot_time,resident_id)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$t['ratings']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  booking_id bigint(20) unsigned NULL DEFAULT NULL,
  unit_id bigint(20) unsigned NOT NULL DEFAULT 0,
  resident_id bigint(20) unsigned NOT NULL DEFAULT 0,
  slot_date date NOT NULL,
  answers varchar(500) NOT NULL DEFAULT '',
  issues varchar(255) NOT NULL DEFAULT '',
  comment text NULL,
  quick tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY booking_unit (booking_id,unit_id),
  KEY slot_date (slot_date)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$t['series']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(100) NOT NULL DEFAULT '',
  mode varchar(30) NOT NULL DEFAULT '',
  attributes text NULL,
  weekdays varchar(20) NOT NULL DEFAULT '',
  interval_n smallint(5) unsigned NOT NULL DEFAULT 1,
  start_date date NOT NULL,
  end_date date NULL DEFAULT NULL,
  start_time time NOT NULL,
  slots smallint(5) unsigned NOT NULL DEFAULT 1,
  active tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id)
) {$charset};"
		);

		update_option( 'saunaplan_db_version', self::DB_VERSION );
	}
}

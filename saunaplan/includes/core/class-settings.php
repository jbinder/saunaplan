<?php
namespace Saunaplan\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings, stored as one option array (core keys + room profile keys).
 */
class Settings {

	const OPTION = 'saunaplan_settings';

	/** @var array|null Request cache. */
	private static $cache = null;

	public static function core_defaults(): array {
		$hours = array();
		for ( $day = 1; $day <= 7; $day++ ) {
			$hours[ $day ] = array( 'from' => '08:00', 'to' => '23:00' );
		}
		return array(
			'language'         => '',
			'page_id'          => 0,
			'unlisted'         => 1,
			'from_name'        => '',
			'from_email'       => '',
			'admin_recipients' => (string) get_option( 'admin_email' ),
			'slot_minutes'     => 60,
			'hours'            => $hours,
			'closed_dates'     => '',
			'capacity'         => 6,
			'horizon_days'     => 14,
			'monthly_limit'    => 4,
			'max_consecutive'  => 2,
			'rating_gate'      => 1,
			'reminder_hours'   => 3,
			'retention_days'   => 90,
			'session_days'     => 180,
			'delete_data'      => 0,
		);
	}

	public static function defaults(): array {
		return array_merge( self::core_defaults(), Room::profile()->settings_defaults(), Mailer::default_templates() );
	}

	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$saved = get_option( self::OPTION, array() );
		$all   = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		// Unchanged email templates follow the plugin language.
		foreach ( Mailer::default_templates() as $key => $default ) {
			if ( self::is_default_template( $key, (string) $all[ $key ] ) ) {
				$all[ $key ] = $default;
			}
		}
		self::$cache = $all;
		return $all;
	}

	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/** Whether a template text is empty or one of the shipped defaults (in any language). */
	public static function is_default_template( string $key, string $value ): bool {
		static $variants = null;
		if ( null === $variants ) {
			$variants = array();
			foreach ( array_keys( I18n::languages() ) as $locale ) {
				foreach ( I18n::with_locale( $locale, array( Mailer::class, 'default_templates' ) ) as $k => $text ) {
					$variants[ $k ][] = self::normalize( $text );
				}
			}
		}
		$value = self::normalize( $value );
		return '' === $value || in_array( $value, $variants[ $key ] ?? array(), true );
	}

	private static function normalize( string $text ): string {
		return trim( str_replace( "\r\n", "\n", $text ) );
	}

	public static function page_url(): string {
		$id = (int) self::get( 'page_id' );
		if ( $id && 'publish' === get_post_status( $id ) ) {
			return (string) get_permalink( $id );
		}
		return home_url( '/' );
	}

	/** @return string[] */
	public static function admin_recipients(): array {
		return array_values( array_filter( preg_split( '/[\s,;]+/', (string) self::get( 'admin_recipients' ) ), 'is_email' ) );
	}

	/** @return string[] Closed dates (Y-m-d). */
	public static function closed_dates(): array {
		$dates = array();
		foreach ( preg_split( '/[\s,;]+/', (string) self::get( 'closed_dates' ) ) as $date ) {
			if ( Time::is_date( $date ) ) {
				$dates[] = $date;
			}
		}
		return $dates;
	}

	public static function sanitize( $in ): array {
		$in  = is_array( $in ) ? $in : array(); // options.php already unslashed the input.
		$def = self::core_defaults();
		$out = array();

		$out['language']   = isset( I18n::languages()[ $in['language'] ?? '' ] ) ? $in['language'] : '';
		$out['page_id']    = absint( $in['page_id'] ?? 0 );
		$out['unlisted']   = empty( $in['unlisted'] ) ? 0 : 1;
		$out['from_name']  = sanitize_text_field( $in['from_name'] ?? '' );
		$from_email        = sanitize_email( $in['from_email'] ?? '' );
		$out['from_email'] = is_email( $from_email ) ? $from_email : '';

		$recipients              = array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) ( $in['admin_recipients'] ?? '' ) ) );
		$out['admin_recipients'] = implode( "\n", array_filter( $recipients, 'is_email' ) );

		$minutes             = absint( $in['slot_minutes'] ?? 0 );
		$out['slot_minutes'] = in_array( $minutes, array( 30, 45, 60, 90, 120 ), true ) ? $minutes : $def['slot_minutes'];

		$out['hours'] = array();
		for ( $day = 1; $day <= 7; $day++ ) {
			$row  = (array) ( $in['hours'][ $day ] ?? array() );
			$from = self::sanitize_time( $row['from'] ?? '', '' );
			$to   = self::sanitize_time( $row['to'] ?? '', '' );
			if ( '24:00' === trim( (string) ( $row['to'] ?? '' ) ) ) {
				$to = '24:00';
			}
			$out['hours'][ $day ] = ( '' !== $from && '' !== $to && $from < $to ) ? array( 'from' => $from, 'to' => $to ) : array( 'from' => '', 'to' => '' );
		}

		$dates               = array_filter( preg_split( '/[\s,;]+/', (string) ( $in['closed_dates'] ?? '' ) ), array( Time::class, 'is_date' ) );
		$dates               = array_unique( $dates );
		sort( $dates );
		$out['closed_dates'] = implode( "\n", $dates );

		$out['capacity']        = max( 1, min( 50, absint( $in['capacity'] ?? $def['capacity'] ) ) );
		$out['horizon_days']    = max( 1, min( 60, absint( $in['horizon_days'] ?? $def['horizon_days'] ) ) );
		$out['monthly_limit']   = min( 100, absint( $in['monthly_limit'] ?? 0 ) );
		$out['max_consecutive'] = min( 24, absint( $in['max_consecutive'] ?? 0 ) );
		$out['rating_gate']     = empty( $in['rating_gate'] ) ? 0 : 1;
		$out['reminder_hours']  = min( 72, absint( $in['reminder_hours'] ?? 0 ) );
		$out['retention_days']  = max( 7, min( 3650, absint( $in['retention_days'] ?? $def['retention_days'] ) ) );
		$out['session_days']    = max( 1, min( 730, absint( $in['session_days'] ?? $def['session_days'] ) ) );
		$out['delete_data']     = empty( $in['delete_data'] ) ? 0 : 1;

		$out = array_merge( $out, Room::profile()->sanitize_settings( $in ) );

		foreach ( array_keys( Mailer::default_templates() ) as $key ) {
			$value = (string) ( $in[ $key ] ?? '' );
			$value = substr( $key, -8 ) === '_subject' ? sanitize_text_field( $value ) : sanitize_textarea_field( $value );
			// Store defaults as empty, so they keep following the language setting.
			$out[ $key ] = self::is_default_template( $key, $value ) ? '' : $value;
		}

		self::flush();
		return $out;
	}

	public static function sanitize_time( $value, string $fallback ): string {
		$value = substr( trim( (string) $value ), 0, 5 );
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : $fallback;
	}
}

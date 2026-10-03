<?php
namespace Saunaplan\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks into WordPress' personal data export/erase tools (by email address).
 * Bookings themselves only know the flat, not the person.
 */
class Privacy {

	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
	}

	public static function register_exporter( $exporters ) {
		$exporters['saunaplan'] = array(
			'exporter_friendly_name' => __( 'Saunaplan', 'saunaplan' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['saunaplan'] = array(
			'eraser_friendly_name' => __( 'Saunaplan', 'saunaplan' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	public static function export( $email, $page = 1 ) {
		$items = array();
		foreach ( Repository::residents_by_email( $email ) as $resident ) {
			$unit = Repository::unit( (int) $resident->unit_id );
			$data = array(
				array( 'name' => __( 'Flat', 'saunaplan' ), 'value' => Units::label( $unit ) ),
				array( 'name' => __( 'Email', 'saunaplan' ), 'value' => $resident->email ),
				array( 'name' => __( 'Signed in since (UTC)', 'saunaplan' ), 'value' => $resident->created_at ),
			);
			foreach ( Repository::participations_by_resident( (int) $resident->id ) as $p ) {
				$booking = Repository::booking( $p->booking_id );
				if ( $booking ) {
					$data[] = array(
						'name'  => __( 'Booking', 'saunaplan' ),
						'value' => $booking->slot_date . ' ' . $booking->slot_time . ' (' . $booking->mode . ', ' . Room::format_counts( $p->counts ) . ')' . ( $booking->cancelled_at ? ' – ' . __( 'cancelled', 'saunaplan' ) : '' ),
					);
				}
			}
			$items[] = array(
				'group_id'    => 'saunaplan',
				'group_label' => __( 'Saunaplan', 'saunaplan' ),
				'item_id'     => 'saunaplan-resident-' . $resident->id,
				'data'        => $data,
			);
		}
		return array( 'data' => $items, 'done' => true );
	}

	/** Removes the person (address, sessions, waiting list). Flat bookings stay anonymous until retention deletes them. */
	public static function erase( $email, $page = 1 ) {
		$residents = Repository::residents_by_email( $email );
		foreach ( $residents as $resident ) {
			Repository::delete_resident( (int) $resident->id );
		}
		return array(
			'items_removed'  => count( $residents ) > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}

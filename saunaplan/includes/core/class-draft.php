<?php
namespace Saunaplan\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A booking or join request as seen by the rules.
 */
class Draft {

	/** @var string 'book' or 'join'. */
	public $action = 'book';

	/** @var string */
	public $date = '';

	/** @var string "HH:MM" */
	public $time = '';

	/** @var string Mode of the new booking, or of the booking being joined. */
	public $mode = '';

	/** @var array<string,int> People per category. */
	public $counts = array();

	/** @var array<string,bool> */
	public $attributes = array();

	/** @var object Resident row. */
	public $resident;

	/** @var int */
	public $unit_id = 0;

	/** @var array|null Slot from Schedule::slot(). */
	public $slot = null;

	/** @var \DateTimeImmutable */
	public $now;

	public function total(): int {
		return array_sum( $this->counts );
	}

	public function mode_def(): ?array {
		return Room::mode( $this->mode );
	}

	public function start(): \DateTimeImmutable {
		return Time::at( $this->date, $this->time );
	}

	/** People already in the slot's booking. */
	public function current_total(): int {
		return $this->slot ? Schedule::total( $this->slot['participants'] ) : 0;
	}
}

<?php
namespace Saunaplan\Sauna;

use Saunaplan\Core\Room;
use Saunaplan\Core\Room_Profile;
use Saunaplan\Core\Schedule;
use Saunaplan\Core\Settings;
use Saunaplan\Core\Time;

defined( 'ABSPATH' ) || exit;

/**
 * The sauna: private or open sessions (women / men / everyone), Aufguss,
 * "keep warm" for the people before you, and cooldown instructions.
 */
class Profile implements Room_Profile {

	public function __construct() {
		add_action( 'saunaplan_enqueue', array( $this, 'enqueue' ) );
	}

	public function enqueue() {
		wp_enqueue_style( 'saunaplan-sauna', SAUNAPLAN_URL . 'assets/css/sauna.css', array( 'saunaplan' ), SAUNAPLAN_VERSION );
	}

	public function label(): string {
		return __( 'Sauna', 'saunaplan' );
	}

	public function modes(): array {
		return array(
			'closed'      => array(
				'label'               => __( 'Private', 'saunaplan' ),
				'short'               => __( 'Private', 'saunaplan' ),
				'description'         => __( 'Only the flat that booked. Others can join the waiting list.', 'saunaplan' ),
				'icon'                => '🔒',
				'joinable'            => false,
				'counts_toward_limit' => true,
				'requires_people'     => false,
			),
			'open_all'    => array(
				'label'           => __( 'Open for everyone', 'saunaplan' ),
				'short'           => __( 'Open · everyone', 'saunaplan' ),
				'description'     => __( 'Neighbours can join you.', 'saunaplan' ),
				'icon'            => '👥',
				'joinable'        => true,
				'requires_people' => true,
			),
			'open_female' => array(
				'label'           => __( 'Open for women', 'saunaplan' ),
				'short'           => __( 'Open · women', 'saunaplan' ),
				'description'     => __( 'Only women can join.', 'saunaplan' ),
				'icon'            => '♀',
				'joinable'        => true,
				'requires_people' => true,
				'categories'      => array( 'female' ),
			),
			'open_male'   => array(
				'label'           => __( 'Open for men', 'saunaplan' ),
				'short'           => __( 'Open · men', 'saunaplan' ),
				'description'     => __( 'Only men can join.', 'saunaplan' ),
				'icon'            => '♂',
				'joinable'        => true,
				'requires_people' => true,
				'categories'      => array( 'male' ),
			),
		);
	}

	public function categories(): array {
		return array(
			'female'  => array( 'label' => __( 'Women', 'saunaplan' ), 'symbol' => '♀' ),
			'male'    => array( 'label' => __( 'Men', 'saunaplan' ), 'symbol' => '♂' ),
			'diverse' => array( 'label' => __( 'Diverse', 'saunaplan' ), 'symbol' => '⚧' ),
		);
	}

	public function fields(): array {
		return array(
			'aufguss'   => array(
				'label' => __( 'Aufguss', 'saunaplan' ),
				'help'  => __( 'There will be an Aufguss (water with essential oils on the stones).', 'saunaplan' ),
				'icon'  => '💦',
				'modes' => array( 'open_all', 'open_female', 'open_male' ),
			),
			'keep_warm' => array(
				'label' => __( 'Keep warm', 'saunaplan' ),
				'help'  => __( 'Asks the people before you to leave the sauna on, so it is hot when you arrive.', 'saunaplan' ),
				'icon'  => '🔥',
				'modes' => array( 'closed', 'open_all', 'open_female', 'open_male' ),
			),
		);
	}

	public function rating_questions(): array {
		return array(
			'scales' => array(
				'clean' => __( 'Was it clean?', 'saunaplan' ),
				'ready' => __( 'Was it warm and ready?', 'saunaplan' ),
			),
			'issues' => array(
				'dirty'  => __( 'Dirty', 'saunaplan' ),
				'cold'   => __( 'Not heated', 'saunaplan' ),
				'broken' => __( 'Something broken', 'saunaplan' ),
				'items'  => __( 'Things left behind', 'saunaplan' ),
				'late'   => __( 'Previous people stayed too long', 'saunaplan' ),
				'other'  => __( 'Other problem', 'saunaplan' ),
			),
		);
	}

	public function badges( array $booking ): array {
		$badges = array();
		foreach ( $this->fields() as $key => $field ) {
			if ( ! empty( $booking['attributes'][ $key ] ) ) {
				$badges[] = array( 'icon' => $field['icon'], 'label' => $field['label'] );
			}
		}
		return $badges;
	}

	/* ------------------------------------------------------- Instructions */

	public function cooldown_text(): string {
		$text = trim( (string) Settings::get( 'cooldown_text' ) );
		return '' !== $text ? $text : $this->default_cooldown_text();
	}

	public function keep_warm_text(): string {
		$text = trim( (string) Settings::get( 'keep_warm_text' ) );
		return '' !== $text ? $text : $this->default_keep_warm_text();
	}

	public function default_cooldown_text(): string {
		return __( 'When you leave: switch off the oven, leave the door and the vent open so the sauna can dry, wipe the benches and take your towels and rubbish with you.', 'saunaplan' );
	}

	public function default_keep_warm_text(): string {
		return __( 'The people after you asked to keep the sauna warm: please leave the oven on and close the door. Still wipe the benches and take your towels and rubbish with you.', 'saunaplan' );
	}

	public function same_flat_text(): string {
		return __( 'Your flat also has the next slot – leave the oven on.', 'saunaplan' );
	}

	/** What the people in a booking should do when they leave, depending on the next slot. */
	public function leaving_instructions( $booking ): string {
		$end  = Time::end( $booking );
		$slot = $end->format( 'Y-m-d' ) === $booking->slot_date ? Schedule::slot( $booking->slot_date, $end->format( 'H:i' ) ) : null;
		$next = $slot ? Schedule::occupancy( $slot ) : null;
		if ( $next && Room::BLOCKED !== $next['mode'] ) {
			if ( $slot['booking'] && $slot['booking']->owner_id && $booking->unit_id && $slot['booking']->unit_id === $booking->unit_id ) {
				return $this->same_flat_text();
			}
			if ( ! empty( $next['attributes']['keep_warm'] ) ) {
				return $this->keep_warm_text();
			}
		}
		return $this->cooldown_text();
	}

	public function mail_vars( $booking, string $template ): array {
		$mode  = Room::mode( $booking->mode );
		$parts = array( $mode ? $mode['label'] : $booking->mode );
		if ( '' !== (string) $booking->title ) {
			$parts[] = $booking->title;
		}
		foreach ( $this->badges( array( 'attributes' => $booking->attributes ) ) as $badge ) {
			$parts[] = $badge['icon'] . ' ' . $badge['label'];
		}
		$counts = Room::format_counts( Schedule::sum_counts( \Saunaplan\Core\Repository::participants( $booking->id ) ) );
		if ( '' !== $counts ) {
			$parts[] = $counts;
		}
		return array(
			'details'      => implode( ' · ', $parts ),
			'instructions' => 'reminder' === $template ? $this->leaving_instructions( $booking ) : '',
		);
	}

	public function notes_for_participants( $booking ): array {
		$notes = array( $this->leaving_instructions( $booking ) );
		if ( ! empty( $booking->attributes['keep_warm'] ) ) {
			$notes[] = __( 'You asked the people before you to keep the sauna warm.', 'saunaplan' );
		}
		return $notes;
	}

	public function rules(): array {
		return array();
	}

	/* ------------------------------------------------------------ Settings */

	public function settings_defaults(): array {
		return array(
			'cooldown_text'  => '',
			'keep_warm_text' => '',
		);
	}

	public function sanitize_settings( array $in ): array {
		$out = array();
		foreach ( array( 'cooldown_text' => $this->default_cooldown_text(), 'keep_warm_text' => $this->default_keep_warm_text() ) as $key => $default ) {
			$value = trim( sanitize_textarea_field( (string) ( $in[ $key ] ?? '' ) ) );
			// Unchanged defaults stay empty, so they follow the language setting.
			$out[ $key ] = $value === $default ? '' : $value;
		}
		return $out;
	}

	public function render_settings( array $settings, string $option ): void {
		$rows = array(
			'cooldown_text'  => array( __( 'When leaving (cooldown)', 'saunaplan' ), $this->default_cooldown_text(), __( 'Shown in reminders and with the own booking, unless the next people asked to keep it warm.', 'saunaplan' ) ),
			'keep_warm_text' => array( __( 'When leaving (keep warm)', 'saunaplan' ), $this->default_keep_warm_text(), __( 'Used instead when the next booking asks to keep the sauna warm.', 'saunaplan' ) ),
		);
		foreach ( $rows as $key => $row ) {
			printf(
				'<tr><th scope="row"><label for="sp-%1$s">%2$s</label></th><td><textarea id="sp-%1$s" class="large-text" rows="3" name="%3$s[%1$s]" placeholder="%4$s">%5$s</textarea><p class="description">%6$s %7$s</p></td></tr>',
				esc_attr( $key ),
				esc_html( $row[0] ),
				esc_attr( $option ),
				esc_attr( $row[1] ),
				esc_textarea( (string) ( $settings[ $key ] ?? '' ) ),
				esc_html( $row[2] ),
				esc_html__( 'Leave empty to use the text shown in grey.', 'saunaplan' )
			);
		}
	}

	public function default_templates(): array {
		return array(
			'tpl_reminder_subject' => __( '🔥 Sauna reminder: {date}, {time}', 'saunaplan' ),
		);
	}
}

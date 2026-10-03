<?php
namespace Saunaplan\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Describes one kind of room. The core asks the profile for everything
 * room-specific (modes, people categories, extra booking fields, rating
 * questions, mail texts) and never checks such details itself.
 */
interface Room_Profile {

	/** Short name of the room, used in texts (e.g. "Party room"). */
	public function label(): string;

	/**
	 * Booking modes, keyed by mode ID.
	 *
	 * Each mode: label, short (list label), description, icon,
	 * joinable (others may join), counts_toward_limit (monthly limit),
	 * requires_people (a number of people must be given),
	 * categories (allowed category keys).
	 *
	 * @return array<string,array>
	 */
	public function modes(): array;

	/**
	 * People categories, keyed by ID: label, symbol.
	 *
	 * @return array<string,array{label:string,symbol:string}>
	 */
	public function categories(): array;

	/**
	 * Extra yes/no booking fields, keyed by ID: label, help, icon,
	 * modes (mode IDs the field applies to).
	 *
	 * @return array<string,array>
	 */
	public function fields(): array;

	/**
	 * Rating form: scales (key => label, answered 1–3) and issues (key => label).
	 *
	 * @return array{scales:array<string,string>,issues:array<string,string>}
	 */
	public function rating_questions(): array;

	/**
	 * Small badges shown with a booking in the list, e.g. icons for extra fields.
	 *
	 * @param array $booking Public booking data (mode, attributes, title).
	 * @return array<int,array{icon:string,label:string}>
	 */
	public function badges( array $booking ): array;

	/**
	 * Placeholder values for a booking mail, at least {details} and {instructions}.
	 *
	 * @param object $booking  Booking row.
	 * @param string $template Template key (confirmation, reminder …).
	 * @return array<string,string>
	 */
	public function mail_vars( $booking, string $template ): array;

	/**
	 * Notes for the people in a booking, shown on their own bookings.
	 *
	 * @return string[]
	 */
	public function notes_for_participants( $booking ): array;

	/** Additional rules, checked after the core rules. @return Rule[] */
	public function rules(): array;

	/** Defaults for profile settings (merged into the plugin settings). */
	public function settings_defaults(): array;

	/** Sanitize the profile's own settings from the settings form. */
	public function sanitize_settings( array $in ): array;

	/** Print the profile's own settings as rows of a form table. */
	public function render_settings( array $settings, string $option ): void;

	/** Overrides for the default mail templates (key => text). */
	public function default_templates(): array;
}

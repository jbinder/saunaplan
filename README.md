# Saunaplan

WordPress plugin for booking the building's sauna. Residents book hourly slots privately or as open sessions their neighbours can join. Waiting lists, Aufguss, "keep warm", reminders with cooldown instructions, quick ratings and fair-use rules are included. It's built for privacy and for people who aren't tech-savvy.

The plugin lives in [`saunaplan/`](saunaplan/).

## Features

- **Booking modes:** *Private* (only the booking flat; others can join the waiting list), *Open for everyone*, *Open for women*, *Open for men*. For open sessions the number of people is required (♀ / ♂ / ⚧). Others can join until the capacity is reached, but only in the categories the mode allows.
- **Aufguss** (open sessions) and **Keep warm**: the people in the slot *before* see in their reminder and on their booking that they should leave the oven on. Otherwise they get the cooldown instructions. If a flat also has the next slot, it is told to leave the oven on.
- **Waiting list** for private or full sessions. When a place becomes free, everyone on the list gets an email at once, and whoever books first gets the place.
- **Week calendar** as the default view (Mon–Sun columns, one row per slot, ‹ › to change week). A *List* view is one tap away, and each device remembers the choice.
- **What others see:** mode, number of people per category, waiting list length, badges. They never see which flat booked or any names.
- **Fair-use rules:** max. private bookings per flat and month (default 4; open sessions don't count), max. slots in a row per flat (default 2), booking horizon (default 14 days), capacity (default 6).
- **Rating in ~10 seconds:** one tap *Everything was fine*, or smileys for "clean?" and "warm and ready?", issue chips and a comment. It is requested by email after the session and required before the next booking. Ratings with issues or comments are emailed to the feedback recipients.
- **Recurring sessions** set by admins, e.g. an open session for women every Tuesday 19–21 with Aufguss, or *Not available* for cleaning. Single dates can be skipped.
- **Opening hours** per weekday, slot length (30–120 min), closed dates.
- **Login without email or password:** every flat gets a **code letter** with a QR code for its mailbox (see [Authentication](#authentication)). Email is optional and only used for confirmations, reminders, waiting-list news and rating requests. A new address must be confirmed through a link.
- **Unlisted page:** the booking page is left out of menus, site search, the sitemap and the public REST page list, and is marked `noindex`. Without signing in, it shows nothing but the login form.
- **Languages:** English and German (informal "du"), chosen under *Settings → Language*, independent of the WordPress language.
- **Admin screens:**
  - Schedule: shows which flat booked; cancel bookings, skip series dates.
  - Flats: import, codes, letters, sign out devices.
  - Recurring sessions.
  - Ratings: averages, issues, comments.
  - Settings, including the email texts.
- **Privacy:** bookings and waiting lists are deleted after 90 days (configurable), and ratings stay without the flat. Login codes and device tokens are only stored as hashes. WordPress personal data export/erase work by email address. Uninstall deletes data only if you opt in.

Background jobs run on WP-Cron every 15 minutes.

## Authentication

Admins paste or upload the list of flats (`stair;door` per line) and print one letter per flat. The letter holds an 8-character code (no easily confused characters) and a QR code with the sign-in link. Mailbox access proves that someone lives in that flat. Everyone in the flat uses the same code, and each device stays signed in for 180 days, extended with every visit.

- Codes can only be printed within one hour of creating them. After that, only a hash is kept, so a lost letter means creating a new code.
- Admins can sign out all devices of a flat, deactivate it, or issue new codes for everyone (e.g. once a year).
- Sign-in attempts are rate-limited per IP.
- The page removes the code from the address bar right after loading. The code from the QR link can still end up in the web server's access log.

Sign-in sits behind a small interface (`Saunaplan\Core\Identity_Provider`, filter `saunaplan_identity_provider`). Further methods can be added without touching the booking code, for example EVVA AirKey (tap your key, or phone number plus a check) or email codes.

## Code structure

```
saunaplan/
  saunaplan.php            bootstrap, registers the sauna profile
  includes/core/           generic booking core – knows nothing about saunas
  includes/sauna/          the sauna profile: modes, ♀/♂/⚧, Aufguss, keep warm,
                           rating questions, cooldown texts, theme
  admin/                   admin screens
  assets/                  core-app.js (schema-driven UI), core.css, sauna.css
  languages/               .pot, de_DE .po/.mo
```

The core asks the room profile (`Saunaplan\Core\Room_Profile`) for modes, people categories, extra fields, rating questions, badges, mail texts and extra rules. It never checks sauna details itself, and the booking page builds its forms from the schema the API returns. `tests/architecture-test.php` fails if anything in `includes/core/` or `core-app.js` mentions the sauna layer or sauna words. A laundry or party room could therefore reuse the core with its own profile: filters `saunaplan_room_profile` and `saunaplan_rules`, action `saunaplan_enqueue`.

## Installation

**Requirements:** WordPress 6.0+, PHP 7.4+, MySQL or MariaDB. SQLite isn't supported, because bookings are serialized with a database lock.

1. Build the zip in the repo root: `mkdir -p build && zip -r build/saunaplan.zip saunaplan -x '*.DS_Store'`
2. Go to **Plugins → Add New Plugin → Upload Plugin**, upload `saunaplan.zip`, then **Activate**.
3. Go to **Pages → Add New**, create a page with the shortcode `[saunaplan]` (Shortcode block) and give it a slug that is hard to guess, e.g. `/sauna-k7q2/`.
4. Under **Sauna → Settings**, choose that page and check the opening hours, rules and feedback recipients.
5. Under **Sauna → Flats**, paste the flats, then print the letters and put them into the mailboxes.
6. Optionally, add recurring sessions under **Sauna → Recurring sessions**.

On the live site, make sure `wp_mail` works (e.g. WP Mail SMTP), and that WP-Cron runs. On quiet sites, set up a real cron job every 5–15 minutes:

```
wget -q -O - https://your-site.example/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

## Development

```sh
docker compose up -d                          # WordPress :8081, Mailpit :8026
docker compose run --rm cli wp core install --url=http://localhost:8081 --title=Dev \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.org --skip-email
docker compose run --rm cli wp plugin activate saunaplan
# booking page, 6 sample flats (prints their codes/login links), a recurring session:
docker compose run --rm -v "$PWD/dev:/dev-seed" cli wp eval-file /dev-seed/seed.php
# run reminders / rating requests as if it were another time:
docker compose run --rm -v "$PWD/dev:/dev-seed" cli wp eval-file /dev-seed/cron-at.php "2026-10-04 18:30"
for t in tests/*-test.php; do php "$t"; done   # plain PHP tests (no WordPress needed)
```

All mail in the dev environment goes to Mailpit at http://localhost:8026.

### Translations

```sh
docker compose run --rm cli sh -c 'wp i18n make-pot wp-content/plugins/saunaplan /tmp/s.pot \
  --skip-js --exclude=languages,assets/vendor >/dev/null && cat /tmp/s.pot' > saunaplan/languages/saunaplan.pot
msgmerge -U saunaplan/languages/saunaplan-de_DE.po saunaplan/languages/saunaplan.pot
# translate the new entries (e.g. with Poedit), then:
msgfmt -o saunaplan/languages/saunaplan-de_DE.mo saunaplan/languages/saunaplan-de_DE.po
```

To add a language, add a `saunaplan-<locale>.po/.mo` pair and register the locale in `I18n::languages()`.

### Third-party code

`saunaplan/assets/vendor/qrcode.min.js`: QR Code Generator by Kazuhiko Arase (MIT), used only on the admin screen for code letters.

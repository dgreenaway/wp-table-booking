=== getBooked – Table Reservations ===
Contributors: dgtalweb
Tags: reservation, booking, restaurant, table booking, appointments
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A complete table reservation system for restaurants. Multi-step booking form, floor plan editor, automated emails, and a full admin dashboard.

== Description ==

**getBooked** gives restaurants a professional, self-contained reservation system without monthly fees or third-party dependencies. Everything runs inside WordPress: guests book online in seconds, you manage it all from your dashboard.

= How it works =

Add the `[getbooked]` shortcode to any page — or insert the **getBooked** block in the Gutenberg editor — and a polished multi-step booking form appears instantly. Guests choose a date, time, and party size, fill in their details, and receive a confirmation email with everything they need, including a one-click cancellation link that requires no account.

= Two booking modes =

* **Simple mode** — define available time slots and a maximum seat capacity for each. Ideal for smaller venues or quick setup.
* **Floor Plan mode** — build a visual, drag-and-drop layout of your dining room. Assign each table a shape, size, label, and capacity. The system automatically matches the guest's party size to suitable tables and shows only genuinely available options.

= Admin dashboard =

* Reservations list with date, status, party size, and guest name at a glance
* Filter by date range, status (Pending, Confirmed, Cancelled, Completed), or search by guest
* Summary statistics: total bookings, covers, and cancellations for any period
* Detail view: full guest information, internal notes, and one-click status changes
* Bulk confirm, cancel, or delete reservations with a single action
* Daily run sheet: printer-friendly view of any day's reservations

= Automated email notifications =

* **Customer confirmation** — sent immediately on submission; includes booking summary, a signed self-cancellation link, and an Add to Calendar (.ics) link
* **One-click admin confirm** — confirm a pending booking directly from your phone via a signed link in the notification email
* **Status change emails** — guests are notified automatically when you confirm or cancel their reservation
* **Reminder emails** — configurable advance reminders (e.g. 24 hours before, 48 hours before) sent via WordPress Cron

= Guest self-cancellation =

Every confirmation email contains a unique, cryptographically signed cancellation link. Guests can cancel their own reservation without logging in or contacting the restaurant.

= Per-day opening hours =

Set different opening and closing times for each day of the week, and mark individual days as closed entirely — ideal for restaurants that open later on weekdays, close on Mondays, or have different weekend hours.

= Visual themes =

Choose from five built-in themes that style the booking form and all front-end output:

* **Modern** — clean lines, rounded corners, accent colour
* **Dark** — dark-surface design for moody, atmospheric sites
* **Classic** — traditional form styling that suits established brands
* **Minimal** — stripped back, maximum white space
* **Bold** — high-contrast, strong typography

Prefer to match your active WordPress theme automatically? Enable **Site Styles** mode and the form inherits your theme's fonts, colours, and button styles with no extra CSS required.

= Responsive design controls =

* Form width presets (Narrow / Standard / Wide / Full)
* Spacing density (Compact / Comfortable / Spacious)
* Mobile button stacking
* Step indicator style (numbered dots, progress bar, or text)
* UI scale: 100 %, 125 %, or 150 %

= Privacy and GDPR =

* Personal data exporter integrated with WordPress's built-in privacy tools
* Personal data eraser: remove a guest's details on request without deleting the booking record
* Privacy policy helper text you can add to your site's privacy policy with one click
* Configurable data retention period: automatically anonymise or delete reservations older than a set number of days

= Settings export / import =

Back up your entire plugin configuration — time slots, floor plan, email templates, theme settings — as a JSON file and restore it on any WordPress installation.

= Activity log =

Every status change, cancellation, and configuration update is recorded with a timestamp and user. Useful for accountability and debugging.

= Clean uninstall =

Enable the "Remove all data on uninstall" option and every database table, option, scheduled event, and transient created by getBooked is removed when the plugin is deleted. No orphaned data.

= Translation ready =

All user-facing strings are internationalised. A `.pot` file is included. Compatible with WPML and Polylang.

== Installation ==

= Automatic installation =

1. In your WordPress admin go to **Plugins > Add New**.
2. Search for **getBooked**.
3. Click **Install Now**, then **Activate**.

= Manual installation =

1. Download the plugin zip file.
2. In your WordPress admin go to **Plugins > Add New > Upload Plugin**.
3. Choose the zip file and click **Install Now**, then **Activate**.

= After activation =

1. Go to **getBooked > Settings** and choose a booking mode (Simple or Floor Plan).
2. In Simple mode, configure your available time slots and seat capacity.
   In Floor Plan mode, open the **Floor Plan** page and drag tables onto your room layout.
3. Set your opening hours (per day of week), booking window, and party size limits.
4. Customise your confirmation email template under **getBooked > Emails**.
5. Add the `[getbooked]` shortcode to any page, or insert the **getBooked** block in the block editor.
6. Visit the page to confirm the form is working, then place a test booking.

== Frequently Asked Questions ==

= Do guests need a WordPress account to make a reservation? =

No. The booking form is fully public-facing. Guests enter their name, email, phone number, and party size — no registration or login required. The confirmation and cancellation system works entirely via signed email links.

= What shortcode do I use? =

Add `[getbooked]` to any page or post. The legacy shortcode `[table_booking]` is also supported for backwards compatibility.

= How does the Floor Plan editor work? =

Go to **getBooked > Floor Plan** in your admin. Drag table shapes (round, square, rectangle) onto the canvas, resize them, and set a label and capacity for each. You can also define zones (e.g. Indoor, Outdoor, Bar). When a guest books, the system checks which tables can accommodate the party size and are not already reserved for that slot, and assigns a suitable table automatically or lets you choose manually.

= Can I use both Simple mode and Floor Plan mode at the same time? =

No. The two modes are mutually exclusive. You select one in **getBooked > Settings > Booking Mode**. You can switch between them at any time; existing reservations are preserved but future bookings will follow the newly selected mode.

= Can guests cancel their own reservation? =

Yes. Every confirmation email contains a unique cancellation link. Clicking it takes the guest to a confirmation page; once confirmed, the reservation is marked Cancelled and you receive an optional admin notification. The link is cryptographically signed and cannot be guessed or reused.

= How do reminder emails work? =

Under **getBooked > Emails > Reminders** you can enable one or more reminder intervals (e.g. 48 hours before, 24 hours before). WordPress Cron checks periodically and dispatches reminders at the right time. You can customise the subject line and body using template tags such as `{guest_name}`, `{booking_date}`, `{booking_time}`, and `{party_size}`.

= Does the plugin work with page builders? =

The `[getbooked]` shortcode works in any context that renders WordPress shortcodes, including Elementor, Bricks, Divi, and WPBakery. The native Gutenberg block is available whenever the block editor is active.

= Is the plugin GDPR compliant? =

The plugin includes a personal data exporter and eraser that integrate with the WordPress privacy tools at **Tools > Export Personal Data** and **Tools > Erase Personal Data**. It also provides suggested privacy policy language and a configurable automatic data retention period. You remain responsible for your own privacy policy and compliance obligations.

= Is the plugin translation-ready? =

Yes. All user-facing strings are internationalised using the `table-booking` text domain. A `.pot` file is included in the `languages/` folder. The plugin is compatible with WPML, Polylang, and any translation plugin that supports standard WordPress i18n.

= Does the plugin support WPML or Polylang? =

Yes. All strings use the standard WordPress `__()` and `_e()` functions with the `table-booking` text domain. WPML and Polylang can translate all front-end and email strings using the included `.pot` file.

= Will my data be removed if I delete the plugin? =

Only if you enable **Remove all data on uninstall** in **getBooked > Settings > Advanced** before deleting. With that option active, all plugin database tables, options, scheduled events, and transients are removed. With it inactive, your data is preserved so you can reinstall without losing anything.

== Screenshots ==

1. **Booking form (front end)** — the multi-step booking form displayed on the site using the Modern theme, showing the date and area selection step.
2. **Admin reservations list** — the main dashboard view with a filterable list of reservations, status badges, summary statistics, and bulk actions.
3. **Floor Plan editor** — the drag-and-drop dining room layout builder with round and rectangular tables placed on a canvas, labelled by zone.
4. **Styles and design settings** — the Appearance settings page showing theme swatches, form width presets, spacing density options, and UI scale controls.
5. **Email settings** — the email configuration page showing confirmation email options, logo upload, reminder settings, and custom success message.

== Changelog ==

= 1.0.0 =
* Initial release.
* Multi-step booking form with `[getbooked]` shortcode and Gutenberg block.
* Simple booking mode: time slots with configurable seat capacity.
* Floor Plan mode: drag-and-drop table layout editor with zones, shapes, labels, and per-table capacity.
* Per-day opening hours: set different hours (or closed) for each day of the week.
* Admin reservations list with filters, search, statistics, bulk actions, and detail view.
* Print view / daily run sheet for any date.
* Automated customer confirmation email on booking submission with Add to Calendar link.
* One-click admin confirm via signed email link.
* Status change notification emails (confirmed, cancelled).
* Configurable reminder emails via WordPress Cron.
* Guest self-cancellation via signed, time-limited email link.
* Five built-in visual themes: Modern, Dark, Classic, Minimal, Bold.
* Site Styles mode to inherit the active WordPress theme's design.
* Responsive design controls: form width, spacing density, mobile stacking, step indicator style, UI scale.
* GDPR personal data exporter and eraser integrated with WordPress privacy tools.
* Privacy policy helper text.
* Configurable data retention period with automatic anonymisation.
* Settings export and import as JSON.
* Activity log for status changes and configuration updates.
* Rate limiting and honeypot spam prevention.
* Clean uninstall option to remove all plugin data on deletion.

== Upgrade Notice ==

= 1.0.0 =
Initial release. No upgrade steps required.

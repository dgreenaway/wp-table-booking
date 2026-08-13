# getBooked - Table Reservations for WordPress

A self-contained table reservation system for restaurants. No monthly fees, no third-party accounts, everything runs inside WordPress.

---

## What it does

Guests land on your booking page, pick a date and time, choose their party size, fill in their details, and hit confirm. They get an email straight away with a summary and a one-click cancellation link. You get a notifcation and can confirm or manage it from your admin dashboard.

That's the short version. There's quite a bit more under the hood.

---

## Features

### Booking form
- Multi-step form (date & area, time & party size, details, review)
- Two booking modes: **Simple** (time slots + total capacity) or **Floor Plan** (drag-and-drop table layout)
- Shortcode `[getbooked]` or native Gutenberg block
- Works with Elementor, Bricks, Divi, and WPBakery

### Opening hours
- Set different opening and closing times for each day of the week
- Mark individual days as closed (e.g. closed Mondays, shorter hours on Sundays)
- Date picker automaticaly blocks out days you're not open

### Admin dashboard
- Reservations list with filters, search, status badges, and summary stats
- Detail view with guest info, internal notes, and one-click status changes
- Bulk confirm, cancel, or delete
- Daily run sheet, a printer-friendly view of any day's bookings

### Emails
- Customer confirmation sent immediately on submission, with an Add to Calendar (.ics) link
- One-click admin confirm via a signed link in the notification email
- Status change notifications when you confirm or cancel
- Configurable reminder emails (e.g. 48 hours before, 24 hours before)

### Guest self-cancellation
Every confirmation email includes a unique, cryptographically signed cancellation link. Guests can cancel without logging in or calling the restaurant.

### Themes and design
Five built-in themes: Modern, Dark, Classic, Minimal, Bold. Or enable **Site Styles** to inherit your active theme's fonts and colours automatically.

Form width, spacing density, mobile layout, step indicator style, and UI scale are all configurable.

### Privacy and GDPR
- Personal data exporter and eraser integrated with WordPress's built-in privacy tools
- Configurable data retention period with automatic anonymisation
- Privacy policy helper text included

### Everything else
- Settings export and import as JSON
- Activity log for all status changes and config updates
- Rate limiting and honeypot spam protection
- Clean uninstall that optionally removes every table, option, and transient on deletion
- Translation ready, compatible with WPML and Polylang

---

## Shortcode

```
[getbooked]
```

The legacy shortcode `[table_booking]` is also supported for backwards compatibility.

---

## Requirements

- WordPress 6.0 or later
- PHP 8.0 or later

---

## Installation

1. Download the plugin zip.
2. Go to **Plugins > Add New > Upload Plugin** in your WordPress admin.
3. Upload the zip and activate.
4. Go to **getBooked > Settings** to pick a booking mode and configure your hours.
5. Add `[getbooked]` to any page.

---

## Compatibility

Tested with WordPress 6.8. Works with most caching plugins (WP Super Cache, W3 Total Cache, WP Rocket). The booking page is automatically excluded from page caching to keep nonces fresh.

---

## Development

Built as a standard WordPress plugin with no build step required for PHP or CSS. The JavaScript in `public/js/booking.js` and `admin/js/admin.js` is plain ES5-compatible jQuery, no bundler needed.

If you want to contribute or fork, just clone the repo and drop the folder into your local WordPress install's `wp-content/plugins/` directory.

---

## Roadmap

Things that might be worth adding down the line:

- Stripe / PayPal deposit payments
- Google Calendar two-way sync
- Waitlist for fully-booked slots
- SMS reminders via Twilio or similar

Not committed to any of these, just ideas.

---

## License

GPLv2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

---

Built by [dgtalweb](https://dgtalweb.com)

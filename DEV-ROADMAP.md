# getBooked — Future Development

## WP.org Submission (manual, blocking)

- [ ] Take 5 screenshots, save as `assets/screenshot-1.png` through `screenshot-5.png`
- [ ] Create banner images: `assets/banner-772x250.png` and `assets/banner-1544x500.png`
- [ ] Create plugin icons: `assets/icon-128x128.png` and `assets/icon-256x256.png`
- [ ] Run `wp plugin check table-booking` on a test install and fix any failures
- [ ] Do a full booking flow with `WP_DEBUG=true` — confirm zero PHP notices/warnings
- [ ] Verify WP.org username matches `Contributors: dgtalweb` in `readme.txt`

---

## Features — High Priority

### Stripe deposit / card-on-file
Take a configurable deposit (e.g. £10/head) to hold a table. Most requested feature
in competing plugins. Also the natural entry point for a Pro tier. Stripe Elements
for the frontend, webhook for payment confirmation, deposit amount shown in the
confirmation email and admin detail view. Include a partial/full refund flow for
cancellations.

### Guest CRM / returning guest recognition
When an email is entered (booking form or admin new booking), surface their booking
history: visit count, last visit date, usual party size, any previous special requests.
Store in a separate `tb_guests` table keyed by email. Show in the admin detail view
and optionally a dedicated Guests page with lifetime visit count, total covers, and
notes. Flag regulars with a badge on the reservations list.

### Waitlist
When a slot is fully booked, show a "Join Waitlist" option on the booking form.
Store waitlist entries in a `tb_waitlist` table. When a cancellation comes in,
automatically email the next person on the list and give them a timed link (e.g.
2 hours) to claim the slot before it moves to the next in line.

### Custom form fields
Let admins add extra fields to the booking form — text inputs, dropdowns, checkboxes,
date pickers. Use cases: "Occasion", "Dietary requirements", "How did you hear about
us?", "Seating preference", "High chair needed?". Store field definitions as JSON in
settings, values in a `tb_reservation_meta` table. Show in admin detail view, CSV
export, daily digest, and run sheet.

### Booking amendment (guest self-service)
Alongside the existing self-cancellation link, include an "Amend my booking" link in
confirmation emails. Lets guests change their date, time, or party size within
configurable rules (e.g. not within 24 hours). Generates a new confirmation email on
save. No account required — uses the same signed-link pattern as cancellation.

### Review prompt
After N successful bookings (configurable, default 10), show a tasteful admin notice
with a direct link to the WP.org review page. Dismiss permanently on click. Track
count in a wp_options entry. High ROI — compounds over time and directly affects
WP.org search ranking.

---

## Features — Medium Priority

### Calendar view
A week/day calendar in the admin showing reservations as blocks, colour-coded by
status or area. Click a block to open the detail view. Click an empty slot to create
a new reservation. More intuitive than the list view for busy services. Could reuse
the existing floor plan canvas approach or use a simple CSS grid.

### Google Calendar sync
Phase 1: publish a read-only iCal feed URL (authenticated with a signed token) that
calendar apps can subscribe to — one URL per area or one combined feed. Phase 2:
two-way sync via Google Calendar API — create events on confirm, delete on
cancellation, update on amendment. OAuth 2.0 flow in Settings.

### SMS reminders
Hook into a WP SMS plugin (WP SMS, Twilio for WordPress) via an action/filter, or
integrate Twilio directly with configurable credentials. SMS sent X hours before,
configurable alongside existing email reminders. Opt-in checkbox on the booking form
for consent; store consent flag on the reservation.

### Zapier / webhook triggers
Fire a configurable webhook URL on key events: new booking, booking confirmed,
booking cancelled, no-show marked. Payload includes the full reservation object as
JSON. Unlocks integration with Zapier, Make (Integromat), n8n, and custom CRMs
without writing PHP. Add a webhook log to the Activity Log page.

### REST API endpoints
Expose `GET /wp-json/getbooked/v1/reservations`, `POST .../reservations`,
`PATCH .../reservations/{id}`, `GET .../availability` with application password auth.
Lets headless WordPress sites, POS systems, and third-party apps integrate cleanly.
Include OpenAPI/Swagger schema in the plugin for discoverability.

### Multi-location support
One install, multiple restaurant locations each with their own settings, opening hours,
floor plan, and areas. Guests pick a location on the booking form. Admin filters the
reservations list by location. Requires a `tb_locations` table and a `location_id`
column on reservations. Good fit for restaurant groups and agency installs.

### Table combination
Merge two or more adjacent tables for larger parties in Floor Plan mode. Define
combinations in the layout editor with a visual join tool. Availability check treats
a combination as a unit — if any constituent table is booked, the combination is
unavailable. Show combined label (e.g. "T1 + T2") in admin and run sheet.

### Reservation tags / labels
Admin can tag reservations: VIP, Regular, Media, Allergy, Birthday, Anniversary.
Tags stored as a comma-separated column (or `tb_reservation_tags` table). Filter
the reservations list by tag. Tags appear on the run sheet and floor plan overlay.
Colour-coded chips in the admin detail view.

### Kitchen / front-of-house notes
A second notes field on each reservation visible only to kitchen/FOH staff, separate
from admin-only notes. Configurable label. Appears on the run sheet. Keeps
operational notes out of the guest-facing trail.

### Blackout periods / special event rules
Define date ranges where different rules apply — e.g. Valentine's Day: longer sitting
duration, higher minimum party size, deposit required, custom booking form text.
Blackout periods override the normal schedule. Manage from a calendar-style UI in
Settings.

### No-show management
Mark a reservation as No-show from the run sheet or list view. Automatically flag
the guest's email in the CRM. Optional auto-email to the guest after X minutes past
booking time ("We held your table…"). No-show rate visible in the Reports page.

---

## Integrations

### Mailchimp / email marketing opt-in
Checkbox on the booking form: "Keep me updated with news and offers." On opt-in,
add the guest's email and name to a configured Mailchimp list (or any list via a
filter). Store consent flag on the reservation for GDPR.

### WooCommerce deposit
Alternative to direct Stripe integration — use WooCommerce as the payment layer.
Create a virtual product per booking type, redirect to checkout after step 3,
complete the reservation on WooCommerce order completion. Inherits all WooCommerce
payment gateways automatically.

### Elementor / Divi native widgets
A proper Elementor widget and Divi module that wraps the booking form with
point-and-click style controls in those builders — not just a shortcode embed.
Improves discoverability for builder-first users who never look at shortcodes.

### WP CLI commands
`wp getbooked list` — list reservations with filters.
`wp getbooked create` — create a reservation from the CLI.
`wp getbooked export` — export to CSV.
`wp getbooked cleanup` — run the data retention job manually.
Useful for hosting providers, automated testing, and power users.

---

## Reporting & Analytics

### Extended reports page
Expand the current 30-day bar chart to include:
- Busiest days of the week (heatmap)
- Average party size over time
- Cancellation rate trend
- Lead time distribution (how far in advance people book)
- Peak booking hours (when during the day people make reservations, not when they dine)
- No-show rate by month

### Revenue forecasting
If deposits are enabled, show projected deposit income for the next 7/30 days based
on confirmed bookings. Actual vs expected reconciliation view.

### Guest analytics
Most frequent guests, highest lifetime covers, guests who haven't returned in 90 days
(re-engagement candidates), first-time vs returning ratio over time.

---

## Developer / Technical

### Deploy telemetry worker (ready to go)
The opt-in telemetry system is built and wired up but disabled via `TB_Telemetry::ENABLED = false`.
The Cloudflare Worker code and D1 schema are in `worker/`. To activate:
1. `wrangler d1 create getbooked-telemetry` — copy the database_id into `worker/wrangler.toml`
2. `wrangler d1 execute getbooked-telemetry --file=worker/schema.sql`
3. `wrangler deploy worker/telemetry-worker.js`
4. Update `TB_Telemetry::ENDPOINT` with the `.workers.dev` URL
5. Set `TB_Telemetry::ENABLED = true`
Tracks: plugin version, WP/PHP version, booking mode, table count, reservation total, locale, multisite flag.

### Performance
- Redis/Memcached object cache compatibility for the availability transient layer
- Lazy-load floor plan canvas data only when the layout tab is active
- Background processing queue for bulk email sends (WP Background Processing library)
  so confirming 50 bookings at once doesn't time out

### WP-CLI test harness
Seed script to generate N random reservations for a given date range. Used to stress-
test the availability logic and test the run sheet, reports, and CSV export at scale.

### Unit / integration tests
PHPUnit test suite covering: availability calculation (simple mode), availability
calculation (floor plan mode), HMAC token generation and verification, rate limiting,
data retention cleanup, GDPR erasure. Run on GitHub Actions against PHP 8.0, 8.1,
8.2, 8.3.

### Multisite / network support
Network-activate compatible — each site gets its own settings and reservation tables
with the correct table prefix. Super admin can see an aggregate dashboard across all
sites. Clarify in readme.txt that per-site activation is supported; network activation
is not officially tested.

### RTL admin CSS
Flip admin UI layout for Arabic/Hebrew installs — currently only the frontend booking
form has RTL support (`public/css/booking-rtl.css`). Admin pages use fixed-direction
layouts that need separate overrides in `admin/css/admin-rtl.css`.

---

## Guest Experience

### Self-service booking portal
A signed URL (no account needed) where guests can view their upcoming reservations,
amend details, or cancel — all from one page. Link included in the confirmation
email. More complete than individual cancel/amend links.

### Post-visit feedback email
N hours after a reservation's end time, send the guest an optional feedback email
with a star rating link or a short Google Forms / Typeform URL. Configurable delay
and template. Responses not stored in the plugin (links to external form).

### Occasion / anniversary tracking
If a guest books for a birthday or anniversary (via a custom field or occasion
dropdown), flag it in the CRM and on the run sheet. Optional: auto-email the guest
N days before the date the following year with a "Book again?" prompt.

### "Book again" shortcut
In the guest's confirmation / post-visit email, include a pre-filled booking link
that carries forward their party size, area preference, and time of day. Saves them
re-entering details. Increases repeat booking rate.

---

## Marketing / Non-code

- [ ] **Demo site** — a live WordPress install where anyone can click through the full
      booking flow without signing up. Linked from the WP.org listing and the README.
      Converts better than screenshots alone.

- [ ] **Landing page** — a separate site (getbooked.io or similar) with feature overview,
      screenshots, and an email capture for a Pro version announcement. Gives an
      email list independent of WP.org.

- [ ] **WP.org support forum** — respond to every thread within 24 hours. WP.org surfaces
      plugins with high support resolution rates in search results. Set up a notification
      for new threads.

- [ ] **Pro version planning** — define the free/pro feature split before the listing goes
      live. Suggested Pro tier: Stripe deposits, SMS reminders, multi-location, guest CRM,
      calendar view, webhook triggers, priority support.

- [ ] **Affiliate / referral programme** — once Pro exists, offer 30% recurring commission
      to bloggers and agency partners who refer paying customers.

---

## Post-launch / Longer Term

- HTML daily digest email with table formatting and status colour coding
- Redirect to a custom URL on booking success (instead of inline success message)
- Google Sheets export via Apps Script webhook
- QR code per table — scan to see who is booked at that table and when
- Loyalty / rewards system: track covers per guest, unlock perks at milestones
- Progressive Web App wrapper for the admin run sheet (offline capable)
- Voice assistant integration: "Hey Siri, book a table for 2 at 7pm"
- Dark mode for the booking form that follows the OS `prefers-color-scheme` setting
- Accessible floor plan editor — full keyboard navigation, screen reader labels
- Bulk import reservations from CSV (for migrating from other systems)
- Migration guides and import tools for popular competing plugins

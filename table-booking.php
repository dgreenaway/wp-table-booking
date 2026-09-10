<?php
/**
 * Plugin Name:       getBooked
 * Description:       A complete table reservation system for restaurants — multi-step booking form, floor plan editor, automated emails, and a full admin dashboard.
 * Version:           1.0.0
 * Author:            dgtalweb
 * Author URI:        https://dgtalweb.com
 * Text Domain:       table-booking
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Tested up to:      6.8
 */

defined('ABSPATH') || exit;

// Core bootstrap file. Defines constants, loads classes, and wires up all front-end
// actions, shortcodes, and template hooks via the plugins_loaded callback below.

define('TB_VERSION',  '1.0.0');
define('TB_DIR',      plugin_dir_path(__FILE__));
define('TB_URL',      plugin_dir_url(__FILE__));
define('TB_BASENAME', plugin_basename(__FILE__));

require_once TB_DIR . 'includes/class-tb-logger.php';
require_once TB_DIR . 'includes/class-tb-database.php';
require_once TB_DIR . 'includes/class-tb-reminders.php';   // before reservations (used in DB defaults)
require_once TB_DIR . 'includes/class-tb-emails.php';
require_once TB_DIR . 'includes/class-tb-reservations.php';
require_once TB_DIR . 'includes/class-tb-layout.php';
require_once TB_DIR . 'includes/class-tb-admin.php';
require_once TB_DIR . 'includes/class-tb-ajax.php';
require_once TB_DIR . 'includes/class-tb-privacy.php';
require_once TB_DIR . 'includes/class-tb-telemetry.php';

// Creates/upgrades DB tables, schedules crons, and sets the redirect transient
// that sends first-time users to the setup wizard on their next admin visit.
register_activation_hook(__FILE__, function () {
    TB_Database::install();
    TB_Reminders::activate();
    TB_Telemetry::on_activation();
    if (!wp_next_scheduled('tb_cleanup_old_reservations')) {
        wp_schedule_event(time(), 'weekly', 'tb_cleanup_old_reservations');
    }
    if (!wp_next_scheduled('tb_daily_digest')) {
        $digest_time = TB_Database::get_setting('daily_digest_time', '08:00');
        $first_run   = strtotime('tomorrow ' . $digest_time);
        wp_schedule_event($first_run, 'daily', 'tb_daily_digest');
    }
    set_transient('tb_activation_redirect', true, 30);
    TB_Logger::info('Plugin activated — v' . TB_VERSION, 'system');
});

// Unschedule all crons on deactivation. Tables and data are kept — deletion only
// happens if the user has enabled that option and then removes the plugin entirely.
register_deactivation_hook(__FILE__, function () {
    TB_Reminders::deactivate();
    foreach (['tb_cleanup_old_reservations', 'tb_daily_digest'] as $hook) {
        $ts = wp_next_scheduled($hook);
        if ($ts) wp_unschedule_event($ts, $hook);
    }
    TB_Telemetry::on_deactivation();
});

// Hooked to plugins_loaded so our classes are ready before themes or other plugins
// that use init can try to interact with the shortcode or AJAX endpoints.
function tb_boot() {
    load_plugin_textdomain('table-booking', false, dirname(TB_BASENAME) . '/languages');

    TB_Database::maybe_upgrade();
    TB_Reminders::init();
    TB_Privacy::register();

    add_action('tb_cleanup_old_reservations', ['TB_Reservations', 'cleanup_old']);
    add_action('tb_daily_digest',             'tb_send_daily_digest');
    TB_Telemetry::init();

    if (is_admin()) {
        (new TB_Admin())->init();
    }
    (new TB_Ajax())->init();

    add_shortcode('getbooked',     'tb_render_booking_form');
    add_shortcode('table_booking', 'tb_render_booking_form'); // legacy alias
    add_action('wp_enqueue_scripts', 'tb_enqueue_frontend');
    add_action('template_redirect',  'tb_handle_cancel');
    add_action('template_redirect',  'tb_handle_ical');
    add_action('template_redirect',  'tb_handle_admin_confirm');
    add_action('init',               'tb_register_block');
}

// Registers a Gutenberg block backed by the same shortcode render callback.
// Wraps the existence check so the plugin doesn't break on older WP versions.
function tb_register_block(): void {
    if (!function_exists('register_block_type')) return;
    wp_register_script(
        'tb-booking-block',
        TB_URL . 'blocks/table-booking/index.js',
        ['wp-blocks', 'wp-element', 'wp-server-side-render'],
        TB_VERSION,
        true
    );
    register_block_type('table-booking/form', [
        'editor_script'   => 'tb-booking-block',
        'render_callback' => 'tb_render_booking_form',
    ]);
}
add_action('plugins_loaded', 'tb_boot');

// Only loads assets on pages that actually contain the booking form — checks for
// the shortcode, the Gutenberg block, and an escape-hatch filter for page builders.
// Non-modern themes get CSS variable overrides inlined rather than an extra file.
function tb_enqueue_frontend() {
    $post_id = get_the_ID();
    $content = $post_id ? get_post_field('post_content', $post_id) : '';

    $load = has_shortcode($content, 'getbooked')
         || has_shortcode($content, 'table_booking')
         || ($post_id && function_exists('has_block') && has_block('table-booking/form', $post_id))
         || apply_filters('getbooked_load_assets', false);

    if (!$load) return;

    $style = TB_Database::get_setting('booking_style', 'modern');

    if ($style === 'site') {
        wp_enqueue_style('tb-booking', TB_URL . 'public/css/booking-site.css', [], TB_VERSION);
    } else {
        wp_enqueue_style('tb-booking', TB_URL . 'public/css/booking.css', [], TB_VERSION);

        if ($style !== 'modern') {
            $themes = tb_style_themes();
            if (!empty($themes[$style]['vars'])) {
                $css = '.tb-booking-wrap{';
                foreach ($themes[$style]['vars'] as $prop => $val) {
                    $css .= $prop . ':' . $val . ';';
                }
                $css .= '}';
                wp_add_inline_style('tb-booking', $css);
            }
        }
    }

    // Derive open days (JS convention: 0=Sun … 6=Sat) from weekly_hours setting.
    $weekly_h  = json_decode(TB_Database::get_setting('weekly_hours', '{}'), true);
    $js_day_map = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
    $open_days  = [];
    foreach ($js_day_map as $key => $num) {
        if (!empty($weekly_h[$key]['open'])) $open_days[] = $num;
    }
    if (empty($open_days)) $open_days = [0, 1, 2, 3, 4, 5, 6]; // fallback: all days

    if (is_rtl()) {
        wp_enqueue_style('tb-booking-rtl', TB_URL . 'public/css/booking-rtl.css', ['tb-booking'], TB_VERSION);
    }

    wp_enqueue_script('tb-booking', TB_URL . 'public/js/booking.js', ['jquery'], TB_VERSION, true);
    add_filter('script_loader_tag', 'tb_defer_booking_script', 10, 2);

    wp_localize_script('tb-booking', 'tbData', [
        'ajaxUrl'     => admin_url('admin-ajax.php'),
        'nonce'       => wp_create_nonce('tb_frontend'),
        'areas'       => json_decode(TB_Database::get_setting('areas', '[]'), true),
        'maxParty'    => (int) TB_Database::get_setting('max_party_size', 12),
        'maxDays'     => (int) TB_Database::get_setting('max_advance_days', 60),
        'openDays'    => $open_days,
        'closedDates' => json_decode(TB_Database::get_setting('closed_dates', '[]'), true) ?: [],
        'successMsg'  => TB_Database::get_setting('booking_success_message', ''),
    ]);
}

// Adds the defer attribute to the booking JS tag. The script initialises on
// DOMContentLoaded so deferring it doesn't affect anything and helps page speed scores.
function tb_defer_booking_script(string $tag, string $handle): string {
    if ($handle === 'tb-booking') {
        return str_replace(' src=', ' defer src=', $tag);
    }
    return $tag;
}

// Returns the built-in theme definitions used by both the style picker and the
// enqueue function. Each theme is a set of CSS custom property overrides applied
// to the booking wrapper element.
function tb_style_themes(): array {
    return [
        'modern' => [
            'name'  => 'Modern',
            'desc'  => 'Clean and professional with blue accents',
            'vars'  => [],
        ],
        'dark' => [
            'name'  => 'Dark',
            'desc'  => 'Sleek dark theme for evening venues',
            'vars'  => [
                '--tb-primary'        => '#60a5fa',
                '--tb-primary-hover'  => '#93c5fd',
                '--tb-primary-light'  => '#1e3a5f',
                '--tb-primary-ring'   => 'rgba(96,165,250,0.2)',
                '--tb-success'        => '#4ade80',
                '--tb-success-light'  => '#052e16',
                '--tb-success-border' => '#166534',
                '--tb-success-text'   => '#4ade80',
                '--tb-bg'             => '#1f2937',
                '--tb-bg-subtle'      => '#111827',
                '--tb-bg-muted'       => '#374151',
                '--tb-border'         => '#374151',
                '--tb-border-input'   => '#4b5563',
                '--tb-text'           => '#f9fafb',
                '--tb-text-secondary' => '#e5e7eb',
                '--tb-text-muted'     => '#9ca3af',
                '--tb-text-faint'     => '#6b7280',
            ],
        ],
        'classic' => [
            'name'  => 'Classic',
            'desc'  => 'Warm tones with a timeless restaurant feel',
            'vars'  => [
                '--tb-primary'        => '#92400e',
                '--tb-primary-hover'  => '#78350f',
                '--tb-primary-light'  => '#fffbeb',
                '--tb-primary-ring'   => 'rgba(146,64,14,0.15)',
                '--tb-success'        => '#065f46',
                '--tb-success-light'  => '#f0fdf4',
                '--tb-success-border' => '#a7f3d0',
                '--tb-success-text'   => '#065f46',
                '--tb-bg'             => '#faf9f7',
                '--tb-bg-subtle'      => '#f5f5f4',
                '--tb-bg-muted'       => '#e7e5e4',
                '--tb-border'         => '#d6d3d1',
                '--tb-border-input'   => '#a8a29e',
                '--tb-text'           => '#1c1917',
                '--tb-text-secondary' => '#44403c',
                '--tb-text-muted'     => '#78716c',
                '--tb-text-faint'     => '#a8a29e',
            ],
        ],
        'minimal' => [
            'name'  => 'Minimal',
            'desc'  => 'Black and white with maximum whitespace',
            'vars'  => [
                '--tb-primary'        => '#000000',
                '--tb-primary-hover'  => '#333333',
                '--tb-primary-light'  => '#f5f5f5',
                '--tb-primary-ring'   => 'rgba(0,0,0,0.08)',
                '--tb-success'        => '#000000',
                '--tb-success-light'  => '#f5f5f5',
                '--tb-success-border' => '#d4d4d4',
                '--tb-success-text'   => '#000000',
                '--tb-bg'             => '#ffffff',
                '--tb-bg-subtle'      => '#fafafa',
                '--tb-bg-muted'       => '#f5f5f5',
                '--tb-border'         => '#e5e5e5',
                '--tb-border-input'   => '#d4d4d4',
                '--tb-text'           => '#000000',
                '--tb-text-secondary' => '#333333',
                '--tb-text-muted'     => '#737373',
                '--tb-text-faint'     => '#a3a3a3',
                '--tb-radius'         => '2px',
            ],
        ],
        'bold' => [
            'name'  => 'Bold',
            'desc'  => 'Vibrant purple with strong visual contrast',
            'vars'  => [
                '--tb-primary'        => '#7c3aed',
                '--tb-primary-hover'  => '#6d28d9',
                '--tb-primary-light'  => '#ede9fe',
                '--tb-primary-ring'   => 'rgba(124,58,237,0.15)',
                '--tb-success'        => '#059669',
                '--tb-success-light'  => '#ecfdf5',
                '--tb-success-border' => '#6ee7b7',
                '--tb-success-text'   => '#065f46',
                '--tb-bg'             => '#ffffff',
                '--tb-bg-subtle'      => '#faf5ff',
                '--tb-bg-muted'       => '#f3f0ff',
                '--tb-border'         => '#e9d5ff',
                '--tb-border-input'   => '#d8b4fe',
                '--tb-text'           => '#1e1b4b',
                '--tb-text-secondary' => '#312e81',
                '--tb-text-muted'     => '#6b7280',
                '--tb-text-faint'     => '#9ca3af',
            ],
        ],
    ];
}

// Self-service cancellation handler linked from confirmation emails. Uses an HMAC
// token rather than a nonce so the link stays valid indefinitely without the guest
// needing a WordPress account or an active session.
function tb_handle_cancel(): void {
    if (($_GET['tb_action'] ?? '') !== 'cancel') return;

    $id  = (int) ($_GET['id']  ?? 0);
    $tok = sanitize_text_field(wp_unslash($_GET['tok'] ?? ''));

    $error = '';

    if (!$id || !$tok) {
        $error = __('This cancellation link is invalid.', 'table-booking');
    } else {
        $res = new TB_Reservations();
        $row = $res->get($id);

        if (!$row) {
            $error = __('Reservation not found.', 'table-booking');
        } else {
            $expected = hash_hmac('sha256', "cancel:{$id}:{$row['reservation_number']}", wp_salt('secure_auth'));
            if (!hash_equals($expected, $tok)) {
                $error = __('This cancellation link is invalid or has expired.', 'table-booking');
            } elseif (in_array($row['status'], ['cancelled', 'completed', 'no_show'], true)) {
                $error = __('This reservation has already been cancelled or completed.', 'table-booking');
            } elseif ($row['status'] === 'seated') {
                $error = __('Your reservation is already in progress and cannot be cancelled online.', 'table-booking');
            } else {
                $ok = $res->update($id, ['status' => 'cancelled']);
                if (!$ok) {
                    $error = __('We could not cancel your reservation. Please contact us directly.', 'table-booking');
                } else {
                    TB_Logger::info("Guest cancelled reservation #{$row['reservation_number']} (id:{$id})", 'cancel');
                    TB_Emails::send_admin_cancellation_notice($id);
                }
            }
        }
    }

    $success = ($error === '');
    $cfg     = TB_Database::get_all_settings();
    $name    = ($success && isset($row)) ? esc_html($row['customer_name']) : '';
    $ref     = ($success && isset($row)) ? esc_html($row['reservation_number']) : '';
    $restaurant = esc_html($cfg['restaurant_name'] ?? get_bloginfo('name'));

    get_header();
    ?>
    <div style="max-width:560px;margin:60px auto;padding:0 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
      <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:40px 36px;text-align:center;">
        <?php if ($success) : ?>
          <div style="width:56px;height:56px;border-radius:50%;background:#ecfdf5;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:28px;color:#059669;">&#10003;</div>
          <h1 style="margin:0 0 8px;font-size:22px;color:#111827;"><?= esc_html__('Reservation Cancelled', 'table-booking') ?></h1>
          <p style="margin:0 0 20px;color:#6b7280;font-size:15px;">
            <?= sprintf(
                esc_html__('Hi %1$s, your reservation %2$s at %3$s has been cancelled. We hope to see you another time.', 'table-booking'),
                '<strong>' . $name . '</strong>',
                '<strong>' . $ref . '</strong>',
                '<strong>' . $restaurant . '</strong>'
            ) ?>
          </p>
          <p style="margin:0;font-size:13px;color:#9ca3af;"><?= esc_html__('No further action is needed.', 'table-booking') ?></p>
        <?php else : ?>
          <div style="width:56px;height:56px;border-radius:50%;background:#fef2f2;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:28px;color:#dc2626;">&#10007;</div>
          <h1 style="margin:0 0 8px;font-size:22px;color:#111827;"><?= esc_html__('Unable to Cancel', 'table-booking') ?></h1>
          <p style="margin:0;color:#6b7280;font-size:15px;"><?= esc_html($error) ?></p>
        <?php endif; ?>
      </div>
    </div>
    <?php
    get_footer();
    exit;
}

// Generates a .ics calendar file for the guest. Times are stored as floating local
// times (no UTC offset) which is correct for a restaurant booking — the table is at
// 7pm local time regardless of what timezone the guest's calendar app is configured for.
function tb_handle_ical(): void {
    if (($_GET['tb_action'] ?? '') !== 'ical') return;

    $id  = (int) ($_GET['id']  ?? 0);
    $tok = sanitize_text_field(wp_unslash($_GET['tok'] ?? ''));

    if (!$id || !$tok) wp_die(esc_html__('Invalid link.', 'table-booking'));

    $res = new TB_Reservations();
    $row = $res->get($id);
    if (!$row) wp_die(esc_html__('Reservation not found.', 'table-booking'));

    $expected = hash_hmac('sha256', "ical:{$id}:{$row['reservation_number']}", wp_salt('secure_auth'));
    if (!hash_equals($expected, $tok)) wp_die(esc_html__('Invalid link.', 'table-booking'));

    $cfg     = TB_Database::get_all_settings();
    $sit_min = max(1, (int) ($cfg['sitting_duration'] ?? 90));

    // Build floating local times (no UTC offset) — correct for a restaurant slot.
    $tp      = explode(':', $row['reservation_time']);
    $h       = (int) $tp[0];
    $m       = (int) ($tp[1] ?? 0);
    $end_min = $h * 60 + $m + $sit_min;
    $date_c  = str_replace('-', '', $row['reservation_date']);
    $start_t = sprintf('%02d%02d00', $h, $m);
    $end_t   = sprintf('%02d%02d00', intdiv($end_min, 60) % 24, $end_min % 60);

    $uid        = $row['reservation_number'] . '@' . wp_parse_url(home_url(), PHP_URL_HOST);
    $restaurant = sanitize_text_field($cfg['restaurant_name'] ?? get_bloginfo('name'));
    $address    = sanitize_text_field($cfg['restaurant_address'] ?? '');
    $desc       = 'Reference: ' . $row['reservation_number'] . '\nParty of ' . $row['party_size'];
    if ($row['special_requests']) {
        $desc .= '\nRequests: ' . str_replace(["\r\n", "\n"], '\n', $row['special_requests']);
    }

    $ical = "BEGIN:VCALENDAR\r\n"
          . "VERSION:2.0\r\n"
          . "PRODID:-//getBooked//WordPress//EN\r\n"
          . "CALSCALE:GREGORIAN\r\n"
          . "METHOD:PUBLISH\r\n"
          . "BEGIN:VEVENT\r\n"
          . "UID:{$uid}\r\n"
          . "DTSTART:{$date_c}T{$start_t}\r\n"
          . "DTEND:{$date_c}T{$end_t}\r\n"
          . "SUMMARY:{$restaurant} - Table Reservation\r\n"
          . "DESCRIPTION:{$desc}\r\n"
          . ($address ? "LOCATION:{$address}\r\n" : '')
          . "STATUS:CONFIRMED\r\n"
          . "END:VEVENT\r\n"
          . "END:VCALENDAR\r\n";

    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="booking-' . sanitize_file_name($row['reservation_number']) . '.ics"');
    header('Cache-Control: no-cache, no-store');
    echo $ical; // phpcs:ignore WordPress.Security.EscapeOutput
    exit;
}

// One-click confirm link for admins, embedded in the new booking notification email.
// Lets the admin approve the reservation from their inbox without needing to log in.
function tb_handle_admin_confirm(): void {
    if (($_GET['tb_action'] ?? '') !== 'admin_confirm') return;

    $id  = (int) ($_GET['id']  ?? 0);
    $tok = sanitize_text_field(wp_unslash($_GET['tok'] ?? ''));

    $error = '';

    if (!$id || !$tok) {
        $error = __('This confirmation link is invalid.', 'table-booking');
    } else {
        $res = new TB_Reservations();
        $row = $res->get($id);

        if (!$row) {
            $error = __('Reservation not found.', 'table-booking');
        } else {
            $expected = hash_hmac('sha256', "admin_confirm:{$id}:{$row['reservation_number']}", wp_salt('secure_auth'));
            if (!hash_equals($expected, $tok)) {
                $error = __('This confirmation link is invalid or has expired.', 'table-booking');
            } elseif ($row['status'] === 'confirmed') {
                $error = __('This reservation is already confirmed.', 'table-booking');
            } elseif (in_array($row['status'], ['cancelled', 'completed', 'no_show'], true)) {
                $error = __('This reservation cannot be confirmed — it has already been closed.', 'table-booking');
            } else {
                $res->update($id, ['status' => 'confirmed']);
                TB_Emails::send_client_status_update($id, 'confirmed');
                TB_Logger::info("Admin confirmed reservation #{$row['reservation_number']} via email link", 'booking');
            }
        }
    }

    $success   = ($error === '');
    $ref       = ($success && isset($row)) ? esc_html($row['reservation_number']) : '';
    $admin_url = admin_url('admin.php?page=tb-reservations' . ($id ? '&view=' . $id : ''));

    get_header();
    ?>
    <div style="max-width:560px;margin:60px auto;padding:0 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
      <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:40px 36px;text-align:center;">
        <?php if ($success) : ?>
          <div style="width:56px;height:56px;border-radius:50%;background:#ecfdf5;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:28px;color:#059669;">&#10003;</div>
          <h1 style="margin:0 0 8px;font-size:22px;color:#111827;"><?= esc_html__('Booking Confirmed', 'table-booking') ?></h1>
          <p style="margin:0 0 20px;color:#6b7280;font-size:15px;">
            <?= sprintf(esc_html__('Reservation %s has been confirmed and the guest has been notified by email.', 'table-booking'), '<strong>' . $ref . '</strong>') ?>
          </p>
          <a href="<?= esc_url($admin_url) ?>" style="display:inline-block;padding:10px 20px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:600;"><?= esc_html__('View in Dashboard', 'table-booking') ?></a>
        <?php else : ?>
          <div style="width:56px;height:56px;border-radius:50%;background:#fef2f2;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:28px;color:#dc2626;">&#10007;</div>
          <h1 style="margin:0 0 8px;font-size:22px;color:#111827;"><?= esc_html__('Could Not Confirm', 'table-booking') ?></h1>
          <p style="margin:0 0 20px;color:#6b7280;font-size:15px;"><?= esc_html($error) ?></p>
          <a href="<?= esc_url($admin_url) ?>" style="display:inline-block;padding:10px 20px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:600;"><?= esc_html__('View in Dashboard', 'table-booking') ?></a>
        <?php endif; ?>
      </div>
    </div>
    <?php
    get_footer();
    exit;
}

// Renders the multi-step booking form. Cache-control headers are set here because
// a cached page with a stale nonce would silently break every AJAX submission.
function tb_render_booking_form() {
    // Tell caching plugins not to cache pages containing the booking form,
    // as a cached page will have a stale nonce that breaks AJAX submissions.
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
    }

    $responsive    = (bool) TB_Database::get_setting('booking_responsive', '1');
    $form_width    = TB_Database::get_setting('booking_form_width', 'default');
    $density       = TB_Database::get_setting('booking_density', 'default');
    $stack_buttons = (bool) TB_Database::get_setting('booking_stack_buttons', '0');
    $steps_mobile  = TB_Database::get_setting('booking_steps_mobile', 'labels');
    $ui_scale      = TB_Database::get_setting('booking_ui_scale', '100');

    $classes = ['tb-booking-wrap'];
    if (!$responsive)                 $classes[] = 'tb-fixed';
    if ($form_width !== 'default')    $classes[] = 'tb-width-' . $form_width;
    if ($density !== 'default')       $classes[] = 'tb-density-' . $density;
    if ($stack_buttons)               $classes[] = 'tb-stack-btns';
    if ($steps_mobile === 'progress') $classes[] = 'tb-steps-progress';
    if (in_array($ui_scale, ['125', '150'], true)) $classes[] = 'tb-scale-' . $ui_scale;

    $wrap_class = implode(' ', $classes);

    ob_start();
    if (!$responsive) echo '<div class="tb-booking-outer">';
    ?>
    <div id="tb-booking-wrap" class="<?= esc_attr($wrap_class) ?>">

        <!-- Step indicators -->
        <nav class="tb-steps" aria-label="<?= esc_attr__('Booking progress', 'table-booking') ?>">
            <div class="tb-step active" data-step="1" aria-current="step"><span aria-hidden="true">1</span> <?= esc_html__('Date &amp; Area', 'table-booking') ?></div>
            <div class="tb-step" data-step="2"><span aria-hidden="true">2</span> <?= esc_html__('Time &amp; Size', 'table-booking') ?></div>
            <div class="tb-step" data-step="3"><span aria-hidden="true">3</span> <?= esc_html__('Your Details', 'table-booking') ?></div>
            <div class="tb-step" data-step="4"><span aria-hidden="true">4</span> <?= esc_html__('Confirm', 'table-booking') ?></div>
        </nav>

        <!-- Honeypot: off-screen, never sent by JS, catches bots that fill all DOM fields -->
        <div class="tb-hp-wrap" aria-hidden="true">
            <label for="tb-hp"><?= esc_html__('Leave this field empty', 'table-booking') ?></label>
            <input type="text" id="tb-hp" name="tb_hp" tabindex="-1" autocomplete="off">
        </div>

        <div class="tb-form-body">

            <!-- Step 1: Date & Area -->
            <div class="tb-panel" id="tb-panel-1" role="group" aria-labelledby="tb-h-step1">
                <h3 id="tb-h-step1"><?= esc_html__('When & Where?', 'table-booking') ?></h3>
                <div class="tb-field">
                    <label for="tb-date"><?= esc_html__('Date', 'table-booking') ?></label>
                    <input type="date" id="tb-date" class="tb-input" autocomplete="off"
                           aria-required="true" aria-describedby="tb-error" />
                    <div id="tb-date-error" style="display:none;margin-top:6px;font-size:13px;color:var(--tb-error);"></div>
                </div>
                <div class="tb-field">
                    <label id="tb-area-lbl"><?= esc_html__('Seating Area', 'table-booking') ?></label>
                    <div id="tb-area-grid" class="tb-area-grid" role="group" aria-labelledby="tb-area-lbl"></div>
                </div>
                <div class="tb-nav">
                    <button class="tb-btn tb-btn-primary" id="tb-step1-next" disabled aria-disabled="true">
                        <?= esc_html__('See Available Times', 'table-booking') ?> &rarr;
                    </button>
                </div>
            </div>

            <!-- Step 2: Time & Party Size -->
            <div class="tb-panel" id="tb-panel-2" style="display:none;" role="group" aria-labelledby="tb-h-step2">
                <h3 id="tb-h-step2"><?= esc_html__('Choose a Time & Party Size', 'table-booking') ?></h3>
                <div class="tb-loading" id="tb-time-loading" role="status" aria-live="polite">
                    <?= esc_html__('Loading available times…', 'table-booking') ?>
                </div>
                <div class="tb-field" id="tb-time-wrap" style="display:none;">
                    <label id="tb-times-lbl"><?= esc_html__('Available Times', 'table-booking') ?></label>
                    <div id="tb-time-slots" class="tb-time-grid" role="group" aria-labelledby="tb-times-lbl"></div>
                </div>
                <div class="tb-field" id="tb-party-wrap" style="display:none;">
                    <label id="tb-party-lbl"><?= esc_html__('Party Size', 'table-booking') ?></label>
                    <div class="tb-party-selector" id="tb-party-selector" role="group" aria-labelledby="tb-party-lbl"></div>
                </div>
                <div class="tb-nav">
                    <button class="tb-btn tb-btn-secondary" id="tb-step2-back">&larr; <?= esc_html__('Back', 'table-booking') ?></button>
                    <button class="tb-btn tb-btn-primary" id="tb-step2-next" disabled aria-disabled="true">
                        <?= esc_html__('Next', 'table-booking') ?> &rarr;
                    </button>
                </div>
            </div>

            <!-- Step 3: Contact details -->
            <div class="tb-panel" id="tb-panel-3" style="display:none;" role="group" aria-labelledby="tb-h-step3">
                <h3 id="tb-h-step3"><?= esc_html__('Your Details', 'table-booking') ?></h3>
                <div class="tb-field">
                    <label for="tb-name"><?= esc_html__('Full Name', 'table-booking') ?> <span class="tb-req" aria-hidden="true">*</span></label>
                    <input type="text" id="tb-name" class="tb-input"
                           placeholder="<?= esc_attr__('Jane Smith', 'table-booking') ?>"
                           autocomplete="name" aria-required="true" aria-describedby="tb-error" />
                </div>
                <div class="tb-field">
                    <label for="tb-email"><?= esc_html__('Email Address', 'table-booking') ?> <span class="tb-req" aria-hidden="true">*</span></label>
                    <input type="email" id="tb-email" class="tb-input"
                           placeholder="<?= esc_attr__('jane@example.com', 'table-booking') ?>"
                           autocomplete="email" aria-required="true" aria-describedby="tb-error" />
                </div>
                <div class="tb-field">
                    <label for="tb-phone"><?= esc_html__('Phone Number', 'table-booking') ?></label>
                    <input type="tel" id="tb-phone" class="tb-input"
                           placeholder="<?= esc_attr__('e.g. +44 7700 900000', 'table-booking') ?>"
                           autocomplete="tel" />
                </div>
                <div class="tb-field">
                    <label for="tb-notes"><?= esc_html__('Special Requests', 'table-booking') ?></label>
                    <textarea id="tb-notes" class="tb-input" rows="3"
                              placeholder="<?= esc_attr__('Allergies, high chair, anniversary, etc.', 'table-booking') ?>"></textarea>
                </div>
                <div class="tb-nav">
                    <button class="tb-btn tb-btn-secondary" id="tb-step3-back">&larr; <?= esc_html__('Back', 'table-booking') ?></button>
                    <button class="tb-btn tb-btn-primary" id="tb-step3-next"><?= esc_html__('Review', 'table-booking') ?> &rarr;</button>
                </div>
            </div>

            <!-- Step 4: Review & confirm -->
            <div class="tb-panel" id="tb-panel-4" style="display:none;" role="group" aria-labelledby="tb-h-step4">
                <h3 id="tb-h-step4"><?= esc_html__('Review Your Booking', 'table-booking') ?></h3>
                <div class="tb-summary" id="tb-summary" aria-live="polite"></div>
                <div class="tb-nav">
                    <button class="tb-btn tb-btn-secondary" id="tb-step4-back">&larr; <?= esc_html__('Back', 'table-booking') ?></button>
                    <button class="tb-btn tb-btn-primary" id="tb-submit"><?= esc_html__('Confirm Booking', 'table-booking') ?></button>
                </div>
            </div>

            <!-- Success -->
            <div class="tb-panel tb-success" id="tb-panel-success" style="display:none;" role="status" aria-live="polite">
                <div class="tb-success-icon" aria-hidden="true">&#10003;</div>
                <h3><?= esc_html__('Booking Confirmed!', 'table-booking') ?></h3>
                <p id="tb-success-msg"></p>
                <div class="tb-success-ref" id="tb-success-ref"></div>
            </div>

            <!-- Error -->
            <div class="tb-error" id="tb-error" style="display:none;" role="alert" aria-live="assertive" aria-atomic="true"></div>
        </div>
    </div>
    <?php
    if (!$responsive) echo '</div>';
    return ob_get_clean();
}

// Plain-text daily summary of bookings, fired by WP-Cron. Returns early if the
// feature is disabled so the cron event can stay registered without doing any work.
function tb_send_daily_digest(): void {
    if (!TB_Database::get_setting('daily_digest_enabled', '0')) return;

    $cfg         = TB_Database::get_all_settings();
    $res         = new TB_Reservations();
    $today       = wp_date('Y-m-d');
    $rows        = $res->get_all(['date' => $today, 'per_page' => 500, 'page' => 1]);
    $admin_email = $cfg['admin_email'] ?? get_option('admin_email');
    $rest_name   = $cfg['restaurant_name'] ?? get_bloginfo('name');
    $date_disp   = wp_date('l, j F Y');
    $count       = count($rows);

    usort($rows, fn($a, $b) => strcmp($a['reservation_time'], $b['reservation_time']));

    $subject = sprintf('[%s] Daily digest — %s (%d reservation%s)', $rest_name, $date_disp, $count, $count !== 1 ? 's' : '');

    if ($count === 0) {
        $body = "No reservations today ({$date_disp}).\n";
    } else {
        $sit_min      = (int) ($cfg['sitting_duration'] ?? 90);
        $total_covers = array_sum(array_column($rows, 'party_size'));
        $body         = "{$rest_name} — Reservations for {$date_disp}\n";
        $body        .= str_repeat('─', 55) . "\n\n";

        foreach ($rows as $row) {
            $start  = wp_date('g:i A', strtotime($row['reservation_time']));
            $end    = wp_date('g:i A', strtotime($row['reservation_time']) + $sit_min * 60);
            $status = ucfirst(str_replace('_', ' ', $row['status']));
            $body  .= "{$start} – {$end}   {$row['customer_name']}   Party of {$row['party_size']}   [{$status}]\n";
            if (!empty($row['customer_phone']))    $body .= "  Phone: {$row['customer_phone']}\n";
            if (!empty($row['special_requests']))  $body .= "  Requests: {$row['special_requests']}\n";
            $body  .= "\n";
        }

        $body .= str_repeat('─', 55) . "\n";
        $body .= "Total: {$count} reservation" . ($count !== 1 ? 's' : '') . ", {$total_covers} cover" . ($total_covers !== 1 ? 's' : '') . "\n";
    }

    $from_name = $cfg['email_from_name']    ?? $rest_name;
    $from_addr = $cfg['email_from_address'] ?? get_option('admin_email');
    $headers   = ["From: {$from_name} <{$from_addr}>", 'Content-Type: text/plain; charset=UTF-8'];

    wp_mail($admin_email, $subject, $body, $headers);
    TB_Logger::info("Daily digest sent for {$today}: {$count} reservation" . ($count !== 1 ? 's' : ''), 'cron');
}

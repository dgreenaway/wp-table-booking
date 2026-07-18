<?php
/**
 * Plugin Name:       Table Booking
 * Plugin URI:        https://example.com/table-booking
 * Description:       A table reservation system with visual floor plan editor.
 * Version:           1.0.0
 * Author:            Table Booking
 * Text Domain:       table-booking
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Tested up to:      6.8
 */

defined('ABSPATH') || exit;

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

register_activation_hook(__FILE__, function () {
    TB_Database::install();
    TB_Reminders::activate();
    if (!wp_next_scheduled('tb_cleanup_old_reservations')) {
        wp_schedule_event(time(), 'weekly', 'tb_cleanup_old_reservations');
    }
    TB_Logger::info('Plugin activated — v' . TB_VERSION, 'system');
});

register_deactivation_hook(__FILE__, function () {
    TB_Reminders::deactivate();
    $ts = wp_next_scheduled('tb_cleanup_old_reservations');
    if ($ts) wp_unschedule_event($ts, 'tb_cleanup_old_reservations');
});

function tb_boot() {
    load_plugin_textdomain('table-booking', false, dirname(TB_BASENAME) . '/languages');

    TB_Database::maybe_upgrade();
    TB_Reminders::init();
    TB_Privacy::register();

    add_action('tb_cleanup_old_reservations', ['TB_Reservations', 'cleanup_old']);

    if (is_admin()) {
        (new TB_Admin())->init();
    }
    (new TB_Ajax())->init();

    add_shortcode('table_booking', 'tb_render_booking_form');
    add_action('wp_enqueue_scripts', 'tb_enqueue_frontend');
    add_action('template_redirect',  'tb_handle_cancel');
}
add_action('plugins_loaded', 'tb_boot');

function tb_enqueue_frontend() {
    if (!has_shortcode(get_post_field('post_content', get_the_ID()), 'table_booking')) return;

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

    wp_enqueue_script('tb-booking', TB_URL . 'public/js/booking.js', ['jquery'], TB_VERSION, true);
    wp_localize_script('tb-booking', 'tbData', [
        'ajaxUrl'     => admin_url('admin-ajax.php'),
        'nonce'       => wp_create_nonce('tb_frontend'),
        'areas'       => json_decode(TB_Database::get_setting('areas', '[]'), true),
        'maxParty'    => (int) TB_Database::get_setting('max_party_size', 12),
        'maxDays'     => (int) TB_Database::get_setting('max_advance_days', 60),
        'openDays'    => array_map('intval', json_decode(TB_Database::get_setting('open_days', '[0,1,2,3,4,5,6]'), true) ?: [0,1,2,3,4,5,6]),
        'closedDates' => json_decode(TB_Database::get_setting('closed_dates', '[]'), true) ?: [],
    ]);
}

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
                }
            }
        }
    }

    $success = ($error === '');
    $cfg     = TB_Database::get_all_settings();
    $name    = $success ? esc_html($row['customer_name']) : '';
    $ref     = $success ? esc_html($row['reservation_number']) : '';
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

function tb_render_booking_form() {
    $responsive    = (bool) TB_Database::get_setting('booking_responsive', '1');
    $form_width    = TB_Database::get_setting('booking_form_width', 'default');
    $density       = TB_Database::get_setting('booking_density', 'default');
    $stack_buttons = (bool) TB_Database::get_setting('booking_stack_buttons', '0');
    $steps_mobile  = TB_Database::get_setting('booking_steps_mobile', 'labels');

    $classes = ['tb-booking-wrap'];
    if (!$responsive)                 $classes[] = 'tb-fixed';
    if ($form_width !== 'default')    $classes[] = 'tb-width-' . $form_width;
    if ($density !== 'default')       $classes[] = 'tb-density-' . $density;
    if ($stack_buttons)               $classes[] = 'tb-stack-btns';
    if ($steps_mobile === 'progress') $classes[] = 'tb-steps-progress';

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

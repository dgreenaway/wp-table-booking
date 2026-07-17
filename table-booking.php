<?php
/**
 * Plugin Name: Table Booking
 * Plugin URI:  https://example.com/table-booking
 * Description: A table reservation system with visual floor plan editor.
 * Version:     1.0.0
 * Author:      Table Booking
 * Text Domain: table-booking
 * License:     GPL v2 or later
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

register_activation_hook(__FILE__, function () {
    TB_Database::install();
    TB_Reminders::activate();
    TB_Logger::info('Plugin activated — v' . TB_VERSION, 'system');
});

register_deactivation_hook(__FILE__, ['TB_Reminders', 'deactivate']);

function tb_boot() {
    TB_Reminders::init(); // register the cron action on every page load

    if (is_admin()) {
        (new TB_Admin())->init();
    }
    (new TB_Ajax())->init();

    // Register shortcode for frontend booking form
    add_shortcode('table_booking', 'tb_render_booking_form');
    add_action('wp_enqueue_scripts', 'tb_enqueue_frontend');
}
add_action('plugins_loaded', 'tb_boot');

function tb_enqueue_frontend() {
    if (!has_shortcode(get_post_field('post_content', get_the_ID()), 'table_booking')) return;

    $style      = TB_Database::get_setting('booking_style', 'modern');
    $responsive = (bool) TB_Database::get_setting('booking_responsive', '1');

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
        'ajaxUrl'  => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('tb_frontend'),
        'areas'    => json_decode(TB_Database::get_setting('areas', '[]'), true),
        'maxParty' => (int) TB_Database::get_setting('max_party_size', 12),
        'maxDays'  => (int) TB_Database::get_setting('max_advance_days', 60),
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

function tb_render_booking_form() {
    $responsive  = (bool) TB_Database::get_setting('booking_responsive', '1');
    $wrap_class  = 'tb-booking-wrap' . ($responsive ? '' : ' tb-fixed');

    ob_start();
    if (!$responsive) echo '<div class="tb-booking-outer">';
    ?>
    <div id="tb-booking-wrap" class="<?= esc_attr($wrap_class) ?>">

        <!-- Step indicators -->
        <div class="tb-steps">
            <div class="tb-step active" data-step="1"><span>1</span> Date &amp; Area</div>
            <div class="tb-step" data-step="2"><span>2</span> Time &amp; Size</div>
            <div class="tb-step" data-step="3"><span>3</span> Your Details</div>
            <div class="tb-step" data-step="4"><span>4</span> Confirm</div>
        </div>

        <div class="tb-form-body">

            <!-- Step 1: Date & Area -->
            <div class="tb-panel" id="tb-panel-1">
                <h3>When &amp; Where?</h3>
                <div class="tb-field">
                    <label for="tb-date">Date</label>
                    <input type="date" id="tb-date" class="tb-input" autocomplete="off" />
                </div>
                <div class="tb-field">
                    <label>Seating Area</label>
                    <div id="tb-area-grid" class="tb-area-grid"></div>
                </div>
                <div class="tb-nav">
                    <button class="tb-btn tb-btn-primary" id="tb-step1-next" disabled>See Available Times &rarr;</button>
                </div>
            </div>

            <!-- Step 2: Time & Party Size -->
            <div class="tb-panel" id="tb-panel-2" style="display:none;">
                <h3>Choose a Time &amp; Party Size</h3>
                <div class="tb-loading" id="tb-time-loading">Loading available times…</div>
                <div class="tb-field" id="tb-time-wrap" style="display:none;">
                    <label>Available Times</label>
                    <div id="tb-time-slots" class="tb-time-grid"></div>
                </div>
                <div class="tb-field" id="tb-party-wrap" style="display:none;">
                    <label>Party Size</label>
                    <div class="tb-party-selector" id="tb-party-selector"></div>
                </div>
                <div class="tb-nav">
                    <button class="tb-btn tb-btn-secondary" id="tb-step2-back">&larr; Back</button>
                    <button class="tb-btn tb-btn-primary" id="tb-step2-next" disabled>Next &rarr;</button>
                </div>
            </div>

            <!-- Step 3: Contact details -->
            <div class="tb-panel" id="tb-panel-3" style="display:none;">
                <h3>Your Details</h3>
                <div class="tb-field">
                    <label for="tb-name">Full Name <span class="tb-req">*</span></label>
                    <input type="text" id="tb-name" class="tb-input" placeholder="Jane Smith" autocomplete="name" />
                </div>
                <div class="tb-field">
                    <label for="tb-email">Email Address <span class="tb-req">*</span></label>
                    <input type="email" id="tb-email" class="tb-input" placeholder="jane@example.com" autocomplete="email" />
                </div>
                <div class="tb-field">
                    <label for="tb-phone">Phone Number</label>
                    <input type="tel" id="tb-phone" class="tb-input" placeholder="+44 7700 900000" autocomplete="tel" />
                </div>
                <div class="tb-field">
                    <label for="tb-notes">Special Requests</label>
                    <textarea id="tb-notes" class="tb-input" rows="3" placeholder="Allergies, high chair, anniversary, etc."></textarea>
                </div>
                <div class="tb-nav">
                    <button class="tb-btn tb-btn-secondary" id="tb-step3-back">&larr; Back</button>
                    <button class="tb-btn tb-btn-primary" id="tb-step3-next">Review &rarr;</button>
                </div>
            </div>

            <!-- Step 4: Review & confirm -->
            <div class="tb-panel" id="tb-panel-4" style="display:none;">
                <h3>Review Your Booking</h3>
                <div class="tb-summary" id="tb-summary"></div>
                <div class="tb-nav">
                    <button class="tb-btn tb-btn-secondary" id="tb-step4-back">&larr; Back</button>
                    <button class="tb-btn tb-btn-primary" id="tb-submit">Confirm Booking</button>
                </div>
            </div>

            <!-- Success -->
            <div class="tb-panel tb-success" id="tb-panel-success" style="display:none;">
                <div class="tb-success-icon">&#10003;</div>
                <h3>Booking Confirmed!</h3>
                <p id="tb-success-msg"></p>
                <div class="tb-success-ref" id="tb-success-ref"></div>
            </div>

            <!-- Error -->
            <div class="tb-error" id="tb-error" style="display:none;"></div>
        </div>
    </div>
    <?php
    if (!$responsive) echo '</div>';
    return ob_get_clean();
}

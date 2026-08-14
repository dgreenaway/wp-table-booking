<?php
defined('ABSPATH') || exit;

class TB_Admin {

    public function init(): void {
        add_action('admin_menu',            [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_tb_save_reservation',   [$this, 'handle_save_reservation']);
        add_action('admin_post_tb_save_settings',      [$this, 'handle_save_settings']);
        add_action('admin_post_tb_save_emails',        [$this, 'handle_save_emails']);
        add_action('admin_post_tb_save_styles',        [$this, 'handle_save_styles']);
        add_action('admin_post_tb_delete_reservation', [$this, 'handle_delete_reservation']);
        add_action('admin_post_tb_clear_logs',         [$this, 'handle_clear_logs']);
        add_action('admin_post_tb_export_settings',    [$this, 'handle_export_settings']);
        add_action('admin_post_tb_import_settings',    [$this, 'handle_import_settings']);
        add_action('admin_post_tb_export_csv',         [$this, 'handle_export_csv']);
        add_action('admin_post_tb_create_reservation', [$this, 'handle_create_reservation']);
        add_action('admin_post_tb_bulk_action',          [$this, 'handle_bulk_action']);
        add_action('admin_footer-plugins.php',           [$this, 'deactivation_modal']);
        add_action('admin_init',                         [$this, 'maybe_redirect_to_setup']);
        add_action('admin_post_tb_save_setup',           [$this, 'handle_save_setup']);
        add_action('admin_post_tb_create_booking_page',  [$this, 'handle_create_booking_page']);
        add_action('admin_post_tb_send_support',          [$this, 'handle_send_support']);
    }

    public function maybe_redirect_to_setup(): void {
        if (isset($_GET['activate-multi']))   return;
        if (!get_transient('tb_activation_redirect')) return;
        delete_transient('tb_activation_redirect');
        if (!current_user_can('manage_options')) return;
        if (TB_Database::get_setting('setup_complete', '0') === '1') return;
        wp_safe_redirect(admin_url('admin.php?page=tb-setup'));
        exit;
    }

    public function register_menu(): void {
        add_menu_page(
            'getBooked',
            'getBooked',
            'manage_options',
            'tb-reservations',
            [$this, 'page_reservations'],
            'dashicons-calendar-alt',
            30
        );
        add_submenu_page('tb-reservations', 'Reservations',   'Reservations',   'manage_options', 'tb-reservations', [$this, 'page_reservations']);
        add_submenu_page('tb-reservations', 'Table Layout',   'Table Layout',   'manage_options', 'tb-layout',       [$this, 'page_layout']);
        add_submenu_page('tb-reservations', 'Settings',       'Settings',       'manage_options', 'tb-settings',     [$this, 'page_settings']);
        add_submenu_page('tb-reservations', 'Emails',         'Emails',         'manage_options', 'tb-emails',       [$this, 'page_emails']);
        add_submenu_page('tb-reservations', 'Styles',         'Styles',         'manage_options', 'tb-styles',       [$this, 'page_styles']);
        add_submenu_page('tb-reservations', 'Reports',         'Reports',         'manage_options', 'tb-reports',      [$this, 'page_reports']);
        add_submenu_page('tb-reservations', 'Activity Log',   'Activity Log',   'manage_options', 'tb-logs',         [$this, 'page_logs']);
        add_submenu_page('tb-reservations', 'Support',         'Support',         'manage_options', 'tb-support',      [$this, 'page_support']);
        add_submenu_page(null,              'getBooked Setup', '',               'manage_options', 'tb-setup',        [$this, 'page_setup']);
    }

    public function enqueue_assets(string $hook): void {
        if (!str_contains($hook, 'tb-')) return;

        wp_enqueue_style('tb-admin', TB_URL . 'admin/css/admin-style.css', [], TB_VERSION);

        if (str_contains($hook, 'tb-emails')) {
            wp_enqueue_media();
        }

        if (str_contains($hook, 'tb-layout')) {
            wp_enqueue_script('tb-layout-editor', TB_URL . 'admin/js/layout-editor.js', ['jquery'], TB_VERSION, true);
            $layout = new TB_Layout();
            wp_localize_script('tb-layout-editor', 'tbLayout', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('tb_layout'),
                'tables'  => $layout->get_all(),
                'areas'   => json_decode(TB_Database::get_setting('areas', '[]'), true),
                'cWidth'  => (int) TB_Database::get_setting('canvas_width',  900),
                'cHeight' => (int) TB_Database::get_setting('canvas_height', 560),
            ]);
        }

        wp_enqueue_script('tb-admin', TB_URL . 'admin/js/admin.js', ['jquery'], TB_VERSION, true);
        wp_localize_script('tb-admin', 'tbAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('tb_admin'),
        ]);
    }

    // =========================================================================
    // Pages
    // =========================================================================

    // =========================================================================
    // Setup wizard
    // =========================================================================

    public function page_setup(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $step = max(1, min(4, (int) ($_GET['step'] ?? 1)));
        $cfg  = TB_Database::get_all_settings();
        ?>
        <style>
            #adminmenuback, #adminmenuwrap { display: none !important; }
            #wpadminbar { display: none !important; }
            #wpcontent  { margin-left: 0 !important; }
            #wpbody-content { padding-bottom: 0; }
            html { margin-top: 0 !important; }
            body.wp-admin { background: #f1f5f9 !important; }
            .tb-sw-input { width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;box-sizing:border-box;line-height:1.5; }
            .tb-sw-input:focus { border-color:#2563eb;outline:2px solid rgba(37,99,235,.2);outline-offset:0; }
            .tb-sw-label { display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px; }
            .tb-sw-btn   { width:100%;padding:11px;background:#2563eb;color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;transition:background .15s; }
            .tb-sw-btn:hover { background:#1d4ed8; }
        </style>

        <div style="min-height:100vh;background:#f1f5f9;display:flex;flex-direction:column;align-items:center;padding:48px 16px;box-sizing:border-box;">

            <div style="text-align:center;margin-bottom:32px;">
                <div style="font-size:20px;font-weight:700;color:#111827;letter-spacing:-.3px;">getBooked</div>
                <div style="font-size:13px;color:#9ca3af;margin-top:2px;">Setup Wizard</div>
            </div>

            <?php $this->render_setup_progress($step); ?>

            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:36px 40px;width:100%;max-width:540px;margin-top:28px;box-shadow:0 2px 12px rgba(0,0,0,.06);">
                <?php
                if      ($step === 1) $this->render_setup_step1($cfg);
                elseif  ($step === 2) $this->render_setup_step2($cfg);
                elseif  ($step === 3) $this->render_setup_step3($cfg);
                else                  $this->render_setup_step4();
                ?>
            </div>

            <?php if ($step < 4): ?>
            <p style="margin-top:20px;font-size:13px;color:#9ca3af;">
                <a href="<?= esc_url(admin_url('admin.php?page=tb-reservations')) ?>"
                   onclick="return confirm('Skip setup? You can always run it again from the Reservations page.');"
                   style="color:#9ca3af;text-decoration:underline;">Skip setup</a>
            </p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_setup_progress(int $step): void {
        $labels = ['Your Restaurant', 'Opening Hours', 'Seating Areas', 'Done'];
        echo '<div style="display:flex;align-items:flex-start;justify-content:center;max-width:540px;width:100%;">';
        foreach ($labels as $i => $label) {
            $num     = $i + 1;
            $is_done = $num < $step;
            $is_cur  = $num === $step;

            if ($is_done)   { $dot_bg = '#2563eb'; $dot_tx = '#fff'; $dot_inner = '&#10003;'; }
            elseif ($is_cur){ $dot_bg = '#2563eb'; $dot_tx = '#fff'; $dot_inner = (string) $num; }
            else            { $dot_bg = '#e5e7eb'; $dot_tx = '#9ca3af'; $dot_inner = (string) $num; }

            $lbl_color  = $is_cur ? '#2563eb' : ($is_done ? '#374151' : '#9ca3af');
            $lbl_weight = $is_cur ? '600'     : '400';

            echo '<div style="display:flex;flex-direction:column;align-items:center;gap:6px;flex:1;">';
            echo '<div style="width:30px;height:30px;border-radius:50%;background:' . $dot_bg . ';color:' . $dot_tx . ';display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;">' . $dot_inner . '</div>';
            echo '<span style="font-size:11px;color:' . $lbl_color . ';font-weight:' . $lbl_weight . ';text-align:center;line-height:1.3;">' . esc_html($label) . '</span>';
            echo '</div>';

            if ($i < count($labels) - 1) {
                $line_bg = ($num < $step) ? '#2563eb' : '#e5e7eb';
                echo '<div style="height:2px;background:' . $line_bg . ';flex:1;margin-top:14px;"></div>';
            }
        }
        echo '</div>';
    }

    private function render_setup_step1(array $cfg): void { ?>
        <h2 style="margin:0 0 4px;font-size:19px;font-weight:700;color:#111827;">Your restaurant</h2>
        <p style="margin:0 0 24px;color:#6b7280;font-size:14px;">These details appear in emails sent to your guests.</p>

        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <?php wp_nonce_field('tb_save_setup_1', 'tb_nonce'); ?>
            <input type="hidden" name="action" value="tb_save_setup">
            <input type="hidden" name="step"   value="1">

            <div style="margin-bottom:16px;">
                <label for="sw-name" class="tb-sw-label">Restaurant name <span style="color:#dc2626;">*</span></label>
                <input type="text" id="sw-name" name="restaurant_name" class="tb-sw-input" required
                       value="<?= esc_attr($cfg['restaurant_name'] ?? get_bloginfo('name')) ?>"
                       placeholder="e.g. The Golden Fork">
            </div>
            <div style="margin-bottom:16px;">
                <label for="sw-addr" class="tb-sw-label">Address <span style="font-weight:400;color:#9ca3af;">(optional)</span></label>
                <input type="text" id="sw-addr" name="restaurant_address" class="tb-sw-input"
                       value="<?= esc_attr($cfg['restaurant_address'] ?? '') ?>"
                       placeholder="Shown in reminder emails">
            </div>
            <div style="margin-bottom:28px;">
                <label for="sw-email" class="tb-sw-label">Booking notification email <span style="color:#dc2626;">*</span></label>
                <input type="email" id="sw-email" name="admin_email" class="tb-sw-input" required
                       value="<?= esc_attr($cfg['admin_email'] ?? get_option('admin_email')) ?>">
                <p style="margin:5px 0 0;font-size:12px;color:#9ca3af;">New booking alerts will be sent here.</p>
            </div>

            <button type="submit" class="tb-sw-btn">Continue &rarr;</button>
        </form>
    <?php }

    private function render_setup_step2(array $cfg): void {
        $weekly_h = json_decode($cfg['weekly_hours'] ?? '{}', true);
        $def_open  = $cfg['opening_time'] ?? '12:00';
        $def_close = $cfg['closing_time']  ?? '22:00';
        $days = [
            'mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu',
            'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun',
        ];
        ?>
        <h2 style="margin:0 0 4px;font-size:19px;font-weight:700;color:#111827;">Opening hours</h2>
        <p style="margin:0 0 20px;color:#6b7280;font-size:14px;">Which days are you open, and what are your hours?</p>

        <div style="margin-bottom:18px;">
            <span class="tb-sw-label" style="margin-bottom:8px;">Quick presets</span>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <button type="button" class="button sw-preset" data-days="mon,tue,wed,thu,fri">Mon – Fri</button>
                <button type="button" class="button sw-preset" data-days="mon,tue,wed,thu,fri,sat">Mon – Sat</button>
                <button type="button" class="button sw-preset" data-days="mon,tue,wed,thu,fri,sat,sun">All week</button>
            </div>
        </div>

        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <?php wp_nonce_field('tb_save_setup_2', 'tb_nonce'); ?>
            <input type="hidden" name="action" value="tb_save_setup">
            <input type="hidden" name="step"   value="2">

            <div style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;margin-bottom:20px;">
                <?php foreach ($days as $key => $lbl):
                    $day_cfg = $weekly_h[$key] ?? ['open' => ($key !== 'sun'), 'from' => $def_open, 'to' => $def_close];
                    $is_open = !empty($day_cfg['open']);
                    $from    = $day_cfg['from'] ?? $def_open;
                    $to      = $day_cfg['to']   ?? $def_close;
                ?>
                <div class="sw-day-row" data-day="<?= esc_attr($key) ?>"
                     style="display:flex;align-items:center;gap:12px;padding:10px 14px;border-bottom:1px solid #f3f4f6;">
                    <label style="display:flex;align-items:center;gap:7px;width:70px;flex-shrink:0;font-size:13px;font-weight:600;cursor:pointer;">
                        <input type="checkbox" name="weekly_hours[<?= esc_attr($key) ?>][open]" value="1"
                               class="sw-day-check" <?= $is_open ? 'checked' : '' ?>>
                        <?= esc_html($lbl) ?>
                    </label>
                    <input type="time" name="weekly_hours[<?= esc_attr($key) ?>][from]" value="<?= esc_attr($from) ?>"
                           style="border:1px solid #d1d5db;border-radius:4px;padding:4px 6px;font-size:13px;">
                    <span style="font-size:12px;color:#9ca3af;">to</span>
                    <input type="time" name="weekly_hours[<?= esc_attr($key) ?>][to]" value="<?= esc_attr($to) ?>"
                           style="border:1px solid #d1d5db;border-radius:4px;padding:4px 6px;font-size:13px;">
                </div>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="tb-sw-btn" style="margin-bottom:10px;">Continue &rarr;</button>
            <a href="<?= esc_url(admin_url('admin.php?page=tb-setup&step=1')) ?>"
               style="display:block;text-align:center;font-size:13px;color:#9ca3af;text-decoration:none;">&larr; Back</a>
        </form>

        <script>
        document.querySelectorAll('.sw-preset').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var days = this.dataset.days.split(',');
                document.querySelectorAll('.sw-day-check').forEach(function(cb) {
                    cb.checked = days.indexOf(cb.closest('.sw-day-row').dataset.day) !== -1;
                });
            });
        });
        </script>
    <?php }

    private function render_setup_step3(array $cfg): void {
        $areas = json_decode($cfg['areas'] ?? '[]', true);
        ?>
        <h2 style="margin:0 0 4px;font-size:19px;font-weight:700;color:#111827;">Seating areas</h2>
        <p style="margin:0 0 20px;color:#6b7280;font-size:14px;">Define where guests can be seated. You can always add more later.</p>

        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <?php wp_nonce_field('tb_save_setup_3', 'tb_nonce'); ?>
            <input type="hidden" name="action" value="tb_save_setup">
            <input type="hidden" name="step"   value="3">

            <div id="sw-areas-list" style="margin-bottom:10px;">
                <?php foreach ($areas as $i => $a): ?>
                <div class="sw-area-row" style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                    <input type="color"  name="areas[<?= $i ?>][color]" value="<?= esc_attr($a['color'] ?? '#3b82f6') ?>"
                           style="width:38px;height:34px;border:1px solid #d1d5db;border-radius:6px;padding:2px;cursor:pointer;flex-shrink:0;">
                    <input type="hidden" name="areas[<?= $i ?>][id]" value="<?= esc_attr($a['id'] ?? '') ?>">
                    <input type="text"   name="areas[<?= $i ?>][label]" value="<?= esc_attr($a['label'] ?? '') ?>"
                           class="tb-sw-input" style="flex:1;" placeholder="Area name" required>
                    <button type="button" class="button sw-remove-area" style="flex-shrink:0;">&times;</button>
                </div>
                <?php endforeach; ?>
            </div>

            <button type="button" id="sw-add-area" class="button"
                    style="width:100%;margin-bottom:24px;border-style:dashed;">+ Add another area</button>

            <button type="submit" class="tb-sw-btn" style="margin-bottom:10px;">Finish setup &rarr;</button>
            <a href="<?= esc_url(admin_url('admin.php?page=tb-setup&step=2')) ?>"
               style="display:block;text-align:center;font-size:13px;color:#9ca3af;text-decoration:none;">&larr; Back</a>
        </form>

        <script>
        (function() {
            var idx = <?= count($areas) ?>;
            var colours = ['#f97316','#3b82f6','#22c55e','#a855f7','#ec4899','#14b8a6'];

            document.getElementById('sw-add-area').addEventListener('click', function() {
                var list = document.getElementById('sw-areas-list');
                var row  = document.createElement('div');
                row.className = 'sw-area-row';
                row.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:8px;';
                row.innerHTML = '<input type="color" name="areas['+idx+'][color]" value="'+colours[idx % colours.length]+'" style="width:38px;height:34px;border:1px solid #d1d5db;border-radius:6px;padding:2px;cursor:pointer;flex-shrink:0;">'
                              + '<input type="hidden" name="areas['+idx+'][id]" value="">'
                              + '<input type="text" name="areas['+idx+'][label]" class="tb-sw-input" style="flex:1;" placeholder="Area name" required>'
                              + '<button type="button" class="button sw-remove-area" style="flex-shrink:0;">&times;</button>';
                list.appendChild(row);
                idx++;
                row.querySelector('input[type="text"]').focus();
            });

            document.getElementById('sw-areas-list').addEventListener('click', function(e) {
                if (e.target.classList.contains('sw-remove-area')) {
                    e.target.closest('.sw-area-row').remove();
                }
            });
        }());
        </script>
    <?php }

    private function render_setup_step4(): void {
        $page_created = isset($_GET['page_created']);
        $page_url     = $page_created ? esc_url(get_permalink((int) $_GET['page_created'])) : '';
        ?>
        <div style="text-align:center;">
            <div style="width:60px;height:60px;border-radius:50%;background:#ecfdf5;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;font-size:30px;color:#059669;">&#10003;</div>
            <h2 style="margin:0 0 8px;font-size:20px;font-weight:700;color:#111827;">You're all set!</h2>
            <p style="color:#6b7280;margin:0 0 24px;font-size:14px;line-height:1.6;">
                Add the booking form to any page using this shortcode:
            </p>

            <div style="display:flex;align-items:center;gap:8px;background:#f3f4f6;border:1px solid #d1d5db;border-radius:6px;padding:10px 14px;margin-bottom:20px;text-align:left;">
                <code id="sw-shortcode" style="flex:1;font-size:15px;font-weight:700;color:#111827;font-family:monospace;">[getbooked]</code>
                <button type="button" id="sw-copy" class="button" style="flex-shrink:0;">Copy</button>
            </div>

            <?php if ($page_url): ?>
            <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:6px;padding:10px 14px;margin-bottom:20px;font-size:13px;color:#065f46;text-align:left;">
                Booking page created. <a href="<?= $page_url ?>" target="_blank" style="color:#065f46;font-weight:600;">View it live &rarr;</a>
            </div>
            <?php else: ?>
            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="margin-bottom:16px;">
                <?php wp_nonce_field('tb_create_booking_page', 'tb_nonce'); ?>
                <input type="hidden" name="action" value="tb_create_booking_page">
                <button type="submit" class="tb-sw-btn">Create a booking page for me &rarr;</button>
            </form>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:4px;">
                <a href="<?= esc_url(admin_url('admin.php?page=tb-reservations')) ?>" class="button" style="text-align:center;">Reservations</a>
                <a href="<?= esc_url(admin_url('admin.php?page=tb-styles'))       ?>" class="button" style="text-align:center;">Choose theme</a>
                <a href="<?= esc_url(admin_url('admin.php?page=tb-emails'))       ?>" class="button" style="text-align:center;">Email settings</a>
                <a href="<?= esc_url(admin_url('admin.php?page=tb-settings'))     ?>" class="button" style="text-align:center;">All settings</a>
            </div>
        </div>

        <script>
        document.getElementById('sw-copy').addEventListener('click', function() {
            navigator.clipboard.writeText('[getbooked]').then(function() {
                var btn = document.getElementById('sw-copy');
                btn.textContent = 'Copied!';
                setTimeout(function() { btn.textContent = 'Copy'; }, 2000);
            });
        });
        </script>
    <?php }

    public function page_reservations(): void {
        $res   = new TB_Reservations();
        $stats = $res->get_stats();
        $cfg   = TB_Database::get_all_settings();
        $areas = json_decode($cfg['areas'] ?? '[]', true);
        $mode  = $cfg['booking_mode'] ?? 'simple';

        $filter_date   = sanitize_text_field($_GET['date']   ?? '');
        $filter_status = sanitize_text_field($_GET['status'] ?? '');
        $filter_area   = sanitize_text_field($_GET['area']   ?? '');
        $filter_search = sanitize_text_field($_GET['s']      ?? '');
        $paged         = max(1, (int) ($_GET['paged'] ?? 1));
        $per_page      = 20;

        $rows  = $res->get_all([
            'date'     => $filter_date,
            'status'   => $filter_status,
            'area'     => $filter_area,
            'search'   => $filter_search,
            'per_page' => $per_page,
            'page'     => $paged,
        ]);
        $total = $res->count([
            'date'   => $filter_date,
            'status' => $filter_status,
            'area'   => $filter_area,
            'search' => $filter_search,
        ]);
        $pages = ceil($total / $per_page);

        $status_options = ['pending' => 'Pending','confirmed' => 'Confirmed','seated' => 'Seated','completed' => 'Completed','cancelled' => 'Cancelled','no_show' => 'No Show'];
        $view = sanitize_text_field($_GET['view'] ?? '');

        if (!empty($_GET['print'])) {
            $print_date = sanitize_text_field($_GET['date'] ?? wp_date('Y-m-d'));
            $this->render_print_view($print_date, $areas, $status_options);
            return;
        }

        if ($view === 'new') {
            $this->render_new_reservation_form($areas, $status_options);
            return;
        }

        $detail_id = is_numeric($view) ? (int) $view : 0;

        if ($detail_id) {
            $this->render_reservation_detail($detail_id, $areas, $status_options);
            return;
        }
        ?>
        <?php if (TB_Database::get_setting('setup_complete', '0') !== '1'): ?>
        <div class="notice notice-info" style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 16px;flex-wrap:wrap;">
            <p style="margin:0;"><strong>Welcome to getBooked!</strong> Complete the 3-step setup to configure your restaurant and start taking bookings.</p>
            <a href="<?= esc_url(admin_url('admin.php?page=tb-setup')) ?>" class="button button-primary" style="white-space:nowrap;">Run setup wizard &rarr;</a>
        </div>
        <?php endif; ?>
        <?php if (isset($_GET['created'])): ?>
        <div class="notice notice-success is-dismissible"><p>Reservation created successfully.</p></div>
        <?php endif; ?>
        <?php if (!empty($_GET['bulk_done'])): ?>
        <div class="notice notice-success is-dismissible"><p><?= esc_html((int) $_GET['bulk_done']) ?> reservation(s) updated.</p></div>
        <?php endif; ?>
        <div class="wrap tb-wrap">
            <h1 class="wp-heading-inline">getBooked</h1>
            <a href="<?= esc_url(admin_url('admin.php?page=tb-reservations&view=new')) ?>" class="page-title-action">Add Reservation</a>
            <?php if ($mode === 'simple'): ?>
            <span class="tb-mode-badge tb-mode-simple">Simple mode &mdash; <?= esc_html($cfg['max_seats'] ?? 50) ?> covers/slot</span>
            <?php else: ?>
            <span class="tb-mode-badge tb-mode-layout">Floor Plan mode</span>
            <?php endif; ?>
            <hr class="wp-header-end">

            <div class="tb-stat-bar">
                <div class="tb-stat">
                    <span class="tb-stat-num"><?= esc_html($stats['today']) ?></span>
                    <span class="tb-stat-lbl">Today's bookings</span>
                </div>
                <div class="tb-stat">
                    <span class="tb-stat-num tb-orange"><?= esc_html($stats['pending']) ?></span>
                    <span class="tb-stat-lbl">Awaiting confirmation</span>
                </div>
                <div class="tb-stat">
                    <span class="tb-stat-num tb-blue"><?= esc_html($stats['upcoming']) ?></span>
                    <span class="tb-stat-lbl">Upcoming</span>
                </div>
            </div>

            <form method="get" class="tb-filter-bar" id="tb-filter-form">
                <input type="hidden" name="page" value="tb-reservations">
                <input type="date"   name="date"   value="<?= esc_attr($filter_date) ?>"   class="tb-filter-input" title="Filter by date">
                <select name="status" class="tb-filter-input">
                    <option value="">All Statuses</option>
                    <?php foreach ($status_options as $val => $lbl): ?>
                    <option value="<?= esc_attr($val) ?>" <?= selected($filter_status, $val, false) ?>><?= esc_html($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="area" class="tb-filter-input">
                    <option value="">All Areas</option>
                    <?php foreach ($areas as $a): ?>
                    <option value="<?= esc_attr($a['id']) ?>" <?= selected($filter_area, $a['id'], false) ?>><?= esc_html($a['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="search" name="s" value="<?= esc_attr($filter_search) ?>" placeholder="Search name / email…" class="tb-filter-input">
                <button type="submit" class="button button-primary">Filter</button>
                <a href="?page=tb-reservations" class="button">Clear</a>
                <?php
                $export_url = add_query_arg([
                    'action'   => 'tb_export_csv',
                    'date'     => $filter_date,
                    'status'   => $filter_status,
                    'area'     => $filter_area,
                    's'        => $filter_search,
                    'tb_nonce' => wp_create_nonce('tb_export_csv'),
                ], admin_url('admin-post.php'));
                ?>
                <a href="<?= esc_url($export_url) ?>" class="button" style="margin-left:auto;">Export CSV</a>
                <?php
                $print_url = add_query_arg([
                    'page'  => 'tb-reservations',
                    'print' => '1',
                    'date'  => $filter_date ?: wp_date('Y-m-d'),
                ], admin_url('admin.php'));
                ?>
                <a href="<?= esc_url($print_url) ?>" class="button" target="_blank">Print Run Sheet</a>
            </form>

            <?php if (empty($rows)): ?>
            <div class="tb-empty">No reservations found.</div>
            <?php else: ?>
            <?php $sit_dur = (int) ($cfg['sitting_duration'] ?? 90); ?>
            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" id="tb-bulk-form">
                <?php wp_nonce_field('tb_bulk_action', 'tb_nonce'); ?>
                <input type="hidden" name="action" value="tb_bulk_action">

                <div class="tablenav top" style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                    <select name="bulk_action" id="tb-bulk-action">
                        <option value="">— Bulk Actions —</option>
                        <option value="confirm">Confirm</option>
                        <option value="cancel">Cancel</option>
                        <option value="delete">Delete</option>
                    </select>
                    <button type="submit" class="button" id="tb-bulk-apply">Apply</button>
                    <span id="tb-bulk-count" style="color:#6b7280;font-size:13px;"></span>
                </div>

                <table class="wp-list-table widefat fixed striped tb-table">
                    <thead>
                        <tr>
                            <th style="width:32px;"><input type="checkbox" id="tb-check-all" title="Select all"></th>
                            <th style="width:130px">Reference</th>
                            <th>Name</th>
                            <th>Date</th>
                            <th>Time &rarr; Until</th>
                            <th>Party</th>
                            <th>Area</th>
                            <th>Table</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row):
                        $area_lbl   = $row['seating_area'];
                        foreach ($areas as $a) { if ($a['id'] === $row['seating_area']) { $area_lbl = $a['label']; break; } }
                        $detail_url = add_query_arg(['page' => 'tb-reservations', 'view' => $row['id']], admin_url('admin.php'));
                        $start_ts   = strtotime($row['reservation_time']);
                        $end_ts     = $start_ts + $sit_dur * 60;
                    ?>
                    <tr>
                        <td><input type="checkbox" name="bulk_ids[]" value="<?= esc_attr($row['id']) ?>" class="tb-row-check"></td>
                        <td><code><?= esc_html($row['reservation_number']) ?></code></td>
                        <td><?= esc_html($row['customer_name']) ?></td>
                        <td><?= esc_html(wp_date('d M Y', strtotime($row['reservation_date']))) ?></td>
                        <td>
                            <?= esc_html(wp_date('g:i A', $start_ts)) ?>
                            <span class="tb-until">&rarr; <?= esc_html(wp_date('g:i A', $end_ts)) ?></span>
                        </td>
                        <td><?= esc_html($row['party_size']) ?></td>
                        <td><?= esc_html($area_lbl) ?></td>
                        <td><?= esc_html($row['table_name'] ?? '—') ?></td>
                        <td><span class="tb-badge tb-badge-<?= esc_attr($row['status']) ?>"><?= esc_html($status_options[$row['status']] ?? $row['status']) ?></span></td>
                        <td>
                            <a href="<?= esc_url($detail_url) ?>" class="button button-small">View</a>
                            <button class="button button-small tb-status-btn"
                                    data-id="<?= esc_attr($row['id']) ?>"
                                    data-status="<?= esc_attr($row['status']) ?>">
                                <?= $row['status'] === 'pending' ? 'Confirm' : 'Update' ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </form>

            <?php if ($pages > 1): ?>
            <div class="tb-pagination">
                <?php for ($p = 1; $p <= $pages; $p++): ?>
                <a href="<?= esc_url(add_query_arg(['paged' => $p])) ?>"
                   class="button <?= $p === $paged ? 'button-primary' : '' ?>"><?= $p ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <div id="tb-status-modal" class="tb-modal" style="display:none;">
            <div class="tb-modal-box">
                <h3>Update Reservation Status</h3>
                <input type="hidden" id="tb-modal-id" value="">
                <select id="tb-modal-status">
                    <?php foreach ($status_options as $v => $l): ?>
                    <option value="<?= esc_attr($v) ?>"><?= esc_html($l) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="tb-modal-actions">
                    <button class="button button-primary" id="tb-modal-save">Save</button>
                    <button class="button" id="tb-modal-cancel">Cancel</button>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_new_reservation_form(array $areas, array $status_options): void {
        $cfg     = TB_Database::get_all_settings();
        $layout  = new TB_Layout();
        $back    = admin_url('admin.php?page=tb-reservations');
        $opening = $cfg['opening_time'] ?? '12:00';
        ?>
        <div class="wrap tb-wrap">
            <h1><a href="<?= esc_url($back) ?>" class="tb-back">&larr;</a> New Reservation</h1>
            <hr class="wp-header-end">

            <div class="tb-detail-grid" style="max-width:640px;">
                <div class="tb-detail-card" style="grid-column:1/-1;">
                    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
                        <?php wp_nonce_field('tb_create_reservation', 'tb_nonce'); ?>
                        <input type="hidden" name="action" value="tb_create_reservation">

                        <h3 style="margin-top:0;">Reservation Details</h3>
                        <table class="form-table" style="margin-top:0;">
                            <tr>
                                <th><label for="nr-date">Date <span style="color:#dc2626;">*</span></label></th>
                                <td><input type="date" id="nr-date" name="reservation_date" required class="tb-admin-input"></td>
                            </tr>
                            <tr>
                                <th><label for="nr-time">Time <span style="color:#dc2626;">*</span></label></th>
                                <td>
                                    <input type="time" id="nr-time" name="reservation_time" value="<?= esc_attr($opening) ?>" required class="tb-admin-input">
                                    <p class="description">Use 24-hour format, e.g. 19:30</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="nr-area">Seating Area <span style="color:#dc2626;">*</span></label></th>
                                <td>
                                    <select id="nr-area" name="seating_area" required class="tb-admin-input">
                                        <?php foreach ($areas as $a): ?>
                                        <option value="<?= esc_attr($a['id']) ?>"><?= esc_html($a['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="nr-party">Party Size <span style="color:#dc2626;">*</span></label></th>
                                <td><input type="number" id="nr-party" name="party_size" value="2" min="1" max="100" required class="tb-admin-input" style="width:80px;"></td>
                            </tr>
                            <?php if (($cfg['booking_mode'] ?? 'simple') === 'layout'): ?>
                            <tr>
                                <th><label for="nr-table">Assign Table</label></th>
                                <td>
                                    <select id="nr-table" name="table_id" class="tb-admin-input">
                                        <option value="">— Auto / Unassigned —</option>
                                        <?php foreach ($layout->get_all() as $t): ?>
                                        <option value="<?= esc_attr($t['id']) ?>"><?= esc_html($t['table_name']) ?> (cap. <?= esc_html($t['capacity']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th><label for="nr-status">Status</label></th>
                                <td>
                                    <select id="nr-status" name="status" class="tb-admin-input">
                                        <?php foreach ($status_options as $v => $l): ?>
                                        <option value="<?= esc_attr($v) ?>" <?= selected($v, 'confirmed', false) ?>><?= esc_html($l) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        </table>

                        <h3>Guest Information</h3>
                        <table class="form-table" style="margin-top:0;">
                            <tr>
                                <th><label for="nr-name">Full Name <span style="color:#dc2626;">*</span></label></th>
                                <td><input type="text" id="nr-name" name="customer_name" required class="regular-text tb-admin-input" autocomplete="off"></td>
                            </tr>
                            <tr>
                                <th><label for="nr-email">Email</label></th>
                                <td><input type="email" id="nr-email" name="customer_email" class="regular-text tb-admin-input" autocomplete="off"></td>
                            </tr>
                            <tr>
                                <th><label for="nr-phone">Phone</label></th>
                                <td><input type="tel" id="nr-phone" name="customer_phone" class="regular-text tb-admin-input"></td>
                            </tr>
                            <tr>
                                <th><label for="nr-requests">Special Requests</label></th>
                                <td><textarea id="nr-requests" name="special_requests" rows="3" class="large-text tb-admin-input"></textarea></td>
                            </tr>
                            <tr>
                                <th><label for="nr-notes">Admin Notes</label></th>
                                <td><textarea id="nr-notes" name="admin_notes" rows="2" class="large-text tb-admin-input"></textarea></td>
                            </tr>
                            <tr>
                                <th>Notify Guest</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="notify_guest" value="1" checked>
                                        Send confirmation email to guest
                                    </label>
                                    <p class="description">Unchecked for phone bookings where the guest doesn't need an email.</p>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" class="button button-primary">Create Reservation</button>
                            <a href="<?= esc_url($back) ?>" class="button" style="margin-left:6px;">Cancel</a>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_reservation_detail(int $id, array $areas, array $status_options): void {
        $res    = new TB_Reservations();
        $layout = new TB_Layout();
        $row    = $res->get($id);

        if (!$row) {
            echo '<div class="wrap"><div class="notice notice-error"><p>Reservation not found.</p></div></div>';
            return;
        }

        $area_lbl    = $row['seating_area'];
        foreach ($areas as $a) { if ($a['id'] === $row['seating_area']) { $area_lbl = $a['label']; break; } }
        $tables_list = $layout->get_all($row['seating_area']);
        $back_url    = admin_url('admin.php?page=tb-reservations');
        ?>
        <div class="wrap tb-wrap">
            <h1><a href="<?= esc_url($back_url) ?>" class="tb-back">&larr;</a> Reservation <?= esc_html($row['reservation_number']) ?></h1>
            <hr class="wp-header-end">

            <div class="tb-detail-grid">
                <div class="tb-detail-card">
                    <h3>Guest Information</h3>
                    <table class="tb-detail-table">
                        <tr><th>Name</th><td><?= esc_html($row['customer_name']) ?></td></tr>
                        <tr><th>Email</th><td><a href="mailto:<?= esc_attr($row['customer_email']) ?>"><?= esc_html($row['customer_email']) ?></a></td></tr>
                        <tr><th>Phone</th><td><?= esc_html($row['customer_phone'] ?: '—') ?></td></tr>
                    </table>

                    <h3>Reservation Details</h3>
                    <table class="tb-detail-table">
                        <tr><th>Date</th><td><?= esc_html(wp_date('l, F j, Y', strtotime($row['reservation_date']))) ?></td></tr>
                        <?php
                            $sit_min  = (int) TB_Database::get_setting('sitting_duration', '90');
                            $end_time = wp_date('g:i A', strtotime($row['reservation_time']) + $sit_min * 60);
                        ?>
                        <tr><th>Time</th><td><?= esc_html(wp_date('g:i A', strtotime($row['reservation_time']))) ?> &rarr; <?= esc_html($end_time) ?> <span style="color:#9ca3af;font-size:11px;">(<?= esc_html($sit_min) ?> min sitting)</span></td></tr>
                        <tr><th>Party</th><td><?= esc_html($row['party_size']) ?> guests</td></tr>
                        <tr><th>Area</th><td><?= esc_html($area_lbl) ?></td></tr>
                        <tr><th>Table</th><td><?= esc_html($row['table_name'] ?? 'Unassigned') ?></td></tr>
                        <tr><th>Status</th><td><span class="tb-badge tb-badge-<?= esc_attr($row['status']) ?>"><?= esc_html($status_options[$row['status']] ?? $row['status']) ?></span></td></tr>
                        <tr><th>Booked</th><td><?= esc_html(wp_date('d M Y H:i', strtotime($row['created_at']))) ?></td></tr>
                    </table>

                    <?php if (!empty($row['special_requests'])): ?>
                    <h3>Special Requests</h3>
                    <p class="tb-requests"><?= nl2br(esc_html($row['special_requests'])) ?></p>
                    <?php endif; ?>
                </div>

                <div class="tb-detail-card">
                    <h3>Edit Reservation</h3>
                    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
                        <?php wp_nonce_field('tb_save_reservation', 'tb_nonce'); ?>
                        <input type="hidden" name="action" value="tb_save_reservation">
                        <input type="hidden" name="id"     value="<?= esc_attr($id) ?>">

                        <div class="tb-field">
                            <label>Status</label>
                            <select name="status" class="tb-admin-input">
                                <?php foreach ($status_options as $v => $l): ?>
                                <option value="<?= esc_attr($v) ?>" <?= selected($row['status'], $v, false) ?>><?= esc_html($l) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="tb-field">
                            <label>Assign Table</label>
                            <select name="table_id" class="tb-admin-input">
                                <option value="">— Unassigned —</option>
                                <?php foreach ($tables_list as $t): ?>
                                <option value="<?= esc_attr($t['id']) ?>" <?= selected((int)$row['table_id'], (int)$t['id'], false) ?>>
                                    <?= esc_html($t['table_name']) ?> (cap. <?= esc_html($t['capacity']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="tb-field">
                            <label>Admin Notes</label>
                            <textarea name="admin_notes" rows="4" class="tb-admin-input"><?= esc_textarea($row['admin_notes'] ?? '') ?></textarea>
                        </div>

                        <div class="tb-field-row">
                            <button type="submit" class="button button-primary">Save Changes</button>
                            <a href="<?= esc_url(add_query_arg(['action' => 'tb_delete_reservation', 'id' => $id, 'tb_nonce' => wp_create_nonce('tb_delete_'.$id)], admin_url('admin-post.php'))) ?>"
                               class="button tb-btn-danger"
                               onclick="return confirm('Delete this reservation?')">Delete</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    public function page_layout(): void {
        $cfg   = TB_Database::get_all_settings();
        $mode  = $cfg['booking_mode'] ?? 'simple';
        $areas = json_decode($cfg['areas'] ?? '[]', true);

        if ($mode === 'simple') { ?>
        <div class="wrap tb-wrap">
            <h1>Table Layout Editor</h1>
            <hr class="wp-header-end">
            <div class="notice notice-info" style="margin-top:16px;">
                <p>
                    <strong>Floor Plan mode is not active.</strong>
                    You are currently using <strong>Simple mode</strong>, where availability is managed by a maximum cover count.
                    To use the floor plan editor, switch to <strong>Floor Plan</strong> mode in
                    <a href="<?= esc_url(admin_url('admin.php?page=tb-settings')) ?>">Settings → Booking Mode</a>.
                </p>
            </div>
        </div>
        <?php return; }
        ?>
        <div class="wrap tb-wrap">
            <h1>Table Layout Editor</h1>
            <hr class="wp-header-end">

            <div class="tb-layout-page">
                <div class="tb-layout-toolbar">
                    <div class="tb-tool-group">
                        <button class="tb-tool-btn active" id="tb-tool-select" title="Select / Move">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 3l14 9-7 1-3 7z"/></svg>
                            Select
                        </button>
                        <button class="tb-tool-btn" id="tb-tool-add" title="Draw a new table">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                            Add Table
                        </button>
                        <button class="tb-tool-btn tb-btn-danger-tool" id="tb-tool-delete" title="Delete selected table">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                            Delete
                        </button>
                    </div>

                    <div class="tb-tool-group" id="tb-add-options" style="display:none;">
                        <select id="tb-new-area" class="tb-filter-input">
                            <?php foreach ($areas as $a): ?>
                            <option value="<?= esc_attr($a['id']) ?>"><?= esc_html($a['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="tb-new-shape" class="tb-filter-input">
                            <option value="square">Square</option>
                            <option value="rectangle">Rectangle</option>
                            <option value="circle">Circle</option>
                        </select>
                        <input type="number" id="tb-new-capacity" value="4" min="1" max="30" class="tb-filter-input" style="width:70px;" title="Capacity">
                        <label style="font-size:12px;color:#555;">cap.</label>
                    </div>

                    <div class="tb-tool-group tb-tool-right">
                        <label style="font-size:12px;color:#555;">View bookings for:</label>
                        <input type="date" id="tb-overlay-date" class="tb-filter-input">
                        <select id="tb-overlay-time" class="tb-filter-input">
                            <option value="">Select time…</option>
                        </select>
                        <button class="button" id="tb-save-layout">Save Layout</button>
                    </div>
                </div>

                <div class="tb-layout-body">
                    <div class="tb-canvas-wrap">
                        <canvas id="tb-canvas"
                                width="<?= esc_attr($cfg['canvas_width'] ?? 900) ?>"
                                height="<?= esc_attr($cfg['canvas_height'] ?? 560) ?>"></canvas>
                    </div>

                    <div class="tb-props-panel" id="tb-props-panel">
                        <h3>Table Properties</h3>
                        <p class="tb-props-hint" id="tb-props-hint">Select a table to edit its properties.</p>

                        <div id="tb-props-form" style="display:none;">
                            <div class="tb-field">
                                <label>Name</label>
                                <input type="text" id="tb-prop-name" class="tb-admin-input" maxlength="50">
                            </div>
                            <div class="tb-field">
                                <label>Area</label>
                                <select id="tb-prop-area" class="tb-admin-input">
                                    <?php foreach ($areas as $a): ?>
                                    <option value="<?= esc_attr($a['id']) ?>"><?= esc_html($a['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="tb-field">
                                <label>Shape</label>
                                <select id="tb-prop-shape" class="tb-admin-input">
                                    <option value="square">Square</option>
                                    <option value="rectangle">Rectangle</option>
                                    <option value="circle">Circle</option>
                                </select>
                            </div>
                            <div class="tb-field-row">
                                <div class="tb-field">
                                    <label>Min cap.</label>
                                    <input type="number" id="tb-prop-min-cap" class="tb-admin-input" min="1" max="30">
                                </div>
                                <div class="tb-field">
                                    <label>Max cap.</label>
                                    <input type="number" id="tb-prop-capacity" class="tb-admin-input" min="1" max="30">
                                </div>
                            </div>
                            <div class="tb-field-row">
                                <div class="tb-field"><label>X</label><input type="number" id="tb-prop-x" class="tb-admin-input" min="0"></div>
                                <div class="tb-field"><label>Y</label><input type="number" id="tb-prop-y" class="tb-admin-input" min="0"></div>
                            </div>
                            <div class="tb-field-row">
                                <div class="tb-field"><label>W</label><input type="number" id="tb-prop-w" class="tb-admin-input" min="40"></div>
                                <div class="tb-field"><label>H</label><input type="number" id="tb-prop-h" class="tb-admin-input" min="40"></div>
                            </div>
                            <button class="button button-primary" id="tb-apply-props" style="width:100%;margin-top:8px;">Apply</button>
                        </div>

                        <div class="tb-legend">
                            <h4>Legend</h4>
                            <?php foreach ($areas as $a): ?>
                            <div class="tb-legend-item">
                                <span class="tb-legend-dot" style="background:<?= esc_attr($a['color']) ?>"></span>
                                <?= esc_html($a['label']) ?>
                            </div>
                            <?php endforeach; ?>
                            <div class="tb-legend-item">
                                <span class="tb-legend-dot" style="background:#ef4444"></span>
                                Booked
                            </div>
                        </div>
                    </div>
                </div>

                <div id="tb-layout-msg" style="margin-top:8px;"></div>
            </div>
        </div>
        <?php
    }

    public function page_settings(): void {
        $cfg        = TB_Database::get_all_settings();
        $areas      = json_decode($cfg['areas'] ?? '[]', true);
        $valid_tabs = ['booking', 'hours', 'areas', 'advanced'];
        $tab        = isset($_GET['tab']) && in_array($_GET['tab'], $valid_tabs, true)
                      ? $_GET['tab'] : 'booking';

        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
        }
        if (isset($_GET['error']) && $_GET['error'] === 'db') {
            echo '<div class="notice notice-error is-dismissible"><p>Settings could not be saved due to a database error. Please try again, or check the Activity Log for details.</p></div>';
        }
        ?>
        <div class="wrap tb-wrap">
            <h1>getBooked Settings</h1>
            <hr class="wp-header-end">

            <nav class="nav-tab-wrapper" style="margin-bottom:0;padding-bottom:0;">
                <?php
                $tab_labels = ['booking' => 'Booking', 'hours' => 'Hours', 'areas' => 'Areas', 'advanced' => 'Advanced'];
                foreach ($tab_labels as $slug => $label):
                    $url = add_query_arg(['page' => 'tb-settings', 'tab' => $slug], admin_url('admin.php'));
                ?>
                <a href="<?= esc_url($url) ?>" class="nav-tab <?= $tab === $slug ? 'nav-tab-active' : '' ?>"><?= esc_html($label) ?></a>
                <?php endforeach; ?>
            </nav>

            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" class="tb-settings-form">
                <?php wp_nonce_field('tb_save_settings', 'tb_nonce'); ?>
                <input type="hidden" name="action" value="tb_save_settings">
                <input type="hidden" name="tb_settings_tab" value="<?= esc_attr($tab) ?>">

                <!-- ══ TAB: Booking ══════════════════════════════════════ -->
                <div id="tb-tab-booking" <?= $tab !== 'booking' ? 'style="display:none"' : '' ?>>

                    <?php $mode = $cfg['booking_mode'] ?? 'simple'; ?>
                    <div class="tb-settings-section">
                        <h2>Booking Mode</h2>
                        <p class="description">Choose how the system manages table availability.</p>
                        <div class="tb-mode-cards">
                            <label class="tb-mode-card <?= $mode === 'simple' ? 'tb-mode-selected' : '' ?>">
                                <input type="radio" name="booking_mode" value="simple" <?= checked($mode, 'simple', false) ?>>
                                <div class="tb-mode-card-body">
                                    <strong>Simple</strong>
                                    <span>Set a maximum number of covers per time slot. No floor plan needed — great for small venues.</span>
                                </div>
                            </label>
                            <label class="tb-mode-card <?= $mode === 'layout' ? 'tb-mode-selected' : '' ?>">
                                <input type="radio" name="booking_mode" value="layout" <?= checked($mode, 'layout', false) ?>>
                                <div class="tb-mode-card-body">
                                    <strong>Floor Plan</strong>
                                    <span>Draw individual tables on a floor plan. Bookings are assigned to specific tables.</span>
                                </div>
                            </label>
                        </div>
                        <div id="tb-simple-opts" style="margin-top:16px;<?= $mode !== 'simple' ? 'display:none;' : '' ?>">
                            <table class="form-table" style="margin-top:0;">
                                <tr>
                                    <th><label for="s-max-seats">Max covers per slot</label></th>
                                    <td>
                                        <input type="number" id="s-max-seats" name="max_seats"
                                               value="<?= esc_attr($cfg['max_seats'] ?? 50) ?>"
                                               min="1" max="9999" class="small-text">
                                        <p class="description">Total number of guests that can be booked into any single time slot across all areas.</p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <div class="tb-settings-section">
                        <h2>General</h2>
                        <table class="form-table">
                            <tr>
                                <th><label for="s-name">Restaurant Name</label></th>
                                <td><input type="text" id="s-name" name="restaurant_name" value="<?= esc_attr($cfg['restaurant_name'] ?? '') ?>" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><label for="s-addr">Restaurant Address</label></th>
                                <td><input type="text" id="s-addr" name="restaurant_address" value="<?= esc_attr($cfg['restaurant_address'] ?? '') ?>" class="regular-text" placeholder="Shown in reminder emails"></td>
                            </tr>
                        </table>
                    </div>

                    <div class="tb-settings-section">
                        <h2>Slot Settings</h2>
                        <table class="form-table">
                            <tr>
                                <th><label for="s-dur">Slot Duration</label></th>
                                <td>
                                    <select id="s-dur" name="slot_duration">
                                        <?php foreach ([30,60,90,120] as $m): ?>
                                        <option value="<?= $m ?>" <?= selected((int)($cfg['slot_duration'] ?? 60), $m, false) ?>><?= $m ?> minutes</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Interval between available booking times shown to guests.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="s-sit">Sitting Duration</label></th>
                                <td>
                                    <select id="s-sit" name="sitting_duration">
                                        <?php foreach ([30,45,60,75,90,105,120,150,180] as $m):
                                            $hrs = $m >= 60 ? floor($m / 60) . 'h' . ($m % 60 ? ' ' . ($m % 60) . 'm' : '') : $m . 'm';
                                        ?>
                                        <option value="<?= $m ?>" <?= selected((int)($cfg['sitting_duration'] ?? 90), $m, false) ?>><?= $hrs ?> (<?= $m ?> min)</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">How long a party occupies a table/slot after their booking time. A 1:00 PM booking with a 90-minute sitting means the table is free again at 2:30 PM.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="s-lbo">Last booking offset</label></th>
                                <td>
                                    <input type="number" id="s-lbo" name="last_booking_offset" value="<?= esc_attr($cfg['last_booking_offset'] ?? 60) ?>" min="0" max="480" class="small-text">
                                    minutes before closing
                                    <p class="description">Additional buffer after the sitting duration. Set to 0 to use only the sitting duration as the cutoff.</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="tb-settings-section">
                        <h2>Booking Limits</h2>
                        <table class="form-table">
                            <tr>
                                <th><label for="s-minadv">Min advance notice</label></th>
                                <td><input type="number" id="s-minadv" name="min_advance_hours" value="<?= esc_attr($cfg['min_advance_hours'] ?? 2) ?>" min="0" max="72" class="small-text"> hours</td>
                            </tr>
                            <tr>
                                <th><label for="s-maxadv">Max advance booking</label></th>
                                <td><input type="number" id="s-maxadv" name="max_advance_days" value="<?= esc_attr($cfg['max_advance_days'] ?? 60) ?>" min="1" max="365" class="small-text"> days ahead</td>
                            </tr>
                            <tr>
                                <th><label for="s-party">Max party size</label></th>
                                <td><input type="number" id="s-party" name="max_party_size" value="<?= esc_attr($cfg['max_party_size'] ?? 12) ?>" min="1" max="100" class="small-text"> guests</td>
                            </tr>
                        </table>
                    </div>

                </div><!-- /tab-booking -->

                <!-- ══ TAB: Hours ════════════════════════════════════════ -->
                <div id="tb-tab-hours" <?= $tab !== 'hours' ? 'style="display:none"' : '' ?>>

                    <?php
                    $weekly_hours = json_decode($cfg['weekly_hours'] ?? '{}', true);
                    $days         = ['mon'=>'Monday','tue'=>'Tuesday','wed'=>'Wednesday','thu'=>'Thursday','fri'=>'Friday','sat'=>'Saturday','sun'=>'Sunday'];
                    $default_open  = $cfg['opening_time'] ?? '12:00';
                    $default_close = $cfg['closing_time']  ?? '22:00';
                    ?>
                    <div class="tb-settings-section">
                        <h2>Opening Hours</h2>
                        <p class="description">Set the days and hours guests can make bookings. Closed days will show no available times.</p>
                        <table class="form-table">
                            <?php foreach ($days as $key => $label):
                                $day_cfg = $weekly_hours[$key] ?? ['open' => true, 'from' => $default_open, 'to' => $default_close];
                                $is_open = !empty($day_cfg['open']);
                                $from    = $day_cfg['from'] ?? $default_open;
                                $to      = $day_cfg['to']   ?? $default_close;
                            ?>
                            <tr>
                                <th style="width:130px;"><?= esc_html($label) ?></th>
                                <td>
                                    <label style="margin-right:16px;">
                                        <input type="checkbox" name="weekly_hours[<?= esc_attr($key) ?>][open]" value="1"
                                            <?= checked($is_open, true, false) ?>> Open
                                    </label>
                                    <label>From
                                        <input type="time" name="weekly_hours[<?= esc_attr($key) ?>][from]"
                                            value="<?= esc_attr($from) ?>" style="margin-left:6px;margin-right:10px;">
                                    </label>
                                    <label>To
                                        <input type="time" name="weekly_hours[<?= esc_attr($key) ?>][to]"
                                            value="<?= esc_attr($to) ?>" style="margin-left:6px;">
                                    </label>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>

                    <?php $closed_dates = json_decode($cfg['closed_dates'] ?? '[]', true); ?>
                    <div class="tb-settings-section">
                        <h2>Closed Dates</h2>
                        <p class="description">Mark specific dates as unavailable — bank holidays, private events, etc.</p>
                        <input type="hidden" name="closed_dates" id="tb-closed-dates-json" value="<?= esc_attr(wp_json_encode(array_values((array) $closed_dates))) ?>">
                        <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;">
                            <input type="date" id="tb-closed-date-picker" class="tb-admin-input" style="width:180px;">
                            <button type="button" class="button" id="tb-add-closed-date">Add Date</button>
                        </div>
                        <div id="tb-closed-dates-list" style="display:flex;flex-wrap:wrap;gap:6px;min-height:24px;"></div>
                    </div>
                    <script>
                    (function () {
                        var dates = <?= wp_json_encode(array_values((array) $closed_dates)) ?>;
                        function render() {
                            var el = document.getElementById('tb-closed-dates-list');
                            el.innerHTML = dates.length ? dates.map(function (d) {
                                var parts = d.split('-');
                                var label = new Date(parts[0], parts[1]-1, parts[2]).toLocaleDateString(undefined, {day:'numeric',month:'short',year:'numeric'});
                                return '<span style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;background:#fee2e2;color:#991b1b;border-radius:20px;font-size:12px;font-weight:600;">'
                                     + label
                                     + '<button type="button" data-date="'+d+'" aria-label="Remove '+d+'" style="background:none;border:none;cursor:pointer;padding:0;line-height:1;color:#991b1b;font-size:16px;margin-left:2px;">&times;</button></span>';
                            }).join('') : '<em style="color:#9ca3af;font-size:13px;">No closed dates set.</em>';
                            document.getElementById('tb-closed-dates-json').value = JSON.stringify(dates);
                        }
                        render();
                        document.getElementById('tb-add-closed-date').addEventListener('click', function () {
                            var v = document.getElementById('tb-closed-date-picker').value;
                            if (!v || dates.indexOf(v) !== -1) return;
                            dates.push(v); dates.sort();
                            document.getElementById('tb-closed-date-picker').value = '';
                            render();
                        });
                        document.getElementById('tb-closed-dates-list').addEventListener('click', function (e) {
                            var btn = e.target.closest('button[data-date]');
                            if (!btn) return;
                            dates = dates.filter(function (x) { return x !== btn.dataset.date; });
                            render();
                        });
                    }());
                    </script>

                </div><!-- /tab-hours -->

                <!-- ══ TAB: Areas ════════════════════════════════════════ -->
                <div id="tb-tab-areas" <?= $tab !== 'areas' ? 'style="display:none"' : '' ?>>

                    <div class="tb-settings-section">
                        <h2>Seating Areas</h2>
                        <p class="description">Define which seating areas guests can choose from.</p>
                        <div id="tb-areas-list">
                            <?php foreach ($areas as $i => $a): ?>
                            <div class="tb-area-row" data-index="<?= $i ?>">
                                <input type="text"  name="areas[<?= $i ?>][id]"    value="<?= esc_attr($a['id']) ?>"    placeholder="id (no spaces)" class="tb-admin-input" style="width:120px;" readonly>
                                <input type="text"  name="areas[<?= $i ?>][label]" value="<?= esc_attr($a['label']) ?>" placeholder="Display name"    class="tb-admin-input" style="width:160px;">
                                <input type="color" name="areas[<?= $i ?>][color]" value="<?= esc_attr($a['color']) ?>" class="tb-color-input">
                                <button type="button" class="button tb-remove-area">Remove</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="button" id="tb-add-area" style="margin-top:8px;">+ Add Area</button>
                    </div>

                    <div class="tb-settings-section">
                        <h2>Floor Plan Canvas</h2>
                        <table class="form-table">
                            <tr>
                                <th><label for="s-cw">Canvas Width (px)</label></th>
                                <td><input type="number" id="s-cw" name="canvas_width"  value="<?= esc_attr($cfg['canvas_width']  ?? 900) ?>" min="400" max="2000" class="small-text"></td>
                            </tr>
                            <tr>
                                <th><label for="s-ch">Canvas Height (px)</label></th>
                                <td><input type="number" id="s-ch" name="canvas_height" value="<?= esc_attr($cfg['canvas_height'] ?? 560) ?>" min="300" max="2000" class="small-text"></td>
                            </tr>
                        </table>
                    </div>

                </div><!-- /tab-areas -->

                <!-- ══ TAB: Advanced ═════════════════════════════════════ -->
                <div id="tb-tab-advanced" <?= $tab !== 'advanced' ? 'style="display:none"' : '' ?>>

                    <div class="tb-settings-section">
                        <h2>Data &amp; Privacy</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Data retention</th>
                                <td>
                                    <label>
                                        Auto-delete completed, cancelled, and no-show reservations older than
                                        <input type="number" name="data_retention_days"
                                               value="<?= esc_attr($cfg['data_retention_days'] ?? '0') ?>"
                                               min="0" max="3650" class="small-text"> days
                                    </label>
                                    <p class="description">Set to <strong>0</strong> to keep all reservations indefinitely. When set, a weekly background job removes old closed bookings. Active, pending, and confirmed reservations are never auto-deleted.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Usage data</th>
                                <td>
                                    <?php $tel_status = get_option(TB_Telemetry::OPT_STATUS, 'pending'); ?>
                                    <p style="margin:0 0 8px;">Status: <strong><?= esc_html(TB_Telemetry::get_status_label()) ?></strong></p>
                                    <?php if ($tel_status !== 'opted_out'): ?>
                                    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display:inline;">
                                        <?php wp_nonce_field('tb_telemetry_response', 'tb_telemetry_nonce'); ?>
                                        <input type="hidden" name="action" value="tb_telemetry">
                                        <input type="hidden" name="tb_telemetry_choice" value="opted_out">
                                        <button type="submit" class="button button-secondary button-small">Opt out</button>
                                    </form>
                                    <?php else: ?>
                                    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display:inline;">
                                        <?php wp_nonce_field('tb_telemetry_response', 'tb_telemetry_nonce'); ?>
                                        <input type="hidden" name="action" value="tb_telemetry">
                                        <input type="hidden" name="tb_telemetry_choice" value="opted_in">
                                        <button type="submit" class="button button-secondary button-small">Opt in</button>
                                    </form>
                                    <?php endif; ?>
                                    <p class="description" style="margin-top:8px;">Anonymous data only: plugin version, WP&nbsp;/&nbsp;PHP version, booking mode, table count. No personal data, no site URLs.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Remove data on deletion</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="delete_data_on_uninstall" value="1" <?= checked($cfg['delete_data_on_uninstall'] ?? '0', '1', false) ?>>
                                        Delete all plugin data when this plugin is removed from WordPress
                                    </label>
                                    <p class="description">
                                        When ticked, permanently deletes all reservations, tables, settings, and logs when the plugin is deleted from the Plugins screen.
                                        <strong>This cannot be undone.</strong> Leave unticked to preserve data if you reinstall later.
                                    </p>
                                    <?php
                                    global $wpdb;
                                    $res_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tb_reservations");
                                    ?>
                                    <p class="description" style="margin-top:6px;">
                                        Currently storing <strong><?= number_format($res_count) ?> reservation<?= $res_count !== 1 ? 's' : '' ?></strong>.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>

                </div><!-- /tab-advanced -->

                <?php submit_button('Save Settings'); ?>
            </form>

            <!-- Export & Import — separate forms, only shown on Advanced tab -->
            <div class="tb-settings-section" <?= $tab !== 'advanced' ? 'style="display:none"' : '' ?>>
                <h2>Export &amp; Import Settings</h2>
                <p class="description">Export all plugin settings to a JSON file for backup or to transfer to another site. Importing will overwrite current settings immediately.</p>
                <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:16px;">
                    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
                        <?php wp_nonce_field('tb_export_settings', 'tb_nonce'); ?>
                        <input type="hidden" name="action" value="tb_export_settings">
                        <button type="submit" class="button">Export Settings</button>
                    </form>
                    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" enctype="multipart/form-data">
                        <?php wp_nonce_field('tb_import_settings', 'tb_nonce'); ?>
                        <input type="hidden" name="action" value="tb_import_settings">
                        <input type="file" name="tb_import_file" accept=".json" required style="display:inline-block;margin-right:8px;">
                        <button type="submit" class="button">Import Settings</button>
                    </form>
                </div>
                <?php if (isset($_GET['imported'])): ?>
                <div class="notice notice-success inline" style="margin-top:12px;"><p>Settings imported successfully.</p></div>
                <?php endif; ?>
                <?php if (isset($_GET['import_error'])): ?>
                <div class="notice notice-error inline" style="margin-top:12px;"><p>Import failed: invalid file.</p></div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function page_reports(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tb_reservations';

        // ── This month stats ──
        $month_from  = wp_date('Y-m-01');
        $month_to    = wp_date('Y-m-t');
        $month_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) as total,
                    COALESCE(SUM(party_size), 0) as covers,
                    SUM(status = 'cancelled') as cancelled
             FROM {$table}
             WHERE reservation_date BETWEEN %s AND %s",
            $month_from, $month_to
        ), ARRAY_A);

        // ── Last 30 days per-day (confirmed/pending/seated/completed) ──
        $bar_rows = $wpdb->get_results(
            "SELECT reservation_date, COUNT(*) as cnt
             FROM {$table}
             WHERE reservation_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
               AND status NOT IN ('cancelled','no_show')
             GROUP BY reservation_date",
            ARRAY_A
        );

        $bar_data = [];
        for ($i = 29; $i >= 0; $i--) {
            $bar_data[wp_date('Y-m-d', strtotime("-{$i} days"))] = 0;
        }
        foreach ($bar_rows as $r) {
            if (isset($bar_data[$r['reservation_date']])) {
                $bar_data[$r['reservation_date']] = (int) $r['cnt'];
            }
        }
        $max_cnt = max(array_values($bar_data)) ?: 1;

        // ── Status breakdown last 30 days ──
        $status_rows = $wpdb->get_results(
            "SELECT status, COUNT(*) as cnt
             FROM {$table}
             WHERE reservation_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
             GROUP BY status
             ORDER BY cnt DESC",
            ARRAY_A
        );

        $status_colours = [
            'pending'   => ['#fef3c7', '#92400e'],
            'confirmed' => ['#d1fae5', '#065f46'],
            'seated'    => ['#dbeafe', '#1e40af'],
            'completed' => ['#f3f4f6', '#374151'],
            'cancelled' => ['#fee2e2', '#991b1b'],
            'no_show'   => ['#fce7f3', '#9d174d'],
        ];
        ?>
        <div class="wrap tb-wrap">
            <h1>Reports</h1>
            <hr class="wp-header-end">

            <h2 style="font-size:14px;font-weight:600;color:#374151;margin:24px 0 12px;"><?= esc_html(wp_date('F Y')) ?></h2>
            <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:32px;">
                <?php
                $stat_items = [
                    ['Total bookings', (int) ($month_stats['total']     ?? 0), '#2563eb'],
                    ['Covers',         (int) ($month_stats['covers']    ?? 0), '#7c3aed'],
                    ['Cancellations',  (int) ($month_stats['cancelled'] ?? 0), '#dc2626'],
                ];
                foreach ($stat_items as [$lbl, $val, $clr]):
                ?>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px 28px;min-width:160px;">
                    <div style="font-size:32px;font-weight:700;color:<?= esc_attr($clr) ?>;line-height:1;"><?= esc_html($val) ?></div>
                    <div style="font-size:13px;color:#6b7280;margin-top:6px;"><?= esc_html($lbl) ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <h2 style="font-size:14px;font-weight:600;color:#374151;margin:0 0 12px;">Bookings — last 30 days</h2>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:24px 24px 12px;margin-bottom:32px;">
                <div style="display:flex;align-items:flex-end;gap:2px;height:120px;">
                    <?php
                    $today = wp_date('Y-m-d');
                    foreach ($bar_data as $date => $cnt):
                        $pct    = $max_cnt > 0 ? round(($cnt / $max_cnt) * 100) : 0;
                        $is_td  = ($date === $today);
                        $colour = $is_td ? '#2563eb' : '#bfdbfe';
                        $label  = wp_date('j', strtotime($date));
                        $full   = wp_date('d M', strtotime($date));
                    ?>
                    <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;gap:4px;"
                         title="<?= esc_attr("{$full}: {$cnt} booking" . ($cnt !== 1 ? 's' : '')) ?>">
                        <div style="width:100%;background:<?= esc_attr($colour) ?>;border-radius:2px 2px 0 0;height:<?= esc_attr("{$pct}%") ?>;min-height:<?= $cnt > 0 ? '3px' : '0' ?>;"></div>
                        <?php if (in_array((int) $label, [1, 8, 15, 22, 29], true) || $is_td): ?>
                        <div style="font-size:9px;color:<?= $is_td ? '#2563eb' : '#9ca3af' ?>;font-weight:<?= $is_td ? '700' : '400' ?>;line-height:1;flex-shrink:0;"><?= esc_html($label) ?></div>
                        <?php else: ?>
                        <div style="height:12px;flex-shrink:0;"></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p style="margin:10px 0 0;font-size:11px;color:#9ca3af;">Excludes cancelled and no-show reservations. Dark bar = today.</p>
            </div>

            <?php if (!empty($status_rows)): ?>
            <h2 style="font-size:14px;font-weight:600;color:#374151;margin:0 0 12px;">Status breakdown — last 30 days</h2>
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:32px;">
                <?php foreach ($status_rows as $sr):
                    [$bg, $tx] = $status_colours[$sr['status']] ?? ['#f3f4f6', '#374151'];
                ?>
                <div style="background:<?= esc_attr($bg) ?>;color:<?= esc_attr($tx) ?>;border-radius:20px;padding:6px 16px;font-size:13px;font-weight:600;">
                    <?= esc_html(ucfirst(str_replace('_', ' ', $sr['status']))) ?>: <?= esc_html($sr['cnt']) ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function page_logs(): void {
        if (isset($_GET['cleared'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Log cleared.</p></div>';
        }

        $logs      = TB_Logger::get(500);
        $log_count = TB_Logger::count();
        ?>
        <div class="wrap tb-wrap">
            <h1 class="wp-heading-inline">Activity Log</h1>
            <span class="tb-log-count" style="margin-left:10px;font-size:13px;color:#9ca3af;"><?= number_format($log_count) ?> entries</span>
            <hr class="wp-header-end">

            <div class="tb-log-toolbar" style="margin:16px 0 12px;">
                <select id="tb-log-level" class="tb-filter-input">
                    <option value="">All Levels</option>
                    <option value="info">Info</option>
                    <option value="warning">Warning</option>
                    <option value="error">Error</option>
                </select>
                <select id="tb-log-ctx" class="tb-filter-input">
                    <option value="">All Contexts</option>
                    <option value="booking">Booking</option>
                    <option value="email">Email</option>
                    <option value="cron">Cron</option>
                    <option value="system">System</option>
                </select>
                <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display:inline-flex;">
                    <?php wp_nonce_field('tb_clear_logs', 'tb_nonce'); ?>
                    <input type="hidden" name="action" value="tb_clear_logs">
                    <button type="submit" class="button tb-btn-danger"
                            onclick="return confirm('Clear all log entries? This cannot be undone.')">
                        Clear All
                    </button>
                </form>
            </div>

            <?php if (empty($logs)): ?>
            <div class="tb-empty">No log entries yet. Activity will appear here once bookings are made.</div>
            <?php else: ?>
            <div class="tb-log-wrap">
                <table class="widefat fixed tb-log-table">
                    <thead>
                        <tr>
                            <th class="tb-log-col-time">Time</th>
                            <th class="tb-log-col-level">Level</th>
                            <th class="tb-log-col-ctx">Context</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody id="tb-log-body">
                    <?php foreach ($logs as $entry): ?>
                    <tr data-level="<?= esc_attr($entry['level']) ?>" data-ctx="<?= esc_attr($entry['context']) ?>">
                        <td class="tb-log-time"><?= esc_html(wp_date('d M Y H:i:s', strtotime($entry['created_at']))) ?></td>
                        <td><span class="tb-log-badge tb-log-<?= esc_attr($entry['level']) ?>"><?= esc_html($entry['level']) ?></span></td>
                        <td class="tb-log-ctx"><?= esc_html($entry['context']) ?></td>
                        <td class="tb-log-msg"><?= esc_html($entry['message']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($log_count > 500): ?>
            <p class="description" style="margin-top:6px;">Showing the 500 most recent of <?= number_format($log_count) ?> total entries.</p>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    // =========================================================================
    // Form handlers
    // =========================================================================

    public function handle_save_reservation(): void {
        check_admin_referer('tb_save_reservation', 'tb_nonce');
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $id         = (int) ($_POST['id'] ?? 0);
        $new_status = sanitize_text_field($_POST['status'] ?? '');
        $res        = new TB_Reservations();
        $old        = $res->get($id);
        $old_status = $old ? $old['status'] : '';

        $res->update($id, [
            'status'      => $new_status,
            'table_id'    => (int) ($_POST['table_id'] ?? 0),
            'admin_notes' => sanitize_textarea_field($_POST['admin_notes'] ?? ''),
        ]);

        if ($new_status !== $old_status && in_array($new_status, ['confirmed', 'cancelled'], true)) {
            TB_Emails::send_client_status_update($id, $new_status);
        }

        wp_safe_redirect(admin_url('admin.php?page=tb-reservations&view=' . $id . '&saved=1'));
        exit;
    }

    public function handle_delete_reservation(): void {
        $id = (int) ($_GET['id'] ?? 0);
        check_admin_referer('tb_delete_' . $id, 'tb_nonce');
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        (new TB_Reservations())->delete($id);
        wp_safe_redirect(admin_url('admin.php?page=tb-reservations'));
        exit;
    }

    public function handle_save_settings(): void {
        check_admin_referer('tb_save_settings', 'tb_nonce');
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        global $wpdb;

        $bm = sanitize_text_field($_POST['booking_mode'] ?? 'simple');
        TB_Database::update_setting('booking_mode', in_array($bm, ['simple','layout'], true) ? $bm : 'simple');
        TB_Database::update_setting('max_seats', (string) max(1, (int) ($_POST['max_seats'] ?? 50)));

        // Per-day opening hours
        $days_cfg = [];
        $day_keys = ['mon','tue','wed','thu','fri','sat','sun'];
        $raw_wh   = $_POST['weekly_hours'] ?? []; // phpcs:ignore WordPress.Security.NonceVerification
        foreach ($day_keys as $d) {
            $day       = is_array($raw_wh[$d] ?? null) ? $raw_wh[$d] : [];
            $days_cfg[$d] = [
                'open' => !empty($day['open']),
                'from' => preg_match('/^\d{2}:\d{2}$/', $day['from'] ?? '') ? $day['from'] : '12:00',
                'to'   => preg_match('/^\d{2}:\d{2}$/', $day['to']   ?? '') ? $day['to']   : '22:00',
            ];
        }
        TB_Database::update_setting('weekly_hours', wp_json_encode($days_cfg));

        // Derive global opening_time/closing_time from the earliest open/latest close across open days
        // so existing code that reads those settings gets a sensible fallback.
        $open_times  = array_column(array_filter($days_cfg, fn($d) => $d['open']), 'from');
        $close_times = array_column(array_filter($days_cfg, fn($d) => $d['open']), 'to');
        if ($open_times) {
            sort($open_times);
            rsort($close_times);
            TB_Database::update_setting('opening_time', $open_times[0]);
            TB_Database::update_setting('closing_time',  $close_times[0]);
        }

        $scalar_keys = [
            'restaurant_name','restaurant_address',
            'slot_duration','sitting_duration','last_booking_offset',
            'min_advance_hours','max_advance_days','max_party_size',
            'canvas_width','canvas_height',
        ];
        foreach ($scalar_keys as $k) {
            if (isset($_POST[$k])) {
                TB_Database::update_setting($k, sanitize_text_field($_POST[$k]));
            }
        }

        // Derive open_days (numeric JS array used by booking form) from weekly_hours — single source of truth.
        $day_num_map = ['sun'=>0,'mon'=>1,'tue'=>2,'wed'=>3,'thu'=>4,'fri'=>5,'sat'=>6];
        $derived_open = array_values(array_map(
            fn($k) => $day_num_map[$k],
            array_keys(array_filter($days_cfg, fn($d) => $d['open']))
        ));
        sort($derived_open);
        TB_Database::update_setting('open_days', wp_json_encode($derived_open));

        // Closed dates — single pass with wp_unslash, uniqueness, and format validation.
        // If the submitted JSON is malformed, preserve the existing stored value rather than wiping it.
        $closed_raw = sanitize_text_field(wp_unslash($_POST['closed_dates'] ?? ''));
        $closed_arr = json_decode($closed_raw, true);
        if (!is_array($closed_arr)) {
            $closed_arr = json_decode(TB_Database::get_setting('closed_dates', '[]'), true) ?: [];
        }
        $closed_dates = array_values(array_unique(array_filter($closed_arr, fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d))));
        sort($closed_dates);
        TB_Database::update_setting('closed_dates', wp_json_encode($closed_dates));

        if (!empty($_POST['areas']) && is_array($_POST['areas'])) {
            $areas = [];
            foreach ($_POST['areas'] as $a) {
                $area_id = sanitize_key($a['id'] ?? '');
                $label   = sanitize_text_field($a['label'] ?? '');
                $color   = sanitize_hex_color($a['color'] ?? '#888888') ?? '#888888';
                if ($area_id && $label) {
                    $areas[] = ['id' => $area_id, 'label' => $label, 'color' => $color];
                }
            }
            TB_Database::update_setting('areas', wp_json_encode($areas));
        }

        $retention = max(0, (int) ($_POST['data_retention_days'] ?? 0));
        TB_Database::update_setting('data_retention_days',      (string) $retention);
        TB_Database::update_setting('delete_data_on_uninstall', isset($_POST['delete_data_on_uninstall']) ? '1' : '0');

        $saved_tab = sanitize_key($_POST['tb_settings_tab'] ?? 'booking');
        $base_url  = admin_url('admin.php?page=tb-settings&tab=' . $saved_tab);

        if ($wpdb->last_error) {
            TB_Logger::error('Settings save incomplete — last DB error: ' . $wpdb->last_error, 'system');
            wp_safe_redirect($base_url . '&error=db');
        } else {
            TB_Logger::info('Settings saved', 'system');
            wp_safe_redirect($base_url . '&saved=1');
        }
        exit;
    }

    public function page_emails(): void {
        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Email settings saved.</p></div>';
        }

        $cfg       = TB_Database::get_all_settings();
        $reminders = json_decode($cfg['reminders'] ?? TB_Reminders::default_config(), true);
        $logo_id   = (int) ($cfg['email_logo_id']  ?? 0);
        $logo_url  = $cfg['email_logo_url'] ?? '';
        ?>
        <div class="wrap tb-wrap">
            <h1>Email Settings</h1>
            <hr class="wp-header-end">

            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" class="tb-settings-form">
                <?php wp_nonce_field('tb_save_emails', 'tb_nonce'); ?>
                <input type="hidden" name="action" value="tb_save_emails">

                <!-- ── Branding ──────────────────────────────────────── -->
                <div class="tb-settings-section">
                    <h2>Branding</h2>
                    <p class="description">The logo appears in the email header. It is displayed above the restaurant name on a coloured background, so use a version with transparency or white text if possible.</p>
                    <table class="form-table">
                        <tr>
                            <th>Email Logo</th>
                            <td>
                                <div class="tb-logo-picker">
                                    <?php if ($logo_url): ?>
                                    <div class="tb-logo-preview-wrap" id="tb-logo-preview-wrap">
                                        <img id="tb-logo-preview" src="<?= esc_url($logo_url) ?>" alt="Logo preview">
                                    </div>
                                    <?php else: ?>
                                    <div class="tb-logo-preview-wrap tb-logo-empty" id="tb-logo-preview-wrap">
                                        <span>No logo set</span>
                                    </div>
                                    <?php endif; ?>
                                    <input type="hidden" name="email_logo_id"  id="tb-logo-id"  value="<?= esc_attr($logo_id) ?>">
                                    <input type="hidden" name="email_logo_url" id="tb-logo-url" value="<?= esc_attr($logo_url) ?>">
                                    <div style="margin-top:10px;display:flex;gap:8px;align-items:center;">
                                        <button type="button" class="button" id="tb-upload-logo">
                                            <?= $logo_url ? 'Change Logo' : 'Upload / Select Logo' ?>
                                        </button>
                                        <?php if ($logo_url): ?>
                                        <button type="button" class="button tb-btn-danger" id="tb-remove-logo">Remove</button>
                                        <?php endif; ?>
                                    </div>
                                    <p class="description" style="margin-top:8px;">Recommended: PNG or SVG, transparent background, max 220 × 64 px. Displayed at actual size in email clients.</p>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ── Delivery ──────────────────────────────────────── -->
                <div class="tb-settings-section">
                    <h2>Delivery</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="e-from-name">From Name</label></th>
                            <td><input type="text" id="e-from-name" name="email_from_name" value="<?= esc_attr($cfg['email_from_name'] ?? '') ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="e-from-addr">From Address</label></th>
                            <td>
                                <input type="email" id="e-from-addr" name="email_from_address" value="<?= esc_attr($cfg['email_from_address'] ?? '') ?>" class="regular-text">
                                <p class="description">Must be an authorised sender on your mail server to avoid spam filtering.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ── Notifications ─────────────────────────────────── -->
                <div class="tb-settings-section">
                    <h2>Notifications</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="e-admin-email">Admin notification address</label></th>
                            <td>
                                <input type="email" id="e-admin-email" name="admin_email" value="<?= esc_attr($cfg['admin_email'] ?? get_option('admin_email')) ?>" class="regular-text">
                                <p class="description">Where new-booking alerts are sent. Separate multiple addresses with commas.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Admin alert</th>
                            <td><label><input type="checkbox" name="notify_admin" value="1" <?= checked(1, (int)($cfg['notify_admin'] ?? 1)) ?>> Email admin when a new booking is made</label></td>
                        </tr>
                        <tr>
                            <th>Guest confirmation</th>
                            <td><label><input type="checkbox" name="email_notifications" value="1" <?= checked(1, (int)($cfg['email_notifications'] ?? 1)) ?>> Send confirmation email to guests on booking</label></td>
                        </tr>
                    </table>
                </div>

                <!-- ── Content ───────────────────────────────────────── -->
                <div class="tb-settings-section">
                    <h2>Email Content</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="e-cancel-policy">Cancellation Policy</label></th>
                            <td>
                                <textarea id="e-cancel-policy" name="cancellation_policy" rows="3" class="large-text"><?= esc_textarea($cfg['cancellation_policy'] ?? '') ?></textarea>
                                <p class="description">Shown at the bottom of guest confirmation emails. Leave blank to omit. Basic HTML allowed: <code>&lt;strong&gt;</code>, <code>&lt;a&gt;</code>, <code>&lt;br&gt;</code>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="e-footer">Email Footer Text</label></th>
                            <td>
                                <input type="text" id="e-footer" name="email_footer" value="<?= esc_attr($cfg['email_footer'] ?? '') ?>" class="regular-text" placeholder="e.g. 123 High Street, London · 020 7000 0000">
                                <p class="description">Appears at the bottom of every email. Leave blank to use restaurant name + site URL. Basic HTML allowed: <code>&lt;a&gt;</code>, <code>&lt;strong&gt;</code>.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ── Booking Success Message ───────────────────────── -->
                <div class="tb-settings-section">
                    <h2>Booking Success Message</h2>
                    <p class="description">Shown to the guest on-screen after a successful booking. Use <code>{party}</code>, <code>{date}</code>, <code>{time}</code>, <code>{ref}</code> as placeholders. Leave blank for the default message.</p>
                    <table class="form-table">
                        <tr>
                            <th><label for="e-success-msg">Success Message</label></th>
                            <td>
                                <textarea id="e-success-msg" name="booking_success_message" rows="3" class="large-text"><?= esc_textarea($cfg['booking_success_message'] ?? '') ?></textarea>
                                <p class="description">Example: <em>Thanks! Your table for {party} on {date} at {time} is confirmed. Reference: {ref}</em></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ── Daily Digest ──────────────────────────────────── -->
                <div class="tb-settings-section">
                    <h2>Daily Digest</h2>
                    <p class="description">Sends a plain-text summary of the day's reservations to your admin email address each morning via WP-Cron.</p>
                    <table class="form-table">
                        <tr>
                            <th>Enable digest</th>
                            <td><label><input type="checkbox" name="daily_digest_enabled" value="1" <?= checked(1, (int)($cfg['daily_digest_enabled'] ?? 0)) ?>> Send a daily booking digest email</label></td>
                        </tr>
                        <tr>
                            <th><label for="e-digest-time">Preferred send time</label></th>
                            <td>
                                <input type="time" id="e-digest-time" name="daily_digest_time" value="<?= esc_attr($cfg['daily_digest_time'] ?? '08:00') ?>">
                                <p class="description">Approximate — WP-Cron fires when a page is loaded near this time. Within 15–30 minutes is typical.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ── Reminder Emails ────────────────────────────────── -->
                <div class="tb-settings-section">
                    <h2>Reminder Emails</h2>
                    <p class="description">Sent via WP-Cron (runs hourly). Guests only receive reminders for non-cancelled reservations.</p>
                    <table class="form-table">
                        <tr>
                            <th>Enable reminders</th>
                            <td><label><input type="checkbox" name="reminders_enabled" value="1" <?= checked(1, (int)($cfg['reminders_enabled'] ?? 1)) ?>> Send automatic reminder emails to guests</label></td>
                        </tr>
                    </table>
                    <table class="widefat fixed tb-reminder-table" style="margin-top:12px;">
                        <thead>
                            <tr>
                                <th style="width:50px;">On</th>
                                <th>Hours before booking</th>
                                <th>Subject preview</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $reminder_slots = array_replace(
                            array_fill(0, 3, ['enabled' => false, 'hours' => 24]),
                            (array) $reminders
                        );
                        foreach ($reminder_slots as $i => $rem):
                            $h   = (int) ($rem['hours'] ?? 24);
                            $lbl = $h >= 24 ? ($h / 24) . ' day' . ($h >= 48 ? 's' : '') : $h . ' hour' . ($h !== 1 ? 's' : '');
                        ?>
                        <tr>
                            <td><input type="checkbox" name="reminders[<?= $i ?>][enabled]" value="1" data-idx="<?= $i ?>" <?= !empty($rem['enabled']) ? 'checked' : '' ?>></td>
                            <td><input type="number" name="reminders[<?= $i ?>][hours]" value="<?= esc_attr($rem['hours'] ?? 24) ?>" min="1" max="720" class="small-text tb-reminder-hours" data-idx="<?= $i ?>"> hours</td>
                            <td class="tb-reminder-preview" id="tb-rp-<?= $i ?>" style="color:#6b7280;font-size:12px;">"Reminder: Your table at [Restaurant] is <strong><?= esc_html($lbl) ?></strong> away"</td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php submit_button('Save Email Settings'); ?>
            </form>
        </div>
        <?php
    }

    public function handle_save_emails(): void {
        check_admin_referer('tb_save_emails', 'tb_nonce');
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        // Logo
        TB_Database::update_setting('email_logo_id',  (string) (int) ($_POST['email_logo_id']  ?? 0));
        TB_Database::update_setting('email_logo_url', esc_url_raw($_POST['email_logo_url'] ?? ''));

        // Delivery
        TB_Database::update_setting('email_from_name', sanitize_text_field($_POST['email_from_name'] ?? ''));
        if (!empty($_POST['email_from_address'])) {
            TB_Database::update_setting('email_from_address', sanitize_email($_POST['email_from_address']));
        }

        // Notifications
        if (!empty($_POST['admin_email'])) {
            $admin_emails = implode(',', array_map('sanitize_email', array_map('trim', explode(',', $_POST['admin_email']))));
            TB_Database::update_setting('admin_email', $admin_emails);
        }
        TB_Database::update_setting('notify_admin',        isset($_POST['notify_admin'])        ? '1' : '0');
        TB_Database::update_setting('email_notifications', isset($_POST['email_notifications']) ? '1' : '0');

        // Daily digest
        TB_Database::update_setting('daily_digest_enabled', isset($_POST['daily_digest_enabled']) ? '1' : '0');
        $digest_time = sanitize_text_field($_POST['daily_digest_time'] ?? '08:00');
        if (preg_match('/^\d{2}:\d{2}$/', $digest_time)) {
            $old_time = TB_Database::get_setting('daily_digest_time', '08:00');
            TB_Database::update_setting('daily_digest_time', $digest_time);
            // Reschedule the cron if the time changed.
            if ($digest_time !== $old_time) {
                wp_clear_scheduled_hook('tb_daily_digest');
                $next = strtotime('today ' . $digest_time);
                if ($next <= time()) $next = strtotime('tomorrow ' . $digest_time);
                wp_schedule_event($next, 'daily', 'tb_daily_digest');
            }
        }

        // Content
        TB_Database::update_setting('cancellation_policy',    wp_kses_post(wp_unslash($_POST['cancellation_policy'] ?? '')));
        TB_Database::update_setting('email_footer',            wp_kses_post(wp_unslash($_POST['email_footer'] ?? '')));
        TB_Database::update_setting('booking_success_message', sanitize_textarea_field($_POST['booking_success_message'] ?? ''));

        // Reminders
        TB_Database::update_setting('reminders_enabled', isset($_POST['reminders_enabled']) ? '1' : '0');
        $reminder_rows = [];
        if (!empty($_POST['reminders']) && is_array($_POST['reminders'])) {
            foreach (array_slice($_POST['reminders'], 0, 3) as $r) {
                $hours   = max(1, min(720, (int) ($r['hours'] ?? 24)));
                $enabled = !empty($r['enabled']);
                $label   = $hours >= 24
                    ? ($hours / 24) . ' day' . ($hours >= 48 ? 's' : '')
                    : $hours . ' hour' . ($hours !== 1 ? 's' : '');
                $reminder_rows[] = ['enabled' => $enabled, 'hours' => $hours, 'label' => $label . ' before'];
            }
        }
        if (!empty($reminder_rows)) {
            TB_Database::update_setting('reminders', wp_json_encode($reminder_rows));
        }

        TB_Logger::info('Email settings saved', 'system');

        wp_safe_redirect(admin_url('admin.php?page=tb-emails&saved=1'));
        exit;
    }

    public function page_styles(): void {
        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Style settings saved.</p></div>';
        }

        $current_style        = TB_Database::get_setting('booking_style', 'modern');
        $current_responsive   = (bool) TB_Database::get_setting('booking_responsive', '1');
        $current_width        = TB_Database::get_setting('booking_form_width', 'default');
        $current_density      = TB_Database::get_setting('booking_density', 'default');
        $current_stack_btns   = (bool) TB_Database::get_setting('booking_stack_buttons', '0');
        $current_steps_mobile = TB_Database::get_setting('booking_steps_mobile', 'labels');
        $current_ui_scale     = TB_Database::get_setting('booking_ui_scale', '100');
        $themes               = tb_style_themes();
        ?>
        <div class="wrap tb-wrap">
            <h1>Booking Form Styles</h1>
            <hr class="wp-header-end">

            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
                <?php wp_nonce_field('tb_save_styles', 'tb_nonce'); ?>
                <input type="hidden" name="action" value="tb_save_styles">

                <div class="tb-settings-section">
                    <h2>Theme</h2>
                    <p class="description">Choose a visual style for the booking form. Changes take effect immediately on the front end.</p>
                    <div class="tb-style-grid">
                        <?php foreach ($themes as $key => $theme):
                            $selected = $current_style === $key;
                        ?>
                        <label class="tb-style-card <?= $selected ? 'tb-style-card-selected' : '' ?>">
                            <input type="radio" name="booking_style" value="<?= esc_attr($key) ?>" <?= checked($selected, true, false) ?>>
                            <div class="tb-style-preview"><?= $this->render_style_preview($theme) ?></div>
                            <div class="tb-style-name"><?= esc_html($theme['name']) ?></div>
                            <div class="tb-style-desc"><?= esc_html($theme['desc']) ?></div>
                        </label>
                        <?php endforeach; ?>

                        <label class="tb-style-card tb-style-card-site <?= $current_style === 'site' ? 'tb-style-card-selected' : '' ?>">
                            <input type="radio" name="booking_style" value="site" <?= checked($current_style, 'site', false) ?>>
                            <div class="tb-style-preview tb-style-preview-site">
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                                <div style="font-size:10px;color:#6b7280;margin-top:4px;">Theme CSS</div>
                            </div>
                            <div class="tb-style-name">Site Styles</div>
                            <div class="tb-style-desc">Inherit colours and fonts from your active WordPress theme — no plugin CSS loaded</div>
                        </label>
                    </div>
                </div>

                <div class="tb-settings-section">
                    <h2>Layout &amp; Responsiveness</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Form width</th>
                            <td>
                                <select name="booking_form_width">
                                    <option value="narrow"  <?= selected($current_width, 'narrow',  false) ?>>Narrow (480 px) — sidebar or narrow column</option>
                                    <option value="default" <?= selected($current_width, 'default', false) ?>>Default (640 px)</option>
                                    <option value="wide"    <?= selected($current_width, 'wide',    false) ?>>Wide (800 px) — hero section or modal</option>
                                    <option value="full"    <?= selected($current_width, 'full',    false) ?>>Full width — inherit container</option>
                                </select>
                                <p class="description">Maximum width of the booking form. Use Narrow for sidebars, Wide or Full for full-width page sections.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Fluid layout</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="booking_responsive" value="1" <?= checked($current_responsive, true, false) ?>>
                                    Fluid — form stretches to fill its container
                                </label>
                                <p class="description">Off: the form uses a fixed pixel width and scrolls horizontally when the viewport is narrower.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Spacing density</th>
                            <td>
                                <select name="booking_density">
                                    <option value="compact"     <?= selected($current_density, 'compact',     false) ?>>Compact — tighter padding for sidebars or popups</option>
                                    <option value="default"     <?= selected($current_density, 'default',     false) ?>>Default</option>
                                    <option value="comfortable" <?= selected($current_density, 'comfortable', false) ?>>Comfortable — more breathing room</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Mobile buttons</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="booking_stack_buttons" value="1" <?= checked($current_stack_btns, true, false) ?>>
                                    Stack navigation buttons vertically on narrow screens
                                </label>
                                <p class="description">Places the primary action above the back button on screens narrower than 480 px.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">UI scale</th>
                            <td>
                                <select name="booking_ui_scale">
                                    <option value="100" <?= selected($current_ui_scale, '100', false) ?>>100% — default</option>
                                    <option value="125" <?= selected($current_ui_scale, '125', false) ?>>125% — larger</option>
                                    <option value="150" <?= selected($current_ui_scale, '150', false) ?>>150% — largest</option>
                                </select>
                                <p class="description">Scales all form elements proportionally. Useful when the form appears small on large screens or high-DPI displays.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Mobile step indicator</th>
                            <td>
                                <fieldset>
                                    <label>
                                        <input type="radio" name="booking_steps_mobile" value="labels" <?= checked($current_steps_mobile, 'labels', false) ?>>
                                        Step labels — numbered steps, text hides on very small screens
                                    </label><br>
                                    <label style="margin-top:6px;display:block;">
                                        <input type="radio" name="booking_steps_mobile" value="progress" <?= checked($current_steps_mobile, 'progress', false) ?>>
                                        Progress bar — compact coloured bar, ideal for narrow embeds
                                    </label>
                                </fieldset>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php submit_button('Save Style Settings'); ?>
            </form>
        </div>
        <?php
    }

    private function render_style_preview(array $theme): string {
        $d = [
            '--tb-primary'      => '#2563eb',
            '--tb-primary-light'=> '#eff6ff',
            '--tb-bg'           => '#ffffff',
            '--tb-bg-subtle'    => '#f9fafb',
            '--tb-border'       => '#e5e7eb',
            '--tb-border-input' => '#d1d5db',
            '--tb-text-muted'   => '#6b7280',
        ];
        $v  = array_merge($d, $theme['vars'] ?? []);
        $p  = esc_attr($v['--tb-primary']);
        $pl = esc_attr($v['--tb-primary-light']);
        $bg = esc_attr($v['--tb-bg']);
        $bs = esc_attr($v['--tb-bg-subtle']);
        $bd = esc_attr($v['--tb-border']);
        $bi = esc_attr($v['--tb-border-input']);
        $tm = esc_attr($v['--tb-text-muted']);

        return '<div style="background:' . $bg . ';border:1px solid ' . $bd . ';border-radius:4px;padding:8px;">' .
                   '<div style="display:flex;gap:2px;margin-bottom:6px;">' .
                       '<div style="flex:1;background:' . $pl . ';border-radius:2px;padding:3px;font-size:8px;font-weight:700;text-align:center;color:' . $p . ';">1</div>' .
                       '<div style="flex:1;background:' . $bs . ';border-radius:2px;padding:3px;font-size:8px;text-align:center;color:' . $tm . ';">2</div>' .
                       '<div style="flex:1;background:' . $bs . ';border-radius:2px;padding:3px;font-size:8px;text-align:center;color:' . $tm . ';">3</div>' .
                   '</div>' .
                   '<div style="background:' . $bs . ';border:1px solid ' . $bi . ';border-radius:2px;height:13px;margin-bottom:5px;"></div>' .
                   '<div style="display:flex;gap:3px;margin-bottom:5px;">' .
                       '<div style="background:' . $p . ';border-radius:2px;height:11px;flex:1;"></div>' .
                       '<div style="background:' . $bs . ';border:1px solid ' . $bi . ';border-radius:2px;height:11px;flex:1;"></div>' .
                       '<div style="background:' . $bs . ';border:1px solid ' . $bi . ';border-radius:2px;height:11px;flex:1;"></div>' .
                   '</div>' .
                   '<div style="background:' . $p . ';border-radius:2px;padding:4px;font-size:8px;color:#fff;font-weight:700;text-align:center;">Confirm →</div>' .
               '</div>';
    }

    public function deactivation_modal(): void {
        $settings_url = admin_url('admin.php?page=tb-settings#tb-data-privacy');
        $plugin_file  = urlencode(TB_BASENAME);
        ?>
        <div id="tb-deactivate-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:999999;align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:8px;padding:32px;max-width:460px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
                <h2 style="margin:0 0 12px;font-size:18px;color:#1d2327;">Deactivate getBooked?</h2>
                <p style="margin:0 0 14px;color:#374151;line-height:1.6;">
                    Your reservations, tables, settings, and logs will be <strong>kept</strong>. The booking form will stop appearing on your site and scheduled reminder emails will pause until you reactivate.
                </p>
                <p style="margin:0 0 24px;color:#374151;line-height:1.6;">
                    To permanently delete all data, enable <a href="<?= esc_url($settings_url) ?>"><em>Remove data on deletion</em></a> in Settings, then delete the plugin.
                </p>
                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <button id="tb-deactivate-cancel" class="button" type="button">Cancel</button>
                    <a id="tb-deactivate-confirm" href="#" class="button" style="background:#d63638;border-color:#d63638;color:#fff;">Deactivate</a>
                </div>
            </div>
        </div>
        <script>
        (function ($) {
            var $overlay  = $('#tb-deactivate-overlay');
            var $confirm  = $('#tb-deactivate-confirm');
            var targetHref = '';

            $('a[href*="action=deactivate"][href*="<?= esc_js($plugin_file) ?>"]').on('click', function (e) {
                e.preventDefault();
                targetHref = $(this).attr('href');
                $overlay.css('display', 'flex');
            });

            $('#tb-deactivate-cancel').on('click', function () {
                $overlay.hide();
            });

            $overlay.on('click', function (e) {
                if (e.target === this) $overlay.hide();
            });

            $confirm.on('click', function (e) {
                e.preventDefault();
                window.location.href = targetHref;
            });
        }(jQuery));
        </script>
        <?php
    }

    public function handle_save_styles(): void {
        check_admin_referer('tb_save_styles', 'tb_nonce');
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $allowed = ['modern', 'dark', 'classic', 'minimal', 'bold', 'site'];
        $style   = sanitize_key($_POST['booking_style'] ?? 'modern');
        if (!in_array($style, $allowed, true)) $style = 'modern';

        $allowed_widths = ['narrow', 'default', 'wide', 'full'];
        $width = sanitize_key($_POST['booking_form_width'] ?? 'default');
        if (!in_array($width, $allowed_widths, true)) $width = 'default';

        $allowed_densities = ['compact', 'default', 'comfortable'];
        $density = sanitize_key($_POST['booking_density'] ?? 'default');
        if (!in_array($density, $allowed_densities, true)) $density = 'default';

        $allowed_steps = ['labels', 'progress'];
        $steps_mobile = sanitize_key($_POST['booking_steps_mobile'] ?? 'labels');
        if (!in_array($steps_mobile, $allowed_steps, true)) $steps_mobile = 'labels';

        $allowed_scales = ['100', '125', '150'];
        $ui_scale = sanitize_key($_POST['booking_ui_scale'] ?? '100');
        if (!in_array($ui_scale, $allowed_scales, true)) $ui_scale = '100';

        TB_Database::update_setting('booking_style',         $style);
        TB_Database::update_setting('booking_form_width',    $width);
        TB_Database::update_setting('booking_responsive',    isset($_POST['booking_responsive'])    ? '1' : '0');
        TB_Database::update_setting('booking_density',       $density);
        TB_Database::update_setting('booking_stack_buttons', isset($_POST['booking_stack_buttons']) ? '1' : '0');
        TB_Database::update_setting('booking_steps_mobile',  $steps_mobile);
        TB_Database::update_setting('booking_ui_scale',      $ui_scale);
        TB_Logger::info("Booking style set to: $style", 'system');

        wp_safe_redirect(admin_url('admin.php?page=tb-styles&saved=1'));
        exit;
    }

    public function handle_clear_logs(): void {
        check_admin_referer('tb_clear_logs', 'tb_nonce');
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        TB_Logger::clear();
        TB_Logger::info('Logs cleared by ' . wp_get_current_user()->user_login, 'system');

        wp_safe_redirect(admin_url('admin.php?page=tb-logs&cleared=1'));
        exit;
    }

    public function handle_export_csv(): void {
        check_admin_referer('tb_export_csv', 'tb_nonce');
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $res  = new TB_Reservations();
        $rows = $res->get_all([
            'date'     => sanitize_text_field(wp_unslash($_GET['date']   ?? '')),
            'status'   => sanitize_text_field(wp_unslash($_GET['status'] ?? '')),
            'area'     => sanitize_text_field(wp_unslash($_GET['area']   ?? '')),
            'search'   => sanitize_text_field(wp_unslash($_GET['s']      ?? '')),
            'per_page' => 9999,
            'page'     => 1,
            'orderby'  => 'reservation_date',
            'order'    => 'ASC',
        ]);

        $filename = 'reservations-' . gmdate('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w'); // phpcs:ignore WordPress.WP.AlternativeFunctions
        fputcsv($out, ['Reference','Name','Email','Phone','Date','Time','Party','Area','Table','Status','Special Requests','Admin Notes','Created']);
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['reservation_number'],
                $row['customer_name'],
                $row['customer_email'],
                $row['customer_phone'] ?? '',
                $row['reservation_date'],
                $row['reservation_time'],
                $row['party_size'],
                $row['seating_area'],
                $row['table_name'] ?? '',
                $row['status'],
                $row['special_requests'] ?? '',
                $row['admin_notes']      ?? '',
                $row['created_at'],
            ]);
        }
        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions
        exit;
    }

    public function handle_create_reservation(): void {
        check_admin_referer('tb_create_reservation', 'tb_nonce');
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $data = [
            'customer_name'    => sanitize_text_field(wp_unslash($_POST['customer_name']    ?? '')),
            'customer_email'   => sanitize_email(wp_unslash($_POST['customer_email']        ?? '')),
            'customer_phone'   => sanitize_text_field(wp_unslash($_POST['customer_phone']   ?? '')),
            'reservation_date' => sanitize_text_field(wp_unslash($_POST['reservation_date'] ?? '')),
            'reservation_time' => sanitize_text_field(wp_unslash($_POST['reservation_time'] ?? '')),
            'party_size'       => max(1, (int) ($_POST['party_size'] ?? 1)),
            'seating_area'     => sanitize_text_field(wp_unslash($_POST['seating_area']     ?? '')),
            'status'           => sanitize_text_field(wp_unslash($_POST['status']           ?? 'pending')),
            'special_requests' => sanitize_textarea_field(wp_unslash($_POST['special_requests'] ?? '')),
            'admin_notes'      => sanitize_textarea_field(wp_unslash($_POST['admin_notes']      ?? '')),
            'table_id'         => (int) ($_POST['table_id'] ?? 0),
        ];

        if (!$data['customer_name'] || !$data['reservation_date'] || !$data['reservation_time'] || !$data['seating_area']) {
            wp_die('Required fields are missing. Please go back and complete the form.', '', ['back_link' => true]);
        }

        $notify = !empty($_POST['notify_guest']);
        $res    = new TB_Reservations();
        $id     = $res->admin_create($data, $notify);

        if (!$id) {
            wp_die('Failed to create the reservation. Please try again.', '', ['back_link' => true]);
        }

        wp_safe_redirect(admin_url('admin.php?page=tb-reservations&view=' . $id . '&created=1'));
        exit;
    }

    public function handle_export_settings(): void {
        check_admin_referer('tb_export_settings', 'tb_nonce');
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $settings = TB_Database::get_all_settings();
        $filename = 'table-booking-settings-' . gmdate('Y-m-d') . '.json';

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store');
        echo wp_json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function handle_import_settings(): void {
        check_admin_referer('tb_import_settings', 'tb_nonce');
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $file = $_FILES['tb_import_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            wp_safe_redirect(admin_url('admin.php?page=tb-settings&import_error=1'));
            exit;
        }

        $raw  = file_get_contents($file['tmp_name']); // phpcs:ignore WordPress.WP.AlternativeFunctions
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            wp_safe_redirect(admin_url('admin.php?page=tb-settings&import_error=1'));
            exit;
        }

        $skip = ['id'];
        foreach ($data as $key => $value) {
            if (in_array($key, $skip, true)) continue;
            TB_Database::update_setting(sanitize_key($key), wp_kses_post((string) $value));
        }

        TB_Logger::info('Settings imported by ' . wp_get_current_user()->user_login, 'system');
        wp_safe_redirect(admin_url('admin.php?page=tb-settings&imported=1'));
        exit;
    }

    public function handle_save_setup(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $step = max(1, min(3, (int) ($_POST['step'] ?? 1)));
        check_admin_referer('tb_save_setup_' . $step, 'tb_nonce');

        if ($step === 1) {
            $name = sanitize_text_field(wp_unslash($_POST['restaurant_name'] ?? ''));
            TB_Database::update_setting('restaurant_name',    $name);
            TB_Database::update_setting('restaurant_address', sanitize_text_field(wp_unslash($_POST['restaurant_address'] ?? '')));
            $email = sanitize_email(wp_unslash($_POST['admin_email'] ?? ''));
            if ($email) {
                TB_Database::update_setting('admin_email', $email);
                // Mirror into email_from_* only if still at the WP default
                if (TB_Database::get_setting('email_from_address', '') === get_option('admin_email')) {
                    TB_Database::update_setting('email_from_address', $email);
                }
                if (TB_Database::get_setting('email_from_name', '') === get_bloginfo('name') || $name) {
                    TB_Database::update_setting('email_from_name', $name ?: get_bloginfo('name'));
                }
            }
            wp_safe_redirect(admin_url('admin.php?page=tb-setup&step=2'));
            exit;
        }

        if ($step === 2) {
            $day_keys = ['mon','tue','wed','thu','fri','sat','sun'];
            $raw_wh   = is_array($_POST['weekly_hours'] ?? null) ? $_POST['weekly_hours'] : [];
            $days_cfg = [];
            foreach ($day_keys as $d) {
                $day = is_array($raw_wh[$d] ?? null) ? $raw_wh[$d] : [];
                $days_cfg[$d] = [
                    'open' => !empty($day['open']),
                    'from' => preg_match('/^\d{2}:\d{2}$/', $day['from'] ?? '') ? $day['from'] : '12:00',
                    'to'   => preg_match('/^\d{2}:\d{2}$/', $day['to']   ?? '') ? $day['to']   : '22:00',
                ];
            }
            TB_Database::update_setting('weekly_hours', wp_json_encode($days_cfg));

            $open_times  = array_column(array_filter($days_cfg, fn($d) => $d['open']), 'from');
            $close_times = array_column(array_filter($days_cfg, fn($d) => $d['open']), 'to');
            if ($open_times) {
                sort($open_times);  rsort($close_times);
                TB_Database::update_setting('opening_time', $open_times[0]);
                TB_Database::update_setting('closing_time',  $close_times[0]);
            }

            $js_map = ['sun'=>0,'mon'=>1,'tue'=>2,'wed'=>3,'thu'=>4,'fri'=>5,'sat'=>6];
            $open_days = [];
            foreach ($js_map as $key => $num) {
                if (!empty($days_cfg[$key]['open'])) $open_days[] = $num;
            }
            TB_Database::update_setting('open_days', wp_json_encode($open_days));

            wp_safe_redirect(admin_url('admin.php?page=tb-setup&step=3'));
            exit;
        }

        if ($step === 3) {
            if (!empty($_POST['areas']) && is_array($_POST['areas'])) {
                $areas = [];
                foreach ($_POST['areas'] as $a) {
                    $label = sanitize_text_field($a['label'] ?? '');
                    $color = sanitize_hex_color($a['color'] ?? '#888888') ?? '#888888';
                    if (!$label) continue;
                    $area_id   = sanitize_key($a['id'] ?? '') ?: sanitize_key($label);
                    $base = $area_id; $n = 2;
                    while (in_array($area_id, array_column($areas, 'id'), true)) {
                        $area_id = $base . '-' . $n++;
                    }
                    $areas[] = ['id' => $area_id, 'label' => $label, 'color' => $color];
                }
                if ($areas) {
                    TB_Database::update_setting('areas', wp_json_encode($areas));
                }
            }
            TB_Database::update_setting('setup_complete', '1');
            TB_Logger::info('Setup wizard completed', 'system');
            wp_safe_redirect(admin_url('admin.php?page=tb-setup&step=4'));
            exit;
        }

        wp_safe_redirect(admin_url('admin.php?page=tb-setup'));
        exit;
    }

    public function handle_create_booking_page(): void {
        check_admin_referer('tb_create_booking_page', 'tb_nonce');
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $page_id = wp_insert_post([
            'post_title'   => 'Book a Table',
            'post_content' => '[getbooked]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => get_current_user_id(),
        ]);

        if (is_wp_error($page_id) || !$page_id) {
            wp_safe_redirect(admin_url('admin.php?page=tb-setup&step=4&page_error=1'));
            exit;
        }

        TB_Logger::info('Booking page created by setup wizard (post ID: ' . $page_id . ')', 'system');
        wp_safe_redirect(admin_url('admin.php?page=tb-setup&step=4&page_created=' . $page_id));
        exit;
    }

    public function handle_bulk_action(): void {
        check_admin_referer('tb_bulk_action', 'tb_nonce');
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $action = sanitize_key($_POST['bulk_action'] ?? '');
        $ids    = array_map('intval', (array) ($_POST['bulk_ids'] ?? []));
        $ids    = array_filter($ids);

        if (!$action || empty($ids)) {
            wp_safe_redirect(admin_url('admin.php?page=tb-reservations'));
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tb_reservations';
        $done  = 0;

        if ($action === 'delete') {
            foreach ($ids as $id) {
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE id = %d", $id), ARRAY_A);
                if (!$row) continue;
                $wpdb->delete($table, ['id' => $id], ['%d']);
                TB_Logger::info("Reservation #{$row['reservation_number']} deleted via bulk action", 'system');
                $done++;
            }
        } else {
            $status_map = ['confirm' => 'confirmed', 'cancel' => 'cancelled'];
            $new_status = $status_map[$action] ?? '';
            if (!$new_status) {
                wp_safe_redirect(admin_url('admin.php?page=tb-reservations'));
                exit;
            }

            foreach ($ids as $id) {
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE id = %d", $id), ARRAY_A);
                if (!$row || $row['status'] === $new_status) continue;
                $wpdb->update($table, ['status' => $new_status], ['id' => $id], ['%s'], ['%d']);
                if (in_array($new_status, ['confirmed', 'cancelled'], true)) {
                    TB_Emails::send_client_status_update($id, $new_status);
                }
                TB_Logger::info("Reservation #{$row['reservation_number']} bulk-set to {$new_status}", 'system');
                $done++;
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=tb-reservations&bulk_done=' . $done));
        exit;
    }

    private function render_print_view(string $date, array $areas, array $status_options): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $res  = new TB_Reservations();
        $cfg  = TB_Database::get_all_settings();
        $rows = $res->get_all(['date' => $date, 'per_page' => 200, 'page' => 1]);

        usort($rows, fn($a, $b) => strcmp($a['reservation_time'], $b['reservation_time']));

        $sit_dur   = (int) ($cfg['sitting_duration'] ?? 90);
        $rest_name = esc_html($cfg['restaurant_name'] ?? get_bloginfo('name'));
        $date_disp = wp_date('l, j F Y', strtotime($date));

        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="UTF-8">
    <title>Run Sheet – <?= esc_html($date_disp) ?></title>
    <style>
        body { font-family: -apple-system, Arial, sans-serif; font-size: 13px; color: #111; margin: 0; padding: 20px 32px; }
        h1 { font-size: 20px; margin: 0 0 2px; }
        .sub { color: #555; font-size: 13px; margin: 0 0 18px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th { background: #f3f4f6; text-align: left; padding: 7px 10px; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; border-bottom: 2px solid #d1d5db; }
        td { padding: 8px 10px; border-bottom: 1px solid #e5e7eb; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        .badge { display:inline-block; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:600; text-transform:uppercase; }
        .badge-pending   { background:#fef3c7; color:#92400e; }
        .badge-confirmed { background:#d1fae5; color:#065f46; }
        .badge-cancelled { background:#fee2e2; color:#991b1b; }
        .badge-seated    { background:#dbeafe; color:#1e40af; }
        .badge-completed { background:#f3f4f6; color:#374151; }
        .badge-no_show   { background:#fce7f3; color:#9d174d; }
        .note { font-size:11px; color:#6b7280; margin:2px 0 0; }
        .no-print-msg { display:none; }
        @media print {
            body { padding: 0; }
            .no-print-msg { display:none; }
        }
    </style>
</head>
<body onload="window.print()">
    <h1><?= $rest_name ?> – Daily Run Sheet</h1>
    <p class="sub"><?= esc_html($date_disp) ?> &mdash; <?= count($rows) ?> reservation<?= count($rows) !== 1 ? 's' : '' ?></p>

    <?php if (empty($rows)): ?>
    <p style="color:#6b7280;">No reservations on this date.</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Time</th>
                <th>Ref</th>
                <th>Guest</th>
                <th>Party</th>
                <th>Area</th>
                <th>Table</th>
                <th>Status</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row):
            $area_lbl  = $row['seating_area'];
            foreach ($areas as $a) { if ($a['id'] === $row['seating_area']) { $area_lbl = $a['label']; break; } }
            $start_ts  = strtotime($row['reservation_time']);
            $end_ts    = $start_ts + $sit_dur * 60;
            $badge_cls = 'badge-' . esc_attr($row['status']);
        ?>
        <tr>
            <td>
                <?= esc_html(wp_date('g:i A', $start_ts)) ?>
                <span style="color:#9ca3af;font-size:11px;">&rarr; <?= esc_html(wp_date('g:i A', $end_ts)) ?></span>
            </td>
            <td style="font-family:monospace;font-size:11px;"><?= esc_html($row['reservation_number']) ?></td>
            <td>
                <?= esc_html($row['customer_name']) ?>
                <?php if ($row['customer_phone']): ?>
                <div class="note"><?= esc_html($row['customer_phone']) ?></div>
                <?php endif; ?>
            </td>
            <td><?= esc_html($row['party_size']) ?></td>
            <td><?= esc_html($area_lbl) ?></td>
            <td><?= esc_html($row['table_name'] ?? '—') ?></td>
            <td><span class="badge <?= $badge_cls ?>"><?= esc_html($status_options[$row['status']] ?? $row['status']) ?></span></td>
            <td style="max-width:200px;font-size:11px;color:#374151;"><?= esc_html($row['special_requests'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</body>
</html>
        <?php
        exit;
    }

    // =========================================================================
    // Support page
    // =========================================================================

    public function page_support(): void {
        $current_user = wp_get_current_user();
        $sent  = isset($_GET['sent'])  && $_GET['sent']  === '1';
        $error = isset($_GET['error']) ? sanitize_key($_GET['error']) : '';

        global $wpdb;
        $reservation_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}tb_reservations`");

        $sys_info = implode("\n", [
            'Plugin version : ' . TB_VERSION,
            'WordPress      : ' . get_bloginfo('version'),
            'PHP            : ' . PHP_VERSION,
            'Site URL       : ' . site_url(),
            'Active theme   : ' . wp_get_theme()->get('Name') . ' ' . wp_get_theme()->get('Version'),
            'Booking mode   : ' . TB_Database::get_setting('booking_mode', 'simple'),
            'Reservations   : ' . $reservation_count,
            'WP_DEBUG       : ' . (defined('WP_DEBUG') && WP_DEBUG ? 'true' : 'false'),
            'Multisite      : ' . (is_multisite() ? 'yes' : 'no'),
        ]);
        ?>
        <div class="wrap tb-wrap">
            <h1>Support</h1>

            <?php if ($sent): ?>
            <div class="notice notice-success"><p><strong>Message sent.</strong> We'll get back to you at your email address within 1-2 business days.</p></div>
            <?php elseif ($error === 'ratelimit'): ?>
            <div class="notice notice-warning"><p>You've already sent a support message in the last hour. Please wait before sending another.</p></div>
            <?php elseif ($error === 'mail'): ?>
            <div class="notice notice-error"><p>There was a problem sending your message. Please try emailing us directly at <a href="mailto:support@dgtalweb.com">support@dgtalweb.com</a>.</p></div>
            <?php elseif ($error === 'empty'): ?>
            <div class="notice notice-error"><p>Please fill in all required fields before sending.</p></div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:1fr 300px;gap:24px;align-items:start;margin-top:16px;">

                <div class="tb-card">
                    <h2 style="margin-top:0;font-size:16px;">Send a message</h2>
                    <p style="color:#6b7280;margin-top:0;">Tell us what's on your mind — a bug, a question, or a feature idea. We read every message.</p>

                    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
                        <?php wp_nonce_field('tb_send_support', 'tb_support_nonce'); ?>
                        <input type="hidden" name="action" value="tb_send_support">

                        <table class="form-table" style="margin-top:0;">
                            <tr>
                                <th style="width:120px;"><label for="tb_support_name">Your name <span style="color:#ef4444;">*</span></label></th>
                                <td><input type="text" id="tb_support_name" name="tb_support_name" class="regular-text" value="<?= esc_attr($current_user->display_name) ?>" required></td>
                            </tr>
                            <tr>
                                <th><label for="tb_support_email">Your email <span style="color:#ef4444;">*</span></label></th>
                                <td><input type="email" id="tb_support_email" name="tb_support_email" class="regular-text" value="<?= esc_attr($current_user->user_email) ?>" required></td>
                            </tr>
                            <tr>
                                <th><label for="tb_support_type">Type <span style="color:#ef4444;">*</span></label></th>
                                <td>
                                    <select id="tb_support_type" name="tb_support_type" class="regular-text">
                                        <option value="general">General question</option>
                                        <option value="bug">Bug report</option>
                                        <option value="feature">Feature request</option>
                                        <option value="other">Other</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="tb_support_message">Message <span style="color:#ef4444;">*</span></label></th>
                                <td><textarea id="tb_support_message" name="tb_support_message" rows="8" class="large-text" required placeholder="Describe your question or issue in as much detail as possible..."></textarea></td>
                            </tr>
                            <tr>
                                <th><label for="tb_include_sysinfo">System info</label></th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="tb_include_sysinfo" name="tb_include_sysinfo" value="1" checked>
                                        Include system info (helps us diagnose issues faster)
                                    </label>
                                    <details style="margin-top:8px;">
                                        <summary style="cursor:pointer;color:#6b7280;font-size:12px;">Preview system info</summary>
                                        <pre style="background:#f9fafb;border:1px solid #e5e7eb;padding:10px;font-size:11px;border-radius:4px;margin-top:6px;white-space:pre-wrap;"><?= esc_html($sys_info) ?></pre>
                                    </details>
                                </td>
                            </tr>
                        </table>

                        <p style="margin-top:16px;">
                            <button type="submit" class="button button-primary">Send message</button>
                        </p>
                    </form>
                </div>

                <div>
                    <div class="tb-card" style="margin-bottom:16px;">
                        <h3 style="margin-top:0;font-size:14px;">Quick links</h3>
                        <ul style="margin:0;padding:0 0 0 16px;color:#374151;line-height:2;">
                            <li><a href="https://wordpress.org/support/plugin/getbooked/" target="_blank" rel="noopener">WP.org support forum</a></li>
                            <li><a href="https://wordpress.org/plugins/getbooked/#faq" target="_blank" rel="noopener">FAQ</a></li>
                            <li><a href="mailto:support@dgtalweb.com">Email us directly</a></li>
                        </ul>
                    </div>

                    <div class="tb-card">
                        <h3 style="margin-top:0;font-size:14px;">Before you write</h3>
                        <p style="color:#6b7280;font-size:13px;margin:0;">Check the FAQ and WP.org forum first — your question may already be answered. For bugs, enabling WP_DEBUG and copying any PHP errors into your message will speed things up considerably.</p>
                    </div>
                </div>

            </div>
        </div>
        <?php
    }

    public function handle_send_support(): void {
        check_admin_referer('tb_send_support', 'tb_support_nonce');
        if (!current_user_can('manage_options')) wp_die('Forbidden');

        $redirect = admin_url('admin.php?page=tb-support');

        $user_id = get_current_user_id();
        $rl_key  = 'tb_support_rl_' . $user_id;
        if (get_transient($rl_key)) {
            wp_safe_redirect($redirect . '&error=ratelimit');
            exit;
        }

        $name    = sanitize_text_field($_POST['tb_support_name']    ?? '');
        $email   = sanitize_email($_POST['tb_support_email']        ?? '');
        $type    = sanitize_key($_POST['tb_support_type']           ?? 'general');
        $message = sanitize_textarea_field($_POST['tb_support_message'] ?? '');

        if (!$name || !$email || !$message) {
            wp_safe_redirect($redirect . '&error=empty');
            exit;
        }

        $type_labels = [
            'general' => 'General question',
            'bug'     => 'Bug report',
            'feature' => 'Feature request',
            'other'   => 'Other',
        ];
        $type_label = $type_labels[$type] ?? 'General question';

        $body  = "Type: {$type_label}\n";
        $body .= "From: {$name} <{$email}>\n";
        $body .= "Site: " . site_url() . "\n\n";
        $body .= "--- Message ---\n\n{$message}\n";

        if (!empty($_POST['tb_include_sysinfo'])) {
            global $wpdb;
            $reservation_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}tb_reservations`");
            $body .= "\n--- System info ---\n\n";
            $body .= 'Plugin version : ' . TB_VERSION . "\n";
            $body .= 'WordPress      : ' . get_bloginfo('version') . "\n";
            $body .= 'PHP            : ' . PHP_VERSION . "\n";
            $body .= 'Site URL       : ' . site_url() . "\n";
            $body .= 'Active theme   : ' . wp_get_theme()->get('Name') . ' ' . wp_get_theme()->get('Version') . "\n";
            $body .= 'Booking mode   : ' . TB_Database::get_setting('booking_mode', 'simple') . "\n";
            $body .= 'Reservations   : ' . $reservation_count . "\n";
            $body .= 'WP_DEBUG       : ' . (defined('WP_DEBUG') && WP_DEBUG ? 'true' : 'false') . "\n";
            $body .= 'Multisite      : ' . (is_multisite() ? 'yes' : 'no') . "\n";
        }

        $to      = apply_filters('getbooked_support_email', 'support@dgtalweb.com');
        $subject = "[getBooked Support] {$type_label} from {$name}";
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            "Reply-To: {$name} <{$email}>",
        ];

        $sent = wp_mail($to, $subject, $body, $headers);

        if ($sent) {
            set_transient($rl_key, 1, HOUR_IN_SECONDS);
            wp_safe_redirect($redirect . '&sent=1');
        } else {
            wp_safe_redirect($redirect . '&error=mail');
        }
        exit;
    }
}

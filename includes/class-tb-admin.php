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
    }

    public function register_menu(): void {
        add_menu_page(
            'Table Booking',
            'Table Booking',
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
        add_submenu_page('tb-reservations', 'Activity Log',   'Activity Log',   'manage_options', 'tb-logs',         [$this, 'page_logs']);
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
        $detail_id = isset($_GET['view']) ? (int) $_GET['view'] : 0;

        if ($detail_id) {
            $this->render_reservation_detail($detail_id, $areas, $status_options);
            return;
        }
        ?>
        <div class="wrap tb-wrap">
            <h1 class="wp-heading-inline">Table Booking</h1>
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
            </form>

            <?php if (empty($rows)): ?>
            <div class="tb-empty">No reservations found.</div>
            <?php else: ?>
            <?php $sit_dur = (int) ($cfg['sitting_duration'] ?? 90); ?>
            <table class="wp-list-table widefat fixed striped tb-table">
                <thead>
                    <tr>
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
                    <td><code><?= esc_html($row['reservation_number']) ?></code></td>
                    <td><?= esc_html($row['customer_name']) ?></td>
                    <td><?= esc_html(date('d M Y', strtotime($row['reservation_date']))) ?></td>
                    <td>
                        <?= esc_html(date('g:i A', $start_ts)) ?>
                        <span class="tb-until">&rarr; <?= esc_html(date('g:i A', $end_ts)) ?></span>
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
                        <tr><th>Date</th><td><?= esc_html(date('l, F j, Y', strtotime($row['reservation_date']))) ?></td></tr>
                        <?php
                            $sit_min  = (int) TB_Database::get_setting('sitting_duration', '90');
                            $end_time = date('g:i A', strtotime($row['reservation_time']) + $sit_min * 60);
                        ?>
                        <tr><th>Time</th><td><?= esc_html(date('g:i A', strtotime($row['reservation_time']))) ?> &rarr; <?= esc_html($end_time) ?> <span style="color:#9ca3af;font-size:11px;">(<?= esc_html($sit_min) ?> min sitting)</span></td></tr>
                        <tr><th>Party</th><td><?= esc_html($row['party_size']) ?> guests</td></tr>
                        <tr><th>Area</th><td><?= esc_html($area_lbl) ?></td></tr>
                        <tr><th>Table</th><td><?= esc_html($row['table_name'] ?? 'Unassigned') ?></td></tr>
                        <tr><th>Status</th><td><span class="tb-badge tb-badge-<?= esc_attr($row['status']) ?>"><?= esc_html($status_options[$row['status']] ?? $row['status']) ?></span></td></tr>
                        <tr><th>Booked</th><td><?= esc_html(date('d M Y H:i', strtotime($row['created_at']))) ?></td></tr>
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
        $cfg       = TB_Database::get_all_settings();
        $areas     = json_decode($cfg['areas']     ?? '[]', true);
        $reminders = json_decode($cfg['reminders'] ?? TB_Reminders::default_config(), true);

        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
        }
        ?>
        <div class="wrap tb-wrap">
            <h1>Table Booking Settings</h1>
            <hr class="wp-header-end">

            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" class="tb-settings-form">
                <?php wp_nonce_field('tb_save_settings', 'tb_nonce'); ?>
                <input type="hidden" name="action" value="tb_save_settings">

                <!-- ── Booking Mode ──────────────────────────────────── -->
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
                    <div id="tb-simple-opts" style="margin-top:16px; <?= $mode !== 'simple' ? 'display:none;' : '' ?>">
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

                <!-- ── General ───────────────────────────────────────── -->
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

                <!-- ── Booking Hours ──────────────────────────────────── -->
                <div class="tb-settings-section">
                    <h2>Booking Hours</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="s-open">Opening Time</label></th>
                            <td><input type="time" id="s-open" name="opening_time" value="<?= esc_attr($cfg['opening_time'] ?? '12:00') ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="s-close">Closing Time</label></th>
                            <td><input type="time" id="s-close" name="closing_time" value="<?= esc_attr($cfg['closing_time'] ?? '22:00') ?>"></td>
                        </tr>
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

                <!-- ── Booking Limits ─────────────────────────────────── -->
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

                <!-- ── Seating Areas ──────────────────────────────────── -->
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

                <!-- ── Canvas Size ────────────────────────────────────── -->
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

                <?php submit_button('Save Settings'); ?>
            </form>
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
                        <td class="tb-log-time"><?= esc_html(date('d M Y H:i:s', strtotime($entry['created_at']))) ?></td>
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

        $id = (int) ($_POST['id'] ?? 0);
        (new TB_Reservations())->update($id, [
            'status'      => sanitize_text_field($_POST['status']     ?? ''),
            'table_id'    => (int) ($_POST['table_id']   ?? 0),
            'admin_notes' => sanitize_textarea_field($_POST['admin_notes'] ?? ''),
        ]);

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

        $bm = sanitize_text_field($_POST['booking_mode'] ?? 'simple');
        TB_Database::update_setting('booking_mode', in_array($bm, ['simple','layout'], true) ? $bm : 'simple');
        TB_Database::update_setting('max_seats', (string) max(1, (int) ($_POST['max_seats'] ?? 50)));

        $scalar_keys = [
            'restaurant_name','restaurant_address',
            'opening_time','closing_time','slot_duration','sitting_duration','last_booking_offset',
            'min_advance_hours','max_advance_days','max_party_size',
            'canvas_width','canvas_height',
        ];
        foreach ($scalar_keys as $k) {
            if (isset($_POST[$k])) {
                TB_Database::update_setting($k, sanitize_text_field($_POST[$k]));
            }
        }

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

        TB_Logger::info('Settings saved', 'system');

        wp_safe_redirect(admin_url('admin.php?page=tb-settings&saved=1'));
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
                                <p class="description">Shown at the bottom of guest confirmation emails. Leave blank to omit.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="e-footer">Email Footer Text</label></th>
                            <td>
                                <input type="text" id="e-footer" name="email_footer" value="<?= esc_attr($cfg['email_footer'] ?? '') ?>" class="regular-text" placeholder="e.g. 123 High Street, London · 020 7000 0000">
                                <p class="description">Appears at the bottom of every email. Leave blank to use restaurant name + site URL.</p>
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

        // Content
        TB_Database::update_setting('cancellation_policy', sanitize_textarea_field($_POST['cancellation_policy'] ?? ''));
        TB_Database::update_setting('email_footer',        sanitize_text_field($_POST['email_footer'] ?? ''));

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

        $current_style      = TB_Database::get_setting('booking_style', 'modern');
        $current_responsive = (bool) TB_Database::get_setting('booking_responsive', '1');
        $themes             = tb_style_themes();
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
                    <h2>Responsive Layout</h2>
                    <table class="form-table">
                        <tr>
                            <th>Mobile optimised</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="booking_responsive" value="1" <?= checked($current_responsive, true, false) ?>>
                                    Fluid layout — form stretches to fill its container
                                </label>
                                <p class="description">
                                    <strong>On:</strong> the form is fluid (<code>width: 100%</code>) and padding/step labels compress on narrow screens.<br>
                                    <strong>Off:</strong> the form is fixed at 640 px and scrolls horizontally on small screens — useful when your theme controls the layout width.
                                </p>
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

    public function handle_save_styles(): void {
        check_admin_referer('tb_save_styles', 'tb_nonce');
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $allowed = ['modern', 'dark', 'classic', 'minimal', 'bold', 'site'];
        $style   = sanitize_key($_POST['booking_style'] ?? 'modern');
        if (!in_array($style, $allowed, true)) $style = 'modern';

        TB_Database::update_setting('booking_style',      $style);
        TB_Database::update_setting('booking_responsive', isset($_POST['booking_responsive']) ? '1' : '0');
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
}

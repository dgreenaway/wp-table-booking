<?php
defined('ABSPATH') || exit;

class TB_Ajax {

    public function init(): void {
        // Frontend – available to guests and logged-in users
        $public = ['tb_get_times', 'tb_submit_booking'];
        foreach ($public as $action) {
            add_action("wp_ajax_$action",        [$this, $action]);
            add_action("wp_ajax_nopriv_$action", [$this, $action]);
        }

        // Admin only
        $admin = ['tb_update_status', 'tb_save_layout', 'tb_get_overlay', 'tb_get_overlay_times'];
        foreach ($admin as $action) {
            add_action("wp_ajax_$action", [$this, $action]);
        }
    }

    // =========================================================================
    // Frontend handlers
    // =========================================================================

    public function tb_get_times(): void {
        check_ajax_referer('tb_frontend', 'nonce');

        $date = sanitize_text_field($_POST['date'] ?? '');
        $area = sanitize_text_field($_POST['area'] ?? '');

        if (!$date || !$area) {
            wp_send_json_error('Missing parameters');
        }

        $ts = strtotime($date);
        if (!$ts || $ts < strtotime(current_time('Y-m-d'))) {
            wp_send_json_error('Invalid date');
        }

        $open_days    = json_decode(TB_Database::get_setting('open_days',    '[0,1,2,3,4,5,6]'), true);
        $closed_dates = json_decode(TB_Database::get_setting('closed_dates', '[]'),              true);

        if (!in_array((int) date('w', $ts), (array) $open_days, false)) {
            wp_send_json_error('The restaurant is closed on this day');
        }
        if (in_array($date, (array) $closed_dates, true)) {
            wp_send_json_error('The restaurant is closed on this date');
        }

        $res   = new TB_Reservations();
        $slots = $res->get_availability($date, $area);

        wp_send_json_success($slots);
    }

    public function tb_submit_booking(): void {
        check_ajax_referer('tb_frontend', 'nonce');

        // Honeypot — bots filling all form fields will populate this; JS never sends it.
        if (!empty($_POST['tb_hp'])) {
            wp_send_json_error(__('Invalid submission.', 'table-booking'));
        }

        // Rate limiting: max 3 submission attempts per IP per 10 minutes.
        $ip      = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
        $rl_key  = 'tb_rl_' . md5($ip);
        $attempts = (int) get_transient($rl_key);
        if ($attempts >= 3) {
            wp_send_json_error(__('Too many booking attempts. Please wait a few minutes and try again.', 'table-booking'));
        }
        set_transient($rl_key, $attempts + 1, 10 * MINUTE_IN_SECONDS);

        $required = ['date','time','area','party_size','customer_name','customer_email'];
        $data     = [];

        foreach ($required as $field) {
            $val = sanitize_text_field($_POST[$field] ?? '');
            if ($val === '') {
                wp_send_json_error("Field $field is required");
            }
            $data[$field] = $val;
        }

        if (!is_email($data['customer_email'])) {
            wp_send_json_error('Invalid email address');
        }

        $max_party = (int) TB_Database::get_setting('max_party_size', 12);
        $party     = (int) $data['party_size'];
        if ($party < 1 || $party > $max_party) {
            wp_send_json_error("Party size must be between 1 and $max_party");
        }

        $booking_ts = strtotime($data['date'] . ' ' . $data['time']);
        $min_adv    = (int) TB_Database::get_setting('min_advance_hours', 2) * 3600;
        if ($booking_ts < current_time('timestamp') + $min_adv) {
            wp_send_json_error('This time slot is no longer available');
        }

        $open_days    = json_decode(TB_Database::get_setting('open_days',    '[0,1,2,3,4,5,6]'), true);
        $closed_dates = json_decode(TB_Database::get_setting('closed_dates', '[]'),              true);
        $date_ts      = strtotime($data['date']);
        if (!in_array((int) date('w', $date_ts), (array) $open_days, false)) {
            wp_send_json_error('The restaurant is closed on this day');
        }
        if (in_array($data['date'], (array) $closed_dates, true)) {
            wp_send_json_error('The restaurant is closed on this date');
        }

        // Early availability pre-check for fast UX feedback (create() re-verifies under a lock).
        $res  = new TB_Reservations();
        $mode = TB_Database::get_setting('booking_mode', 'simple');

        if ($mode === 'layout') {
            if (!$res->find_available_table($data['date'], $data['time'], $data['area'], $party)) {
                wp_send_json_error(__('Sorry, no tables are available for your selection. Please choose a different time or area.', 'table-booking'));
            }
        } else {
            if (!$res->has_seat_capacity($data['date'], $data['time'], $party)) {
                wp_send_json_error(__('Sorry, we\'re fully booked for that time slot. Please choose a different time.', 'table-booking'));
            }
        }

        $id = $res->create([
            'reservation_date' => $data['date'],
            'reservation_time' => $data['time'],
            'seating_area'     => $data['area'],
            'party_size'       => $party,
            'customer_name'    => $data['customer_name'],
            'customer_email'   => $data['customer_email'],
            'customer_phone'   => sanitize_text_field($_POST['customer_phone'] ?? ''),
            'special_requests' => sanitize_textarea_field($_POST['special_requests'] ?? ''),
        ]);

        if (!$id) {
            $msg = match ($res->last_error) {
                'no_capacity'  => __('Sorry, that time slot just filled up. Please choose a different time.', 'table-booking'),
                'no_table'     => __('Sorry, no tables are available. Please choose a different time or area.', 'table-booking'),
                'lock_timeout' => __('The system is busy processing another booking. Please try again in a moment.', 'table-booking'),
                default        => (defined('WP_DEBUG') && WP_DEBUG)
                    ? 'Could not save reservation. DB: ' . $res->last_error
                    : __('Could not save your reservation. Please try again.', 'table-booking'),
            };
            wp_send_json_error($msg);
        }

        $booking = $res->get($id);
        wp_send_json_success([
            'id'                 => $id,
            'reservation_number' => $booking['reservation_number'],
            'date'               => date('l, F j, Y', strtotime($booking['reservation_date'])),
            'time'               => date('g:i A', strtotime($booking['reservation_time'])),
            'party_size'         => $booking['party_size'],
        ]);
    }

    // =========================================================================
    // Admin handlers
    // =========================================================================

    public function tb_update_status(): void {
        check_ajax_referer('tb_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $id     = (int) ($_POST['id']     ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? '');

        $allowed = ['pending','confirmed','seated','completed','cancelled','no_show'];
        if (!in_array($status, $allowed, true)) {
            wp_send_json_error('Invalid status');
        }

        $ok = (new TB_Reservations())->update($id, ['status' => $status]);
        if ($ok !== false) {
            wp_send_json_success(['status' => $status]);
        }
        wp_send_json_error('Update failed');
    }

    public function tb_save_layout(): void {
        check_ajax_referer('tb_layout', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $raw    = stripslashes($_POST['tables'] ?? '');
        $tables = json_decode($raw, true);

        if (!is_array($tables)) {
            wp_send_json_error('Invalid data');
        }

        (new TB_Layout())->save_layout($tables);
        wp_send_json_success('Layout saved');
    }

    public function tb_get_overlay(): void {
        check_ajax_referer('tb_layout', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $date = sanitize_text_field($_POST['date'] ?? '');
        $time = sanitize_text_field($_POST['time'] ?? '');

        if (!$date || !$time) wp_send_json_error('Missing params');

        $res    = new TB_Reservations();
        $booked = $res->get_active_table_ids($date, $time);

        wp_send_json_success(['booked_ids' => array_map('intval', $booked)]);
    }

    public function tb_get_overlay_times(): void {
        check_ajax_referer('tb_layout', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $date = sanitize_text_field($_POST['date'] ?? '');
        if (!$date) wp_send_json_error('Missing date');

        $cfg      = TB_Database::get_all_settings();
        $opening  = $cfg['opening_time']  ?? '12:00';
        $closing  = $cfg['closing_time']  ?? '22:00';
        $duration = (int) ($cfg['slot_duration'] ?? 60);

        $slots   = [];
        $current = strtotime("$date $opening");
        $last    = strtotime("$date $closing");

        while ($current < $last) {
            $slots[] = [
                'value' => date('H:i', $current),
                'label' => date('g:i A', $current),
            ];
            $current += $duration * 60;
        }

        wp_send_json_success($slots);
    }
}

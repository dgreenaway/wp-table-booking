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

        $res   = new TB_Reservations();
        $slots = $res->get_availability($date, $area);

        wp_send_json_success($slots);
    }

    public function tb_submit_booking(): void {
        check_ajax_referer('tb_frontend', 'nonce');

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

        $min_adv    = (int) TB_Database::get_setting('min_advance_hours', 2) * 3600;
        $booking_ts = strtotime($data['date'] . ' ' . $data['time']);
        if ($booking_ts < current_time('timestamp') + $min_adv) {
            wp_send_json_error('This time slot is no longer available');
        }

        $res  = new TB_Reservations();
        $mode = TB_Database::get_setting('booking_mode', 'simple');

        if ($mode === 'layout') {
            $table_id = $res->find_available_table($data['date'], $data['time'], $data['area'], $party);
            if (!$table_id) {
                wp_send_json_error('Sorry, no tables are available for your selection. Please choose a different time or area.');
            }
        } else {
            if (!$res->has_seat_capacity($data['date'], $data['time'], $party)) {
                wp_send_json_error('Sorry, we\'re fully booked for that time slot. Please choose a different time.');
            }
            $table_id = null;
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
            'table_id'         => $table_id,
        ]);

        if (!$id) {
            $detail = (defined('WP_DEBUG') && WP_DEBUG) ? ' DB: ' . $res->last_error : '';
            wp_send_json_error('Could not save reservation. Please try again.' . $detail);
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

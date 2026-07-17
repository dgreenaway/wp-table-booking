<?php
defined('ABSPATH') || exit;

/**
 * Hooks into WordPress's built-in privacy tools:
 * - Personal data exporter (Tools → Export Personal Data)
 * - Personal data eraser  (Tools → Erase Personal Data)
 * - Privacy policy suggested content
 */
class TB_Privacy {

    public static function register(): void {
        add_filter('wp_privacy_personal_data_exporters', [__CLASS__, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers',   [__CLASS__, 'register_eraser']);
        add_action('admin_init',                         [__CLASS__, 'add_policy_content']);
    }

    // -------------------------------------------------------------------------
    // Exporter
    // -------------------------------------------------------------------------

    public static function register_exporter(array $exporters): array {
        $exporters['table-booking'] = [
            'exporter_friendly_name' => __('Table Booking Reservations', 'table-booking'),
            'callback'               => [__CLASS__, 'export_data'],
        ];
        return $exporters;
    }

    public static function export_data(string $email, int $page = 1): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$wpdb->prefix}tb_reservations` WHERE customer_email = %s",
                $email
            ),
            ARRAY_A
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'group_id'    => 'table-booking-reservations',
                'group_label' => __('Table Booking Reservations', 'table-booking'),
                'item_id'     => 'reservation-' . (int) $row['id'],
                'data'        => [
                    ['name' => __('Reservation number', 'table-booking'), 'value' => $row['reservation_number']],
                    ['name' => __('Name',               'table-booking'), 'value' => $row['customer_name']],
                    ['name' => __('Email',              'table-booking'), 'value' => $row['customer_email']],
                    ['name' => __('Phone',              'table-booking'), 'value' => $row['customer_phone']],
                    ['name' => __('Date',               'table-booking'), 'value' => $row['reservation_date']],
                    ['name' => __('Time',               'table-booking'), 'value' => $row['reservation_time']],
                    ['name' => __('Party size',         'table-booking'), 'value' => $row['party_size']],
                    ['name' => __('Seating area',       'table-booking'), 'value' => $row['seating_area']],
                    ['name' => __('Status',             'table-booking'), 'value' => $row['status']],
                    ['name' => __('Special requests',   'table-booking'), 'value' => $row['special_requests']],
                    ['name' => __('Submitted',          'table-booking'), 'value' => $row['created_at']],
                ],
            ];
        }

        return ['data' => $items, 'done' => true];
    }

    // -------------------------------------------------------------------------
    // Eraser
    // -------------------------------------------------------------------------

    public static function register_eraser(array $erasers): array {
        $erasers['table-booking'] = [
            'eraser_friendly_name' => __('Table Booking Reservations', 'table-booking'),
            'callback'             => [__CLASS__, 'erase_data'],
        ];
        return $erasers;
    }

    public static function erase_data(string $email, int $page = 1): array {
        global $wpdb;

        $deleted = (int) $wpdb->delete(
            $wpdb->prefix . 'tb_reservations',
            ['customer_email' => $email],
            ['%s']
        );

        if ($deleted > 0) {
            TB_Logger::info("GDPR erasure: removed {$deleted} reservation(s) for {$email}", 'system');
        }

        return [
            'items_removed'  => $deleted,
            'items_retained' => 0,
            'messages'       => [],
            'done'           => true,
        ];
    }

    // -------------------------------------------------------------------------
    // Privacy policy suggested text
    // -------------------------------------------------------------------------

    public static function add_policy_content(): void {
        if (!function_exists('wp_add_privacy_policy_content')) return;

        $content = '<p>' . esc_html__(
            'When you make a table reservation through this website, the following personal data is collected and stored:',
            'table-booking'
        ) . '</p>'
        . '<ul>'
        . '<li>' . esc_html__('Your name', 'table-booking') . '</li>'
        . '<li>' . esc_html__('Your email address', 'table-booking') . '</li>'
        . '<li>' . esc_html__('Your phone number (optional)', 'table-booking') . '</li>'
        . '<li>' . esc_html__('Your reservation details (date, time, party size, seating area)', 'table-booking') . '</li>'
        . '<li>' . esc_html__('Any special requests you provide', 'table-booking') . '</li>'
        . '</ul>'
        . '<p>' . esc_html__(
            'This information is used solely to manage your reservation and send booking confirmation and reminder emails. It is not shared with third parties.',
            'table-booking'
        ) . '</p>'
        . '<p>' . esc_html__(
            'You may request a copy or deletion of your personal data using the privacy tools on this site.',
            'table-booking'
        ) . '</p>';

        wp_add_privacy_policy_content(
            __('Table Booking', 'table-booking'),
            wp_kses_post($content)
        );
    }
}

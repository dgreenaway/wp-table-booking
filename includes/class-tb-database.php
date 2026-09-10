<?php
defined('ABSPATH') || exit;

// Static utility class for DB setup and settings access. Settings are read via
// get_all_settings() which runs a two-layer cache: static (per-request) then WP
// object cache (persists between requests if a persistent cache plugin is active).
class TB_Database {

    // dbDelta handles both fresh installs and upgrades — adds missing tables and columns
    // but never drops anything. The double space before PRIMARY KEY is intentional; dbDelta's
    // parser requires it and will create a duplicate key index if you remove it.
    public static function install() {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $r  = $wpdb->prefix . 'tb_reservations';
        $t  = $wpdb->prefix . 'tb_tables';
        $s  = $wpdb->prefix . 'tb_settings';
        $l  = $wpdb->prefix . 'tb_logs';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE IF NOT EXISTS $r (
            id int(11) NOT NULL AUTO_INCREMENT,
            reservation_number varchar(20) NOT NULL,
            customer_name varchar(100) NOT NULL,
            customer_email varchar(100) NOT NULL,
            customer_phone varchar(30) DEFAULT '',
            reservation_date date NOT NULL,
            reservation_time time NOT NULL,
            party_size int(11) NOT NULL,
            seating_area varchar(50) NOT NULL DEFAULT 'dining',
            table_id int(11) DEFAULT NULL,
            status varchar(20) DEFAULT 'pending',
            special_requests text,
            admin_notes text,
            reminders_sent text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY reservation_number (reservation_number),
            KEY idx_date (reservation_date),
            KEY idx_status (status),
            KEY idx_date_status (reservation_date, status)
        ) $charset;");

        dbDelta("CREATE TABLE IF NOT EXISTS $t (
            id int(11) NOT NULL AUTO_INCREMENT,
            table_name varchar(50) NOT NULL,
            capacity int(11) NOT NULL DEFAULT 4,
            min_capacity int(11) NOT NULL DEFAULT 1,
            area varchar(50) NOT NULL DEFAULT 'dining',
            pos_x int(11) NOT NULL DEFAULT 50,
            pos_y int(11) NOT NULL DEFAULT 50,
            width int(11) NOT NULL DEFAULT 80,
            height int(11) NOT NULL DEFAULT 80,
            shape varchar(20) DEFAULT 'square',
            status varchar(20) DEFAULT 'active',
            PRIMARY KEY  (id)
        ) $charset;");

        dbDelta("CREATE TABLE IF NOT EXISTS $s (
            id int(11) NOT NULL AUTO_INCREMENT,
            setting_key varchar(100) NOT NULL,
            setting_value longtext,
            PRIMARY KEY  (id),
            UNIQUE KEY setting_key (setting_key)
        ) $charset;");

        dbDelta("CREATE TABLE IF NOT EXISTS $l (
            id int(11) NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL DEFAULT 'info',
            context varchar(50) NOT NULL DEFAULT 'system',
            message text NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_level (level),
            KEY idx_created (created_at)
        ) $charset;");

        self::seed_defaults();
        update_option('tb_db_version', TB_VERSION);
    }

    // Called on every page load from tb_boot. The version comparison exits immediately
    // when up to date, so the overhead is a single option read per request.
    public static function maybe_upgrade(): void {
        $installed = get_option('tb_db_version', '0.0.0');
        if (version_compare($installed, TB_VERSION, '>=')) return;
        // dbDelta is idempotent — adds missing tables/columns; seed_defaults uses INSERT IGNORE.
        self::install();
    }

    // INSERT IGNORE means re-running this on upgrade won't overwrite settings the
    // user has already changed. Only genuinely missing keys get inserted.
    private static function seed_defaults() {
        global $wpdb;
        $s = $wpdb->prefix . 'tb_settings';

        $defaults = [
            'restaurant_name'     => get_bloginfo('name'),
            'restaurant_address'  => '',
            'opening_time'        => '12:00',
            'closing_time'        => '22:00',
            'weekly_hours'        => json_encode([
                'mon' => ['open' => true,  'from' => '12:00', 'to' => '22:00'],
                'tue' => ['open' => true,  'from' => '12:00', 'to' => '22:00'],
                'wed' => ['open' => true,  'from' => '12:00', 'to' => '22:00'],
                'thu' => ['open' => true,  'from' => '12:00', 'to' => '22:00'],
                'fri' => ['open' => true,  'from' => '12:00', 'to' => '22:00'],
                'sat' => ['open' => true,  'from' => '12:00', 'to' => '22:00'],
                'sun' => ['open' => false, 'from' => '12:00', 'to' => '22:00'],
            ]),
            'slot_duration'       => '60',
            'last_booking_offset' => '60',
            'min_advance_hours'   => '2',
            'max_advance_days'    => '60',
            'max_party_size'      => '12',
            'canvas_width'        => '900',
            'canvas_height'       => '560',
            'areas'               => json_encode([
                ['id' => 'dining', 'label' => 'Dining Room', 'color' => '#f97316'],
                ['id' => 'bar',    'label' => 'Bar Area',    'color' => '#3b82f6'],
                ['id' => 'garden', 'label' => 'Garden',      'color' => '#22c55e'],
            ]),
            // Email settings
            'email_from_name'      => get_bloginfo('name'),
            'email_from_address'   => get_option('admin_email'),
            'admin_email'          => get_option('admin_email'),
            'notify_admin'         => '1',
            'email_notifications'  => '1',
            'cancellation_policy'  => '',
            'email_footer'         => '',
            // Booking mode
            'booking_mode'         => 'simple',  // 'simple' | 'layout'
            'max_seats'            => '50',
            'sitting_duration'     => '90',       // minutes a party occupies a table/slot
            // Email branding
            'email_logo_id'        => '',
            'email_logo_url'       => '',
            // Styles & layout
            'booking_style'           => 'modern',
            'booking_responsive'      => '1',
            'booking_form_width'      => 'default',
            'booking_density'         => 'default',
            'booking_stack_buttons'   => '0',
            'booking_steps_mobile'    => 'labels',
            'booking_ui_scale'        => '100',
            // Reminder settings
            'reminders_enabled'       => '1',
            'reminders'               => TB_Reminders::default_config(),
            // Availability
            'closed_dates'             => '[]',
            // Booking form
            'booking_success_message'  => '',
            // Data & privacy
            'delete_data_on_uninstall' => '0',
            'data_retention_days'      => '0',
            // Daily digest
            'daily_digest_enabled'     => '0',
            'daily_digest_time'        => '08:00',
            // Setup wizard
            'setup_complete'           => '0',
        ];

        foreach ($defaults as $key => $value) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO $s (setting_key, setting_value) VALUES (%s, %s)",
                    $key,
                    $value
                )
            );
        }
    }

    private static ?array $cache = null;

    // Two-layer cache: the static property handles repeated calls within the same
    // request; the WP object cache handles subsequent requests when a persistent
    // cache (Redis, Memcached) is active. Both are invalidated on every write.
    public static function get_all_settings(): array {
        if (self::$cache !== null) return self::$cache;

        $cached = wp_cache_get('tb_settings', 'table-booking');
        if (false !== $cached) {
            self::$cache = $cached;
            return $cached;
        }

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT setting_key, setting_value FROM `{$wpdb->prefix}tb_settings`",
            ARRAY_A
        );
        $data = array_column($rows, 'setting_value', 'setting_key');

        wp_cache_set('tb_settings', $data, 'table-booking', HOUR_IN_SECONDS);
        self::$cache = $data;
        return $data;
    }

    public static function get_setting(string $key, string $default = ''): string {
        $all = self::get_all_settings();
        return isset($all[$key]) ? (string) $all[$key] : $default;
    }

    // UPSERT via ON DUPLICATE KEY UPDATE — inserts on first save, updates on subsequent ones.
    // Both cache layers are cleared after every write so the next read is always fresh.
    public static function update_setting(string $key, string $value): bool {
        global $wpdb;
        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `{$wpdb->prefix}tb_settings` (setting_key, setting_value) VALUES (%s, %s)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                $key,
                $value
            )
        );
        if ($result === false) {
            TB_Logger::error("Failed to save setting '{$key}': " . $wpdb->last_error, 'system');
        }
        wp_cache_delete('tb_settings', 'table-booking');
        self::$cache = null;
        return $result !== false;
    }
}

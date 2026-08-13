<?php
defined('ABSPATH') || exit;

class TB_Telemetry {

    const ENDPOINT   = 'https://telemetry.getbooked.workers.dev/ping';
    const OPT_STATUS = 'tb_telemetry_status';   // 'pending' | 'opted_in' | 'opted_out'
    const OPT_LAST   = 'tb_telemetry_last_ping'; // unix timestamp

    public static function init(): void {
        add_action('admin_notices',           [__CLASS__, 'maybe_show_notice']);
        add_action('admin_post_tb_telemetry', [__CLASS__, 'handle_response']);
        add_action('tb_telemetry_ping',       [__CLASS__, 'send_ping']);
        add_filter('cron_schedules',          [__CLASS__, 'add_monthly_schedule']);
    }

    public static function add_monthly_schedule(array $schedules): array {
        if (!isset($schedules['tb_monthly'])) {
            $schedules['tb_monthly'] = [
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => 'Once a month',
            ];
        }
        return $schedules;
    }

    public static function on_activation(): void {
        if (get_option(self::OPT_STATUS) === false) {
            add_option(self::OPT_STATUS, 'pending', '', false);
        }
    }

    public static function on_deactivation(): void {
        wp_clear_scheduled_hook('tb_telemetry_ping');
    }

    public static function maybe_show_notice(): void {
        if (get_option(self::OPT_STATUS) !== 'pending') return;
        if (!current_user_can('manage_options')) return;
        $screen = get_current_screen();
        $id = $screen->id ?? '';
        if (!str_contains($id, 'tb-') && $id !== 'dashboard') return;
        ?>
        <div class="notice notice-info" style="padding:14px 16px;">
            <p style="margin:0 0 10px;font-size:14px;">
                <strong>Help improve getBooked</strong> &mdash; opt in to share anonymous usage data (plugin version, WP&nbsp;/&nbsp;PHP version, booking mode, table count). No personal data, no site URLs, no email addresses.
            </p>
            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display:inline;">
                <?php wp_nonce_field('tb_telemetry_response', 'tb_telemetry_nonce'); ?>
                <input type="hidden" name="action" value="tb_telemetry">
                <input type="hidden" name="tb_telemetry_choice" value="opted_in">
                <button type="submit" class="button button-primary">Allow &mdash; I&rsquo;m in</button>
            </form>
            &nbsp;
            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display:inline;">
                <?php wp_nonce_field('tb_telemetry_response', 'tb_telemetry_nonce'); ?>
                <input type="hidden" name="action" value="tb_telemetry">
                <input type="hidden" name="tb_telemetry_choice" value="opted_out">
                <button type="submit" class="button button-link" style="color:#6b7280;text-decoration:none;">No thanks</button>
            </form>
        </div>
        <?php
    }

    public static function handle_response(): void {
        check_admin_referer('tb_telemetry_response', 'tb_telemetry_nonce');
        if (!current_user_can('manage_options')) wp_die('Forbidden');

        $choice = sanitize_key($_POST['tb_telemetry_choice'] ?? '');
        if (!in_array($choice, ['opted_in', 'opted_out'], true)) {
            wp_safe_redirect(wp_get_referer() ?: admin_url());
            exit;
        }

        update_option(self::OPT_STATUS, $choice, false);

        if ($choice === 'opted_in') {
            self::send_ping();
            if (!wp_next_scheduled('tb_telemetry_ping')) {
                wp_schedule_event(time() + 30 * DAY_IN_SECONDS, 'tb_monthly', 'tb_telemetry_ping');
            }
        } else {
            wp_clear_scheduled_hook('tb_telemetry_ping');
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    public static function send_ping(): void {
        if (get_option(self::OPT_STATUS) !== 'opted_in') return;

        global $wpdb;
        $table_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}tb_tables`");
        $res_total   = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}tb_reservations`");

        $payload = wp_json_encode([
            'plugin_version'    => TB_VERSION,
            'wp_version'        => get_bloginfo('version'),
            'php_version'       => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'booking_mode'      => TB_Database::get_setting('booking_mode', 'simple'),
            'table_count'       => $table_count,
            'reservation_total' => $res_total,
            'locale'            => get_locale(),
            'is_multisite'      => is_multisite() ? 1 : 0,
        ]);

        wp_remote_post(self::ENDPOINT, [
            'body'      => $payload,
            'headers'   => ['Content-Type' => 'application/json'],
            'timeout'   => 5,
            'blocking'  => false,
        ]);

        update_option(self::OPT_LAST, time(), false);
    }

    public static function get_status_label(): string {
        switch (get_option(self::OPT_STATUS, 'pending')) {
            case 'opted_in':  return 'Sharing (opted in)';
            case 'opted_out': return 'Not sharing (opted out)';
            default:          return 'Not decided yet';
        }
    }
}

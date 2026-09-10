<?php
defined('ABSPATH') || exit;

// Thin wrapper around the tb_logs table. Probabilistic pruning in write() keeps the
// table from growing indefinitely without needing a separate scheduled cron event.
class TB_Logger {

    public static function info(string $msg, string $ctx = 'system'): void {
        self::write('info', $ctx, $msg);
    }

    public static function warning(string $msg, string $ctx = 'system'): void {
        self::write('warning', $ctx, $msg);
    }

    public static function error(string $msg, string $ctx = 'system'): void {
        self::write('error', $ctx, $msg);
    }

    // Inserts the log entry, then on a 1-in-50 chance runs a cleanup DELETE for
    // entries older than 30 days. Cheap enough to run inline without a cron.
    private static function write(string $level, string $ctx, string $msg): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'tb_logs',
            [
                'level'      => $level,
                'context'    => $ctx,
                'message'    => $msg,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s']
        );
        // Prune entries older than 30 days on 1-in-50 writes
        if (mt_rand(1, 50) === 1) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}tb_logs WHERE created_at < %s",
                date('Y-m-d H:i:s', strtotime('-30 days'))
            ));
        }
    }

    public static function get(int $limit = 200): array {
        global $wpdb;
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tb_logs ORDER BY id DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
    }

    public static function clear(): void {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}tb_logs");
    }

    public static function count(): int {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tb_logs");
    }
}

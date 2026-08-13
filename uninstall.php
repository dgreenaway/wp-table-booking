<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

// Respect the user's choice — default is to preserve data.
$delete = $wpdb->get_var(
    $wpdb->prepare(
        "SELECT setting_value FROM `{$wpdb->prefix}tb_settings` WHERE setting_key = %s",
        'delete_data_on_uninstall'
    )
);

if ($delete !== '1') {
    return;
}

// Drop tables in an order that avoids any FK issues (logs/reservations before settings).
foreach (['tb_reservations', 'tb_tables', 'tb_logs', 'tb_settings'] as $table) {
    $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

delete_option('tb_db_version');

// Clear rate-limit transients.
$wpdb->query("DELETE FROM `{$wpdb->options}` WHERE option_name LIKE '_transient_tb_rl_%' OR option_name LIKE '_transient_timeout_tb_rl_%'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

// Clear any remaining scheduled events.
$crons = ['tb_send_reminders', 'tb_cleanup_old_reservations'];
foreach ($crons as $hook) {
    $ts = wp_next_scheduled($hook);
    if ($ts) wp_unschedule_event($ts, $hook);
    wp_clear_scheduled_hook($hook);
}

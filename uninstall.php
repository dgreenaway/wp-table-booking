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

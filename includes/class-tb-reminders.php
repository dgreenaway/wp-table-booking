<?php
defined('ABSPATH') || exit;

/**
 * Schedules and dispatches configurable reminder emails via WP-Cron.
 *
 * Strategy: a single hourly cron event scans all upcoming reservations and
 * sends any reminder whose send-time has been reached but hasn't fired yet.
 * This is simpler and more reliable than scheduling one event per reminder:
 * no events to clean up on cancellation, and it survives plugin re-installs.
 *
 * Sent state is persisted in the `reminders_sent` column as a JSON array of
 * reminder-index integers, e.g. [0, 2] means reminders 0 and 2 were sent.
 */
class TB_Reminders {

    const HOOK = 'tb_process_reminders';

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public static function init(): void {
        add_action(self::HOOK, [__CLASS__, 'process']);
    }

    public static function activate(): void {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time(), 'hourly', self::HOOK);
        }
    }

    public static function deactivate(): void {
        $ts = wp_next_scheduled(self::HOOK);
        if ($ts) wp_unschedule_event($ts, self::HOOK);
    }

    // -------------------------------------------------------------------------
    // Cron callback
    // -------------------------------------------------------------------------

    public static function process(): void {
        $cfg = TB_Database::get_all_settings();

        if (empty($cfg['reminders_enabled'])) return;

        $reminder_cfg = json_decode($cfg['reminders'] ?? '[]', true);
        if (empty($reminder_cfg) || !is_array($reminder_cfg)) return;

        // Build list of enabled reminders with their offset in seconds
        $enabled = [];
        foreach ($reminder_cfg as $idx => $r) {
            if (!empty($r['enabled']) && !empty($r['hours']) && (int) $r['hours'] > 0) {
                $enabled[$idx] = (int) $r['hours'];
            }
        }
        if (empty($enabled)) return;

        global $wpdb;
        $rt    = $wpdb->prefix . 'tb_reservations';
        $now   = current_time('timestamp');
        $today = current_time('Y-m-d');

        // Fetch all future reservations that haven't been cancelled/completed
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, reservation_date, reservation_time, status, reminders_sent
                 FROM $rt
                 WHERE reservation_date >= %s
                   AND status NOT IN ('cancelled','completed','no_show')",
                $today
            ),
            ARRAY_A
        );

        $sent_count = 0;
        foreach ($rows as $row) {
            $booking_ts   = strtotime($row['reservation_date'] . ' ' . $row['reservation_time']);
            if ($booking_ts <= $now) continue; // already past

            $sent = json_decode($row['reminders_sent'] ?: '[]', true);
            if (!is_array($sent)) $sent = [];

            foreach ($enabled as $idx => $hours) {
                if (in_array($idx, $sent, true)) continue; // already sent

                $send_at = $booking_ts - ($hours * 3600);
                if ($now < $send_at) continue;  // not yet time

                // Send and record
                $reminder_def = $reminder_cfg[$idx];
                $ok = TB_Emails::send_client_reminder((int) $row['id'], [
                    'hours' => $hours,
                    'label' => $reminder_def['label'] ?? ($hours . 'h'),
                ]);

                if ($ok !== false) {
                    $sent_count++;
                    $sent[] = $idx;
                    $wpdb->update(
                        $rt,
                        ['reminders_sent' => wp_json_encode($sent)],
                        ['id'             => (int) $row['id']],
                        ['%s'],
                        ['%d']
                    );
                }
            }
        }

        if ($sent_count > 0) {
            TB_Logger::info("Cron: sent {$sent_count} reminder email(s)", 'cron');
        }
    }

    // -------------------------------------------------------------------------
    // Reset reminders when a reservation is cancelled (stops future sends)
    // -------------------------------------------------------------------------

    public static function cancel_for_reservation(int $id): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'tb_reservations',
            ['reminders_sent' => wp_json_encode(['cancelled'])],
            ['id' => $id],
            ['%s'],
            ['%d']
        );
    }

    // -------------------------------------------------------------------------
    // Default reminder config
    // -------------------------------------------------------------------------

    public static function default_config(): string {
        return wp_json_encode([
            ['enabled' => true,  'hours' => 24, 'label' => '24 hours before'],
            ['enabled' => true,  'hours' => 2,  'label' => '2 hours before'],
            ['enabled' => false, 'hours' => 48, 'label' => '48 hours before'],
        ]);
    }
}

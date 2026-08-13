<?php
defined('ABSPATH') || exit;

class TB_Reservations {

    private string $rtable;
    public  string $last_error = '';

    public function __construct() {
        global $wpdb;
        $this->rtable = $wpdb->prefix . 'tb_reservations';
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    public function create(array $data): int|false {
        global $wpdb;

        $date  = sanitize_text_field($data['reservation_date']);
        $time  = sanitize_text_field($data['reservation_time']);
        $area  = sanitize_text_field($data['seating_area']);
        $party = (int) $data['party_size'];
        $mode  = TB_Database::get_setting('booking_mode', 'simple');

        // Advisory lock serializes concurrent requests for the same date/time/area,
        // preventing TOCTOU double-bookings when two submissions race past the
        // pre-check in the AJAX handler simultaneously.
        $lock_key = 'tb_slot_' . md5("{$date}_{$time}_{$area}");
        $locked   = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT GET_LOCK(%s, 5)", $lock_key)
        );
        if ($locked !== '1') {
            $this->last_error = 'lock_timeout';
            return false;
        }

        // Re-verify availability under the lock.
        if ($mode === 'layout') {
            $table_id = $this->find_available_table($date, $time, $area, $party);
            if (!$table_id) {
                $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_key));
                $this->last_error = 'no_table';
                return false;
            }
        } else {
            $table_id = null;
            if (!$this->has_seat_capacity($date, $time, $party)) {
                $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_key));
                $this->last_error = 'no_capacity';
                return false;
            }
        }

        $num = $this->generate_number();
        $row = [
            'reservation_number' => $num,
            'customer_name'      => sanitize_text_field($data['customer_name']),
            'customer_email'     => sanitize_email($data['customer_email']),
            'customer_phone'     => sanitize_text_field($data['customer_phone'] ?? ''),
            'reservation_date'   => $date,
            'reservation_time'   => $time,
            'party_size'         => $party,
            'seating_area'       => $area,
            'status'             => 'pending',
            'special_requests'   => sanitize_textarea_field($data['special_requests'] ?? ''),
            'created_at'         => current_time('mysql'),
        ];
        $fmt = ['%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s'];

        if ($table_id !== null) {
            $row['table_id'] = $table_id;
            $fmt[]           = '%d';
        }

        $ok = $wpdb->insert($this->rtable, $row, $fmt);
        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_key));

        if (!$ok) {
            $this->last_error = $wpdb->last_error;
            TB_Logger::error(
                "Booking insert failed for {$data['customer_name']} ({$date} {$time}): " . $this->last_error,
                'booking'
            );
            return false;
        }

        $id = $wpdb->insert_id;
        TB_Logger::info(
            "Booking created: #{$num} — {$data['customer_name']} on {$date} at {$time}, party of {$party}",
            'booking'
        );

        self::clear_availability_cache($date, $area);

        TB_Emails::send_client_confirmation($id);
        TB_Emails::send_admin_notification($id);

        return $id;
    }

    public function get(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT r.*, t.table_name, t.capacity AS table_capacity
                 FROM {$this->rtable} r
                 LEFT JOIN {$wpdb->prefix}tb_tables t ON r.table_id = t.id
                 WHERE r.id = %d",
                $id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function update(int $id, array $data): bool {
        global $wpdb;
        $allowed  = ['status','customer_name','customer_email','customer_phone',
                     'reservation_date','reservation_time','party_size',
                     'seating_area','table_id','special_requests','admin_notes'];
        $int_cols = ['party_size','table_id'];

        $fields = $fmts = [];
        foreach ($allowed as $col) {
            if (!array_key_exists($col, $data)) continue;
            $fields[$col] = in_array($col, $int_cols) ? (int) $data[$col] : sanitize_text_field($data[$col]);
            $fmts[]       = in_array($col, $int_cols) ? '%d' : '%s';
        }

        if (empty($fields)) return false;
        $ok = (bool) $wpdb->update($this->rtable, $fields, ['id' => $id], $fmts, ['%d']);

        if ($ok) {
            // Clear availability cache for this reservation's date/area so the next
            // request reflects the updated capacity or cancellation immediately.
            $row = $this->get($id);
            if ($row) {
                self::clear_availability_cache($row['reservation_date'], $row['seating_area']);
            }
            if (isset($data['status'])) {
                TB_Logger::info("Booking #{$id} status → {$data['status']}", 'booking');
                if (in_array($data['status'], ['cancelled','no_show','completed'], true)) {
                    TB_Reminders::cancel_for_reservation($id);
                }
            }
        }

        return $ok;
    }

    /**
     * Admin-side booking creation — skips availability checks and rate limiting.
     * $notify controls whether confirmation emails are sent to the guest.
     */
    public function admin_create(array $data, bool $notify = true): int|false {
        global $wpdb;

        $num = $this->generate_number();
        $status = sanitize_text_field($data['status'] ?? 'pending');

        $row = [
            'reservation_number' => $num,
            'customer_name'      => sanitize_text_field($data['customer_name']),
            'customer_email'     => sanitize_email($data['customer_email']),
            'customer_phone'     => sanitize_text_field($data['customer_phone'] ?? ''),
            'reservation_date'   => sanitize_text_field($data['reservation_date']),
            'reservation_time'   => sanitize_text_field($data['reservation_time']),
            'party_size'         => max(1, (int) $data['party_size']),
            'seating_area'       => sanitize_text_field($data['seating_area']),
            'status'             => $status,
            'special_requests'   => sanitize_textarea_field($data['special_requests'] ?? ''),
            'admin_notes'        => sanitize_textarea_field($data['admin_notes']      ?? ''),
            'created_at'         => current_time('mysql'),
        ];
        $fmt = ['%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s'];

        if (!empty($data['table_id'])) {
            $row['table_id'] = (int) $data['table_id'];
            $fmt[]           = '%d';
        }

        $ok = $wpdb->insert($this->rtable, $row, $fmt);
        if (!$ok) {
            TB_Logger::error('Admin booking insert failed: ' . $wpdb->last_error, 'booking');
            return false;
        }

        $id = $wpdb->insert_id;
        self::clear_availability_cache($row['reservation_date'], $row['seating_area']);
        TB_Logger::info("Admin created booking: #{$num} — {$data['customer_name']} on {$row['reservation_date']}", 'booking');

        if ($notify) {
            if ($status === 'confirmed') {
                TB_Emails::send_client_status_update($id, 'confirmed');
            } else {
                TB_Emails::send_client_confirmation($id);
            }
            TB_Emails::send_admin_notification($id);
        }

        return $id;
    }

    public function delete(int $id): bool {
        global $wpdb;
        return (bool) $wpdb->delete($this->rtable, ['id' => $id], ['%d']);
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    public function get_all(array $args = []): array {
        global $wpdb;
        $args = wp_parse_args($args, [
            'status'   => '',
            'date'     => '',
            'area'     => '',
            'search'   => '',
            'per_page' => 25,
            'page'     => 1,
            'orderby'  => 'reservation_date',
            'order'    => 'DESC',
        ]);

        [$where, $params] = $this->build_where($args);

        $allowed_order = ['reservation_date','created_at','customer_name','status','party_size'];
        $ob = in_array($args['orderby'], $allowed_order) ? $args['orderby'] : 'reservation_date';
        $od = $args['order'] === 'ASC' ? 'ASC' : 'DESC';

        $limit  = max(1, (int) $args['per_page']);
        $offset = max(0, ((int) $args['page'] - 1) * $limit);
        $params[] = $limit;
        $params[] = $offset;

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.*, t.table_name
                 FROM {$this->rtable} r
                 LEFT JOIN {$wpdb->prefix}tb_tables t ON r.table_id = t.id
                 WHERE $where
                 ORDER BY r.$ob $od, r.reservation_time ASC
                 LIMIT %d OFFSET %d",
                $params
            ),
            ARRAY_A
        );
    }

    public function count(array $args = []): int {
        global $wpdb;
        [$where, $params] = $this->build_where($args);
        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$this->rtable} WHERE $where", $params)
        );
    }

    public function get_by_date(string $date): array {
        global $wpdb;
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.*, t.table_name
                 FROM {$this->rtable} r
                 LEFT JOIN {$wpdb->prefix}tb_tables t ON r.table_id = t.id
                 WHERE r.reservation_date = %s AND r.status NOT IN ('cancelled','no_show')
                 ORDER BY r.reservation_time ASC",
                $date
            ),
            ARRAY_A
        );
    }

    // -------------------------------------------------------------------------
    // Availability
    // -------------------------------------------------------------------------

    private static function avail_cache_key(string $date, string $area): string {
        return 'tb_av_' . md5($date . $area);
    }

    public static function clear_availability_cache(string $date, string $area): void {
        delete_transient(self::avail_cache_key($date, $area));
    }

    public function get_availability(string $date, string $area): array {
        $cache_key = self::avail_cache_key($date, $area);
        $cached    = get_transient($cache_key);
        if ($cached !== false) return $cached;
        $cfg    = TB_Database::get_all_settings();
        $closed = json_decode($cfg['closed_dates'] ?? '[]', true);
        if (in_array($date, (array) $closed, true)) {
            return [];
        }

        // Per-day hours: check the weekly schedule for this specific day of week.
        $day_key      = strtolower(wp_date('D', strtotime($date)));  // 'mon','tue', etc.
        $weekly_hours = json_decode($cfg['weekly_hours'] ?? '{}', true);
        $day_cfg      = $weekly_hours[$day_key] ?? null;

        if ($day_cfg !== null && !$day_cfg['open']) {
            return [];  // Restaurant closed on this day of week.
        }

        $opening  = ($day_cfg['from'] ?? null) ?: ($cfg['opening_time'] ?? '12:00');
        $closing  = ($day_cfg['to']   ?? null) ?: ($cfg['closing_time']  ?? '22:00');
        $slot_dur = (int) ($cfg['slot_duration']       ?? 60);
        $sit_dur  = max(1, (int) ($cfg['sitting_duration'] ?? 90));
        $lbo      = (int) ($cfg['last_booking_offset']  ?? 60);
        $min_adv  = (int) ($cfg['min_advance_hours']    ?? 2) * 3600;
        $mode     = $cfg['booking_mode'] ?? 'simple';

        $now     = current_time('timestamp');
        $current = strtotime("$date $opening");
        // Last slot must leave enough room for the sitting to complete before closing
        $last    = strtotime("$date $closing") - max($lbo, $sit_dur) * 60;

        $slots = [];
        while ($current <= $last) {
            $ts       = $current;
            $time_str = gmdate('H:i', $ts);
            $end_ts   = $ts + $sit_dur * 60;

            if ($ts < $now + $min_adv) {
                $current += $slot_dur * 60;
                continue;
            }

            if ($mode === 'layout') {
                $booked  = $this->get_booked_table_ids($date, $time_str, $sit_dur);
                $free    = $this->count_free_tables($area, $booked);
                $slots[] = [
                    'time'      => $time_str,
                    'label'     => wp_date('g:i A', $ts),
                    'end_time'  => gmdate('H:i', $end_ts),
                    'end_label' => wp_date('g:i A', $end_ts),
                    'available' => $free > 0,
                    'tables'    => $free,
                ];
            } else {
                $slots[] = [
                    'time'      => $time_str,
                    'label'     => wp_date('g:i A', $ts),
                    'end_time'  => gmdate('H:i', $end_ts),
                    'end_label' => wp_date('g:i A', $end_ts),
                    'available' => $this->has_seat_capacity($date, $time_str, 1, $sit_dur, $cfg),
                ];
            }

            $current += $slot_dur * 60;
        }

        set_transient($cache_key, $slots, 2 * MINUTE_IN_SECONDS);
        return $slots;
    }

    /**
     * Returns true if the venue has capacity for $party more guests at the given
     * time slot, accounting for all sittings that overlap that window.
     * Used in Simple mode only.
     */
    public function has_seat_capacity(
        string $date,
        string $time,
        int    $party,
        int    $sitting_minutes = 0,
        array  $cfg = []
    ): bool {
        global $wpdb;
        if (empty($cfg))          $cfg             = TB_Database::get_all_settings();
        if ($sitting_minutes < 1) $sitting_minutes = max(1, (int) ($cfg['sitting_duration'] ?? 90));

        $max      = max(1, (int) ($cfg['max_seats'] ?? 50));
        $sit_sec  = $sitting_minutes * 60;

        // Sum party sizes of ALL bookings whose sitting window overlaps [T, T+D)
        $booked = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(party_size), 0) FROM {$this->rtable}
                 WHERE reservation_date = %s
                   AND status NOT IN ('cancelled','completed','no_show')
                   AND reservation_time        < ADDTIME(%s, SEC_TO_TIME(%d))
                   AND ADDTIME(reservation_time, SEC_TO_TIME(%d)) > %s",
                $date,
                $time, $sit_sec,
                $sit_sec, $time
            )
        );
        return ($booked + $party) <= $max;
    }

    /**
     * Returns IDs of tables whose sitting window conflicts with a new booking
     * at $time of $sitting_minutes duration.
     *
     * Two sittings conflict when [B, B+D) overlaps [T, T+D):
     *   B < T+D  AND  T < B+D
     */
    public function get_booked_table_ids(string $date, string $time, int $sitting_minutes = 0): array {
        global $wpdb;
        if ($sitting_minutes < 1) {
            $sitting_minutes = max(1, (int) TB_Database::get_setting('sitting_duration', '90'));
        }
        $sit_sec = $sitting_minutes * 60;

        return (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT table_id FROM {$this->rtable}
                 WHERE reservation_date = %s
                   AND status NOT IN ('cancelled','completed','no_show')
                   AND table_id IS NOT NULL
                   AND reservation_time        < ADDTIME(%s, SEC_TO_TIME(%d))
                   AND ADDTIME(reservation_time, SEC_TO_TIME(%d)) > %s",
                $date,
                $time, $sit_sec,
                $sit_sec, $time
            )
        );
    }

    /**
     * Tables whose sitting is actively running AT $time (for the canvas overlay).
     * Uses "contains T" logic: B <= T < B+D
     */
    public function get_active_table_ids(string $date, string $time): array {
        global $wpdb;
        $sit_sec = max(1, (int) TB_Database::get_setting('sitting_duration', '90')) * 60;

        return (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT table_id FROM {$this->rtable}
                 WHERE reservation_date = %s
                   AND status NOT IN ('cancelled','completed','no_show')
                   AND table_id IS NOT NULL
                   AND reservation_time               <= %s
                   AND ADDTIME(reservation_time, SEC_TO_TIME(%d)) > %s",
                $date,
                $time, $sit_sec, $time
            )
        );
    }

    public function find_available_table(string $date, string $time, string $area, int $party): ?int {
        if ((TB_Database::get_setting('booking_mode', 'simple')) === 'simple') {
            return null; // simple mode — no individual table assignment
        }

        global $wpdb;
        $tt     = $wpdb->prefix . 'tb_tables';
        $booked = $this->get_booked_table_ids($date, $time);

        $excl = '';
        if (!empty($booked)) {
            $ids  = implode(',', array_map('intval', $booked));
            $excl = "AND id NOT IN ($ids)";
        }

        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $tt
                 WHERE area = %s AND capacity >= %d AND min_capacity <= %d
                   AND status = 'active' $excl
                 ORDER BY capacity ASC LIMIT 1",
                $area,
                $party,
                $party
            )
        );

        return $id ? (int) $id : null;
    }

    public function get_stats(): array {
        global $wpdb;
        $today = current_time('Y-m-d');
        return [
            'today'    => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->rtable} WHERE reservation_date = %s AND status != 'cancelled'", $today)),
            'pending'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->rtable} WHERE status = 'pending'"),
            'upcoming' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->rtable} WHERE reservation_date >= %s AND status NOT IN ('cancelled','completed')", $today)),
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function count_free_tables(string $area, array $booked): int {
        global $wpdb;
        $tt   = $wpdb->prefix . 'tb_tables';
        $excl = '';
        if (!empty($booked)) {
            $ids  = implode(',', array_map('intval', $booked));
            $excl = "AND id NOT IN ($ids)";
        }
        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM $tt WHERE area = %s AND status = 'active' $excl", $area)
        );
    }

    private function build_where(array $args): array {
        global $wpdb;
        $where  = ['1=1'];
        $params = [];

        if (!empty($args['status'])) {
            $where[]  = 'r.status = %s';
            $params[] = $args['status'];
        }
        if (!empty($args['date'])) {
            $where[]  = 'r.reservation_date = %s';
            $params[] = $args['date'];
        }
        if (!empty($args['area'])) {
            $where[]  = 'r.seating_area = %s';
            $params[] = $args['area'];
        }
        if (!empty($args['search'])) {
            $like     = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[]  = '(r.customer_name LIKE %s OR r.customer_email LIKE %s OR r.reservation_number LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        return [implode(' AND ', $where), $params];
    }

    private function generate_number(): string {
        return 'RES-' . strtoupper(wp_generate_password(8, false));
    }

    // -------------------------------------------------------------------------
    // Data retention cleanup (called by weekly cron)
    // -------------------------------------------------------------------------

    public static function cleanup_old(): void {
        $days = (int) TB_Database::get_setting('data_retention_days', '0');
        if ($days < 1) return;

        global $wpdb;
        $cutoff  = gmdate('Y-m-d', strtotime("-{$days} days"));
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$wpdb->prefix}tb_reservations`
                 WHERE reservation_date < %s
                   AND status IN ('completed','cancelled','no_show')",
                $cutoff
            )
        );

        if ($deleted > 0) {
            TB_Logger::info("Data retention: removed {$deleted} old reservation(s) (>{$days} days old)", 'system');
        }
    }

}

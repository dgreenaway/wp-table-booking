<?php
defined('ABSPATH') || exit;

// CRUD wrapper for the tb_tables table. Individual operations cover the admin detail
// view; save_layout() handles the bulk canvas save which replaces the whole table set.
class TB_Layout {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'tb_tables';
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    public function get_all(string $area = ''): array {
        global $wpdb;
        if ($area) {
            return (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$this->table} WHERE area = %s AND status = 'active' ORDER BY id ASC",
                    $area
                ),
                ARRAY_A
            );
        }
        return (array) $wpdb->get_results(
            "SELECT * FROM {$this->table} WHERE status = 'active' ORDER BY id ASC",
            ARRAY_A
        );
    }

    public function get(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function create(array $data): int|false {
        global $wpdb;
        $ok = $wpdb->insert($this->table, $this->sanitize($data));
        return $ok ? $wpdb->insert_id : false;
    }

    public function update(int $id, array $data): bool {
        global $wpdb;
        return (bool) $wpdb->update($this->table, $this->sanitize($data), ['id' => $id]);
    }

    public function delete(int $id): bool {
        global $wpdb;
        return (bool) $wpdb->delete($this->table, ['id' => $id], ['%d']);
    }

    // -------------------------------------------------------------------------
    // Bulk save from canvas editor
    // -------------------------------------------------------------------------

    // Upserts every table from the canvas state then deletes any rows whose IDs weren't
    // in the new set. This is how the editor removes tables — not via a separate DELETE call.
    public function save_layout(array $tables): void {
        global $wpdb;

        $kept_ids = [];

        foreach ($tables as $t) {
            $data = $this->sanitize($t);
            $tid  = (int) ($t['id'] ?? 0);

            if ($tid > 0) {
                $wpdb->update($this->table, $data, ['id' => $tid]);
                $kept_ids[] = $tid;
            } else {
                $wpdb->insert($this->table, $data);
                if ($wpdb->insert_id) {
                    $kept_ids[] = $wpdb->insert_id;
                }
            }
        }

        // Remove any tables not present in the new layout
        if (!empty($kept_ids)) {
            $placeholders = implode(',', array_fill(0, count($kept_ids), '%d'));
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$this->table} WHERE id NOT IN ($placeholders)",
                    $kept_ids
                )
            );
        } else {
            $wpdb->query("DELETE FROM {$this->table}");
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    // Shape is validated against an allowlist; everything else is sanitized and clamped
    // to sensible minimums so bad data from the canvas can't corrupt the table record.
    private function sanitize(array $d): array {
        $shape = in_array($d['shape'] ?? '', ['square', 'rectangle', 'circle'], true)
            ? $d['shape']
            : 'square';

        return [
            'table_name'   => sanitize_text_field($d['table_name']   ?? 'Table'),
            'capacity'     => max(1,  (int) ($d['capacity']     ?? 4)),
            'min_capacity' => max(1,  (int) ($d['min_capacity'] ?? 1)),
            'area'         => sanitize_key($d['area']           ?? 'dining'),
            'pos_x'        => max(0,  (int) ($d['pos_x']        ?? 50)),
            'pos_y'        => max(0,  (int) ($d['pos_y']        ?? 50)),
            'width'        => max(40, (int) ($d['width']        ?? 80)),
            'height'       => max(40, (int) ($d['height']       ?? 80)),
            'shape'        => $shape,
            'status'       => 'active',
        ];
    }
}

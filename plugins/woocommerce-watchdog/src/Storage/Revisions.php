<?php

namespace BusinessWatchdog\WooCommerce\Storage;

final class Revisions
{
    public const DELETED_HASH = 'deleted';

    public static function observe(string $aggregateKey, string $snapshotHash, ?string $parentKey, ?array $data): ?int
    {
        global $wpdb;

        $table = Schema::revisionsTable();
        $now = gmdate('Y-m-d H:i:s');
        $affected = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (aggregate_key, parent_key, revision, snapshot_hash, last_data, updated_at) VALUES (%s, %s, 1, %s, %s, %s)"
            . ' ON DUPLICATE KEY UPDATE'
            . ' revision = IF(snapshot_hash = VALUES(snapshot_hash), revision, revision + 1),'
            . ' parent_key = IF(snapshot_hash = VALUES(snapshot_hash), parent_key, VALUES(parent_key)),'
            . ' last_data = IF(snapshot_hash = VALUES(snapshot_hash), last_data, VALUES(last_data)),'
            . ' updated_at = IF(snapshot_hash = VALUES(snapshot_hash), updated_at, VALUES(updated_at)),'
            . ' snapshot_hash = VALUES(snapshot_hash)',
            $aggregateKey,
            $parentKey,
            $snapshotHash,
            $data === null ? null : wp_json_encode($data),
            $now
        ));

        if ($affected === false || (int) $affected === 0) {
            return null;
        }

        return (int) $wpdb->get_var($wpdb->prepare("SELECT revision FROM {$table} WHERE aggregate_key = %s", $aggregateKey));
    }

    public static function find(string $aggregateKey): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::revisionsTable() . ' WHERE aggregate_key = %s', $aggregateKey), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public static function liveChildren(string $parentKey): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT aggregate_key, last_data FROM ' . Schema::revisionsTable() . ' WHERE parent_key = %s AND snapshot_hash <> %s',
            $parentKey,
            self::DELETED_HASH
        ), ARRAY_A);

        $children = [];

        foreach ((array) $rows as $row) {
            $decoded = json_decode((string) $row['last_data'], true);
            $children[(string) $row['aggregate_key']] = is_array($decoded) ? $decoded : null;
        }

        return $children;
    }
}

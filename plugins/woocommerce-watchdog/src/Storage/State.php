<?php

namespace BusinessWatchdog\WooCommerce\Storage;

final class State
{
    public static function get(string $name, $default = null)
    {
        global $wpdb;

        $value = $wpdb->get_var($wpdb->prepare('SELECT value FROM ' . Schema::stateTable() . ' WHERE name = %s', $name));

        if ($value === null) {
            return $default;
        }

        $decoded = json_decode((string) $value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    public static function set(string $name, $value): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . Schema::stateTable() . ' (name, value, updated_at) VALUES (%s, %s, %s)'
            . ' ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            $name,
            wp_json_encode($value),
            gmdate('Y-m-d H:i:s')
        ));
    }

    public static function delete(string $name): void
    {
        global $wpdb;

        $wpdb->delete(Schema::stateTable(), ['name' => $name], ['%s']);
    }

    public static function installId(): string
    {
        $installId = self::get('install_id');

        if (is_string($installId) && $installId !== '') {
            return $installId;
        }

        $installId = wp_generate_uuid4();
        self::set('install_id', $installId);

        return $installId;
    }

    public static function acquireLock(string $name, int $ttlSeconds): bool
    {
        global $wpdb;

        $table = Schema::stateTable();
        $now = time();
        $key = 'lock:' . $name;
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (name, value, updated_at) VALUES (%s, %s, %s)",
            $key,
            (string) ($now + $ttlSeconds),
            gmdate('Y-m-d H:i:s')
        ));

        if ((int) $inserted === 1) {
            return true;
        }

        $takenOver = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET value = %s, updated_at = %s WHERE name = %s AND CAST(value AS UNSIGNED) <= %d",
            (string) ($now + $ttlSeconds),
            gmdate('Y-m-d H:i:s'),
            $key,
            $now
        ));

        return (int) $takenOver === 1;
    }

    public static function releaseLock(string $name): void
    {
        global $wpdb;

        $wpdb->delete(Schema::stateTable(), ['name' => 'lock:' . $name], ['%s']);
    }
}

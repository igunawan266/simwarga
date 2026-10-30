<?php

namespace App\Services;

use Config\Database;

/** Read-only access to non-secret platform settings (system_settings). */
class SettingsService
{
    private static array $cache = [];

    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        $id = "{$group}.{$key}";
        if (! array_key_exists($id, self::$cache)) {
            $row = Database::connect()->table('system_settings')->where('setting_group', $group)->where('setting_key', $key)->get()->getRowArray();
            self::$cache[$id] = $row === null ? null : match ($row['value_type']) {
                'int'   => (int) $row['setting_value'],
                'bool'  => (bool) $row['setting_value'],
                'json'  => json_decode($row['setting_value'], true),
                default => $row['setting_value'],
            };
        }

        return self::$cache[$id] ?? $default;
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}

<?php

declare(strict_types=1);

namespace HostAgent\Stores;

use HostAgent\Services\Config;

/**
 * Generic key -> JSON-value store, the `global_state` table of
 * Config::push_sqlite_path() (see SqliteDb's own docblock for what this
 * is for). Each key is one small, single-blob global concern that used
 * to be its own plain JSON file - not meant for anything with real
 * per-row/per-session structure, those get their own dedicated table
 * (SessionStatusStore, PushQuotaStateStore, etc.) instead.
 */
class GlobalStateStore
{
    private static function db(): \PDO
    {
        return SqliteDb::connect(Config::push_sqlite_path(), SqliteDb::push_schema());
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function read(string $key): ?array
    {
        $stmt = self::db()->prepare('SELECT value_json FROM global_state WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        if ($value === false || $value === null) {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function write(string $key, array $value): void
    {
        $stmt = self::db()->prepare(
            'INSERT INTO global_state (key, value_json, updated_at) VALUES (:key, :value_json, :updated_at)
             ON CONFLICT(key) DO UPDATE SET value_json = excluded.value_json, updated_at = excluded.updated_at'
        );

        $stmt->execute([
            ':key' => $key,
            ':value_json' => json_encode($value),
            ':updated_at' => time(),
        ]);
    }

    public static function delete(string $key): void
    {
        $stmt = self::db()->prepare('DELETE FROM global_state WHERE key = ?');
        $stmt->execute([$key]);
    }
}

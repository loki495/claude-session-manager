<?php

declare(strict_types=1);

namespace HostAgent\Stores;

use HostAgent\Services\Config;

/**
 * Per-session metadata (workdir, spawned_at, claude_session_id) that
 * doesn't live anywhere tmux itself tracks - one row per app-spawned
 * session in the `sidecars` table of Config::sessions_sqlite_path()
 * (tmpfs, wiped on reboot - see that method's own docblock). Only ever
 * set for sessions this app created (see SessionService::create_cc_session()) -
 * a bare/manually-attached session has no sidecar.
 *
 * Backed by plain JSON files (one per session, plus separate .status.json/
 * .pending-tool.json siblings for SessionStatusStore/PendingToolStore)
 * until 2026-08-24 - migrated to SQLite to fix a real read-modify-write
 * race (SessionStatusStore::update_status() specifically, found live
 * 2026-08-23) with a proper atomic UPDATE instead. The migration shipped
 * with a lazy legacy-JSON-file importer for a live, no-downtime cutover;
 * removed once every real session on this machine had migrated (confirmed
 * live 2026-08-24, see CONTRIBUTING.md) - this store is SQLite-only now,
 * no file fallback.
 */
class SidecarStore
{
    private static function db(): \PDO
    {
        return SqliteDb::connect(Config::sessions_sqlite_path(), SqliteDb::sessions_schema());
    }

    /**
     * @return array{workdir:?string, spawned_at:?int, claude_session_id?:?string, spawned_by_csm?:bool}|null
     */
    public static function read_sidecar(string $sessionName): ?array
    {
        $stmt = self::db()->prepare('SELECT workdir, spawned_at, claude_session_id, spawned_by_csm FROM sidecars WHERE session_name = ?');
        $stmt->execute([$sessionName]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row !== false) {
            return [
                'workdir' => $row['workdir'],
                'spawned_at' => $row['spawned_at'] !== null ? (int)$row['spawned_at'] : null,
                'claude_session_id' => $row['claude_session_id'],
                // Genuinely null (key absent), not coerced to false, when
                // never written - callers like session_start.php's hook
                // rely on `$existingSidecar['spawned_by_csm'] ?? <default>`
                // falling through when a sidecar was written without this
                // key at all (found live 2026-08-24: coercing to false here
                // made that ?? see an already-"set" value and never fall
                // through to the hook's own CSM_SESSION_NAME-based default).
                'spawned_by_csm' => $row['spawned_by_csm'] !== null ? (bool)$row['spawned_by_csm'] : null,
            ];
        }

        return null;
    }

    public static function write_sidecar(string $sessionName, array $data): void
    {
        $stmt = self::db()->prepare(
            'INSERT INTO sidecars (session_name, workdir, spawned_at, claude_session_id, spawned_by_csm)
             VALUES (:session_name, :workdir, :spawned_at, :claude_session_id, :spawned_by_csm)
             ON CONFLICT(session_name) DO UPDATE SET
                workdir = excluded.workdir,
                spawned_at = excluded.spawned_at,
                claude_session_id = excluded.claude_session_id,
                spawned_by_csm = excluded.spawned_by_csm'
        );

        $stmt->execute([
            ':session_name' => $sessionName,
            ':workdir' => $data['workdir'] ?? null,
            ':spawned_at' => $data['spawned_at'] ?? null,
            ':claude_session_id' => $data['claude_session_id'] ?? null,
            // NULL, not 0, when the key is genuinely absent - see
            // read_sidecar()'s own comment on why this three-state
            // (true/false/absent) distinction matters to callers.
            ':spawned_by_csm' => array_key_exists('spawned_by_csm', $data) ? (!empty($data['spawned_by_csm']) ? 1 : 0) : null,
        ]);
    }

    public static function delete_sidecar(string $sessionName): void
    {
        $stmt = self::db()->prepare('DELETE FROM sidecars WHERE session_name = ?');
        $stmt->execute([$sessionName]);
    }

    /**
     * A session can die on its own (crash, host reboot, bad cwd) without ever
     * going through kill_cc_session(), leaving its sidecar/status/pending-tool
     * rows behind. Since this runs on every listing anyway, prune anything
     * whose session no longer exists rather than letting them accumulate.
     */
    public static function prune_orphaned_sidecars(array $liveSessionNames): void
    {
        $db = self::db();
        $placeholders = implode(',', array_fill(0, count($liveSessionNames), '?'));

        foreach (['sidecars', 'session_status', 'pending_tools'] as $table) {
            $sql = "DELETE FROM {$table} WHERE session_name NOT IN ({$placeholders})";

            if ($liveSessionNames === []) {
                $sql = "DELETE FROM {$table}";
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($liveSessionNames);
        }
    }
}

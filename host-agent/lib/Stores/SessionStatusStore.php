<?php

declare(strict_types=1);

namespace HostAgent\Stores;

use HostAgent\Services\Config;

/**
 * A session's live mode/working-status/blocked-prompt state, fed by three
 * Claude Code hooks (see host-agent/hooks/user_prompt_submit.php,
 * permission_request.php, stop.php) instead of tmux pane-scraping - one
 * row per session in the `session_status` table of
 * Config::sessions_sqlite_path() (see SidecarStore's own docblock for why
 * this migrated off plain JSON files 2026-08-24, and why the migration's
 * lazy legacy-file importer was later removed once every real session had
 * cut over). Each hook only knows
 * its own event's fields, so update_status() does an atomic UPDATE that
 * only touches the columns it was actually given - the exact
 * read-modify-write race the old read-json-merge-write-json version of
 * this had (found live 2026-08-23: PreToolUse and PermissionRequest can
 * fire close enough together that both read the same "current" content
 * and each write their own merged version, silently losing whichever
 * wrote first) is now structurally impossible - SQLite serializes writers
 * to the same row.
 *
 * SessionService::build_session_entry() reads mode/working-status/
 * blocked-prompt-content EXCLUSIVELY from this store for every tool except
 * AskUserQuestion - these three hooks are mandatory (see the dashboard's
 * health box), not "preferred with a pane-scraping fallback": a session
 * with no status row (hooks not installed, or genuinely hasn't had one
 * fire yet) just reports unknown/idle/no-prompt, nothing is scraped from
 * the pane to fill the gap. Only two prompt shapes still need the live
 * pane, structurally, regardless of hook installation - the trust dialog
 * (fires none of these hooks at all, ever) and AskUserQuestion's CONTENT
 * (a single PermissionRequest fire can't say which tab of a multi-question
 * call is currently showing - see PromptParser::
 * augment_prompt_with_pending_tool()'s own docblock).
 */
class SessionStatusStore
{
    private static function db(): \PDO
    {
        $pdo = SqliteDb::connect(Config::sessions_sqlite_path(), SqliteDb::sessions_schema());
        SqliteDb::add_column_if_missing($pdo, 'session_status', 'last_turn_error', 'TEXT');
        SqliteDb::add_column_if_missing($pdo, 'session_status', 'token_usage_json', 'TEXT');

        return $pdo;
    }

    /**
     * @return array{mode:?string, status:?string, blocked:?array, last_message:?string, last_turn_error:?string, token_usage:?array<string,mixed>, updated_at:?int}|null
     */
    public static function read_status(string $sessionName): ?array
    {
        $stmt = self::db()->prepare('SELECT status, blocked_json, mode, last_message, last_turn_error, token_usage_json, updated_at FROM session_status WHERE session_name = ?');
        $stmt->execute([$sessionName]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row !== false) {
            return [
                'status' => $row['status'],
                'blocked' => $row['blocked_json'] !== null ? json_decode($row['blocked_json'], true) : null,
                'mode' => $row['mode'],
                'last_message' => $row['last_message'],
                'last_turn_error' => $row['last_turn_error'],
                'token_usage' => $row['token_usage_json'] !== null ? json_decode($row['token_usage_json'], true) : null,
                'updated_at' => $row['updated_at'] !== null ? (int)$row['updated_at'] : null,
            ];
        }

        return null;
    }

    /**
     * Merges $fields onto whatever's already on disk (a hook only ever
     * supplies the fields its own event actually carries - e.g. Stop
     * never touches `blocked`... it explicitly clears it, but never
     * touches anything it wasn't told about at all), via a single atomic
     * UPDATE (INSERT ... ON CONFLICT DO UPDATE, using SQLite's own
     * COALESCE against the existing row rather than a separate PHP-side
     * read) and stamps `updated_at` fresh. Never called with a `mode` key
     * when the raw hook payload's permission_mode value didn't map to one
     * of this app's own mode strings (see PermissionMode::
     * normalize_hook_permission_mode()) - omitting the key here, rather
     * than passing null, is what keeps a previously-known mode from being
     * clobbered by an unrecognized one; array_key_exists (not isset) is
     * what lets a deliberate null (e.g. Stop clearing `blocked`) still
     * take effect, since isset() would treat a null value the same as a
     * missing key.
     *
     * @param array<string, mixed> $fields
     */
    public static function update_status(string $sessionName, array $fields): void
    {
        // Ensure a row exists first so the COALESCE-against-self UPDATE
        // below always has something to coalesce against - a plain INSERT
        // OR IGNORE is enough, it only ever adds an all-NULL row when one
        // doesn't already exist yet.
        $db = self::db();
        $db->prepare('INSERT OR IGNORE INTO session_status (session_name) VALUES (?)')->execute([$sessionName]);

        $sets = [];
        $params = [':session_name' => $sessionName];

        foreach (['status', 'mode', 'last_message', 'last_turn_error'] as $column) {
            if (array_key_exists($column, $fields)) {
                $sets[] = "{$column} = :{$column}";
                $params[":{$column}"] = $fields[$column];
            }
        }

        if (array_key_exists('blocked', $fields)) {
            $sets[] = 'blocked_json = :blocked_json';
            $params[':blocked_json'] = $fields['blocked'] !== null ? json_encode($fields['blocked']) : null;
        }

        if (array_key_exists('token_usage', $fields)) {
            $sets[] = 'token_usage_json = :token_usage_json';
            $params[':token_usage_json'] = $fields['token_usage'] !== null ? json_encode($fields['token_usage']) : null;
        }

        $sets[] = 'updated_at = :updated_at';
        $params[':updated_at'] = time();

        $sql = 'UPDATE session_status SET ' . implode(', ', $sets) . ' WHERE session_name = :session_name';
        $db->prepare($sql)->execute($params);
    }

    public static function write_status(string $sessionName, array $data): void
    {
        $stmt = self::db()->prepare(
            'INSERT INTO session_status (session_name, status, blocked_json, mode, last_message, updated_at)
             VALUES (:session_name, :status, :blocked_json, :mode, :last_message, :updated_at)
             ON CONFLICT(session_name) DO UPDATE SET
                status = excluded.status,
                blocked_json = excluded.blocked_json,
                mode = excluded.mode,
                last_message = excluded.last_message,
                updated_at = excluded.updated_at'
        );

        $stmt->execute([
            ':session_name' => $sessionName,
            ':status' => $data['status'] ?? null,
            ':blocked_json' => isset($data['blocked']) ? json_encode($data['blocked']) : null,
            ':mode' => $data['mode'] ?? null,
            ':last_message' => $data['last_message'] ?? null,
            ':updated_at' => $data['updated_at'] ?? time(),
        ]);
    }

    public static function delete_status(string $sessionName): void
    {
        $stmt = self::db()->prepare('DELETE FROM session_status WHERE session_name = ?');
        $stmt->execute([$sessionName]);
    }
}

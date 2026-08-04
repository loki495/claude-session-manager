<?php

declare(strict_types=1);

namespace HostAgent\Stores;

use HostAgent\Services\Config;

/**
 * Per-session metadata (workdir, spawned_at, claude_session_id) that
 * doesn't live anywhere tmux itself tracks - one small JSON file per
 * app-spawned session under Config::sidecar_dir() (tmpfs, wiped on
 * reboot). Only ever set for sessions this app created (see
 * SessionService::create_cc_session()) - a bare/manually-attached
 * session has no sidecar.
 */
class SidecarStore
{
    /**
     * Suffixes (beyond plain "sessionName.json") every other kind of
     * session-keyed sidecar file uses - see PendingToolStore::pending_tool_path().
     * Kept as one list so prune_orphaned_sidecars() only has one place to
     * update when a new sidecar kind is added.
     */
    public const SIDECAR_FILE_SUFFIXES = ['.pending-tool'];

    public static function sidecar_path(string $sessionName): string
    {
        return Config::sidecar_dir() . '/' . $sessionName . '.json';
    }

    /**
     * @return array{workdir:?string, spawned_at:?int}|null
     */
    public static function read_sidecar(string $sessionName): ?array
    {
        $raw = @file_get_contents(self::sidecar_path($sessionName));

        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public static function write_sidecar(string $sessionName, array $data): void
    {
        if (!is_dir(Config::sidecar_dir())) {
            @mkdir(Config::sidecar_dir(), 0700, true);
        }

        @file_put_contents(self::sidecar_path($sessionName), json_encode($data));
    }

    public static function delete_sidecar(string $sessionName): void
    {
        @unlink(self::sidecar_path($sessionName));
    }

    /**
     * A session can die on its own (crash, host reboot, bad cwd) without ever
     * going through kill_cc_session(), leaving its sidecar file(s) behind on
     * tmpfs. Since this runs on every listing anyway, prune anything whose
     * session no longer exists rather than letting them accumulate.
     *
     * Globs every sidecar kind (plain sessionName.json, plus each suffixed
     * kind in SIDECAR_FILE_SUFFIXES) in one pass - the suffix is stripped
     * back off before the liveness check so a live session's own pending-tool
     * file is never mistaken for an orphan just because its filename doesn't
     * equal a session name verbatim.
     */
    public static function prune_orphaned_sidecars(array $liveSessionNames): void
    {
        foreach (glob(Config::sidecar_dir() . '/*.json') ?: [] as $path) {
            $base = basename($path, '.json');
            $name = $base;

            foreach (self::SIDECAR_FILE_SUFFIXES as $suffix) {
                if (str_ends_with($base, $suffix)) {
                    $name = substr($base, 0, -strlen($suffix));
                    break;
                }
            }

            if (!in_array($name, $liveSessionNames, true)) {
                @unlink($path);
            }
        }
    }
}

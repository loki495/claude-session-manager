<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Reads /proc directly to discover and correlate real host processes -
 * find every `claude` process regardless of who started it, map
 * pid->ppid for ancestry checks, and resolve a process's actual start
 * time. Nothing here touches tmux (see TmuxService) - this is pure
 * process-table inspection.
 */
class ProcessInspector
{
    public const CLK_TCK = 100; // USER_HZ has been 100 on Linux/x86_64 since the 2.6 era

    /**
     * @return array{pid:int,ppid:int}[] keyed by pid
     */
    public static function build_ppid_map(): array
    {
        $map = [];

        foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $procDir) {
            $pid = (int)basename($procDir);
            $stat = @file_get_contents("$procDir/stat");

            if ($stat === false) {
                continue;
            }

            $rparen = strrpos($stat, ')');

            if ($rparen === false) {
                continue;
            }

            $fields = preg_split('/\s+/', trim(substr($stat, $rparen + 1))) ?: [];

            // $stat fields are 1-indexed in `man proc`; after splitting off
            // "pid (comm) ", $fields[0] is field 3 (state), $fields[1] is
            // field 4 (ppid), $fields[19] is field 22 (starttime).
            if (isset($fields[1])) {
                $map[$pid] = (int)$fields[1];
            }
        }

        return $map;
    }

    public static function process_start_time(int $pid): ?int
    {
        $stat = @file_get_contents("/proc/$pid/stat");

        if ($stat === false) {
            return null;
        }

        $rparen = strrpos($stat, ')');

        if ($rparen === false) {
            return null;
        }

        $fields = preg_split('/\s+/', trim(substr($stat, $rparen + 1))) ?: [];

        if (!isset($fields[19])) {
            return null;
        }

        $startTicks = (int)$fields[19];
        $uptimeRaw = @file_get_contents('/proc/uptime');

        if ($uptimeRaw === false) {
            return null;
        }

        $uptime = (float)explode(' ', trim($uptimeRaw))[0];
        $bootEpoch = time() - (int)$uptime;

        return $bootEpoch + intdiv($startTicks, self::CLK_TCK);
    }

    public static function is_descendant(int $pid, int $ancestorPid, array $ppidMap, int $maxDepth = 25): bool
    {
        $current = $pid;

        for ($i = 0; $i < $maxDepth; $i++) {
            if ($current === $ancestorPid) {
                return true;
            }

            if (!isset($ppidMap[$current]) || $ppidMap[$current] === 0) {
                return false;
            }

            $current = $ppidMap[$current];
        }

        return false;
    }

    /**
     * Scans /proc for every real `claude` process on the host, regardless of
     * whether it was started by this tool, another tmux session, or by hand
     * in a plain terminal. argv[0] is matched rather than /proc/pid/exe,
     * because claude re-execs into a versioned binary under
     * ~/.local/share/claude/versions/*, so exe changes on every update while
     * the launcher path in argv stays stable.
     *
     * @return array{pid:int, cwd:?string, started_at:?int}[]
     */
    public static function find_claude_processes(): array
    {
        $procs = [];

        foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $procDir) {
            $pid = (int)basename($procDir);
            $cmdlineRaw = @file_get_contents("$procDir/cmdline");

            if ($cmdlineRaw === false || $cmdlineRaw === '') {
                continue;
            }

            $argv = explode("\0", rtrim($cmdlineRaw, "\0"));

            // Match argv[0] specifically, not "appears anywhere in argv": the
            // tmux server process that auto-starts to run `new-session ...
            // /home/andres/.local/bin/claude` retains that whole command line
            // as its own argv, which would otherwise false-positive-match the
            // tmux server itself as a bare claude process.
            if (($argv[0] ?? null) !== Config::claude_bin()) {
                continue;
            }

            $procs[] = [
                'pid' => $pid,
                'cwd' => @readlink("$procDir/cwd") ?: null,
                'started_at' => self::process_start_time($pid),
            ];
        }

        return $procs;
    }

    /**
     * Finds the tmux pane (if any, from an already-fetched
     * TmuxService::all_tmux_panes() map) that $pid runs under, by walking
     * its ancestry same as the cc-* matching in list_all_sessions() does.
     *
     * @param array<int, array{session:string, title:?string}> $allPanes
     * @return array{session:string, title:?string}|null
     */
    public static function find_owning_pane(int $pid, array $allPanes, array $ppidMap): ?array
    {
        foreach ($allPanes as $panePid => $pane) {
            if (self::is_descendant($pid, $panePid, $ppidMap)) {
                return $pane;
            }
        }

        return null;
    }
}

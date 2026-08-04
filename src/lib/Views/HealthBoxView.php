<?php

declare(strict_types=1);

namespace App\Views;

/**
 * Dashboard setup-status box - one place to see whether everything this
 * app needs (hooks, claude-quota, tmux socket dir, VAPID keys, Composer
 * vendor/) is actually installed, instead of discovering each piece is
 * missing separately (see HostAgent\Services\PushHealthService::health_check()
 * for what's actually checked), plus the push-check interval control folded
 * into the same panel.
 */
class HealthBoxView
{
    /**
     * The push-check interval control - a small preset dropdown + Save button
     * so Andres can adjust how often csm-push-check.timer polls without
     * editing/reinstalling the unit file by hand on the host (see
     * HostAgent\Services\PushTimerService::set_push_timer_interval() for the
     * actual mechanics). $currentSeconds is always included as an option even
     * when it isn't one of the presets (a value set by hand outside this
     * control, or a future default change), so the dropdown never silently
     * misrepresents what's actually running.
     *
     * Renders nothing when $currentSeconds is null - the timer unit isn't
     * installed, or the agent is unreachable, and there's nothing to adjust
     * in either case.
     */
    public static function push_timer_interval_control_html(?int $currentSeconds, string $csrfToken): string
    {
        if ($currentSeconds === null) {
            return '';
        }

        $presets = [5, 10, 15, 30, 60, 120];

        if (!in_array($currentSeconds, $presets, true)) {
            $presets[] = $currentSeconds;
            sort($presets);
        }

        $options = '';

        foreach ($presets as $seconds) {
            $selected = $seconds === $currentSeconds ? ' selected' : '';
            $options .= "<option value=\"{$seconds}\"{$selected}>{$seconds}s</option>";
        }

        $csrf = htmlspecialchars($csrfToken, ENT_QUOTES);

        return <<<HTML
        <form method="post" action="/" class="flex items-center gap-2 pt-2 mt-2 border-t border-slate-800">
          <input type="hidden" name="action" value="set_push_timer_interval">
          <input type="hidden" name="csrf_token" value="{$csrf}">
          <label for="push-timer-interval-select" class="text-slate-400">Push check interval</label>
          <select id="push-timer-interval-select" name="seconds" class="rounded border border-slate-700 bg-slate-800 text-slate-300 text-xs px-1.5 py-1 ml-auto">
            {$options}
          </select>
          <button type="submit" class="rounded border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-300 text-xs font-medium px-2 py-1">Save</button>
        </form>
        HTML;
    }

    /**
     * Collapsed <details> rather than an always-open block since most of the
     * time there's nothing to look at - the summary row alone (colored dot +
     * "All systems OK"/"Some setup checks failed") is enough for the common
     * case.
     *
     * Renders nothing when $checks is empty - either the host agent is
     * unreachable (already covered by index.php's own red banner for that) or
     * the health_check call itself failed, neither of which this box can add
     * anything useful to.
     *
     * $pushTimerIntervalSeconds/$csrfToken: folds the push-check interval
     * control (see push_timer_interval_control_html()) into this same panel,
     * right below the "Push delivery" check it's directly related to, rather
     * than a separate floating control elsewhere on the page.
     *
     * @param array<int, array{key?:string, label?:string, ok?:bool, detail?:?string}> $checks
     */
    public static function health_box_html(array $checks, ?int $pushTimerIntervalSeconds = null, string $csrfToken = ''): string
    {
        if ($checks === []) {
            return '';
        }

        $allOk = true;

        foreach ($checks as $check) {
            if (!($check['ok'] ?? false)) {
                $allOk = false;
                break;
            }
        }

        $dotColor = $allOk ? 'bg-emerald-400' : 'bg-amber-400';
        $summaryColor = $allOk ? 'text-emerald-400' : 'text-amber-400';
        $summaryText = $allOk ? 'All systems OK' : 'Some setup checks failed';

        $rows = '';

        foreach ($checks as $check) {
            $ok = (bool)($check['ok'] ?? false);
            $label = htmlspecialchars((string)($check['label'] ?? ''), ENT_QUOTES);
            $detail = $check['detail'] ?? null;
            $icon = $ok
                ? '<span class="text-emerald-400">&#10003;</span>'
                : '<span class="text-amber-400">&#10007;</span>';
            $detailHtml = ($detail !== null && $detail !== '')
                ? '<div class="text-[11px] text-slate-500 font-mono break-all mt-0.5">' . htmlspecialchars((string)$detail, ENT_QUOTES) . '</div>'
                : '';

            $rows .= '<div class="flex items-start gap-2 py-1.5 border-t border-slate-800 first:border-t-0">'
                . '<span class="mt-0.5">' . $icon . '</span>'
                . '<div class="min-w-0 flex-1"><div class="text-slate-300">' . $label . '</div>' . $detailHtml . '</div>'
                . '</div>';
        }

        $intervalControl = self::push_timer_interval_control_html($pushTimerIntervalSeconds, $csrfToken);

        return <<<HTML
        <details class="mb-4 rounded-lg border border-slate-800 bg-slate-900/50 text-sm">
          <summary class="px-4 py-3 cursor-pointer list-none flex items-center gap-2 [&::-webkit-details-marker]:hidden">
            <span class="w-2 h-2 rounded-full {$dotColor} shrink-0"></span>
            <span class="{$summaryColor} font-medium">{$summaryText}</span>
            <span class="text-slate-500 ml-auto text-xs">Setup health</span>
          </summary>
          <div class="px-4 pb-3">{$rows}{$intervalControl}</div>
        </details>
        HTML;
    }
}

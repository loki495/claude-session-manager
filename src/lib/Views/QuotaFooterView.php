<?php

declare(strict_types=1);

namespace App\Views;

/**
 * The sticky quota footer's markup + its own self-contained fetch-and-poll
 * script - shared between index.php (its own standalone sticky bar) and
 * session.php (folded into the same sticky bar as the message compose
 * box, rather than stacking two separate fixed bars on mobile).
 */
class QuotaFooterView extends View
{
    /**
     * A caller echoes this once; it renders itself and keeps itself
     * updated. Sized for mobile (small text, not the dashboard's original
     * text-xl) and user-collapsible (persisted in localStorage, shared
     * across both pages since it's the same feature either place).
     *
     * $extraHtml renders on the same row as the "Quota" collapse toggle
     * (outside #quota-info, which the fetch/poll script above fully
     * replaces on every refresh - anything placed inside it would get wiped
     * out) - session.php uses this slot for its mode-toggle button so the
     * two controls share one line instead of stacking.
     */
    public static function quota_footer_html(string $extraHtml = ''): string
    {
        return self::render('quota-footer/footer', [
            'extraHtml' => $extraHtml,
        ]);
    }
}

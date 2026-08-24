<?php

declare(strict_types=1);

namespace App\Views;

/**
 * The full-page templates (index.php's dashboard, session.php's live detail
 * view, archived-session.php's read-only dormant-session view) - a thin
 * wrapper so each controller stays a plain data-gathering class with zero
 * HTML of their own, same as every other App\Views\* class, rather than a
 * one-off bypass of the View::render() convention.
 */
class PageView extends View
{
    public static function render_session_page(array $data): string
    {
        return self::render('pages/session', $data);
    }

    public static function render_index_page(array $data): string
    {
        return self::render('pages/index', $data);
    }

    public static function render_archived_session_page(array $data): string
    {
        return self::render('pages/archived-session', $data);
    }
}

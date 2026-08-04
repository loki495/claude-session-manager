<?php

declare(strict_types=1);

namespace App\Views;

/**
 * The two full-page templates (index.php's dashboard, session.php's detail
 * view) - a thin wrapper so session.php/index.php stay plain data-gathering
 * controllers with zero HTML of their own, same as every other App\Views\*
 * class, rather than a one-off bypass of the View::render() convention.
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
}

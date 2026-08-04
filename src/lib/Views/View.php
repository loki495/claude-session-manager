<?php

declare(strict_types=1);

namespace App\Views;

use League\Plates\Engine;

/**
 * Every App\Views\* class extends this - it owns the shared Plates engine
 * (one instance, lazily built) and a render() helper so concrete View
 * classes never touch League\Plates directly. Template root is src/partials/,
 * the same directory session.php/index.php's own page partials already live
 * in - component templates get their own subdirectory per feature
 * (partials/transcript/, partials/blocked-prompt/, ...) rather than a
 * second templates root.
 */
abstract class View
{
    private static ?Engine $engine = null;

    protected static function render(string $template, array $data = []): string
    {
        self::$engine ??= new Engine(__DIR__ . '/../../partials');

        return self::$engine->render($template, $data);
    }
}

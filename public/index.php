<?php

declare(strict_types=1);

/**
 * The one true front controller - every request (Apache/nginx via a
 * rewrite-everything-here rule, or php -S given this file's path as its
 * router-script argument) reaches this file first.
 *
 * Static assets (/js/*.js, /sw.js) are deliberately excluded below and
 * served directly by whatever's in front instead - Apache/nginx's own
 * real-file handling (see public/.htaccess), or php -S's own fallback
 * when this file returns false. Checked against the query-string-stripped
 * path so Assets::versioned_url()'s ?v=<mtime> cache-busting suffix never
 * defeats the match. No output happens before that `return false` -
 * anything echoed here would leak into whatever gets served next for
 * this same request (only relevant when this file is used as a php -S
 * router-script argument; Apache/nginx never reach this file at all for
 * a real static asset, per public/.htaccess / an equivalent nginx
 * try_files rule).
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

if ($path === '/sw.js' || preg_match('#^/js/[\w-]+\.js$#', $path) === 1) {
    return false;
}

require __DIR__ . '/../vendor/autoload.php';

$router = require __DIR__ . '/../src/routes.php';
$handler = $router->match($_SERVER['REQUEST_METHOD'], $path);

if ($handler !== null) {
    [$controllerClass, $methodName] = $handler;
    (new $controllerClass())->$methodName();

    return;
}

/**
 * MIGRATION MODE ONLY: this endpoint hasn't been ported to a Controller
 * yet. Its old flat file still lives under src/, keyed by the exact same
 * URL path it always used (e.g. /quota.php -> src/quota.php, / ->
 * src/index.php). public/ is now the docroot, not src/, so a plain
 * `return false` (php -S's normal "serve/execute the real file at this
 * path" fallback) would no longer reach it - explicitly require it
 * instead, so it runs exactly as it did before this router existed.
 * realpath() + the containment check below is defense in depth against a
 * request path smuggling a ".." segment out of src/ - matches the same
 * boundary-check pattern browse.php's own folder browser already uses.
 * Delete this whole branch in the final cleanup phase, once every route
 * is registered above and the old flat files are gone - $handler will
 * then always be non-null or genuinely a 404.
 */
$legacyUrlPath = $path === '/' ? '/index.php' : $path;
$srcDir = realpath(__DIR__ . '/../src');
$legacyFile = $srcDir !== false ? realpath($srcDir . $legacyUrlPath) : false;

if (
    $srcDir !== false
    && $legacyFile !== false
    && str_starts_with($legacyFile, $srcDir . DIRECTORY_SEPARATOR)
    && is_file($legacyFile)
    && substr($legacyFile, -4) === '.php'
) {
    require $legacyFile;

    return;
}

http_response_code(404);
echo 'Not found';

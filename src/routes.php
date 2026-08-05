<?php

declare(strict_types=1);

use App\Controllers\BrowseController;
use App\Controllers\DashboardController;
use App\Controllers\QuotaController;
use App\Controllers\SessionController;
use App\Controllers\UploadController;
use App\Http\Router;

$router = new Router();

// Routes are added incrementally, one migration phase at a time, as each
// old flat file under src/ is ported to a Controller. Until an endpoint
// is registered here, public/index.php falls through to its untouched
// old flat file.

$router->get('/quota.php', [QuotaController::class, 'show']);

$router->get('/browse.php', [BrowseController::class, 'browse']);

$router->get('/sessions_fragment.php', [DashboardController::class, 'fragment']);
$router->get('/sessions_list.php', [DashboardController::class, 'list']);

$router->get('/session_detail.php', [SessionController::class, 'detail']);
$router->get('/session_history.php', [SessionController::class, 'history']);

$router->get('/uploaded_files.php', [UploadController::class, 'list']);

return $router;

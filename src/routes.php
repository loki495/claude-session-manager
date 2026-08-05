<?php

declare(strict_types=1);

use App\Controllers\BrowseController;
use App\Controllers\DashboardController;
use App\Controllers\PushController;
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

// The following are POST-only in behavior (require_post_json() 405s a
// GET internally) - both methods are registered to the same handler so
// an unmigrated-looking 404 never replaces the historical 405 once the
// old flat file is gone.
$router->get('/upload_file.php', [UploadController::class, 'upload']);
$router->post('/upload_file.php', [UploadController::class, 'upload']);
$router->get('/delete_uploaded_file.php', [UploadController::class, 'deleteOne']);
$router->post('/delete_uploaded_file.php', [UploadController::class, 'deleteOne']);
$router->get('/delete_all_uploaded_files.php', [UploadController::class, 'deleteAll']);
$router->post('/delete_all_uploaded_files.php', [UploadController::class, 'deleteAll']);

$router->get('/push_subscribe.php', [PushController::class, 'subscribe']);
$router->post('/push_subscribe.php', [PushController::class, 'subscribe']);
$router->get('/push_unsubscribe.php', [PushController::class, 'unsubscribe']);
$router->post('/push_unsubscribe.php', [PushController::class, 'unsubscribe']);

return $router;

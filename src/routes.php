<?php

declare(strict_types=1);

use App\Http\Router;

$router = new Router();

// Routes are added incrementally, one migration phase at a time, as each
// old flat file under src/ is ported to a Controller. Until an endpoint
// is registered here, public/index.php falls through to its untouched
// old flat file.

return $router;

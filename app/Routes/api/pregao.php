<?php

declare(strict_types=1);

/**
 * Rotas API do Pregão (também registradas em web.php para descoberta).
 *
 * @var \App\Router $router
 */

use App\Controllers\PregaoController;

$router->get('api/pregao/snapshot', PregaoController::class, 'snapshot');
$router->get('api/pregao/events', PregaoController::class, 'events');
$router->get('api/pregao/stream', PregaoController::class, 'stream');
$router->get('api/pregao/ticket', PregaoController::class, 'ticket');
$router->post('api/pregao/qa/run', PregaoController::class, 'qaRun');
$router->get('qa/live/{runId}', PregaoController::class, 'qaLive');
$router->get('qa/frame/{runId}', PregaoController::class, 'qaFrame');
$router->get('api/pregao/watchlist', PregaoController::class, 'watchlistList');
$router->post('api/pregao/watchlist', PregaoController::class, 'watchlistAdd');
$router->post('api/pregao/watchlist/seed', PregaoController::class, 'watchlistSeed');
$router->delete('api/pregao/watchlist/{mlbId}', PregaoController::class, 'watchlistRemove');
$router->post('api/pregao/watchlist/collect', PregaoController::class, 'watchlistCollect');
$router->post('api/pregao/listing-apply/simulate', PregaoController::class, 'listingApplySimulate');
$router->post('api/pregao/listing-apply/apply', PregaoController::class, 'listingApplyApply');

use App\Controllers\SentinelaController;
$router->get('api/sentinela/snapshot', SentinelaController::class, 'snapshot');

use App\Controllers\CogsController;
$router->get('api/cogs/audit', CogsController::class, 'audit');
$router->put('api/cogs/{mlbId}', CogsController::class, 'upsert');
$router->post('api/cogs/import', CogsController::class, 'importCsv');

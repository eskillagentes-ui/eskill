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

use App\Controllers\SentinelaController;
$router->get('api/sentinela/snapshot', SentinelaController::class, 'snapshot');

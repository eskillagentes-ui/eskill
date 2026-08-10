<?php

declare(strict_types=1);

/**
 * Rotas do coletor local de rank (T1b) — chave dedicada, sem token ML.
 *
 * @var \App\Router $router
 */

use App\Controllers\RankCollectorController;

$router->get('api/rank/assignments', RankCollectorController::class, 'assignments');
$router->post('api/rank/ingest', RankCollectorController::class, 'ingest');

<?php

declare(strict_types=1);

/**
 * Endpoint auxiliar (Routes root-owned):
 *   GET  /api-awa-bpp-status.php
 *
 * Retorna status BPP completo: membership API + direitos cadastrados.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../autoload.php';

use App\Services\AwaBrandProtectionService;

header('Content-Type: application/json; charset=utf-8');

try {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->safeLoad();

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Não autenticado.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $accountId = (int) ($_SESSION['active_ml_account_id'] ?? $_GET['account_id'] ?? 1335);
    if ($accountId <= 0) {
        $accountId = 1335;
    }

    $data = (new AwaBrandProtectionService($accountId))->getStatus();

    echo json_encode([
        'success' => true,
        'data' => $data,
        'account_id' => $accountId,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

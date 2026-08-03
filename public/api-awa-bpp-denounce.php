<?php

declare(strict_types=1);

/**
 * Endpoint auxiliar (Routes root-owned):
 *   POST /api-awa-bpp-denounce.php
 *
 * Body JSON:
 *   { "item_id":"MLB...", "report_reason_id":"PPPI2", "comment":"...", "dry_run": true, "confirm": false }
 *
 * Por padrão dry_run=true. Para enviar denúncia real: dry_run=false e confirm=true.
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
        echo json_encode(['success' => false, 'error' => 'Não autenticado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $raw = file_get_contents('php://input');
    $body = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($body)) {
        $body = [];
    }

    $accountId = (int) ($_SESSION['active_ml_account_id'] ?? $body['account_id'] ?? 1335);
    if ($accountId <= 0) {
        $accountId = 1335;
    }

    $itemId = (string) ($body['item_id'] ?? '');
    $reason = (string) ($body['report_reason_id'] ?? 'PPPI2');
    $comment = (string) ($body['comment'] ?? '');
    $dryRun = array_key_exists('dry_run', $body)
        ? filter_var($body['dry_run'], FILTER_VALIDATE_BOOLEAN)
        : true;
    $confirm = filter_var($body['confirm'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $sellerRegistryId = isset($body['seller_registry_id']) ? (int) $body['seller_registry_id'] : null;

    if (!$dryRun && !$confirm) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'error' => 'Para denúncia real envie dry_run=false e confirm=true.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $bpp = new AwaBrandProtectionService($accountId);
    $result = $bpp->denounceItem(
        $itemId,
        $reason,
        $comment,
        $dryRun,
        $sellerRegistryId > 0 ? $sellerRegistryId : null,
        'user:' . (string) $_SESSION['user_id']
    );

    echo json_encode([
        'success' => (bool) ($result['success'] ?? false),
        'data' => $result,
        'account_id' => $accountId,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

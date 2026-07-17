#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * CLI para renovar tokens do Mercado Livre
 * 
 * Uso:
 *   php scripts/refresh_ml_tokens.php           # Renova todos os tokens prestes a expirar
 *   php scripts/refresh_ml_tokens.php --all     # Força renovação de todas as contas
 *   php scripts/refresh_ml_tokens.php --account=1  # Renova apenas a conta específica
 * 
 * Cron recomendado (a cada hora):
 *   0 * * * * cd /home/eskill/htdocs/eskill.com.br && php scripts/refresh_ml_tokens.php >> storage/logs/token_refresh.log 2>&1
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

use App\Services\MercadoLivreOrchestratorService;

echo "===========================================\n";
echo "   🔄 ML Token Refresh - " . date('Y-m-d H:i:s') . "\n";
echo "===========================================\n\n";

$orchestrator = new MercadoLivreOrchestratorService(dirname(__DIR__));

// Parse argumentos
$options = getopt('', ['all', 'account::', 'help']);

if (isset($options['help'])) {
    echo "Uso:\n";
    echo "  php scripts/refresh_ml_tokens.php           # Renova tokens prestes a expirar\n";
    echo "  php scripts/refresh_ml_tokens.php --all     # Força renovação de todas as contas\n";
    echo "  php scripts/refresh_ml_tokens.php --account=1  # Renova conta específica\n";
    exit(0);
}

if (isset($options['account'])) {
    $accountId = (int)$options['account'];
    echo "Renovando token da conta #{$accountId}...\n";

    $response = $orchestrator->runTokenRefresh(false, $accountId, true);
    $success = (bool)($response['success'] ?? false);
    $result = is_array($response['result'] ?? null) ? $response['result'] : [];

    if (!empty($response['skipped'])) {
        echo "⏭️ Execução ignorada: " . ($response['reason'] ?? 'lock_busy') . "\n";
        exit(0);
    }

    if ($success) {
        echo "✅ Token renovado com sucesso!\n";
        if (!empty($result['message'])) {
            echo "Mensagem: {$result['message']}\n";
        }
        exit(0);
    } else {
        echo "❌ Falha ao renovar token. A conta pode precisar de reconexão manual.\n";
        if (!empty($result['message'])) {
            echo "Mensagem: {$result['message']}\n";
        }
        exit(1);
    }
}

// Executar job completo
$forceAll = isset($options['all']);
if ($forceAll) {
    echo "🔄 Modo: Forçar renovação de TODAS as contas ativas\n\n";
} else {
    echo "🔄 Modo: Renovar apenas tokens prestes a expirar (< 2h)\n\n";
}
$response = $orchestrator->runTokenRefresh($forceAll, null, true);

if (!empty($response['skipped'])) {
    echo "⏭️ Execução ignorada: " . ($response['reason'] ?? 'lock_busy') . "\n";
    exit(0);
}

$results = is_array($response['result'] ?? null) ? $response['result'] : [];

echo "Contas verificadas: {$results['accounts_checked']}\n";
echo "✅ Tokens renovados: {$results['tokens_refreshed']}\n";
echo "❌ Falhas: {$results['tokens_failed']}\n";
echo "⏭️ Já expirados (requer reconexão): " . ($results['tokens_skipped'] ?? $results['already_expired'] ?? 0) . "\n\n";

if (!empty($results['details'])) {
    echo "Detalhes:\n";
    foreach ($results['details'] as $detail) {
        $icon = match($detail['result'] ?? 'unknown') {
            'success' => '✅',
            'failed' => '❌',
            'skipped' => '⏭️',
            'error' => '💥',
            default => '❓',
        };
        
        echo "  {$icon} {$detail['nickname']} (#{$detail['account_id']}): {$detail['result']}";
        if (!empty($detail['reason'])) {
            echo " - {$detail['reason']}";
        }
        echo "\n";
    }
}

echo "\n===========================================\n";
echo "Finalizado em: " . ($results['finished_at'] ?? date('Y-m-d H:i:s')) . "\n";

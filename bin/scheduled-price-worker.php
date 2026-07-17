#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Scheduled Price Worker
 *
 * Processa agendamentos de preços e campanhas
 *
 * Usage:
 *   php bin/scheduled-price-worker.php [--once] [--account=ID] [--verbose]
 *
 * Options:
 *   --once      Executa uma vez e sai
 *   --account   Processa apenas uma conta específica
 *   --verbose   Mostra logs detalhados
 */

require_once __DIR__ . '/../autoload.php';

\App\Helpers\MlWriteAutomation::exitIfDisabledForCli(basename(__FILE__));

use App\Database;
use App\Services\ScheduledPriceService;
use App\Services\PriceNotificationService;

// Parse arguments
$options = getopt('', ['once', 'account:', 'verbose', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Scheduled Price Worker - Processa agendamentos de preços

Usage:
  php bin/scheduled-price-worker.php [options]

Options:
  --once       Executa uma vez e sai
  --account=ID Processa apenas uma conta específica
  --verbose    Mostra logs detalhados
  --help       Mostra esta ajuda

HELP;
    exit(0);
}

$runOnce = isset($options['once']);
$specificAccount = $options['account'] ?? null;
$verbose = isset($options['verbose']);

// Logger
function logMsg(string $message): void
{
    echo "[" . date('Y-m-d H:i:s') . "] {$message}\n";
}

logMsg("🚀 Scheduled Price Worker iniciado");
logMsg("Mode: " . ($runOnce ? "Single run" : "Continuous"));

$db = Database::getInstance();

// Loop principal
do {
    try {
        // Obter contas ativas com agendamentos pendentes
        $accountQuery = "";
        $params = [];

        if ($specificAccount) {
            $accountQuery = "AND a.id = :account_id";
            $params['account_id'] = $specificAccount;
        }

        $stmt = $db->prepare("
            SELECT DISTINCT a.id, a.nickname AS nome
            FROM ml_accounts a
            INNER JOIN pricing_schedules ps ON ps.account_id = a.id
            WHERE a.status = 'active'
            AND ps.status = 'pending'
            AND ps.scheduled_at <= NOW()
            {$accountQuery}
        ");
        $stmt->execute($params);
        $accountsWithSchedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Também verificar rollbacks pendentes
        $stmt = $db->prepare("
            SELECT DISTINCT a.id, a.nickname AS nome
            FROM ml_accounts a
            INNER JOIN pricing_schedules ps ON ps.account_id = a.id
            WHERE a.status = 'active'
            AND ps.status = 'executed'
            AND ps.rollback_at <= NOW()
            AND ps.rollback_at IS NOT NULL
            {$accountQuery}
        ");
        $stmt->execute($params);
        $accountsWithRollbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Merge unique accounts
        $allAccounts = [];
        foreach ($accountsWithSchedules as $acc) {
            $allAccounts[$acc['id']] = $acc;
        }
        foreach ($accountsWithRollbacks as $acc) {
            $allAccounts[$acc['id']] = $acc;
        }

        if (empty($allAccounts)) {
            if ($verbose) {
                logMsg("📭 Nenhum agendamento pendente");
            }
        } else {
            logMsg("📋 Processando " . count($allAccounts) . " conta(s) com agendamentos");
        }

        foreach ($allAccounts as $account) {
            $accountId = $account['id'];
            $accountName = $account['nome'] ?? "Account #{$accountId}";

            logMsg("📊 Conta: {$accountName}");

            try {
                $service = new ScheduledPriceService($accountId);

                // Processar agendamentos pendentes
                logMsg("  ⏰ Processando agendamentos pendentes...");
                $scheduleResult = $service->processPendingSchedules();

                if ($scheduleResult['success']) {
                    $executed = $scheduleResult['executed'] ?? 0;
                    $failed = $scheduleResult['failed'] ?? 0;

                    if ($executed > 0 || $failed > 0) {
                        logMsg("  ✅ Agendamentos: {$executed} executados, {$failed} falharam");

                        // Notificar
                        try {
                            $notificationService = new PriceNotificationService($accountId);
                            $notificationService->notify(
                                PriceNotificationService::EVENT_SCHEDULE_EXECUTED,
                                [
                                    'executed_count' => $executed,
                                    'failed_count' => $failed,
                                    'timestamp' => date('Y-m-d H:i:s')
                                ],
                                $failed > 0
                                    ? PriceNotificationService::SEVERITY_WARNING
                                    : PriceNotificationService::SEVERITY_INFO
                            );
                        } catch (\Throwable $e) {
                            // Notificação não é crítica
                        }
                    }
                }

                // Processar rollbacks
                logMsg("  🔄 Processando rollbacks...");
                $rollbackResult = $service->processRollbacks();

                if ($rollbackResult['success']) {
                    $rolledBack = $rollbackResult['rolled_back'] ?? 0;
                    $rollbackFailed = $rollbackResult['failed'] ?? 0;

                    if ($rolledBack > 0 || $rollbackFailed > 0) {
                        logMsg("  ✅ Rollbacks: {$rolledBack} revertidos, {$rollbackFailed} falharam");
                    }
                }
            } catch (\Throwable $e) {
                logMsg("  ❌ Erro na conta {$accountId}: " . $e->getMessage());
            }
        }

        // Processar campanhas que precisam iniciar
        processStartingCampaigns($db, $specificAccount, $verbose);

        // Processar campanhas que precisam terminar
        processEndingCampaigns($db, $specificAccount, $verbose);

        if (!$runOnce) {
            // Aguardar antes do próximo ciclo (1 minuto)
            $sleepTime = 60;
            if ($verbose) {
                logMsg("💤 Aguardando {$sleepTime}s...");
            }
            sleep($sleepTime);
        }
    } catch (\Throwable $e) {
        logMsg("❌ Erro crítico: " . $e->getMessage());

        if (!$runOnce) {
            sleep(30); // Aguardar 30s em caso de erro
        }
    }
} while (!$runOnce);

logMsg("🏁 Scheduled Price Worker finalizado");

/**
 * Processa campanhas que devem iniciar
 */
function processStartingCampaigns(PDO $db, ?int $specificAccount, bool $verbose): void
{
    $accountQuery = "";
    $params = [];

    if ($specificAccount) {
        $accountQuery = "AND account_id = :account_id";
        $params['account_id'] = $specificAccount;
    }

    $stmt = $db->prepare("
        SELECT id, account_id, name
        FROM pricing_campaigns
        WHERE status = 'scheduled'
        AND starts_at <= NOW()
        {$accountQuery}
    ");
    $stmt->execute($params);
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($campaigns as $campaign) {
        logMsg("  🚀 Iniciando campanha: {$campaign['name']}");

        try {
            // Atualizar status para ativo
            $updateStmt = $db->prepare("
                UPDATE pricing_campaigns
                SET status = 'active', updated_at = NOW()
                WHERE id = :id
            ");
            $updateStmt->execute(['id' => $campaign['id']]);

            logMsg("  ✅ Campanha iniciada com sucesso");
        } catch (\Throwable $e) {
            logMsg("  ❌ Erro ao iniciar campanha: " . $e->getMessage());
        }
    }
}

/**
 * Processa campanhas que devem terminar
 */
function processEndingCampaigns(PDO $db, ?int $specificAccount, bool $verbose): void
{
    $accountQuery = "";
    $params = [];

    if ($specificAccount) {
        $accountQuery = "AND account_id = :account_id";
        $params['account_id'] = $specificAccount;
    }

    $stmt = $db->prepare("
        SELECT id, account_id, name, rollback_enabled
        FROM pricing_campaigns
        WHERE status = 'active'
        AND ends_at <= NOW()
        {$accountQuery}
    ");
    $stmt->execute($params);
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($campaigns as $campaign) {
        logMsg("  🏁 Finalizando campanha: {$campaign['name']}");

        try {
            // Atualizar status para completo
            $updateStmt = $db->prepare("
                UPDATE pricing_campaigns
                SET status = 'completed', updated_at = NOW()
                WHERE id = :id
            ");
            $updateStmt->execute(['id' => $campaign['id']]);

            // Se rollback habilitado, os schedules individuais cuidarão disso
            if ($campaign['rollback_enabled']) {
                logMsg("  ℹ️  Rollback de preços será processado pelos agendamentos individuais");
            }

            logMsg("  ✅ Campanha finalizada");
        } catch (\Throwable $e) {
            logMsg("  ❌ Erro ao finalizar campanha: " . $e->getMessage());
        }
    }
}

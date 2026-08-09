#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Database;
use App\Services\AccountHealthService;
use App\Services\BulkSEOService;
use App\Services\ItemSyncService;

$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoloadPath)) {
    fwrite(STDERR, "ERRO: dependências PHP não instaladas (vendor/autoload.php ausente).\n");
    fwrite(STDERR, "Rode: composer install\n");
    exit(1);
}

require $autoloadPath;

// Garantir que variáveis de ambiente do projeto sejam carregadas (prioriza .env.testing)
try {
    if (class_exists(\Dotenv\Dotenv::class)) {
        $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/..', ['.env.testing', '.env']);
        $dotenv->safeLoad();
    }
} catch (Throwable $e) {
    // CLI deve continuar mesmo se .env não estiver disponível.
}

/**
 * ML Auto Improve (MVP)
 *
 * Pipeline operacional (ML-only):
 * - Seleciona até 10 contas ativas do ml_accounts
 * - Sincroniza itens (ItemSyncService)
 * - Executa Raio-X (AccountHealthService)
 * - Pega piores itens (seo_quality.details.worst_items)
 * - Faz dry-run de SEO em lote (BulkSEOService)
 * - Aplica automaticamente apenas risco none/low (BulkSEOService::applyBatch)
 */

main($argv);

function main(array $argv): void
{
    $opts = parseArgs($argv);

    $limitAccounts = (int)($opts['limit-accounts'] ?? 10);
    if ($limitAccounts <= 0) {
        $limitAccounts = 10;
    }
    if ($limitAccounts > 10) {
        $limitAccounts = 10;
    }

    $maxItemsPerAccount = (int)($opts['max-items-per-account'] ?? 20);
    if ($maxItemsPerAccount <= 0) {
        $maxItemsPerAccount = 20;
    }
    if ($maxItemsPerAccount > 50) {
        $maxItemsPerAccount = 50;
    }

    $dryRun = array_key_exists('dry-run', $opts);
    $yes = array_key_exists('yes', $opts);
    $maxRisk = (string)($opts['max-risk'] ?? 'low');
    if (!in_array($maxRisk, ['none', 'low', 'medium', 'high'], true)) {
        $maxRisk = 'low';
    }

    $accountIds = [];
    if (!empty($opts['account-ids'])) {
        $accountIds = array_values(array_filter(array_map('intval', explode(',', (string)$opts['account-ids']))));
    }

    if (array_key_exists('preflight', $opts)) {
        runPreflight($accountIds, $limitAccounts);
        return;
    }

    echo "ML Auto Improve (MVP)\n";
    echo "===================\n";
    echo "- limit-accounts: {$limitAccounts}\n";
    echo "- max-items-per-account: {$maxItemsPerAccount}\n";
    echo "- max-risk: {$maxRisk}\n";
    echo "- mode: " . ($dryRun ? 'DRY-RUN' : 'APPLY') . "\n\n";

    $db = Database::getInstance();

    $accounts = loadAccounts($db, $accountIds, $limitAccounts);
    if (empty($accounts)) {
        echo "Nenhuma conta ativa encontrada em ml_accounts.\n";
        exit(2);
    }

    $overall = [
        'accounts_total' => count($accounts),
        'accounts_ok' => 0,
        'accounts_failed' => 0,
        'items_considered' => 0,
        'items_approved' => 0,
        'items_applied' => 0,
        'titles_applied' => 0,
        'descriptions_applied' => 0,
    ];

    foreach ($accounts as $account) {
        $accountId = (int)$account['id'];
        $userId = (int)$account['user_id'];
        $nickname = (string)($account['nickname'] ?? '');

        echo "\n---\n";
        echo "Conta #{$accountId}" . ($nickname !== '' ? " ({$nickname})" : '') . "\n";

        try {
            $syncService = new ItemSyncService();
            $syncStats = $syncService->syncForAccount($accountId);

            echo "Sync OK: " . json_encode([
                'total_found' => $syncStats['total_found'] ?? null,
                'total_synced' => $syncStats['total_synced'] ?? null,
                'batches' => $syncStats['batches'] ?? null,
            ], JSON_UNESCAPED_UNICODE) . "\n";

            $healthService = new AccountHealthService($accountId);
            $healthService->clearCache();
            $diagnostic = $healthService->getFullDiagnostic();

            $seoDetails = $diagnostic['pillars']['seo_quality']['details'] ?? [];
            $worstItems = $seoDetails['worst_items'] ?? [];

            $candidateItemIds = pickCandidateItemIds($worstItems, $maxItemsPerAccount);
            $overall['items_considered'] += count($candidateItemIds);

            if (empty($candidateItemIds)) {
                echo "Nenhum item elegível (worst_items vazio ou todos em catálogo).\n";
                $overall['accounts_ok']++;
                continue;
            }

            echo "Itens candidatos: " . count($candidateItemIds) . "\n";

            $bulk = new BulkSEOService($accountId);
            $dry = $bulk->dryRunBatch($candidateItemIds, [
                'optimize_title' => true,
                'optimize_description' => true,
            ]);

            if (!($dry['success'] ?? false)) {
                throw new RuntimeException('Dry-run falhou: ' . (string)($dry['error'] ?? 'erro desconhecido'));
            }

            $approved = buildApprovedItemsFromDryRun($dry, $maxRisk);
            $overall['items_approved'] += count($approved);

            echo "Aprovados para " . ($dryRun ? 'dry-run' : 'aplicar') . ": " . count($approved) . "\n";

            if (!empty($approved)) {
                echo "Amostra de itens aprovados:\n";
                printApprovedItems($approved, 20);
            }

            if ($dryRun || empty($approved)) {
                $overall['accounts_ok']++;
                continue;
            }

            ensureApplyConfirmed($yes, $approved, $maxRisk);

            $apply = $bulk->applyBatch($approved, $userId, [
                'changed_by' => 'automation',
                'reason' => 'ml-auto-improve',
                'source' => 'bin/ml-auto-improve.php',
                'max_risk' => $maxRisk,
            ]);

            if (!($apply['success'] ?? false)) {
                throw new RuntimeException('Apply falhou: ' . (string)($apply['error'] ?? 'erro desconhecido'));
            }

            $stats = $apply['stats'] ?? [];
            $overall['items_applied'] += (int)($stats['total'] ?? 0);
            $overall['titles_applied'] += (int)($stats['titles_applied'] ?? 0);
            $overall['descriptions_applied'] += (int)($stats['descriptions_applied'] ?? 0);

            echo "Apply OK: " . json_encode([
                'total' => $stats['total'] ?? null,
                'titles_applied' => $stats['titles_applied'] ?? null,
                'descriptions_applied' => $stats['descriptions_applied'] ?? null,
                'errors' => $stats['errors'] ?? null,
            ], JSON_UNESCAPED_UNICODE) . "\n";

            $overall['accounts_ok']++;
        } catch (Throwable $e) {
            $overall['accounts_failed']++;
            echo "ERRO: {$e->getMessage()}\n";
        }
    }

    echo "\n\nResumo\n";
    echo "------\n";
    echo json_encode($overall, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

function parseArgs(array $argv): array
{
    $opts = [];

    foreach ($argv as $i => $arg) {
        if ($i === 0) {
            continue;
        }

        if ($arg === '--help' || $arg === '-h') {
            echo "Uso: bin/ml-auto-improve.php [--limit-accounts=10] [--account-ids=1,2] [--max-items-per-account=20] [--max-risk=low] [--dry-run] [--yes] [--preflight]\n";
            exit(0);
        }

        if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
            [$k, $v] = explode('=', substr($arg, 2), 2);
            $opts[$k] = $v;
            continue;
        }

        if (str_starts_with($arg, '--')) {
            $opts[substr($arg, 2)] = true;
        }
    }

    return $opts;
}

function runPreflight(array $accountIds, int $limitAccounts): void
{
    echo "ML Auto Improve (Preflight)\n";
    echo "========================\n";

    $env = [
        'DB_HOST' => getenv('DB_HOST') ?: '',
        'DB_PORT' => getenv('DB_PORT') ?: '',
        'DB_DATABASE' => getenv('DB_DATABASE') ?: '',
        'DB_USERNAME' => getenv('DB_USERNAME') ?: '',
    ];
    echo "DB: " . json_encode($env, JSON_UNESCAPED_UNICODE) . "\n";

    try {
        $db = Database::getInstance();
        $db->query('SELECT 1')->fetchColumn();
        echo "DB conexão: OK\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "DB conexão: FALHOU ({$e->getMessage()})\n");
        exit(2);
    }

    try {
        $db = Database::getInstance();

        $tables = [
            'migrations',
            'ml_accounts',
        ];

        foreach ($tables as $table) {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t"
            );
            $stmt->execute(['t' => $table]);
            $exists = ((int)$stmt->fetchColumn()) > 0;
            echo "Tabela {$table}: " . ($exists ? 'OK' : 'AUSENTE') . "\n";
        }

        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ml_accounts'"
        );
        $stmt->execute();
        $mlAccountsExists = ((int)$stmt->fetchColumn()) > 0;

        if ($mlAccountsExists) {
            $accounts = loadAccounts($db, $accountIds, $limitAccounts);
            echo "Contas ativas encontradas: " . count($accounts) . "\n";
            if (empty($accounts)) {
                echo "Aviso: nenhuma conta ativa em ml_accounts (status='active').\n";
            }
        } else {
            echo "Aviso: schema ainda não aplicado (rode migrations).\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Preflight parcial (ignorado): {$e->getMessage()}\n");
    }

    echo "Preflight: OK\n";
}

/**
 * @return array<int, array{id:int,user_id:int,nickname:?string,status:string}>
 */
function loadAccounts(PDO $db, array $accountIds, int $limitAccounts): array
{
    if (!empty($accountIds)) {
        $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
        $stmt = $db->prepare(
            "SELECT id, user_id, nickname, status FROM ml_accounts WHERE id IN ({$placeholders}) AND status = 'active' ORDER BY id ASC"
        );
        $stmt->execute(array_values($accountIds));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $stmt = $db->prepare(
        "SELECT id, user_id, nickname, status FROM ml_accounts WHERE status = 'active' ORDER BY id ASC LIMIT " . max(1, min(200, (int)$limitAccounts))
    );
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param array<int, array<string, mixed>> $worstItems
 * @return string[]
 */
function pickCandidateItemIds(array $worstItems, int $maxItems): array
{
    $ids = [];

    foreach ($worstItems as $row) {
        $itemId = (string)($row['item_id'] ?? '');
        if ($itemId === '') {
            continue;
        }

        // Evitar itens de catálogo (podem ter restrições na edição)
        if (!empty($row['in_catalog'])) {
            continue;
        }

        $ids[] = $itemId;
        if (count($ids) >= $maxItems) {
            break;
        }
    }

    return $ids;
}

/**
 * @return array<int, array{item_id:string,apply_title:bool,apply_description:bool,title?:string,description?:string}>
 */
function buildApprovedItemsFromDryRun(array $dryRun, string $maxRisk): array
{
    $riskOrder = [
        'none' => 0,
        'low' => 1,
        'medium' => 2,
        'high' => 3,
    ];

    $maxRiskValue = $riskOrder[$maxRisk] ?? 1;

    $approved = [];
    $items = $dryRun['items'] ?? [];

    foreach ($items as $itemId => $preview) {
        if (!is_array($preview) || !($preview['success'] ?? false) || !($preview['has_changes'] ?? false)) {
            continue;
        }

        $riskLevel = (string)($preview['risk_level'] ?? 'none');
        if (!isset($riskOrder[$riskLevel]) || $riskOrder[$riskLevel] > $maxRiskValue) {
            continue;
        }

        $applyTitle = (bool)($preview['changes']['title'] ?? false);
        $applyDescription = (bool)($preview['changes']['description'] ?? false);

        $suggestedTitle = (string)($preview['suggested']['title'] ?? '');
        $suggestedDescription = (string)($preview['suggested']['description'] ?? '');

        if ($applyTitle && $suggestedTitle === '') {
            $applyTitle = false;
        }

        if ($applyDescription && $suggestedDescription === '') {
            $applyDescription = false;
        }

        if (!$applyTitle && !$applyDescription) {
            continue;
        }

        $row = [
            'item_id' => (string)$itemId,
            'apply_title' => $applyTitle,
            'apply_description' => $applyDescription,
            'risk_level' => $riskLevel,
        ];

        if ($applyTitle) {
            $row['title'] = $suggestedTitle;
        }

        if ($applyDescription) {
            $row['description'] = $suggestedDescription;
        }

        $approved[] = $row;
    }

    return $approved;
}

/**
 * @param array<int, array{item_id:string,apply_title:bool,apply_description:bool,risk_level:string,title?:string,description?:string}> $approved
 */
function printApprovedItems(array $approved, int $maxRows): void
{
    $total = count($approved);
    $rows = array_slice($approved, 0, $maxRows);

    foreach ($rows as $row) {
        $itemId = (string)($row['item_id'] ?? '');
        $risk = (string)($row['risk_level'] ?? '');
        $flags = [];
        if (!empty($row['apply_title'])) {
            $flags[] = 'title';
        }
        if (!empty($row['apply_description'])) {
            $flags[] = 'description';
        }
        $flagsText = implode('+', $flags);
        echo "- {$itemId} | risk={$risk} | {$flagsText}\n";
    }

    if ($total > $maxRows) {
        echo "... e mais " . ($total - $maxRows) . " itens\n";
    }
}

/**
 * Exige confirmação no modo APPLY.
 *
 * Permite bypass com:
 * - flag `--yes`
 * - variável `CONFIRM_APPLY=yes`
 */
function ensureApplyConfirmed(bool $yesFlag, array $approved, string $maxRisk): void
{
    if ($yesFlag) {
        return;
    }

    $envConfirm = (string)(getenv('CONFIRM_APPLY') ?: ($_ENV['CONFIRM_APPLY'] ?? ''));
    if ($envConfirm === 'yes') {
        return;
    }

    if (!isInteractiveStdin()) {
        echo "ERRO: modo APPLY requer confirmação. Use --yes ou CONFIRM_APPLY=yes.\n";
        exit(3);
    }

    echo "\nATENÇÃO: Você está prestes a APLICAR mudanças em " . count($approved) . " itens (max-risk={$maxRisk}).\n";
    echo "Digite APPLY e pressione Enter para confirmar: ";
    $input = trim((string)fgets(STDIN));
    if ($input !== 'APPLY') {
        echo "Cancelado.\n";
        exit(3);
    }
}

function isInteractiveStdin(): bool
{
    if (!defined('STDIN')) {
        return false;
    }

    if (function_exists('posix_isatty')) {
        return @posix_isatty(STDIN);
    }

    return false;
}

#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Migração Fase 2 do Pregão: provenance (source) + limpeza de seeds.
 *
 * Uso:
 *   php bin/pregao-migrate-fase2.php
 *   php bin/pregao-migrate-fase2.php --purge-seed-data --account-id=1335
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Database;

$opts = getopt('', ['purge-seed-data', 'account-id:', 'dry-run', 'mark-existing-seed']);
$dry = isset($opts['dry-run']);
$accountId = isset($opts['account-id']) ? (int) $opts['account-id'] : null;
$forceMark = isset($opts['mark-existing-seed']);
$markedExisting = false;

$pdo = Database::getInstance();

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return ((int) $stmt->fetchColumn()) > 0;
}

function add_column(PDO $pdo, string $sql, string $label): void
{
    try {
        $pdo->exec($sql);
        fwrite(STDOUT, "OK: {$label}\n");
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'Duplicate column')) {
            fwrite(STDOUT, "SKIP: {$label} (já existe)\n");
            return;
        }
        throw $e;
    }
}

$markedExisting = false;
if (!column_exists($pdo, 'pregao_events', 'source')) {
    add_column(
        $pdo,
        "ALTER TABLE pregao_events ADD COLUMN `source` varchar(32) NOT NULL DEFAULT 'live' AFTER `payload`",
        'pregao_events.source'
    );
    $markedExisting = true;
} else {
    fwrite(STDOUT, "SKIP: pregao_events.source\n");
}

if (!column_exists($pdo, 'account_index_metrics', 'metrics_meta')) {
    add_column(
        $pdo,
        'ALTER TABLE account_index_metrics ADD COLUMN `metrics_meta` json DEFAULT NULL AFTER `semaforo_status`',
        'account_index_metrics.metrics_meta'
    );
} else {
    fwrite(STDOUT, "SKIP: metrics_meta\n");
}

if (!column_exists($pdo, 'account_index_metrics', 'factors_active')) {
    add_column(
        $pdo,
        'ALTER TABLE account_index_metrics ADD COLUMN `factors_active` tinyint unsigned DEFAULT NULL AFTER `metrics_meta`',
        'factors_active'
    );
} else {
    fwrite(STDOUT, "SKIP: factors_active\n");
}

if (!column_exists($pdo, 'account_index_metrics', 'factors_total')) {
    add_column(
        $pdo,
        'ALTER TABLE account_index_metrics ADD COLUMN `factors_total` tinyint unsigned NOT NULL DEFAULT 5 AFTER `factors_active`',
        'factors_total'
    );
} else {
    fwrite(STDOUT, "SKIP: factors_total\n");
}

// Marca eventos pré-fase2 como seed (só na 1ª criação da coluna ou --mark-existing-seed)
if ($markedExisting || $forceMark) {
    $markSql = "UPDATE pregao_events SET source = 'seed' WHERE source <> 'seed'";
    if ($dry) {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM pregao_events WHERE source <> 'seed'")->fetchColumn();
        fwrite(STDOUT, "DRY: marcaria {$count} eventos como source=seed\n");
    } else {
        $n = $pdo->exec($markSql);
        fwrite(STDOUT, "Marcados como seed: {$n} eventos\n");
    }
} else {
    fwrite(STDOUT, "SKIP: mark existing seed (use --mark-existing-seed)\n");
}

if (isset($opts['purge-seed-data'])) {
    $aid = $accountId ?? 1335;
    $statements = [
        "DELETE FROM keyword_ranks WHERE account_id = {$aid}",
        "DELETE FROM account_index_daily WHERE account_id = {$aid}",
        "DELETE FROM pregao_events WHERE source = 'seed' AND (account_id = {$aid} OR account_id IS NULL)",
        "UPDATE account_index_metrics SET
            vendas_hoje = 0, receita_hoje = 0, ticket_medio = 0, vendas_7d = 0,
            tacos = 0, posicao_media = 0, health_medio = 0, reputacao_cor = '',
            reclamacoes_pct = 0, atrasos_pct = 0, cancelamentos_pct = 0,
            perguntas_hoje = 0, tempo_medio_resposta_s = 0, acoes_hora = 0,
            indice_atual = 0, semaforo_status = '',
            metrics_meta = NULL, factors_active = 0
         WHERE account_id = {$aid}",
    ];
    foreach ($statements as $sql) {
        if ($dry) {
            fwrite(STDOUT, "DRY: {$sql}\n");
            continue;
        }
        $affected = $pdo->exec($sql);
        fwrite(STDOUT, "PURGE ({$affected}): " . substr($sql, 0, 60) . "…\n");
    }
}

fwrite(STDOUT, "Fase 2 migrate concluída.\n");

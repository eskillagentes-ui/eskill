#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Migração Fase 2.1: visitas_7d / visitas_baseline + colunas watchlist.
 *
 * Uso: php bin/pregao-migrate-fase21.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Database;

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
            fwrite(STDOUT, "SKIP: {$label}\n");
            return;
        }
        throw $e;
    }
}

if (!column_exists($pdo, 'account_index_metrics', 'visitas_7d')) {
    add_column(
        $pdo,
        'ALTER TABLE account_index_metrics ADD COLUMN `visitas_7d` decimal(14,2) NOT NULL DEFAULT 0.00 AFTER `posicao_media`',
        'visitas_7d'
    );
} else {
    fwrite(STDOUT, "SKIP: visitas_7d\n");
}

if (!column_exists($pdo, 'account_index_baselines', 'visitas_baseline')) {
    add_column(
        $pdo,
        'ALTER TABLE account_index_baselines ADD COLUMN `visitas_baseline` decimal(14,2) NOT NULL DEFAULT 1.00 AFTER `pos_baseline`',
        'visitas_baseline'
    );
} else {
    fwrite(STDOUT, "SKIP: visitas_baseline\n");
}

$watchCols = [
    'apelido' => "ALTER TABLE competitor_items ADD COLUMN `apelido` varchar(128) NOT NULL DEFAULT '' AFTER `title`",
    'keyword_alvo' => 'ALTER TABLE competitor_items ADD COLUMN `keyword_alvo` varchar(255) DEFAULT NULL AFTER `apelido`',
    'sold_quantity' => 'ALTER TABLE competitor_items ADD COLUMN `sold_quantity` int DEFAULT NULL AFTER `price`',
    'available_quantity' => 'ALTER TABLE competitor_items ADD COLUMN `available_quantity` int DEFAULT NULL AFTER `sold_quantity`',
    'last_sold_delta' => 'ALTER TABLE competitor_items ADD COLUMN `last_sold_delta` int NOT NULL DEFAULT 0 AFTER `available_quantity`',
    'active' => 'ALTER TABLE competitor_items ADD COLUMN `active` tinyint(1) NOT NULL DEFAULT 1 AFTER `status`',
    'last_checked_at' => 'ALTER TABLE competitor_items ADD COLUMN `last_checked_at` datetime DEFAULT NULL AFTER `updated_at`',
];

foreach ($watchCols as $col => $sql) {
    if (!column_exists($pdo, 'competitor_items', $col)) {
        add_column($pdo, $sql, "competitor_items.{$col}");
    } else {
        fwrite(STDOUT, "SKIP: competitor_items.{$col}\n");
    }
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS `competitor_item_snapshots` (
      `id` bigint unsigned NOT NULL AUTO_INCREMENT,
      `account_id` int NOT NULL,
      `competitor_item_id` int NOT NULL,
      `mlb_id` varchar(32) NOT NULL,
      `price` decimal(14,2) DEFAULT NULL,
      `sold_quantity` int DEFAULT NULL,
      `available_quantity` int DEFAULT NULL,
      `status` varchar(32) DEFAULT NULL,
      `captured_at` datetime(3) NOT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_cis_item_ts` (`competitor_item_id`, `captured_at`),
      KEY `idx_cis_account_ts` (`account_id`, `captured_at`),
      KEY `idx_cis_mlb` (`mlb_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
fwrite(STDOUT, "OK: competitor_item_snapshots\n");
fwrite(STDOUT, "Fase 2.1 migrate concluída.\n");

<?php

declare(strict_types=1);

/**
 * Migration: AWA Sellers deep scan enhancements
 *
 * - account_status em awa_seller_registry (status da conta ML)
 * - índices auxiliares para volume/localização
 *
 * Uso:
 *   php database/migrations/2026_08_03_awa_seller_deep_scan_enhancements.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../autoload.php';

use App\Database;

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

$db = Database::getInstance();

function columnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function indexExists(PDO $db, string $table, string $index): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

echo "=== AWA deep scan schema enhancements ===\n";

if (!columnExists($db, 'awa_seller_registry', 'account_status')) {
    $db->exec(
        "ALTER TABLE awa_seller_registry
         ADD COLUMN account_status VARCHAR(50) NULL
         COMMENT 'site_status do perfil ML'
         AFTER power_seller_status"
    );
    echo "OK: coluna account_status adicionada\n";
} else {
    echo "SKIP: account_status já existe\n";
}

if (!indexExists($db, 'awa_seller_registry', 'idx_awa_seller_registry_account_items')) {
    $db->exec(
        'CREATE INDEX idx_awa_seller_registry_account_items
         ON awa_seller_registry (account_id, items_count DESC)'
    );
    echo "OK: índice idx_awa_seller_registry_account_items\n";
} else {
    echo "SKIP: índice items já existe\n";
}

if (!indexExists($db, 'awa_seller_registry', 'idx_awa_seller_registry_account_location')) {
    $db->exec(
        'CREATE INDEX idx_awa_seller_registry_account_location
         ON awa_seller_registry (account_id, state, city)'
    );
    echo "OK: índice idx_awa_seller_registry_account_location\n";
} else {
    echo "SKIP: índice location já existe\n";
}

echo "=== Migration concluída ===\n";

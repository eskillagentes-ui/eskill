<?php

declare(strict_types=1);

/**
 * Migration: grants GO por MLB (uma ativa por conta; consume no apply).
 *
 * php database/migrations/2026_09_15_create_item_go_grants.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

$pdo = App\Database::getInstance();

$exists = false;
try {
    $cols = $pdo->query('SHOW COLUMNS FROM item_go_grants');
    $exists = $cols !== false && $cols->fetch() !== false;
} catch (Throwable) {
    $exists = false;
}

if (!$exists) {
    $pdo->exec(
        'CREATE TABLE item_go_grants (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id INT NOT NULL,
            mlb_id VARCHAR(32) NOT NULL,
            status VARCHAR(16) NOT NULL,
            issued_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME NULL,
            issued_by INT NULL,
            KEY idx_igg_account_status (account_id, status),
            KEY idx_igg_account_mlb (account_id, mlb_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    echo "created item_go_grants\n";
} else {
    echo "item_go_grants already exists\n";
}

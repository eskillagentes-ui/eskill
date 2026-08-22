<?php

declare(strict_types=1);

/**
 * Migration: rascunhos locais de investigação de anúncio (apply_blocked).
 *
 * php database/migrations/2026_08_21_create_listing_investigations.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

$pdo = App\Database::getInstance();

$exists = false;
try {
    $cols = $pdo->query('SHOW COLUMNS FROM listing_investigations');
    $exists = $cols !== false && $cols->fetch() !== false;
} catch (Throwable) {
    $exists = false;
}

if (!$exists) {
    $pdo->exec(
        'CREATE TABLE listing_investigations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id INT NOT NULL,
            mlb_id VARCHAR(32) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT \'open\',
            blockers JSON NULL,
            draft_title VARCHAR(512) NULL,
            draft_notes TEXT NULL,
            model_used VARCHAR(64) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_li_account_status (account_id, status),
            KEY idx_li_account_mlb (account_id, mlb_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    echo "created listing_investigations\n";
} else {
    echo "listing_investigations already exists\n";
}

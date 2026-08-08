#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Aplica schema Hidden SEO (índice + view) no DB do .env atual.
 *
 * Uso:
 *   php scripts/apply_hidden_seo_schema.php
 *   # em staging: cd /home/eskill/htdocs/staging.eskill.com.br && php scripts/apply_hidden_seo_schema.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable($root);
$dotenv->safeLoad();

use App\Database;

$db = Database::getInstance();
$dbName = (string)(getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? ''));
$appEnv = (string)(getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? ''));

echo "DB={$dbName} APP_ENV={$appEnv}\n";

// Índice auxiliar (ignora se já existir)
$idxName = 'idx_tech_sheet_hidden_seo';
$check = $db->prepare(
    'SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i'
);
$check->execute([':t' => 'tech_sheet_suggestions', ':i' => $idxName]);
if ((int)$check->fetchColumn() === 0) {
    $db->exec(
        'CREATE INDEX idx_tech_sheet_hidden_seo
         ON tech_sheet_suggestions (account_id, source, status, attribute_id)'
    );
    echo "OK index {$idxName} created\n";
} else {
    echo "OK index {$idxName} already exists\n";
}

$db->exec(
    "CREATE OR REPLACE VIEW v_pending_hidden_seo_changes AS
     SELECT
       id,
       account_id,
       item_id AS ml_item_id,
       attribute_id,
       suggested_value AS new_value,
       source,
       confidence,
       status,
       meta,
       created_at,
       updated_at,
       decided_at AS reviewed_at
     FROM tech_sheet_suggestions
     WHERE source = 'hidden_seo'
       AND status IN ('pending', 'approved')"
);
echo "OK view v_pending_hidden_seo_changes\n";

$count = (int)$db->query('SELECT COUNT(*) FROM v_pending_hidden_seo_changes')->fetchColumn();
echo "OK view rows={$count}\n";

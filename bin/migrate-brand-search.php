#!/usr/bin/env php
<?php

/**
 * Migration runner — Módulo 20 Brand Search
 *
 * Uso:
 *   php bin/migrate-brand-search.php           → aplica as 3 migrations
 *   php bin/migrate-brand-search.php --dry-run → mostra SQL sem executar
 *   php bin/migrate-brand-search.php --rollback → remove as 3 tabelas
 *
 * Segue o padrão de php bin/migrate.php já existente no projeto.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$isDryRun   = in_array('--dry-run',  $argv, true);
$isRollback = in_array('--rollback', $argv, true);

// ── Bootstrap ──────────────────────────────────────────────────────────────
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$configDb = require __DIR__ . '/../config/database.php';
$default  = $configDb['default'] ?? 'mysql';
$conn     = $configDb['connections'][$default] ?? $configDb['connections']['mysql'];

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $conn['host'] ?? 'localhost',
    (string) ($conn['port'] ?? '3306'),
    $conn['database'] ?? ''
);

try {
    $pdo = new PDO($dsn, $conn['username'] ?? '', $conn['password'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "[ERRO] Conexão com banco falhou: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

// ── Migrations ─────────────────────────────────────────────────────────────
$migrationsDir = __DIR__ . '/../database/migrations/';

$files = [
    '100_brand_searches.sql',
    '101_brand_sellers.sql',
    '102_brand_items.sql',
];

if ($isRollback) {
    runRollback($pdo, $isDryRun);
    exit(0);
}

echo PHP_EOL . "eskill — Migration Módulo 20 Brand Search" . PHP_EOL;
echo str_repeat('─', 50) . PHP_EOL;

$success = 0;

foreach ($files as $file) {
    $path = $migrationsDir . $file;

    if (!file_exists($path)) {
        echo "[ERRO] Arquivo não encontrado: {$path}" . PHP_EOL;
        exit(1);
    }

    $sql = file_get_contents($path);

    if ($isDryRun) {
        echo PHP_EOL . "[DRY-RUN] {$file}" . PHP_EOL;
        echo $sql . PHP_EOL;
        continue;
    }

    try {
        $pdo->exec($sql);
        echo "[OK] {$file}" . PHP_EOL;
        $success++;
    } catch (PDOException $e) {
        echo "[ERRO] {$file}: " . $e->getMessage() . PHP_EOL;
        echo "Abortando. Nenhuma migration subsequente foi executada." . PHP_EOL;
        exit(1);
    }
}

if (!$isDryRun) {
    echo str_repeat('─', 50) . PHP_EOL;
    echo "{$success}/3 migrations aplicadas com sucesso." . PHP_EOL . PHP_EOL;
}

// ── Rollback ───────────────────────────────────────────────────────────────
function runRollback(PDO $pdo, bool $isDryRun): void
{
    $tables = [
        'brand_items',    // filho primeiro (FK)
        'brand_sellers',
        'brand_searches', // pai por último
    ];

    echo PHP_EOL . "eskill — Rollback Módulo 20 Brand Search" . PHP_EOL;
    echo str_repeat('─', 50) . PHP_EOL;

    foreach ($tables as $table) {
        $sql = "DROP TABLE IF EXISTS `{$table}`;";

        if ($isDryRun) {
            echo "[DRY-RUN] {$sql}" . PHP_EOL;
            continue;
        }

        try {
            $pdo->exec($sql);
            echo "[OK] Tabela `{$table}` removida." . PHP_EOL;
        } catch (PDOException $e) {
            echo "[ERRO] `{$table}`: " . $e->getMessage() . PHP_EOL;
            exit(1);
        }
    }

    echo str_repeat('─', 50) . PHP_EOL;
    echo "Rollback concluído." . PHP_EOL . PHP_EOL;
}

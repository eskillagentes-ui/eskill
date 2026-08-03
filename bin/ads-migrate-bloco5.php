#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Migração Bloco 5 — tabelas do módulo Ads (read-only).
 *
 * Uso: php bin/ads-migrate-bloco5.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Database;

$pdo = Database::getInstance();
$sqlFile = dirname(__DIR__) . '/database/migrations/2026_08_03_ads_modulo_bloco5.sql';
if (!is_readable($sqlFile)) {
    fwrite(STDERR, "SQL não encontrado: {$sqlFile}\n");
    exit(1);
}

$sql = file_get_contents($sqlFile);
if ($sql === false) {
    fwrite(STDERR, "Falha ao ler SQL\n");
    exit(1);
}

$statements = array_filter(array_map('trim', explode(';', $sql)), static function (string $s): bool {
    if ($s === '') {
        return false;
    }
    // ignora linhas só de comentário
    $lines = array_filter(explode("\n", $s), static fn ($l) => !str_starts_with(trim($l), '--'));
    return trim(implode("\n", $lines)) !== '';
});

foreach ($statements as $stmt) {
    try {
        $pdo->exec($stmt);
        $preview = preg_replace('/\s+/', ' ', substr($stmt, 0, 80)) ?? '';
        fwrite(STDOUT, "OK: {$preview}…\n");
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'already exists')) {
            fwrite(STDOUT, "SKIP: already exists\n");
            continue;
        }
        fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

// Seed marco zero dos sucessores (conta 1335 / FACILYTY)
$accountId = (int) ($_ENV['PREGAO_ACCOUNT_ID'] ?? 1335);
$milestones = [
    [
        'mlb' => 'MLB7297087912',
        'pred' => 'MLB6574414098',
        'promo' => '2026-07-30 00:00:00',
        'campaign' => null,
        'notes' => 'Sucessor catálogo criado ~30/07. Promo/campanha: registrar quando ativadas. Fonte: docs/reconciliacao-catalogo-2026-08-03.md',
    ],
    [
        'mlb' => 'MLB7314817026',
        'pred' => 'MLB6574534100',
        'promo' => '2026-08-02 00:00:00',
        'campaign' => null,
        'notes' => 'Sucessor catálogo criado ~02/08. Promo/campanha: registrar quando ativadas. Fonte: docs/reconciliacao-catalogo-2026-08-03.md',
    ],
];

$ins = $pdo->prepare(
    'INSERT INTO ads_recovery_milestones
       (account_id, mlb_id, predecessor_mlb_id, promo_activated_at, campaign_activated_at, notes)
     VALUES (?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       predecessor_mlb_id = VALUES(predecessor_mlb_id),
       notes = VALUES(notes)'
);
foreach ($milestones as $m) {
    $ins->execute([$accountId, $m['mlb'], $m['pred'], $m['promo'], $m['campaign'], $m['notes']]);
    fwrite(STDOUT, "OK: milestone {$m['mlb']}\n");
}

fwrite(STDOUT, "Migração Bloco 5 concluída.\n");
fwrite(STDOUT, "TACOS baseline inicial: 10% (AdsMetricsCollector::TACOS_BASELINE_INITIAL).\n");

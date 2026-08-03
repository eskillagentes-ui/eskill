#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Limpeza de pregao_events > 90 dias.
 *
 * Uso: php bin/pregao-cleanup.php [--days=90] [--dry-run]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Database;

$opts = getopt('', ['days:', 'dry-run']);
$days = max(7, (int) ($opts['days'] ?? 90));
$dry = isset($opts['dry-run']);

$pdo = Database::getInstance();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM pregao_events WHERE created_at < (NOW() - INTERVAL ? DAY)');
$stmt->execute([$days]);
$count = (int) $stmt->fetchColumn();

fwrite(STDOUT, "Eventos com mais de {$days} dias: {$count}\n");
if ($dry || $count === 0) {
    exit(0);
}

$del = $pdo->prepare('DELETE FROM pregao_events WHERE created_at < (NOW() - INTERVAL ? DAY)');
$del->execute([$days]);
fwrite(STDOUT, "Removidos: {$del->rowCount()}\n");

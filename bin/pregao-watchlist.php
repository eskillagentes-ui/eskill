#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Watchlist Pregão — gerencia concorrentes e coleta diária.
 *
 * Uso:
 *   php bin/pregao-watchlist.php --account-id=1335 --add=MLB123 --apelido="Concorrente X" --keyword="portao pet"
 *   php bin/pregao-watchlist.php --account-id=1335 --collect
 *   php bin/pregao-watchlist.php --account-id=1335 --list
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Database;
use App\Services\Pregao\PregaoWatchlistCollector;

$opts = getopt('', ['account-id:', 'add:', 'apelido:', 'keyword:', 'collect', 'list', 'json']);
$accountId = (int) ($opts['account-id'] ?? ($_ENV['PREGAO_ACCOUNT_ID'] ?? 1335));
$service = new PregaoWatchlistCollector();

if (isset($opts['add'])) {
    $id = $service->upsert($accountId, [
        'mlb_id' => (string) $opts['add'],
        'apelido' => (string) ($opts['apelido'] ?? $opts['add']),
        'keyword_alvo' => isset($opts['keyword']) ? (string) $opts['keyword'] : null,
    ]);
    fwrite(STDOUT, "upsert id={$id} mlb={$opts['add']}\n");
    exit(0);
}

if (isset($opts['list'])) {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare(
        'SELECT id, ml_item_id AS mlb_id, apelido, keyword_alvo, price, sold_quantity, status, available_quantity, last_checked_at
         FROM competitor_items WHERE account_id = ? AND COALESCE(active,1)=1 ORDER BY id'
    );
    $stmt->execute([$accountId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (isset($opts['json'])) {
        echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        foreach ($rows as $r) {
            fwrite(STDOUT, sprintf(
                "#%d %s · %s · R$ %s · sold=%s · %s\n",
                $r['id'],
                $r['mlb_id'],
                $r['apelido'] ?: '-',
                $r['price'] ?? 'n/d',
                $r['sold_quantity'] ?? 'n/d',
                $r['status'] ?? '?'
            ));
        }
        if ($rows === []) {
            fwrite(STDOUT, "(vazio)\n");
        }
    }
    exit(0);
}

if (isset($opts['collect'])) {
    $result = $service->collect($accountId);
    if (isset($opts['json'])) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        fwrite(STDOUT, sprintf(
            "checked=%d alerts=%d errors=%d\n",
            $result['checked'],
            $result['alerts'],
            count($result['errors'])
        ));
        foreach ($result['errors'] as $err) {
            fwrite(STDERR, "  err: {$err}\n");
        }
    }
    exit($result['errors'] === [] ? 0 : 2);
}

fwrite(STDERR, "Informe --add=MLB…, --list ou --collect\n");
exit(1);

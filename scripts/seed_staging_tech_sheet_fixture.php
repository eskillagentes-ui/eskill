#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Seed mínimo de Ficha Técnica no staging (UI/TestSprite).
 * NÃO usa conta ML 1335. Tokens dummy — sem sync real com a API ML.
 *
 * Uso (no staging):
 *   php scripts/seed_staging_tech_sheet_fixture.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$envFile = $root . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v, " \t\"'");
        if ($k !== '' && getenv($k) === false) {
            putenv("$k=$v");
        }
    }
}

$appEnv = (string)(getenv('APP_ENV') ?: '');
$dbName = (string)(getenv('DB_DATABASE') ?: '');
if ($appEnv !== 'staging' && $dbName !== 'eskill_staging') {
    fwrite(STDERR, "Abort: rode só no staging (APP_ENV=staging / DB_DATABASE=eskill_staging). Got APP_ENV={$appEnv} DB={$dbName}\n");
    exit(1);
}

use App\Database;

$db = Database::getInstance();
$user = $db->query('SELECT id, email FROM users ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    fwrite(STDERR, "Abort: nenhum user no staging\n");
    exit(1);
}
$userId = (int)$user['id'];
$mlUserId = 'STAGING_UI_999001';

$st = $db->prepare('SELECT id FROM ml_accounts WHERE ml_user_id = ?');
$st->execute([$mlUserId]);
$accountId = (int)$st->fetchColumn();
if ($accountId <= 0) {
    $ins = $db->prepare(
        "INSERT INTO ml_accounts (user_id, ml_user_id, nickname, email, site_id, access_token, refresh_token, token_expires_at, status, tokens_encrypted)
         VALUES (?,?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL 30 DAY), 'active', 0)"
    );
    $ins->execute([
        $userId,
        $mlUserId,
        'STAGING_UI_FIXTURE',
        'staging-ui@example.local',
        'MLB',
        'staging-fixture-no-token',
        'staging-fixture-no-refresh',
    ]);
    $accountId = (int)$db->lastInsertId();
}

if ($accountId === 1335) {
    fwrite(STDERR, "Abort: recusou account_id 1335\n");
    exit(1);
}

$cols = $db->query("SHOW COLUMNS FROM users LIKE 'active_ml_account_id'")->fetchAll();
if ($cols) {
    $db->prepare('UPDATE users SET active_ml_account_id = ? WHERE id = ?')->execute([$accountId, $userId]);
}

$itemIns = $db->prepare(
    "INSERT INTO items (ml_item_id, account_id, title, category_id, category_name, price, currency_id, available_quantity, sold_quantity, status, data, created_at, updated_at)
     VALUES (?,?,?,?,?,?, 'BRL', 10, 0, 'active', ?, NOW(), NOW())
     ON DUPLICATE KEY UPDATE title=VALUES(title), data=VALUES(data), status='active', updated_at=NOW()"
);
$sumIns = $db->prepare(
    "INSERT INTO tech_sheet_item_summary
      (account_id, item_id, category_id, total_available, filled, missing, completeness_percent, missing_required, missing_filter, missing_hidden, missing_recommended, last_analyzed_at, meta, created_at, updated_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?, NOW(), ?, NOW(), NOW())
     ON DUPLICATE KEY UPDATE completeness_percent=VALUES(completeness_percent), missing_hidden=VALUES(missing_hidden), missing_required=VALUES(missing_required), meta=VALUES(meta), updated_at=NOW()"
);

for ($i = 1; $i <= 25; $i++) {
    $mlId = sprintf('MLBSTG%08d', $i);
    $models = ['Fan 125', 'Titan 150', 'Biz 125', 'CG 160', 'Factor 150'];
    $model = $models[($i - 1) % count($models)];
    $title = sprintf('Fixture Staging Peça Moto %02d %s', $i, $model);
    $cat = 'MLB438146';
    $sku = sprintf('STG-MPN-%02d-%s', $i, preg_replace('/\s+/', '', $model));
    $attrs = [
        ['id' => 'SELLER_SKU', 'name' => 'SKU', 'value_name' => $sku],
    ];
    // Itens ímpares: título com MPN explícito para evidência Hidden SEO
    if ($i % 2 === 1) {
        $title = sprintf('Fixture Staging Peça Moto %02d %s MPN: %s', $i, $model, $sku);
    }
    $data = json_encode([
        'id' => $mlId,
        'title' => $title,
        'category_id' => $cat,
        'status' => 'active',
        'attributes' => $attrs,
    ], JSON_UNESCAPED_UNICODE);
    $itemIns->execute([$mlId, $accountId, $title, $cat, 'Acessórios para Motos', 99.90 + $i, $data]);
    $meta = json_encode(['analyzer_version' => 3, 'missing_model' => ($i % 3 === 0)], JSON_UNESCAPED_UNICODE);
    $sumIns->execute([
        $accountId, $mlId, $cat,
        20, 12, 8, 60.0 + ($i % 30),
        0, 0, 2 + ($i % 4), 1,
        $meta,
    ]);
}

$c = $db->prepare('SELECT COUNT(*) FROM items WHERE account_id = ? AND status = ?');
$c->execute([$accountId, 'active']);
$items = (int)$c->fetchColumn();
echo "OK account_id={$accountId} user_id={$userId} active_items={$items}\n";

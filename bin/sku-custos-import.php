#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Importa custos por SKU via CSV (fundação do trio de ROAS).
 *
 * Cabeçalho esperado:
 *   mlb_id,custo_produto,comissao_pct,frete_medio,custos_operacionais_pct,preco_minimo
 *
 * Uso:
 *   php bin/sku-custos-import.php --account-id=1335 --file=storage/sku_custos.csv
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Services\Ads\SkuCustoService;

$opts = getopt('', ['account-id:', 'file:', 'json']);
$accountId = (int) ($opts['account-id'] ?? ($_ENV['PREGAO_ACCOUNT_ID'] ?? 1335));
$file = (string) ($opts['file'] ?? '');

if ($accountId <= 0 || $file === '') {
    fwrite(STDERR, "Uso: php bin/sku-custos-import.php --account-id=1335 --file=path.csv\n");
    exit(1);
}

$svc = new SkuCustoService();
$result = $svc->importCsv($accountId, $file);

if (isset($opts['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($result['errors'] === [] ? 0 : 2);
}

fwrite(STDOUT, sprintf("importados: %d\n", $result['imported']));
foreach ($result['errors'] as $err) {
    fwrite(STDERR, "erro: {$err}\n");
}
exit($result['errors'] === [] ? 0 : 2);

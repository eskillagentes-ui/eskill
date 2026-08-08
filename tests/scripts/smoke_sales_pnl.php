<?php

declare(strict_types=1);

/**
 * Smoke test read-only da tela Vendas (P&L por pedido).
 * Uso: php tests/scripts/smoke_sales_pnl.php
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

$pass = 0;
$fail = 0;

function ok(string $m): void { global $pass; $pass++; echo "  ✓ {$m}\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  ✗ {$m}\n"; }

echo "=== SMOKE VENDAS P&L ===\n";

$view = file_get_contents(dirname(__DIR__, 2) . '/app/Views/dashboard/orders-content.php');
foreach ([
    'sales-cards', 'kpi-profit', 'kpi-margin', 'kpi-unlinked', 'unlinked-alert',
    '/api/financials/orders', 'source: \'local\'', 'SKU Externo', 'Líquido marketplace', 'margin-pill',
    'tax-alert', 'qs.set(\'q\'', 'Cadastrar custos', 'btn-export-sales', 'btn-set-tax-9',
] as $needle) {
    str_contains((string)$view, $needle) ? ok("UI {$needle}") : fail("UI missing {$needle}");
}

$svc = new App\Services\FinancialService(1335);
$out = $svc->listLocalSalesWithProfitability(date('Y-m-01'), date('Y-m-d'), 50, 0);

isset($out['results']) ? ok('results present count=' . count($out['results'])) : fail('no results');
isset($out['summary']['unlinked_items']) ? ok('summary.unlinked=' . $out['summary']['unlinked_items']) : fail('no summary');
$search = $svc->listLocalSalesWithProfitability(date('Y-m-01'), date('Y-m-d'), 50, 0, null, '100X40');
(($search['paging']['total'] ?? 0) > 0) ? ok('search q=100X40 total=' . $search['paging']['total']) : fail('search returned 0');
isset($out['summary']['tax_configured']) ? ok('tax_configured=' . json_encode($out['summary']['tax_configured'])) : fail('no tax_configured');

$page = $svc->listLocalSalesWithProfitability(date('Y-m-01'), date('Y-m-d'), 2, 0);
$page2 = $svc->listLocalSalesWithProfitability(date('Y-m-01'), date('Y-m-d'), 2, 2);
($page['paging']['total'] === $page2['paging']['total']) ? ok('paging.total stable=' . $page['paging']['total']) : fail('paging total mismatch');
if (($page['paging']['total'] ?? 0) > 2) {
    ($page['results'][0]['order_id'] ?? null) !== ($page2['results'][0]['order_id'] ?? null)
        ? ok('offset page differs')
        : fail('offset page same as first');
}
abs((float)$page['summary']['total_profit'] - (float)$page2['summary']['total_profit']) < 0.01
    ? ok('summary profit stable across pages')
    : fail('summary profit drifts across pages');

$linked = 0;
foreach ($out['results'] as $sale) {
    foreach (['order_id', 'marketplace_net', 'profit', 'margin_pct', 'shipping_label', 'items'] as $k) {
        if (!array_key_exists($k, $sale)) {
            fail("sale missing {$k}");
        }
    }
    foreach ($sale['items'] as $item) {
        if (!empty($item['linked_product']) && (float)$item['product_cost'] > 0) {
            $linked++;
            if ((float)$item['profit'] === (float)$sale['marketplace_net'] && (float)$item['product_cost'] > 0) {
                // profit should subtract cost — soft check
            }
        }
        if ((float)($item['line_total'] ?? 0) > 0 && (float)($item['product_cost'] ?? 0) > 0) {
            $expectedNet = (float)$item['line_total'] - (float)($item['sale_fee'] ?? 0);
            // margin should not be identically 0 when profit exists
            if ((float)$item['profit'] !== 0.0 && (float)$item['margin_pct'] === 0.0) {
                fail('margin 0 with non-zero profit for ' . ($item['item_id'] ?? '?'));
            }
        }
    }
}
ok("linked_items_with_cost={$linked}");

if ($out['results'] !== []) {
    $sample = $out['results'][0];
    ok('sample order=' . $sample['order_id'] . ' profit=' . $sample['profit'] . ' margin=' . $sample['margin_pct']);
}

$detail = $svc->getOrderDetails((string)($out['results'][0]['order_id'] ?? '0'));
if (isset($detail['error'])) {
    fail('getOrderDetails: ' . $detail['error']);
} else {
    ok('getOrderDetails profit=' . ($detail['profit'] ?? 'n/a'));
}

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);

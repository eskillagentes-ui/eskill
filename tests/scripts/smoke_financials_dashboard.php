<?php

declare(strict_types=1);

/**
 * Smoke test read-only do dashboard financeiro (/dashboard/financials).
 * Cobre: elementos DOM, métodos JS, serviços backend e shape das respostas.
 *
 * Uso: php tests/scripts/smoke_financials_dashboard.php
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = App\Database::getInstance();
$account = $db->query("SELECT id FROM ml_accounts WHERE status = 'active' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$user = $db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);

$accountId = (int)($account['id'] ?? 0);
$userId = (int)($user['id'] ?? 0);

$_SESSION['user_id'] = $userId;
$_SESSION['active_ml_account_id'] = $accountId;

$start = date('Y-m-01');
$end = date('Y-m-d');
$prevStart = date('Y-m-01', strtotime('-1 month'));
$prevEnd = date('Y-m-t', strtotime('-1 month'));

$pass = 0;
$fail = 0;
$warn = 0;
$lines = [];

function ok(string $msg): void
{
    global $pass, $lines;
    $pass++;
    $lines[] = "  ✓ {$msg}";
}

function fail(string $msg): void
{
    global $fail, $lines;
    $fail++;
    $lines[] = "  ✗ {$msg}";
}

function warn(string $msg): void
{
    global $warn, $lines;
    $warn++;
    $lines[] = "  ⚠ {$msg}";
}

echo "=== SMOKE FINANCIALS DASHBOARD ===\n";
echo "account_id={$accountId} user_id={$userId} period={$start}..{$end}\n\n";

// ---------------------------------------------------------------------------
// 1) DOM / view elements
// ---------------------------------------------------------------------------
echo "1) View DOM elements\n";
$viewPath = dirname(__DIR__, 2) . '/app/Views/dashboard/financials.php';
$view = file_get_contents($viewPath);
if ($view === false) {
    fail('Não foi possível ler financials.php');
} else {
    ok('View financials.php legível (' . strlen($view) . ' bytes)');
}

$requiredIds = [
    'date-start', 'date-end', 'btn-financial-filter', 'btn-financial-export',
    'kpi-cards', 'kpi-gross-revenue', 'kpi-net-revenue', 'kpi-net-profit', 'kpi-margin',
    'kpi-orders', 'kpi-units-sold', 'kpi-avg-ticket', 'kpi-roi', 'kpi-cost-rate',
    'ads-kpi-cards', 'kpi-ads-spend', 'kpi-tacos', 'kpi-net-profit-post-ads', 'kpi-mpa',
    'tab-overview-btn', 'tab-abc-btn', 'tab-fees-btn', 'tab-compare-btn', 'tab-projection-btn',
    'tab-claims-btn', 'tab-settlements-btn', 'tab-cashflow-btn', 'tab-profitability-btn',
    'tab-overview', 'tab-abc', 'tab-fees', 'tab-compare', 'tab-projection',
    'tab-claims', 'tab-settlements', 'tab-cashflow', 'tab-profitability',
    'pnl-table', 'revenueChart',
    'abc-summary-cards', 'abc-class-filter', 'abc-products-tbody',
    'fees-summary-cards', 'fees-breakdown-tbody', 'feesChart',
    'compare-cards', 'compare-tbody',
    'projection-days', 'btn-reload-projection', 'projection-cards',
    'claims-summary-cards', 'claims-tbody',
    'settlements-summary-cards', 'settlements-tbody',
    'cashflow-summary-cards', 'cashflow-inflows-tbody', 'cashflow-outflows-tbody', 'cashflowChart',
    'profitability-limit', 'btn-reload-profitability', 'profitability-content',
];

foreach ($requiredIds as $id) {
    $inAttr = str_contains($view, 'id="' . $id . '"') || str_contains($view, "id='" . $id . "'");
    $inArray = str_contains($view, "'" . $id . "'") || str_contains($view, '"' . $id . '"');
    if ($inAttr || $inArray) {
        ok("DOM #{$id}");
    } else {
        fail("DOM ausente #{$id}");
    }
}

$requiredJsMethods = [
    'loadData', 'renderKpis', 'renderAdsKpis', 'renderPnL', 'renderChart',
    'loadAbc', 'renderAbcSummary', 'renderAbcProducts',
    'loadFees', 'renderFees',
    'loadCompare', 'renderCompare',
    'loadProjection', 'renderProjection',
    'loadClaims', 'renderClaims',
    'loadSettlements', 'renderSettlements',
    'loadCashflow', 'renderCashflow',
    'loadProfitability', 'renderProfitability',
    'exportPdf', 'escapeHtml',
];

foreach ($requiredJsMethods as $method) {
    if (preg_match('/\b' . preg_quote($method, '/') . '\s*:/', $view)) {
        ok("JS method {$method}");
    } else {
        fail("JS method ausente {$method}");
    }
}

if (str_contains($view, 'window.financialManager')) {
    ok('window.financialManager exposto');
} else {
    fail('window.financialManager não encontrado');
}

if (str_contains($view, 'DEBUG-4e3ccb') || str_contains($view, '__debugKpiLayout')) {
    fail('Instrumentação de debug ainda presente');
} else {
    ok('Sem instrumentação de debug residual');
}

// ---------------------------------------------------------------------------
// 2) Backend services (read-only)
// ---------------------------------------------------------------------------
echo "\n2) Backend services (read-only)\n";

if ($accountId <= 0) {
    fail('Nenhuma conta ML ativa para testar serviços');
} else {
    $financial = new App\Services\FinancialService($accountId);
    $ads = new App\Services\Ads\AdsObservationService();

    // PnL
    try {
        $pnl = $financial->getPnL($start, $end . ' 23:59:59');
        foreach (['gross_revenue', 'net_revenue', 'net_profit', 'avg_margin', 'units_sold', 'total_orders'] as $k) {
            if (array_key_exists($k, $pnl)) {
                ok("PnL.{$k}=" . (is_scalar($pnl[$k]) ? $pnl[$k] : 'ok'));
            if ($k === 'units_sold' && (int)$pnl['total_orders'] > 0 && (int)$pnl[$k] === 0) {
                fail('units_sold=0 com pedidos no período (order_data/JSON_TABLE?)');
            }
            } else {
                fail("PnL sem campo {$k}");
            }
        }
    } catch (Throwable $e) {
        fail('getPnL: ' . $e->getMessage());
    }

    // Metrics
    try {
        $metrics = $financial->getMetrics($start, $end . ' 23:59:59');
        foreach (['total_orders', 'avg_ticket', 'roi', 'cost_rate', 'net_profit'] as $k) {
            if (array_key_exists($k, $metrics)) {
                ok("Metrics.{$k}=" . $metrics[$k]);
            } else {
                fail("Metrics sem campo {$k}");
            }
        }
    } catch (Throwable $e) {
        fail('getMetrics: ' . $e->getMessage());
    }

    // Daily chart
    try {
        $daily = $financial->getDailyRevenue($start, $end . ' 23:59:59');
        ok('getDailyRevenue count=' . count($daily));
    } catch (Throwable $e) {
        fail('getDailyRevenue: ' . $e->getMessage());
    }

    // Fees
    try {
        $fees = $financial->getFeesBreakdown($start, $end);
        if (isset($fees['fees']['total'], $fees['breakdown_by_type'])) {
            ok('Fees total=' . $fees['fees']['total'] . ' types=' . count($fees['breakdown_by_type']));
        } else {
            fail('Fees shape inválido: ' . json_encode(array_keys($fees)));
        }
    } catch (Throwable $e) {
        fail('getFeesBreakdown: ' . $e->getMessage());
    }

    // Compare
    try {
        $cmp = $financial->comparePeriods($start, $end . ' 23:59:59', $prevStart, $prevEnd . ' 23:59:59');
        if (isset($cmp['current'], $cmp['previous'], $cmp['variations'])) {
            ok('Compare variations=' . json_encode($cmp['variations']));
        } else {
            fail('Compare shape inválido');
        }
    } catch (Throwable $e) {
        fail('comparePeriods: ' . $e->getMessage());
    }

    // Projection
    try {
        $proj = $financial->getFinancialProjection(30);
        if (isset($proj['projected']['revenue'], $proj['confidence'])) {
            ok('Projection revenue=' . $proj['projected']['revenue'] . ' conf=' . $proj['confidence']);
        } else {
            fail('Projection shape inválido');
        }
    } catch (Throwable $e) {
        fail('getFinancialProjection: ' . $e->getMessage());
    }

    // ABC + Z
    try {
        $abc = $financial->calculateABCAnalysis($start, $end . ' 23:59:59');
        if (isset($abc['error'])) {
            warn('ABC: ' . $abc['error']);
        } elseif (isset($abc['summary']['class_a'], $abc['summary']['class_z'], $abc['products']['class_z'])) {
            ok('ABC A=' . $abc['summary']['class_a']['count'] . ' Z=' . $abc['summary']['class_z']['count']);
            $z0 = $abc['products']['class_z'][0]['item_id'] ?? '';
            if ($z0 !== '' && !str_starts_with((string)$z0, 'MLB') && !str_starts_with((string)$z0, 'MLM')) {
                fail('Curva Z item_id não é MLB/MLM: ' . $z0);
            } else {
                ok('Curva Z item_id=' . $z0);
            }
        } else {
            fail('ABC shape incompleto: ' . json_encode(array_keys($abc)));
        }
    } catch (Throwable $e) {
        fail('calculateABCAnalysis: ' . $e->getMessage());
    }

    // Cashflow
    try {
        $cf = $financial->getCashFlow($start, $end . ' 23:59:59');
        if (isset($cf['inflows']['total'], $cf['outflows']['total'], $cf['balance'])) {
            ok('Cashflow in=' . $cf['inflows']['total'] . ' out=' . $cf['outflows']['total'] . ' bal=' . $cf['balance']);
        } else {
            fail('Cashflow shape inválido');
        }
    } catch (Throwable $e) {
        fail('getCashFlow: ' . $e->getMessage());
    }

    // Profitability (+ margin fallback)
    try {
        $prof = $financial->getProfitabilityByProduct($start, $end . ' 23:59:59', 10);
        $top = $prof['top_profitable'][0] ?? null;
        if ($top) {
            ok('Profitability top margin=' . $top['avg_margin'] . '% profit=' . $top['profit']);
            if ((float)$top['revenue'] > 0 && (float)$top['profit'] != 0.0 && (float)$top['avg_margin'] == 0.0) {
                fail('Margem ainda 0 com receita/lucro não-zero');
            } else {
                ok('Margem de lucratividade coerente no top item');
            }
        } else {
            warn('Profitability sem produtos no período');
        }
    } catch (Throwable $e) {
        fail('getProfitabilityByProduct: ' . $e->getMessage());
    }

    // Claims report (pode falhar se token ML expirado — warn)
    try {
        $claims = $financial->getClaimsFinancialReport($start, $end);
        if (isset($claims['error'])) {
            warn('Claims report: ' . $claims['error']);
        } elseif (isset($claims['statistics']['total'], $claims['summary']['resolution_rate'])) {
            ok('Claims total=' . $claims['statistics']['total'] . ' resolution=' . $claims['summary']['resolution_rate']);
        } else {
            fail('Claims shape inválido: ' . json_encode(array_keys($claims)));
        }
    } catch (Throwable $e) {
        warn('getClaimsFinancialReport: ' . $e->getMessage());
    }

    // Settlements (API ou local)
    try {
        $set = $financial->getSettlementReport($start, $end);
        if (isset($set['error']) && empty($set['results'])) {
            warn('Settlements: ' . $set['error']);
        } else {
            $count = is_array($set['results'] ?? null) ? count($set['results']) : 0;
            ok('Settlements source=' . ($set['source'] ?? 'n/a') . ' count=' . $count);
        }
    } catch (Throwable $e) {
        warn('getSettlementReport: ' . $e->getMessage());
    }

    // Ads observation + period metrics
    try {
        $dash = $ads->dashboard($accountId);
        ok('Ads dashboard has_campaigns=' . json_encode($dash['has_campaigns'] ?? null) . ' gasto_hoje=' . json_encode($dash['gasto_hoje'] ?? null));
        $period = $ads->periodMetrics($accountId, $start, $end);
        if (!empty($period['available'])) {
            ok('Ads periodMetrics gasto=' . $period['gasto'] . ' tacos=' . json_encode($period['tacos']));
        } else {
            warn('Ads periodMetrics unavailable: ' . ($period['error'] ?? 'n/a'));
        }
    } catch (Throwable $e) {
        warn('AdsObservationService: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n=== RESULTADO ===\n";
foreach ($lines as $line) {
    echo $line . "\n";
}
echo "\nPASS={$pass} FAIL={$fail} WARN={$warn}\n";
exit($fail > 0 ? 1 : 0);

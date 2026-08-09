#!/usr/bin/env php
<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/vendor/autoload.php';

// Load environment variables (if available)
if (file_exists(ROOT_PATH . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(ROOT_PATH);
    $dotenv->load();
}

use App\Database;
use App\Services\AdsService;
use App\Services\FinancialService;
use App\Services\SeoAnalyzerService;
use App\Services\PricingStrategyService;

$options = getopt('', [
    'account:',
    'days::',
    'seo-items::',
    'orders-sample::',
    'item-visits::',
    'output::',
    'help',
]);

if (isset($options['help']) || !isset($options['account'])) {
    echo <<<HELP
Raio-X da Conta (Mercado Livre)
==============================

Gera um relatório de diagnóstico focado em: queda de vendas, dependência de Ads,
custo (ACOS/ROAS), saúde de anúncios (SEO) e sinais de preço/competitividade.

Uso:
  php bin/raiox-conta.php --account=2

Opções:
  --account=ID         ID interno em ml_accounts (obrigatório)
  --days=N             Janela para análise (padrão: 30)
  --seo-items=N        Quantidade de itens (top por pedidos) para análise SEO (padrão: 5)
  --orders-sample=N    Quantidade de pedidos para amostragem de itens (padrão: 400)
    --item-visits=0|1    Inclui visitas por item na tabela de quedas (padrão: 0)
  --output=ARQUIVO     Salva o relatório em arquivo (Markdown). Se omitido, imprime no stdout.
  --help               Mostra esta ajuda

Exemplos:
  php bin/raiox-conta.php --account=2 --days=60 --seo-items=10 --output=storage/reports/raiox_conta_2.md

HELP;
    exit(isset($options['help']) ? 0 : 2);
}

$accountId = (int)$options['account'];
$days = isset($options['days']) ? max(1, (int)$options['days']) : 30;
$seoItems = isset($options['seo-items']) ? max(0, (int)$options['seo-items']) : 5;
$ordersSample = isset($options['orders-sample']) ? max(50, (int)$options['orders-sample']) : 400;
$withItemVisits = isset($options['item-visits']) ? ((int)$options['item-visits'] === 1) : false;
$outputPath = isset($options['output']) ? (string)$options['output'] : null;

function money(float $value): string
{
    return 'R$ ' . number_format($value, 2, ',', '.');
}

function pct(float $value, int $precision = 2): string
{
    return number_format($value, $precision, ',', '.') . '%';
}

function deltaPct(float $current, float $previous): ?float
{
    if ($previous <= 0.0) {
        return null;
    }
    return (($current - $previous) / $previous) * 100;
}

function safeDate(string $ymd): string
{
    return date('Y-m-d', strtotime($ymd));
}

function isoStart(string $ymd): string
{
    // Endpoint /users/{id}/items_visits é estrito: normalmente aceita apenas YYYY-MM-DD.
    return $ymd;
}

function isoEnd(string $ymd): string
{
    return $ymd;
}

function extractKeywordFromTitle(string $title): ?string
{
    $title = trim(mb_strtolower($title));
    if ($title === '') {
        return null;
    }

    $stop = [
        'de',
        'da',
        'do',
        'das',
        'dos',
        'para',
        'com',
        'sem',
        'e',
        'ou',
        'a',
        'o',
        'as',
        'os',
        'em',
        'no',
        'na',
        'nos',
        'nas',
        'por',
        'um',
        'uma',
        'uns',
        'umas',
    ];

    $rawTokens = preg_split('/\s+/u', preg_replace('/[^\p{L}\p{N}\s\-]+/u', ' ', $title)) ?: [];
    $tokens = [];
    foreach ($rawTokens as $t) {
        $t = trim($t);
        if ($t === '' || mb_strlen($t) < 3) {
            continue;
        }
        if (in_array($t, $stop, true)) {
            continue;
        }
        $tokens[] = $t;
        if (count($tokens) >= 4) {
            break;
        }
    }

    if (empty($tokens)) {
        return null;
    }

    return implode(' ', $tokens);
}

function fetchRevenueAndOrders(PDO $db, int $accountId, string $dateFrom, string $dateTo): array
{
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(total_amount), 0) AS revenue, COUNT(*) AS orders\n"
            . "FROM ml_orders\n"
            . "WHERE ml_account_id = :account_id\n"
            . "  AND status = 'paid'\n"
            . "  AND date_created BETWEEN :date_from AND :date_to"
    );
    $stmt->execute([
        'account_id' => $accountId,
        'date_from' => $dateFrom . ' 00:00:00',
        'date_to' => $dateTo . ' 23:59:59',
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'revenue' => (float)($row['revenue'] ?? 0),
        'orders' => (int)($row['orders'] ?? 0),
    ];
}

function fetchLatestOrderSync(PDO $db, int $accountId): array
{
    $stmt = $db->prepare(
        "SELECT MAX(synced_at) AS last_synced_at, MAX(date_created) AS last_order_date\n"
            . "FROM ml_orders WHERE ml_account_id = :account_id"
    );
    $stmt->execute(['account_id' => $accountId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'last_synced_at' => $row['last_synced_at'] ?? null,
        'last_order_date' => $row['last_order_date'] ?? null,
    ];
}

function fetchItemCounts(PDO $db, int $accountId): array
{
    // Prefer ml_items when available (tende a ter status/available_quantity real)
    try {
        $stmt = $db->prepare(
            "SELECT\n"
                . "  SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_count,\n"
                . "  SUM(CASE WHEN status = 'paused' THEN 1 ELSE 0 END) AS paused_count,\n"
                . "  SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) AS closed_count,\n"
                . "  SUM(CASE WHEN status = 'active' AND available_quantity = 0 THEN 1 ELSE 0 END) AS stockout_count\n"
                . "FROM ml_items WHERE account_id = :account_id"
        );
        $stmt->execute(['account_id' => $accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return [
                'source' => 'ml_items',
                'active' => (int)($row['active_count'] ?? 0),
                'paused' => (int)($row['paused_count'] ?? 0),
                'closed' => (int)($row['closed_count'] ?? 0),
                'stockout' => (int)($row['stockout_count'] ?? 0),
            ];
        }
    } catch (Throwable $e) {
        // fallback below
    }

    // Fallback: items (pode não ter status/available_quantity dependendo do schema)
    try {
        $stmt = $db->prepare("SELECT COUNT(*) AS total FROM items WHERE account_id = :account_id");
        $stmt->execute(['account_id' => $accountId]);
        $total = (int)$stmt->fetchColumn();
        return ['source' => 'items', 'total' => $total];
    } catch (Throwable $e) {
        return ['source' => 'none'];
    }
}

function extractTopItemsFromOrders(PDO $db, int $accountId, int $days, int $ordersSample): array
{
    $dateFrom = date('Y-m-d', strtotime("-{$days} days"));

    $stmt = $db->prepare(
        "SELECT order_data\n"
            . "FROM ml_orders\n"
            . "WHERE ml_account_id = :account_id\n"
            . "  AND status = 'paid'\n"
            . "  AND date_created >= :date_from\n"
            . "ORDER BY date_created DESC\n"
            . "LIMIT :limit"
    );
    $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
    $stmt->bindValue(':date_from', $dateFrom . ' 00:00:00');
    $stmt->bindValue(':limit', $ordersSample, PDO::PARAM_INT);
    $stmt->execute();

    $counts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $data = json_decode($row['order_data'] ?? '', true);
        if (!is_array($data)) {
            continue;
        }

        $orderItems = $data['order_items'] ?? [];
        if (!is_array($orderItems)) {
            continue;
        }

        foreach ($orderItems as $oi) {
            if (!is_array($oi)) {
                continue;
            }

            $item = $oi['item'] ?? null;
            $itemId = is_array($item) ? ($item['id'] ?? null) : null;
            if (!is_string($itemId) || $itemId === '') {
                continue;
            }

            $qty = (int)($oi['quantity'] ?? 1);
            $counts[$itemId] = ($counts[$itemId] ?? 0) + max(1, $qty);
        }
    }

    arsort($counts);
    $top = [];
    foreach ($counts as $itemId => $qty) {
        $top[] = ['item_id' => $itemId, 'qty' => $qty];
        if (count($top) >= 50) {
            break;
        }
    }
    return $top;
}

function fetchDailyRevenueAndOrders(PDO $db, int $accountId, int $days): array
{
    $dateFrom = date('Y-m-d', strtotime("-{$days} days"));

    $stmt = $db->prepare(
        "SELECT DATE(date_created) AS d, COUNT(*) AS orders, SUM(total_amount) AS revenue\n"
            . "FROM ml_orders\n"
            . "WHERE ml_account_id = :account_id\n"
            . "  AND status = 'paid'\n"
            . "  AND date_created >= :date_from\n"
            . "GROUP BY DATE(date_created)\n"
            . "ORDER BY d ASC"
    );
    $stmt->execute([
        'account_id' => $accountId,
        'date_from' => $dateFrom . ' 00:00:00',
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $series = [];
    foreach ($rows as $row) {
        $series[] = [
            'date' => (string)($row['d'] ?? ''),
            'orders' => (int)($row['orders'] ?? 0),
            'revenue' => (float)($row['revenue'] ?? 0),
        ];
    }
    return $series;
}

function fetchSellerDailyVisits(int $accountId, int $days): array
{
    try {
        $financial = new FinancialService($accountId);
        $result = $financial->getSellerVisitsByTimeWindow($days);

        if (isset($result['error'])) {
            return [
                'ok' => false,
                'error' => (string)($result['error'] ?? 'Erro ao buscar visitas'),
                'daily' => [],
                'meta' => $result,
            ];
        }

        $daily = [];
        $rows = $result['daily_visits'] ?? [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $date = (string)($row['date'] ?? '');
                if ($date === '') {
                    continue;
                }
                $ymd = date('Y-m-d', strtotime($date));
                $daily[$ymd] = (int)($row['total'] ?? 0);
            }
        }

        return [
            'ok' => true,
            'error' => null,
            'daily' => $daily,
            'meta' => $result,
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => $e->getMessage(),
            'daily' => [],
            'meta' => null,
        ];
    }
}

function fetchSellerTotalVisitsByPeriod(int $accountId, string $dateFrom, string $dateTo): ?int
{
    try {
        $financial = new FinancialService($accountId);
        $result = $financial->getSellerTotalVisits(isoStart($dateFrom), isoEnd($dateTo));
        if (isset($result['error'])) {
            return null;
        }
        return isset($result['total_visits']) ? (int)$result['total_visits'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function fetchItemVisitsTotalByPeriod(int $accountId, string $itemId, string $dateFrom, string $dateTo): ?int
{
    try {
        $financial = new FinancialService($accountId);
        $result = $financial->getItemVisitsByPeriod($itemId, $dateFrom, $dateTo);

        if (isset($result['error'])) {
            return null;
        }

        return isset($result['total_visits']) ? (int)$result['total_visits'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function extractTopItemsFromOrdersBetween(PDO $db, int $accountId, string $dateFrom, string $dateTo, int $ordersSample): array
{
    $stmt = $db->prepare(
        "SELECT order_data\n"
            . "FROM ml_orders\n"
            . "WHERE ml_account_id = :account_id\n"
            . "  AND status = 'paid'\n"
            . "  AND date_created BETWEEN :date_from AND :date_to\n"
            . "ORDER BY date_created DESC\n"
            . "LIMIT :limit"
    );
    $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
    $stmt->bindValue(':date_from', $dateFrom . ' 00:00:00');
    $stmt->bindValue(':date_to', $dateTo . ' 23:59:59');
    $stmt->bindValue(':limit', $ordersSample, PDO::PARAM_INT);
    $stmt->execute();

    $counts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $data = json_decode($row['order_data'] ?? '', true);
        if (!is_array($data)) {
            continue;
        }

        $orderItems = $data['order_items'] ?? [];
        if (!is_array($orderItems)) {
            continue;
        }

        foreach ($orderItems as $oi) {
            if (!is_array($oi)) {
                continue;
            }

            $item = $oi['item'] ?? null;
            $itemId = is_array($item) ? ($item['id'] ?? null) : null;
            if (!is_string($itemId) || $itemId === '') {
                continue;
            }

            $qty = (int)($oi['quantity'] ?? 1);
            $counts[$itemId] = ($counts[$itemId] ?? 0) + max(1, $qty);
        }
    }

    arsort($counts);
    $top = [];
    foreach ($counts as $itemId => $qty) {
        $top[] = ['item_id' => $itemId, 'qty' => $qty];
        if (count($top) >= 50) {
            break;
        }
    }
    return $top;
}

function fetchItemsFromLocalTable(PDO $db, int $accountId, array $itemIds): array
{
    $itemIds = array_values(array_filter(array_unique(array_values($itemIds)), fn($v) => is_string($v) && $v !== ''));
    if (empty($itemIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $map = [];

    // 1) Tenta ml_items (quando existe e está populada)
    try {
        $sql = "SELECT id, title, category_id, price, available_quantity, status FROM ml_items WHERE account_id = ? AND id IN ({$placeholders})";
        $stmt = $db->prepare($sql);

        $i = 1;
        $stmt->bindValue($i++, $accountId, PDO::PARAM_INT);
        foreach ($itemIds as $id) {
            $stmt->bindValue($i++, $id);
        }

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $id = (string)($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $map[$id] = $row;
        }
    } catch (Throwable $e) {
        // segue para fallback
    }

    // 2) Fallback: tabela items (ItemSyncService escreve aqui)
    try {
        $sql2 = "SELECT ml_item_id AS id, title, category_id, price, available_quantity, status FROM items WHERE account_id = ? AND ml_item_id IN ({$placeholders})";
        $stmt2 = $db->prepare($sql2);

        $i = 1;
        $stmt2->bindValue($i++, $accountId, PDO::PARAM_INT);
        foreach ($itemIds as $id) {
            $stmt2->bindValue($i++, $id);
        }

        $stmt2->execute();
        $rows2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows2 as $row) {
            $id = (string)($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if (!isset($map[$id])) {
                $map[$id] = $row;
            }
        }
    } catch (Throwable $e) {
        // sem fallback disponível
    }

    return $map;
}

try {
    $db = Database::getInstance();

    // Account
    $stmt = $db->prepare(
        'SELECT id, nickname, ml_user_id, email, status, token_expires_at, updated_at FROM ml_accounts WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $accountId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$account) {
        fwrite(STDERR, "Conta #{$accountId} não encontrada em ml_accounts.\n");
        exit(3);
    }

    $nickname = (string)($account['nickname'] ?? '');
    $mlUserId = (string)($account['ml_user_id'] ?? '');
    $status = (string)($account['status'] ?? 'unknown');
    $tokenExpiresAt = (string)($account['token_expires_at'] ?? '');

    $today = date('Y-m-d');
    $from = date('Y-m-d', strtotime("-{$days} days"));

    // Sales windows
    $last7From = safeDate('-6 days');
    $prev7From = safeDate('-13 days');
    $prev7To = safeDate('-7 days');
    $last30From = safeDate('-29 days');
    $prev30From = safeDate('-59 days');
    $prev30To = safeDate('-30 days');

    $last7 = fetchRevenueAndOrders($db, $accountId, $last7From, $today);
    $prev7 = fetchRevenueAndOrders($db, $accountId, $prev7From, $prev7To);
    $last30 = fetchRevenueAndOrders($db, $accountId, $last30From, $today);
    $prev30 = fetchRevenueAndOrders($db, $accountId, $prev30From, $prev30To);

    $last7Delta = deltaPct($last7['revenue'], (float)$prev7['revenue']);
    $last30Delta = deltaPct($last30['revenue'], (float)$prev30['revenue']);

    $sellerVisitsLast7 = fetchSellerTotalVisitsByPeriod($accountId, $last7From, $today);
    $sellerVisitsPrev7 = fetchSellerTotalVisitsByPeriod($accountId, $prev7From, $prev7To);
    $sellerVisitsDelta = (is_int($sellerVisitsLast7) && is_int($sellerVisitsPrev7) && $sellerVisitsPrev7 > 0)
        ? deltaPct((float)$sellerVisitsLast7, (float)$sellerVisitsPrev7)
        : null;

    $sellerConvLast7 = (is_int($sellerVisitsLast7) && $sellerVisitsLast7 > 0)
        ? (($last7['orders'] / $sellerVisitsLast7) * 100)
        : null;
    $sellerConvPrev7 = (is_int($sellerVisitsPrev7) && $sellerVisitsPrev7 > 0)
        ? (($prev7['orders'] / $sellerVisitsPrev7) * 100)
        : null;

    $sync = fetchLatestOrderSync($db, $accountId);
    $itemsCount = fetchItemCounts($db, $accountId);

    // ADS
    $ads = new AdsService($accountId);
    $adsMetrics = $ads->getMetrics('last_30_days');
    $adsCampaigns = $ads->getCampaigns('active');

    $adsRevenueShare = $last30['revenue'] > 0
        ? (($adsMetrics['revenue'] ?? 0.0) / $last30['revenue']) * 100
        : 0.0;

    // Ranking/alerts (se existir histórico)
    $rankingStats = null;
    $unresolvedAlerts = [];
    try {
        $ranking = new \App\Services\RankingAlertService($accountId);
        $rankingStats = $ranking->getAlertStats(30);
        $unresolvedAlerts = $ranking->getUnresolvedAlerts(20);
    } catch (Throwable $e) {
        // Sem tabela/serviço disponível — segue sem isso
    }

    // Top itens (por pedidos) para análises focadas
    $topItems = extractTopItemsFromOrders($db, $accountId, $days, $ordersSample);
    $topItemsForSeo = array_slice($topItems, 0, $seoItems);

    // Série diária (para achar “ponto de virada”)
    $dailySeries = fetchDailyRevenueAndOrders($db, $accountId, min(60, max(14, $days)));
    $sellerVisits14 = fetchSellerDailyVisits($accountId, 14);

    // Comparativo de itens: últimos 7d vs 7d anteriores
    $topLast7 = extractTopItemsFromOrdersBetween($db, $accountId, $last7From, $today, $ordersSample);
    $topPrev7 = extractTopItemsFromOrdersBetween($db, $accountId, $prev7From, $prev7To, $ordersSample);
    $prevMap = [];
    foreach ($topPrev7 as $r) {
        $prevMap[(string)$r['item_id']] = (int)$r['qty'];
    }
    $itemDelta = [];
    foreach ($topLast7 as $r) {
        $id = (string)$r['item_id'];
        $cur = (int)$r['qty'];
        $prev = (int)($prevMap[$id] ?? 0);
        $itemDelta[] = ['item_id' => $id, 'qty_last7' => $cur, 'qty_prev7' => $prev, 'delta' => $cur - $prev];
    }
    usort($itemDelta, fn($a, $b) => ($a['delta'] <=> $b['delta']));
    $topDrops = array_slice($itemDelta, 0, 10);

    $localItemMap = fetchItemsFromLocalTable(
        $db,
        $accountId,
        array_merge(
            array_map(fn($r) => (string)$r['item_id'], $topItemsForSeo),
            array_map(fn($r) => (string)$r['item_id'], $topDrops)
        )
    );

    $seoResults = [];
    if ($seoItems > 0 && !empty($topItemsForSeo)) {
        $seo = new SeoAnalyzerService($accountId);
        $pricing = new PricingStrategyService($accountId);
        $competitorBlocked = false;

        foreach ($topItemsForSeo as $entry) {
            $itemId = $entry['item_id'];
            $qty = (int)$entry['qty'];

            $local = $localItemMap[$itemId] ?? null;
            $categoryId = is_array($local) ? ($local['category_id'] ?? null) : null;
            $title = is_array($local) ? ($local['title'] ?? null) : null;

            // No relatório, evita Quality/Health (reduz requisições e 404 de /health)
            $analysis = $seo->analyzeItem($itemId, false);
            $score = (int)($analysis['score'] ?? 0);
            $grade = (string)($analysis['grade'] ?? '');
            $crit = $analysis['critical_issues'] ?? [];
            $prio = $analysis['priority_actions'] ?? [];

            $itemFromSeo = $analysis['item'] ?? null;
            if (is_array($itemFromSeo)) {
                $categoryId = $categoryId ?: ($itemFromSeo['category_id'] ?? null);
                $title = $title ?: ($itemFromSeo['title'] ?? null);
            }

            $keyword = is_string($title) ? extractKeywordFromTitle($title) : null;

            $competitor = null;
            if (!$competitorBlocked && is_string($categoryId) && $categoryId !== '') {
                $competitor = $pricing->analyzeCompetitorPrices($categoryId, null, $keyword);
                if (is_array($competitor) && (int)($competitor['status'] ?? 0) === 403) {
                    $competitorBlocked = true;
                }
            }

            $seoResults[] = [
                'item_id' => $itemId,
                'qty' => $qty,
                'title' => $title,
                'category_id' => $categoryId,
                'seo_score' => $score,
                'seo_grade' => $grade,
                'critical_issues_count' => is_array($crit) ? count($crit) : 0,
                'priority_actions_count' => is_array($prio) ? count($prio) : 0,
                'quality_check' => $analysis['quality_check'] ?? null,
                'competitor_price_stats' => $competitor['price_stats'] ?? null,
                'competitor_status' => $competitor['status'] ?? ($competitor['error'] ?? null),
                'competitor_keyword' => $keyword ?? null,
            ];
        }
    }

    // Build report
    $lines = [];
    $lines[] = '# Raio-X da Conta (Mercado Livre)';
    $lines[] = '';
    $lines[] = '- Gerado em: ' . date('c');
    $lines[] = '- Conta: ' . ($nickname !== '' ? $nickname : "#{$accountId}");
    $lines[] = '- ID interno: ' . $accountId;
    $lines[] = '- ML User ID: ' . ($mlUserId !== '' ? $mlUserId : 'N/A');
    $lines[] = '- Status (DB): ' . $status;
    $lines[] = '- Token expira em: ' . ($tokenExpiresAt !== '' ? $tokenExpiresAt : 'N/A');
    $lines[] = '';

    $lines[] = '## 1) Vendas (sinais de queda)';
    $lines[] = '';
    $lines[] = '- Receita (últimos 7d): ' . money($last7['revenue']) . ' | pedidos: ' . $last7['orders']
        . (is_float($last7Delta) ? (' | var.: ' . pct($last7Delta, 1)) : '');
    if (is_int($sellerVisitsLast7)) {
        $lines[] = '- Visitas (seller, 7d): ' . $sellerVisitsLast7
            . (is_float($sellerVisitsDelta) ? (' | var. vs 7d ant.: ' . pct($sellerVisitsDelta, 1)) : '');
        if (is_float($sellerConvLast7) && is_float($sellerConvPrev7)) {
            $lines[] = '- Conversão (pedidos/visitas): 7d=' . pct($sellerConvLast7, 2) . ' | 7d ant.=' . pct($sellerConvPrev7, 2);
        }
    }
    $lines[] = '- Receita (últimos 30d): ' . money($last30['revenue']) . ' | pedidos: ' . $last30['orders']
        . (is_float($last30Delta) ? (' | var.: ' . pct($last30Delta, 1)) : '');
    $lines[] = '- Janela analisada: ' . $from . ' → ' . $today;

    if (!empty($dailySeries)) {
        $lines[] = '';
        $lines[] = '### Série diária (últimos 14 dias)';
        if (($sellerVisits14['ok'] ?? false) === true) {
            $lines[] = 'Data | Pedidos | Receita | Visitas | Conversão (pedidos/visitas)';
            $lines[] = '---|---:|---:|---:|---:';
        } else {
            $lines[] = 'Data | Pedidos | Receita';
            $lines[] = '---|---:|---:';
        }
        $tail = array_slice($dailySeries, -14);
        foreach ($tail as $row) {
            $date = (string)$row['date'];
            $orders = (int)$row['orders'];
            $revenue = (float)$row['revenue'];

            if (($sellerVisits14['ok'] ?? false) === true) {
                $visits = (int)(($sellerVisits14['daily'] ?? [])[$date] ?? 0);
                $conv = $visits > 0 ? (($orders / $visits) * 100) : 0.0;
                $lines[] = $date . ' | ' . $orders . ' | ' . money($revenue) . ' | ' . $visits . ' | ' . pct($conv, 2);
            } else {
                $lines[] = $date . ' | ' . $orders . ' | ' . money($revenue);
            }
        }

        if (($sellerVisits14['ok'] ?? false) !== true && !empty($sellerVisits14['error'])) {
            $lines[] = '';
            $lines[] = '- Obs: não foi possível buscar visitas do vendedor (API): ' . (string)$sellerVisits14['error'];
        }
    }

    if (!empty($topDrops)) {
        $lines[] = '';
        $lines[] = '### Itens com maior queda (últimos 7d vs 7d anteriores)';
        if ($withItemVisits) {
            $lines[] = 'Item | Últimos 7d (qtd) | 7d anteriores (qtd) | Δ | Status/Estoque (local) | Visitas 7d | Visitas 7d ant. | Conv. 7d (qtd/visitas) | Sinal';
            $lines[] = '---|---:|---:|---:|---|---:|---:|---:|---';
        } else {
            $lines[] = 'Item | Últimos 7d (qtd) | 7d anteriores (qtd) | Δ | Status/Estoque (ml_items)';
            $lines[] = '---|---:|---:|---:|---';
        }
        $missingLocal = 0;
        foreach ($topDrops as $row) {
            $id = (string)$row['item_id'];
            $local = $localItemMap[$id] ?? null;
            if (!is_array($local)) {
                $missingLocal++;
            }
            $statusTxt = is_array($local) ? (string)($local['status'] ?? '-') : '-';
            $qtyTxt = is_array($local) ? (string)($local['available_quantity'] ?? '-') : '-';

            if ($withItemVisits) {
                $v7 = fetchItemVisitsTotalByPeriod($accountId, $id, $last7From, $today);
                $vp = fetchItemVisitsTotalByPeriod($accountId, $id, $prev7From, $prev7To);
                $conv = ($v7 !== null && $v7 > 0) ? (((int)$row['qty_last7'] / $v7) * 100) : null;

                $convPrev = ($vp !== null && $vp > 0) ? (((int)$row['qty_prev7'] / $vp) * 100) : null;
                $visitsDelta = (is_int($v7) && is_int($vp) && $vp > 0) ? deltaPct((float)$v7, (float)$vp) : null;
                $convDelta = (is_float($conv) && is_float($convPrev) && $convPrev > 0) ? deltaPct((float)$conv, (float)$convPrev) : null;
                $signal = 'N/A';
                if (is_float($visitsDelta) || is_float($convDelta)) {
                    $trafficDrop = is_float($visitsDelta) && $visitsDelta <= -20.0;
                    $convDrop = is_float($convDelta) && $convDelta <= -20.0;
                    if ($trafficDrop && $convDrop) {
                        $signal = 'tráfego + conversão';
                    } elseif ($trafficDrop) {
                        $signal = 'tráfego';
                    } elseif ($convDrop) {
                        $signal = 'conversão';
                    } else {
                        $signal = 'misto';
                    }
                }

                $lines[] = $id
                    . ' | ' . (int)$row['qty_last7']
                    . ' | ' . (int)$row['qty_prev7']
                    . ' | ' . (int)$row['delta']
                    . ' | ' . $statusTxt . ' / ' . $qtyTxt
                    . ' | ' . ($v7 !== null ? (string)$v7 : 'N/A')
                    . ' | ' . ($vp !== null ? (string)$vp : 'N/A')
                    . ' | ' . ($conv !== null ? pct($conv, 2) : 'N/A')
                    . ' | ' . $signal;
            } else {
                $lines[] = $id . ' | ' . (int)$row['qty_last7'] . ' | ' . (int)$row['qty_prev7'] . ' | ' . (int)$row['delta'] . ' | ' . $statusTxt . ' / ' . $qtyTxt;
            }
        }

        if ($missingLocal > 0) {
            $lines[] = '';
            $lines[] = '- Obs: ' . $missingLocal . '/' . count($topDrops) . ' itens sem dados em ml_items. Para preencher status/estoque, rode o sync: `php bin/sync-items.php`.';
        }
    }
    $lines[] = '';
    $lines[] = 'Sinais comuns quando “caiu a venda”: (1) Ads diminuiu/encareceu, (2) estoque zerou, (3) preço ficou fora do competitivo, (4) queda de qualidade/SEO, (5) problema de reputação/logística.';
    $lines[] = '';

    $lines[] = '## 2) Ads (Mercado Ads)';
    $lines[] = '';
    $lines[] = '- Investimento (30d): ' . money((float)($adsMetrics['investment'] ?? 0.0));
    $lines[] = '- Receita atribuída Ads (30d): ' . money((float)($adsMetrics['revenue'] ?? 0.0));
    $lines[] = '- ACOS: ' . pct((float)($adsMetrics['acos'] ?? 0.0));
    $lines[] = '- ROAS: ' . number_format((float)($adsMetrics['roas'] ?? 0.0), 2, ',', '.') . 'x';
    $lines[] = '- CPC: ' . money((float)($adsMetrics['cpc'] ?? 0.0)) . ' | CTR: ' . pct((float)($adsMetrics['ctr'] ?? 0.0));
    $lines[] = '- Fonte Ads: ' . (string)(($adsMetrics['_meta']['data_source'] ?? 'unknown'));

    $adsMeta = $adsMetrics['_meta'] ?? [];
    if (is_array($adsMeta)) {
        $obsParts = [];
        if (!empty($adsMeta['api_endpoint'])) {
            $obsParts[] = 'endpoint=' . (string)$adsMeta['api_endpoint'];
        }
        if (!empty($adsMeta['api_status'])) {
            $obsParts[] = 'status=' . (string)$adsMeta['api_status'];
        }
        if (!empty($adsMeta['api_error'])) {
            $obsParts[] = 'erro=' . (string)$adsMeta['api_error'];
        }
        if (!empty($adsMeta['product_ads_status']) || !empty($adsMeta['product_ads_error'])) {
            $pa = [];
            if (!empty($adsMeta['product_ads_status'])) {
                $pa[] = 'status=' . (string)$adsMeta['product_ads_status'];
            }
            if (!empty($adsMeta['product_ads_error'])) {
                $pa[] = 'erro=' . (string)$adsMeta['product_ads_error'];
            }
            $obsParts[] = 'product_ads(' . implode(', ', $pa) . ')';
        }
        if (!empty($adsMeta['reason'])) {
            $obsParts[] = 'motivo=' . (string)$adsMeta['reason'];
        }
        if (!empty($obsParts)) {
            $lines[] = '- Obs (API Ads): ' . implode(' | ', $obsParts);
        }
    }

    $lines[] = '- % Receita total que veio de Ads (aprox.): ' . pct($adsRevenueShare, 1);
    $lines[] = '';
    $campaignCount = is_array($adsCampaigns['campaigns'] ?? null) ? count($adsCampaigns['campaigns']) : 0;
    $lines[] = '- Campanhas ativas (API/cache): ' . $campaignCount . ' | fonte: ' . (string)(($adsCampaigns['_meta']['data_source'] ?? 'unknown'));
    if (!empty($adsCampaigns['_meta']['reason'])) {
        $lines[] = '  - Obs: sem API (fallback) → ' . (string)$adsCampaigns['_meta']['reason'];
    }
    if (!empty($adsCampaigns['_meta']['product_ads_status']) || !empty($adsCampaigns['_meta']['product_ads_error'])) {
        $parts = [];
        if (!empty($adsCampaigns['_meta']['product_ads_status'])) {
            $parts[] = 'status=' . (string)$adsCampaigns['_meta']['product_ads_status'];
        }
        if (!empty($adsCampaigns['_meta']['product_ads_error'])) {
            $parts[] = 'erro=' . (string)$adsCampaigns['_meta']['product_ads_error'];
        }
        $lines[] = '  - Obs (Product Ads API): ' . implode(' | ', $parts);
    }
    $lines[] = '';
    $lines[] = 'Leitura rápida:';
    $lines[] = '- ACOS > 15% costuma “matar margem” (principalmente se preço já é apertado).';
    $lines[] = '- ROAS baixo + queda de receita total normalmente indica: público errado, lances altos, ou criativos/itens com baixa conversão.';
    $lines[] = '';

    $lines[] = '## 3) Operação (sincronização, anúncios, estoque)';
    $lines[] = '';
    $lines[] = '- Último synced_at (ml_orders): ' . ($sync['last_synced_at'] ?? 'N/A');
    $lines[] = '- Última venda registrada (ml_orders.date_created): ' . ($sync['last_order_date'] ?? 'N/A');
    if (($itemsCount['source'] ?? '') === 'ml_items') {
        $lines[] = '- Anúncios (ml_items): ativos=' . ($itemsCount['active'] ?? 0)
            . ', pausados=' . ($itemsCount['paused'] ?? 0)
            . ', encerrados=' . ($itemsCount['closed'] ?? 0)
            . ', estoque zero (ativos)=' . ($itemsCount['stockout'] ?? 0);
    } elseif (($itemsCount['source'] ?? '') === 'items') {
        $lines[] = '- Anúncios (items): total=' . ($itemsCount['total'] ?? 0) . ' (fallback: sem status/estoque detalhado)';
    } else {
        $lines[] = '- Anúncios: não foi possível ler contagens (tabelas ausentes ou schema diferente).';
    }
    $lines[] = '';

    $lines[] = '## 4) Alertas de ranking/preço (se houver histórico)';
    $lines[] = '';
    if (is_array($rankingStats)) {
        $lines[] = '- Stats (30d): ' . json_encode($rankingStats, JSON_UNESCAPED_UNICODE);
        $lines[] = '- Alertas não resolvidos (até 20): ' . (is_array($unresolvedAlerts) ? count($unresolvedAlerts) : 0);
    } else {
        $lines[] = '- Sem dados de ranking no momento (tabela/serviço não disponível ou sem histórico gerado).';
    }
    $lines[] = '';

    $lines[] = '## 5) SEO (amostra dos itens que mais venderam)';
    $lines[] = '';
    if (empty($seoResults)) {
        $lines[] = '- SEO não analisado (seo-items=0 ou sem pedidos suficientes na janela).';
    } else {
        foreach ($seoResults as $r) {
            $title = is_string($r['title']) && $r['title'] !== '' ? $r['title'] : '(título indisponível)';
            $lines[] = "- {$r['item_id']} | qtd(amostra)={$r['qty']} | SEO={$r['seo_score']}/100 ({$r['seo_grade']}) | críticos={$r['critical_issues_count']} | ações={$r['priority_actions_count']}";
            $lines[] = '  - ' . $title;
            if (is_array($r['quality_check'])) {
                $qc = $r['quality_check'];
                $lines[] = '  - Qualidade/Health: quality_score=' . ($qc['quality_score'] ?? 'N/A')
                    . ', health=' . ($qc['health_status'] ?? 'N/A')
                    . ' (' . ($qc['health_score'] ?? 'N/A') . ')'
                    . ', issues=' . ($qc['total_issues'] ?? 'N/A');
            }
            if (is_array($r['competitor_price_stats'])) {
                $s = $r['competitor_price_stats'];
                $lines[] = '  - Concorrência (categoria): min=' . money((float)($s['min'] ?? 0))
                    . ', mediana=' . money((float)($s['median'] ?? 0))
                    . ', média=' . money((float)($s['average'] ?? 0))
                    . ' (q=' . ($s['count'] ?? 0) . ')'
                    . (is_string($r['competitor_keyword']) ? (' | termo=' . $r['competitor_keyword']) : '');
            }
        }
    }
    $lines[] = '';

    $lines[] = '## 6) Próximas ações (o que mais costuma destravar)';
    $lines[] = '';
    $actions = [];
    if (is_float($last7Delta) && $last7Delta < -10) {
        $actions[] = 'Vendas caíram forte na última semana: comparar dias/itens que perderam volume e checar se Ads pausou/budget reduziu.';
    }
    if (($adsMeta['data_source'] ?? '') === 'no_data' || !empty($adsMeta['api_error']) || !empty($adsMeta['product_ads_error'])) {
        $actions[] = 'Ads via API não respondeu (404/sem permissão/sem dados). Validar no painel do Mercado Ads se a conta tem Product Ads ativo e se o token tem escopo de Ads; sem isso, o relatório não consegue medir ACOS/ROAS.';
    }
    if ((float)($adsMetrics['acos'] ?? 0.0) > 15.0) {
        $actions[] = 'ACOS acima de 15%: cortar gasto nos itens sem margem, reduzir lances, e priorizar campanhas/itens com ROAS maior.';
    }
    if ($adsRevenueShare >= 60.0) {
        $actions[] = 'Dependência alta de Ads: otimizar SEO (título/atributos/imagens), preço e logística para recuperar venda orgânica.';
    }
    if (($itemsCount['stockout'] ?? 0) > 0) {
        $actions[] = 'Há anúncios ativos sem estoque: isso derruba relevância e “seca” o orgânico; reabastecer ou pausar itens.';
    }
    if (is_array($unresolvedAlerts) && count($unresolvedAlerts) > 0) {
        $actions[] = 'Existem alertas de ranking/preço não resolvidos: revisar itens fora do competitivo e corrigir preço/custos/estratégia.';
    }
    if (empty($actions)) {
        $actions[] = 'Rodar o relatório com uma janela maior (ex.: --days=60) e aumentar a amostra de SEO (ex.: --seo-items=10) para achar o “ponto de virada”.';
    }
    foreach ($actions as $a) {
        $lines[] = '- ' . $a;
    }
    $lines[] = '';
    $lines[] = '---';
    $lines[] = 'Observação: este relatório depende do que estiver sincronizado no banco local e do token da conta para puxar dados da API (Ads/SEO).';

    $report = implode("\n", $lines) . "\n";

    if ($outputPath) {
        $fullPath = $outputPath;
        if ($fullPath[0] !== '/' && !str_starts_with($fullPath, ROOT_PATH . '/')) {
            $fullPath = ROOT_PATH . '/' . ltrim($fullPath, '/');
        }
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($fullPath, $report);
        echo "OK: relatório salvo em {$fullPath}\n";
    } else {
        echo $report;
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Erro ao gerar raio-x: {$e->getMessage()}\n");
    exit(1);
}

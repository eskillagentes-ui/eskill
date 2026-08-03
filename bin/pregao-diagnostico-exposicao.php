#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Diagnóstico read-only da exposição (Fe) — visitas vs inventário.
 *
 * Uso:
 *   php bin/pregao-diagnostico-exposicao.php --account-id=1335 --dias=60
 *
 * Não escreve na API do ML. Gera CSV em storage/diagnostico/ e MD em docs/.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Database;
use App\Services\MercadoLivreClient;

$opts = getopt('', ['account-id:', 'dias:', 'skip-item-visits', 'json']);
$accountId = (int) ($opts['account-id'] ?? ($_ENV['PREGAO_ACCOUNT_ID'] ?? 1335));
$dias = max(14, min(90, (int) ($opts['dias'] ?? 60)));
$skipItemVisits = isset($opts['skip-item-visits']);

if ($accountId <= 0) {
    fwrite(STDERR, "Informe --account-id=N\n");
    exit(1);
}

$pdo = Database::getInstance();
$tz = new \DateTimeZone('America/Sao_Paulo');
$today = new \DateTimeImmutable('today', $tz);
$runDate = $today->format('Y-m-d');
$outDir = dirname(__DIR__) . '/storage/diagnostico/' . $runDate;
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Não foi possível criar {$outDir}\n");
    exit(1);
}

$accStmt = $pdo->prepare('SELECT id, ml_user_id, nickname FROM ml_accounts WHERE id = ?');
$accStmt->execute([$accountId]);
$account = $accStmt->fetch(PDO::FETCH_ASSOC);
if (!$account || empty($account['ml_user_id'])) {
    fwrite(STDERR, "Conta {$accountId} sem ml_user_id\n");
    exit(1);
}
$mlUserId = (string) $account['ml_user_id'];
$client = new MercadoLivreClient($accountId);

/**
 * @return never
 */
function fail(string $msg): void
{
    fwrite(STDERR, $msg . "\n");
    exit(1);
}

function sleepBackoff(int $attempt): void
{
    usleep((int) (min(8000000, 200000 * (2 ** $attempt))));
}

/**
 * GET com retry em 429.
 *
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function mlGet(MercadoLivreClient $client, string $endpoint, array $params = [], int $maxAttempts = 5): array
{
    $attempt = 0;
    while (true) {
        $attempt++;
        $data = $client->get($endpoint, $params);
        $status = (int) ($data['status'] ?? 0);
        $err = (string) ($data['error'] ?? '');
        if ($status === 429 || $err === 'too_many_requests') {
            if ($attempt >= $maxAttempts) {
                return $data;
            }
            fwrite(STDERR, "[429] backoff {$endpoint} attempt={$attempt}\n");
            sleepBackoff($attempt);
            continue;
        }
        return $data;
    }
}

function pearson(array $x, array $y): ?float
{
    $n = count($x);
    if ($n < 3 || $n !== count($y)) {
        return null;
    }
    $sx = array_sum($x);
    $sy = array_sum($y);
    $sxx = 0.0;
    $syy = 0.0;
    $sxy = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $sxx += $x[$i] * $x[$i];
        $syy += $y[$i] * $y[$i];
        $sxy += $x[$i] * $y[$i];
    }
    $num = $n * $sxy - $sx * $sy;
    $den = sqrt(($n * $sxx - $sx * $sx) * ($n * $syy - $sy * $sy));
    if ($den <= 0.0) {
        return null;
    }
    return round($num / $den, 4);
}

function csvWrite(string $path, array $headers, array $rows): void
{
    $fh = fopen($path, 'wb');
    if ($fh === false) {
        fail("Falha ao escrever {$path}");
    }
    fputcsv($fh, $headers);
    foreach ($rows as $row) {
        $line = [];
        foreach ($headers as $h) {
            $line[] = $row[$h] ?? '';
        }
        fputcsv($fh, $line);
    }
    fclose($fh);
}

fwrite(STDOUT, "[{$runDate}] Diagnóstico exposição account={$accountId} ml_user={$mlUserId} dias={$dias}\n");

// ---------------------------------------------------------------------------
// 1) Status atual (live) — fonte: /users/{id}/items/search
// ---------------------------------------------------------------------------
$statusLive = [
    'active' => null,
    'paused' => null,
    'under_review' => null,
    'closed' => null,
    'inactive' => null,
];
foreach (array_keys($statusLive) as $st) {
    $resp = mlGet($client, "/users/{$mlUserId}/items/search", [
        'status' => $st,
        'limit' => 1,
    ]);
    if (isset($resp['error'])) {
        fwrite(STDERR, "status {$st}: sem dado ({$resp['error']})\n");
        continue;
    }
    $statusLive[$st] = (int) ($resp['paging']['total'] ?? 0);
    usleep(120000);
}
fwrite(STDOUT, 'Status live: ' . json_encode($statusLive) . "\n");

// ---------------------------------------------------------------------------
// 2) Série diária de visitas (account) + vendas locais
// ---------------------------------------------------------------------------
$series = [];
for ($i = $dias - 1; $i >= 0; $i--) {
    $day = $today->modify("-{$i} days")->format('Y-m-d');
    $series[$day] = [
        'data' => $day,
        'visitas_totais' => null,
        'itens_active' => 'sem dado',
        'itens_paused' => 'sem dado',
        'itens_under_review' => 'sem dado',
        'itens_closed' => 'sem dado',
        'vendas' => 0,
        'receita' => 0.0,
        'fonte_visitas' => '',
        'fonte_status' => 'sem histórico em account_health_history',
    ];
}

fwrite(STDOUT, "Coletando visitas diárias da conta ({$dias} chamadas)...\n");
foreach (array_keys($series) as $day) {
    $resp = mlGet($client, "/users/{$mlUserId}/items_visits", [
        'date_from' => $day,
        'date_to' => $day,
    ]);
    if (isset($resp['error'])) {
        $series[$day]['fonte_visitas'] = 'erro:' . (string) $resp['error'];
    } else {
        $series[$day]['visitas_totais'] = (int) ($resp['total_visits'] ?? $resp['body']['total_visits'] ?? 0);
        $series[$day]['fonte_visitas'] = "GET /users/{$mlUserId}/items_visits?date_from={$day}&date_to={$day}";
    }
    usleep(80000);
}

// última linha: status live
$lastDay = $today->format('Y-m-d');
if (isset($series[$lastDay])) {
    $series[$lastDay]['itens_active'] = $statusLive['active'] ?? 'sem dado';
    $series[$lastDay]['itens_paused'] = $statusLive['paused'] ?? 'sem dado';
    $series[$lastDay]['itens_under_review'] = $statusLive['under_review'] ?? 'sem dado';
    $series[$lastDay]['itens_closed'] = $statusLive['closed'] ?? 'sem dado';
    $series[$lastDay]['fonte_status'] = "GET /users/{$mlUserId}/items/search?status=* (snapshot {$lastDay})";
}

$ordStmt = $pdo->prepare(
    "SELECT DATE(date_created) d, COUNT(*) vendas, COALESCE(SUM(total_amount),0) receita
     FROM ml_orders
     WHERE ml_account_id = ?
       AND date_created >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
       AND (status IS NULL OR status NOT IN ('cancelled','canceled'))
     GROUP BY DATE(date_created)"
);
$ordStmt->execute([$accountId, $dias]);
foreach ($ordStmt->fetchAll(PDO::FETCH_ASSOC) as $or) {
    $d = (string) $or['d'];
    if (isset($series[$d])) {
        $series[$d]['vendas'] = (int) $or['vendas'];
        $series[$d]['receita'] = round((float) $or['receita'], 2);
    }
}

$healthWindow = $pdo->prepare(
    'SELECT MIN(created_at) mn, MAX(created_at) mx, COUNT(*) c
     FROM account_health_history WHERE account_id = ?'
);
$healthWindow->execute([$accountId]);
$hw = $healthWindow->fetch(PDO::FETCH_ASSOC) ?: ['mn' => null, 'mx' => null, 'c' => 0];

// Inflection: maior queda dia-a-dia (média móvel 3d)
$visitPoints = [];
foreach ($series as $row) {
    if ($row['visitas_totais'] !== null) {
        $visitPoints[] = ['data' => $row['data'], 'v' => (int) $row['visitas_totais']];
    }
}
$inflection = null;
$maxDrop = 0.0;
for ($i = 3; $i < count($visitPoints); $i++) {
    $prev = ($visitPoints[$i - 3]['v'] + $visitPoints[$i - 2]['v'] + $visitPoints[$i - 1]['v']) / 3.0;
    $cur = (float) $visitPoints[$i]['v'];
    $drop = $prev - $cur;
    if ($drop > $maxDrop) {
        $maxDrop = $drop;
        $inflection = [
            'data' => $visitPoints[$i]['data'],
            'visitas' => $cur,
            'media_3d_anterior' => round($prev, 1),
            'queda_vs_media3d' => round($drop, 1),
        ];
    }
}

// Correlação visitas × active: só possível se houver série de active — não há
$corrVisitsActive = null;
$corrNote = 'sem dado: account_health_history não armazena contagens diárias de status (active/paused/…); só overall_score. Correlação visitas×active não pode ser calculada na janela histórica.';

// Correlação auxiliar: visitas × vendas (mesmos dias com ambos)
$vx = [];
$vy = [];
foreach ($series as $row) {
    if ($row['visitas_totais'] !== null) {
        $vx[] = (float) $row['visitas_totais'];
        $vy[] = (float) $row['vendas'];
    }
}
$corrVisitsSales = pearson($vx, $vy);

$avg7 = null;
$avg28prior = null;
$visits7sum = 0;
$visits28sum = 0;
$n7 = 0;
$n28 = 0;
foreach ($visitPoints as $p) {
    $dayDt = new \DateTimeImmutable($p['data'], $tz);
    $age = (int) floor(($today->getTimestamp() - $dayDt->getTimestamp()) / 86400);
    if ($age >= 0 && $age <= 6) {
        $visits7sum += $p['v'];
        $n7++;
    } elseif ($age >= 7 && $age <= 34) {
        $visits28sum += $p['v'];
        $n28++;
    }
}
if ($n7 > 0) {
    $avg7 = round($visits7sum / $n7, 2);
}
if ($n28 > 0) {
    $avg28prior = round($visits28sum / $n28, 2);
}
$quedaTotalDia = ($avg7 !== null && $avg28prior !== null) ? round($avg28prior - $avg7, 2) : null;

csvWrite($outDir . '/serie-diaria.csv', [
    'data', 'visitas_totais', 'itens_active', 'itens_paused', 'itens_under_review', 'itens_closed',
    'vendas', 'receita', 'fonte_visitas', 'fonte_status',
], array_values($series));

// ---------------------------------------------------------------------------
// 3) Decomposição por item
// ---------------------------------------------------------------------------
$itemsStmt = $pdo->prepare(
    'SELECT ml_item_id, title, status, available_quantity, price, sold_quantity,
            JSON_UNQUOTE(JSON_EXTRACT(data, \'$.sub_status\')) AS sub_status_json,
            JSON_EXTRACT(data, \'$.tags\') AS tags_json
     FROM items
     WHERE account_id = ?
       AND ml_item_id IS NOT NULL AND ml_item_id != \'\''
);
$itemsStmt->execute([$accountId]);
$localItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

/** @var array<string, array<string, mixed>> $byId */
$byId = [];
foreach ($localItems as $it) {
    $id = (string) $it['ml_item_id'];
    $sub = [];
    if (!empty($it['sub_status_json'])) {
        $decoded = json_decode((string) $it['sub_status_json'], true);
        if (is_array($decoded)) {
            $sub = $decoded;
        } else {
            $sub = [trim((string) $it['sub_status_json'], "\"[] ")];
        }
    }
    $byId[$id] = [
        'mlb_id' => $id,
        'titulo' => (string) ($it['title'] ?? ''),
        'status' => (string) ($it['status'] ?? ''),
        'available_quantity' => $it['available_quantity'] !== null ? (int) $it['available_quantity'] : null,
        'price' => $it['price'] !== null ? (float) $it['price'] : null,
        'sold_quantity' => $it['sold_quantity'] !== null ? (int) $it['sold_quantity'] : null,
        'sub_status' => $sub,
    ];
}

// Refresh status live via multiget em lotes de 20 (active+paused prioritários)
$refreshIds = array_keys($byId);
fwrite(STDOUT, 'Refresh status multiget (' . count($refreshIds) . " itens)...\n");
foreach (array_chunk($refreshIds, 20) as $chunk) {
    $details = $client->getMultiItemDetails($chunk, [
        'id', 'title', 'status', 'sub_status', 'available_quantity', 'price', 'sold_quantity', 'tags',
    ]);
    foreach ($chunk as $id) {
        $body = $details[$id] ?? null;
        if (!is_array($body)) {
            continue;
        }
        $byId[$id]['status'] = (string) ($body['status'] ?? $byId[$id]['status']);
        $byId[$id]['titulo'] = (string) ($body['title'] ?? $byId[$id]['titulo']);
        $byId[$id]['available_quantity'] = isset($body['available_quantity']) ? (int) $body['available_quantity'] : $byId[$id]['available_quantity'];
        $byId[$id]['price'] = isset($body['price']) ? (float) $body['price'] : $byId[$id]['price'];
        $byId[$id]['sold_quantity'] = isset($body['sold_quantity']) ? (int) $body['sold_quantity'] : $byId[$id]['sold_quantity'];
        if (isset($body['sub_status']) && is_array($body['sub_status'])) {
            $byId[$id]['sub_status'] = $body['sub_status'];
        }
    }
    usleep(150000);
}

$from7 = $today->modify('-7 days')->format('Y-m-d');
$to7 = $today->format('Y-m-d');
$from28 = $today->modify('-35 days')->format('Y-m-d');
$to28 = $today->modify('-7 days')->format('Y-m-d');

/**
 * Visitas diárias via time_window (read-only).
 * /visits/items com date_from/date_to mostrou totais inconsistentes (mesmo N em janelas distintas) — não usar.
 *
 * @return array{v7: int|null, v28: int|null, avg7: float|null, avg28: float|null, error?: string}
 */
function fetchItemVisitsWindows(MercadoLivreClient $client, string $itemId, \DateTimeImmutable $today, \DateTimeZone $tz): array
{
    $resp = mlGet($client, "/items/{$itemId}/visits/time_window", [
        'last' => 35,
        'unit' => 'day',
    ]);
    if (isset($resp['error'])) {
        return [
            'v7' => null,
            'v28' => null,
            'avg7' => null,
            'avg28' => null,
            'error' => (string) ($resp['message'] ?? $resp['error']),
        ];
    }
    $results = $resp['results'] ?? ($resp['body']['results'] ?? []);
    if (!is_array($results)) {
        $results = [];
    }
    $sum7 = 0;
    $sum28 = 0;
    foreach ($results as $row) {
        if (!is_array($row)) {
            continue;
        }
        $dateRaw = (string) ($row['date'] ?? '');
        $day = substr($dateRaw, 0, 10);
        if ($day === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            continue;
        }
        $dayDt = new \DateTimeImmutable($day, $tz);
        $age = (int) floor(($today->getTimestamp() - $dayDt->getTimestamp()) / 86400);
        $v = (int) ($row['total'] ?? 0);
        if ($age >= 0 && $age <= 6) {
            $sum7 += $v;
        } elseif ($age >= 7 && $age <= 34) {
            $sum28 += $v;
        }
    }
    return [
        'v7' => $sum7,
        'v28' => $sum28,
        'avg7' => round($sum7 / 7.0, 3),
        'avg28' => round($sum28 / 28.0, 3),
    ];
}

$itemRows = [];
if (!$skipItemVisits) {
    // Prioriza active/paused/inactive + waiting_for_patch; inclui closed recentes com stock
    $priority = [];
    foreach ($byId as $id => $it) {
        $st = strtolower($it['status']);
        $sub = array_map('strval', $it['sub_status']);
        $hasPatch = in_array('waiting_for_patch', $sub, true);
        if (in_array($st, ['active', 'paused', 'inactive', 'under_review'], true) || $hasPatch) {
            $priority[] = $id;
        }
    }
    // Completa com closed waiting_for_patch only (evita varrer closed mortos)
    foreach ($byId as $id => $it) {
        if (!in_array($id, $priority, true) && in_array('waiting_for_patch', $it['sub_status'], true)) {
            $priority[] = $id;
        }
    }
    $priority = array_values(array_unique($priority));
    if (count($priority) > 250) {
        $priority = array_slice($priority, 0, 250);
    }

    fwrite(STDOUT, 'Coletando visitas por item via time_window (' . count($priority) . " itens)...\n");
    $done = 0;
    foreach ($priority as $id) {
        $win = fetchItemVisitsWindows($client, $id, $today, $tz);
        usleep(90000);

        $v7 = $win['v7'];
        $v28 = $win['v28'];
        $avg7i = $win['avg7'];
        $avg28i = $win['avg28'];
        $delta = ($avg7i !== null && $avg28i !== null) ? round($avg7i - $avg28i, 3) : null;

        $st = strtolower($byId[$id]['status']);
        $sub = $byId[$id]['sub_status'];
        $stock = $byId[$id]['available_quantity'];
        $motivo = 'sem dado';
        if (isset($win['error'])) {
            $motivo = 'sem dado:visits_api:' . $win['error'];
        } elseif (in_array($st, ['paused', 'closed', 'inactive', 'under_review'], true)) {
            if (in_array('paused_by_seller', $sub, true)) {
                $motivo = 'status:paused_by_seller';
            } elseif (in_array('waiting_for_patch', $sub, true)) {
                $motivo = 'status:waiting_for_patch';
            } elseif (in_array('deleted', $sub, true)) {
                $motivo = 'status:deleted';
            } else {
                $motivo = 'status:' . $st;
            }
            if ($stock !== null && $stock <= 0) {
                $motivo .= '+sem_estoque';
            }
        } elseif ($st === 'active' && $delta !== null && $delta < -0.05) {
            $motivo = 'queda_em_ativo';
            if ($stock !== null && $stock <= 0) {
                $motivo = 'sem_estoque';
            }
        } elseif ($st === 'active') {
            $motivo = 'ativo_estavel_ou_alta';
        }

        $balde = null;
        if ($delta !== null && $delta < -0.05) {
            if (in_array($st, ['paused', 'closed', 'inactive', 'under_review'], true)) {
                $balde = 'A';
            } elseif ($st === 'active') {
                $balde = 'B';
            }
        }

        // Para indisponíveis: perda ≈ média 28d (tráfego que sumiu por não estar active)
        $perda = 0.0;
        if ($balde === 'A' && $avg28i !== null && $avg28i > 0) {
            // Se ainda tem visitas residuais no 7d, perda = max(0, avg28 - avg7)
            $perda = round(max(0.0, $avg28i - ($avg7i ?? 0.0)), 3);
            if ($perda <= 0 && $avg28i > 0 && ($avg7i ?? 0) <= 0.05) {
                $perda = $avg28i;
            }
        } elseif ($balde === 'B' && $delta !== null && $delta < 0) {
            $perda = round(-$delta, 3);
        } elseif (
            in_array($st, ['paused', 'closed', 'inactive', 'under_review'], true)
            && $avg28i !== null
            && $avg28i > 0
            && ($avg7i === null || $avg7i < $avg28i)
        ) {
            $balde = 'A';
            $perda = round(max(0.0, $avg28i - ($avg7i ?? 0.0)), 3);
        }

        $itemRows[] = [
            'mlb_id' => $id,
            'titulo' => mb_substr($byId[$id]['titulo'], 0, 120),
            'status_atual' => $byId[$id]['status'],
            'sub_status' => implode(',', $sub),
            'stock' => $stock ?? '',
            'visitas_7d' => $v7 ?? 'sem dado',
            'visitas_28d_prior' => $v28 ?? 'sem dado',
            'visitas_media_7d' => $avg7i ?? 'sem dado',
            'visitas_media_28d' => $avg28i ?? 'sem dado',
            'delta_media_dia' => $delta ?? 'sem dado',
            'perda_media_dia' => $perda,
            'balde' => $balde ?? '',
            'motivo_provavel' => $motivo,
            'fonte_visitas' => "GET /items/{$id}/visits/time_window?last=35&unit=day",
        ];
        $done++;
        if ($done % 40 === 0) {
            fwrite(STDOUT, "  ... {$done}/" . count($priority) . "\n");
        }
    }
} else {
    fwrite(STDOUT, "skip-item-visits: pulando decomposição por item\n");
}

usort($itemRows, static function (array $a, array $b): int {
    return ($b['perda_media_dia'] <=> $a['perda_media_dia']);
});

$baldeA = 0.0;
$baldeB = 0.0;
$baldeACount = 0;
$baldeBCount = 0;
foreach ($itemRows as $r) {
    if ($r['balde'] === 'A') {
        $baldeA += (float) $r['perda_media_dia'];
        $baldeACount++;
    } elseif ($r['balde'] === 'B') {
        $baldeB += (float) $r['perda_media_dia'];
        $baldeBCount++;
    }
}
$baldeA = round($baldeA, 2);
$baldeB = round($baldeB, 2);
$baldeSum = round($baldeA + $baldeB, 2);

$top20 = array_slice($itemRows, 0, 20);
csvWrite($outDir . '/top20-perda.csv', [
    'mlb_id', 'titulo', 'status_atual', 'sub_status', 'stock',
    'visitas_media_28d', 'visitas_media_7d', 'delta_media_dia', 'perda_media_dia',
    'balde', 'motivo_provavel', 'fonte_visitas',
], $top20);

csvWrite($outDir . '/itens-perda-completa.csv', [
    'mlb_id', 'titulo', 'status_atual', 'sub_status', 'stock',
    'visitas_7d', 'visitas_28d_prior', 'visitas_media_7d', 'visitas_media_28d',
    'delta_media_dia', 'perda_media_dia', 'balde', 'motivo_provavel', 'fonte_visitas',
], $itemRows);

// ---------------------------------------------------------------------------
// 4) waiting_for_patch / health/actions
// ---------------------------------------------------------------------------
$patchItems = [];
foreach ($byId as $id => $it) {
    if (in_array('waiting_for_patch', $it['sub_status'], true)) {
        $patchItems[$id] = $it;
    }
}
$patchReport = [];
foreach ($patchItems as $id => $it) {
    $actions = mlGet($client, "/items/{$id}/health/actions");
    usleep(120000);
    $actionSummary = 'sem dado';
    if (isset($actions['error'])) {
        $actionSummary = 'API erro: ' . (string) ($actions['message'] ?? $actions['error']);
    } elseif (!empty($actions['actions']) && is_array($actions['actions'])) {
        $bits = [];
        foreach ($actions['actions'] as $a) {
            $bits[] = ($a['id'] ?? $a['action'] ?? '?') . ':' . ($a['name'] ?? $a['wording'] ?? json_encode($a));
        }
        $actionSummary = implode(' | ', $bits);
    } elseif (is_array($actions) && $actions !== []) {
        $actionSummary = substr(json_encode($actions, JSON_UNESCAPED_UNICODE), 0, 400);
    }

    $rowVis = null;
    foreach ($itemRows as $ir) {
        if ($ir['mlb_id'] === $id) {
            $rowVis = $ir;
            break;
        }
    }
    $patchReport[] = [
        'mlb_id' => $id,
        'titulo' => mb_substr($it['titulo'], 0, 100),
        'status' => $it['status'],
        'sub_status' => implode(',', $it['sub_status']),
        'visitas_media_28d' => $rowVis['visitas_media_28d'] ?? 'sem dado',
        'visitas_media_7d' => $rowVis['visitas_media_7d'] ?? 'sem dado',
        'health_actions' => $actionSummary,
        'fonte_actions' => "GET /items/{$id}/health/actions",
    ];
}
csvWrite($outDir . '/waiting-for-patch.csv', [
    'mlb_id', 'titulo', 'status', 'sub_status', 'visitas_media_28d', 'visitas_media_7d', 'health_actions', 'fonte_actions',
], $patchReport);

// ---------------------------------------------------------------------------
// 5) Pausados recuperáveis
// ---------------------------------------------------------------------------
$pausedSeller = 0;
$pausedSellerVisita = 0.0;
$pausedOther = 0;
$pausedOtherVisita = 0.0;
$pausedNoStock = 0;
foreach ($itemRows as $r) {
    if (strtolower((string) $r['status_atual']) !== 'paused') {
        continue;
    }
    $subs = explode(',', (string) $r['sub_status']);
    $avg28 = is_numeric($r['visitas_media_28d']) ? (float) $r['visitas_media_28d'] : 0.0;
    if (in_array('paused_by_seller', $subs, true)) {
        $pausedSeller++;
        $pausedSellerVisita += $avg28;
    } else {
        $pausedOther++;
        $pausedOtherVisita += $avg28;
    }
    if ($r['stock'] !== '' && (int) $r['stock'] <= 0) {
        $pausedNoStock++;
    }
}
$pausedSellerVisita = round($pausedSellerVisita, 2);
$pausedOtherVisita = round($pausedOtherVisita, 2);

// ---------------------------------------------------------------------------
// 6) Plano de recuperação (somente lista)
// ---------------------------------------------------------------------------
$plan = [];
if ($pausedSeller > 0 && $pausedSellerVisita > 0) {
    $plan[] = [
        'prioridade' => 1,
        'acao' => 'Reativar anúncios paused_by_seller com estoque > 0 (após aprovação humana)',
        'visita_dia_recuperavel' => $pausedSellerVisita,
        'esforco' => 'médio (lote; revisão de preço/estoque antes)',
        'score' => $pausedSellerVisita / 2.0,
        'itens' => "{$pausedSeller} anúncios paused_by_seller",
        'passo' => 'Listar MLB paused_by_seller → validar estoque/preço → reativar via painel ML (fora deste script)',
    ];
}
$patchRecoverable = [];
foreach ($patchReport as $p) {
    if (!str_contains($p['sub_status'], 'deleted') && is_numeric($p['visitas_media_28d'])) {
        $patchRecoverable[] = $p;
    }
}
if ($patchRecoverable !== []) {
    $v = 0.0;
    foreach ($patchRecoverable as $p) {
        $v += (float) $p['visitas_media_28d'];
    }
    $plan[] = [
        'prioridade' => 2,
        'acao' => 'Resolver waiting_for_patch (aceitar/ajustar catálogo) nos itens ainda não deleted',
        'visita_dia_recuperavel' => round($v, 2),
        'esforco' => 'alto (decisão de catálogo por item)',
        'score' => round($v / 3.0, 2),
        'itens' => implode(', ', array_column($patchRecoverable, 'mlb_id')),
        'passo' => 'Abrir cada MLB → health/actions → cumprir ação OPT_OBEY/patch pedida pelo ML',
    ];
}
// Top active losers
$activeLosers = array_values(array_filter($top20, static fn ($r) => ($r['balde'] ?? '') === 'B'));
if ($activeLosers !== []) {
    $v = 0.0;
    foreach (array_slice($activeLosers, 0, 5) as $r) {
        $v += (float) $r['perda_media_dia'];
    }
    $plan[] = [
        'prioridade' => 3,
        'acao' => 'Investigar top ativos com queda (preço/CTR/estoque) — sem alterar ainda',
        'visita_dia_recuperavel' => round($v, 2),
        'esforco' => 'médio (análise por SKU)',
        'score' => round($v / 2.5, 2),
        'itens' => implode(', ', array_column(array_slice($activeLosers, 0, 5), 'mlb_id')),
        'passo' => 'Comparar preço/promo vs. histórico interno e health do item; só então propor ajuste',
    ];
}
usort($plan, static fn ($a, $b) => $b['score'] <=> $a['score']);
$pri = 1;
foreach ($plan as &$p) {
    $p['prioridade'] = $pri++;
}
unset($p);

// Hipótese
$hipotese = 'parcialmente confirmada';
$hipoteseTxt = '';
if ($quedaTotalDia === null) {
    $hipotese = 'inconclusiva';
    $hipoteseTxt = 'Sem série completa de visitas para quantificar a queda.';
} elseif ($baldeSum <= 0) {
    $hipotese = 'inconclusiva';
    $hipoteseTxt = 'Decomposição por item não capturou perda positiva (API visits ou amostra).';
} else {
    $shareA = $baldeA / max($baldeSum, 0.0001);
    if ($shareA >= 0.6) {
        $hipotese = 'confirmada';
        $hipoteseTxt = sprintf(
            'Balde A (indisponíveis) explica %.0f%% da perda decomposta (%.2f de %.2f visita/dia).',
            $shareA * 100,
            $baldeA,
            $baldeSum
        );
    } elseif ($shareA >= 0.35) {
        $hipotese = 'parcialmente confirmada';
        $hipoteseTxt = sprintf(
            'Balde A = %.0f%% e Balde B = %.0f%% da perda decomposta — inventário e ativos caíram juntos.',
            $shareA * 100,
            (1 - $shareA) * 100
        );
    } else {
        $hipotese = 'refutada (na amostra)';
        $hipoteseTxt = sprintf(
            'Maior parte da perda está em itens ainda active (Balde B=%.2f vs A=%.2f).',
            $baldeB,
            $baldeA
        );
    }
}

$gapNote = '';
if ($quedaTotalDia !== null && $baldeSum > 0) {
    $gap = round($quedaTotalDia - $baldeSum, 2);
    if (abs($gap) > max(5.0, abs($quedaTotalDia) * 0.25)) {
        $gapNote = sprintf(
            'Diferença queda_conta (%.2f) − (A+B=%.2f) = %.2f visita/dia. Possíveis causas: itens closed fora da amostra de visits, visitas de itens não listados localmente, ou sobreposição de janelas/API. Não forçar fechamento.',
            $quedaTotalDia,
            $baldeSum,
            $gap
        );
    } else {
        $gapNote = sprintf(
            'A+B (%.2f) ≈ queda média da conta (%.2f) — diferença residual %.2f visita/dia.',
            $baldeSum,
            $quedaTotalDia,
            round($quedaTotalDia - $baldeSum, 2)
        );
    }
}

// ---------------------------------------------------------------------------
// 7) Markdown
// ---------------------------------------------------------------------------
$mdPath = dirname(__DIR__) . "/docs/diagnostico-exposicao-{$runDate}.md";
$md = [];
$md[] = "# Diagnóstico de exposição (Fe) — conta {$accountId}";
$md[] = '';
$md[] = "- **Gerado em:** {$runDate} (America/Sao_Paulo)";
$md[] = '- **Script:** `bin/pregao-diagnostico-exposicao.php` (read-only; sem escrita ML)';
$md[] = "- **Nickname / ml_user_id:** " . ($account['nickname'] ?? '?') . " / {$mlUserId}";
$md[] = '';
$md[] = '## Resumo executivo';
$md[] = '';
$md[] = sprintf(
    '1. Hipótese principal (queda por inventário indisponível): **%s** — %s',
    $hipotese,
    $hipoteseTxt
);
$md[] = sprintf(
    '2. Visitas/dia: média 7d = **%s** · média 28d anteriores = **%s** · queda = **%s** visita/dia.',
    $avg7 === null ? 'sem dado' : (string) $avg7,
    $avg28prior === null ? 'sem dado' : (string) $avg28prior,
    $quedaTotalDia === null ? 'sem dado' : (string) $quedaTotalDia
);
$md[] = sprintf(
    '3. Decomposição: Balde A (indisponíveis) = **%.2f** visita/dia (%d itens) · Balde B (ativos) = **%.2f** (%d itens).',
    $baldeA,
    $baldeACount,
    $baldeB,
    $baldeBCount
);
$md[] = sprintf(
    '4. Pausados live: **%s** · todos os amostrados com visitas e `paused_by_seller` somam **%.2f** visita/dia recuperável (estoque>0: %d com stock zero).',
    $statusLive['paused'] === null ? 'sem dado' : (string) $statusLive['paused'],
    $pausedSellerVisita,
    $pausedNoStock
);
$md[] = sprintf(
    '5. Inflexão (maior queda vs média móvel 3d): **%s**. Status histórico diário: **sem dado** em `account_health_history` (só scores; janela %s → %s, %d rows).',
    $inflection['data'] ?? 'sem dado',
    $hw['mn'] ?? '?',
    $hw['mx'] ?? '?',
    (int) ($hw['c'] ?? 0)
);
$md[] = '';
$md[] = '## Fontes (rastreabilidade)';
$md[] = '';
$md[] = '| Dado | Fonte |';
$md[] = '|---|---|';
$md[] = "| Visitas diárias conta | `GET /users/{$mlUserId}/items_visits?date_from=D&date_to=D` |";
$md[] = '| Visitas por item | `GET /items/{mlb}/visits/time_window?last=35&unit=day` (somatório 7d vs dias 8–35). **Não** usar `/visits/items` com date_from/date_to — totais inconsistentes entre janelas |';
$md[] = "| Status live | `GET /users/{$mlUserId}/items/search?status=` |";
$md[] = '| Status/sub_status local | tabela `items` (account_id) + refresh `GET /items?ids=` lotes 20 |';
$md[] = '| Vendas/receita | `ml_orders` WHERE ml_account_id |';
$md[] = '| Health history | `account_health_history` (scores only) |';
$md[] = '| Ações catálogo | `GET /items/{id}/health/actions` |';
$md[] = '';
$md[] = '## Snapshot de status (agora)';
$md[] = '';
$md[] = '```json';
$md[] = json_encode($statusLive, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
$md[] = '```';
$md[] = '';
$md[] = 'Nota: `closed` via search pode retornar 0 mesmo com closed locais (API seller search). Contagem local `items`: ver query `SELECT status, COUNT(*) FROM items WHERE account_id=...`.';
$md[] = '';
$md[] = '## Correlação';
$md[] = '';
$md[] = "- **visitas × itens_active (histórica):** {$corrNote}";
$md[] = '- **visitas × vendas (mesmos dias):** ' . ($corrVisitsSales === null ? 'sem dado' : (string) $corrVisitsSales) . ' (Pearson; não testa a hipótese de inventário)';
$md[] = '- **Data de inflexão (série de visitas):** ' . ($inflection === null ? 'sem dado' : json_encode($inflection, JSON_UNESCAPED_UNICODE));
$md[] = '';
$md[] = 'A ausência de série histórica de `itens_active` **impede** confirmar estatisticamente a correlação visitas↔active. A hipótese é julgada pela decomposição A/B e pelo perfil dos pausados (`paused_by_seller`).';
$md[] = '';
$md[] = '## Baldes';
$md[] = '';
$md[] = "| Balde | Definição | Itens | Visita/dia perdida |";
$md[] = '|---|---|---:|---:|';
$md[] = "| A | indisponível agora (paused/closed/inactive/under_review) com delta média < 0 | {$baldeACount} | {$baldeA} |";
$md[] = "| B | active com delta média < 0 | {$baldeBCount} | {$baldeB} |";
$md[] = "| A+B | | | {$baldeSum} |";
$md[] = '';
$md[] = $gapNote !== '' ? $gapNote : 'sem dado para fechar A+B vs queda da conta';
$md[] = '';
$md[] = '## Top 20 perda (visita/dia)';
$md[] = '';
$md[] = '| mlb_id | status | média 28d | média 7d | delta | balde | motivo |';
$md[] = '|---|---|---:|---:|---:|---|---|';
foreach ($top20 as $r) {
    $md[] = sprintf(
        '| %s | %s | %s | %s | %s | %s | %s |',
        $r['mlb_id'],
        $r['status_atual'],
        $r['visitas_media_28d'],
        $r['visitas_media_7d'],
        $r['delta_media_dia'],
        $r['balde'] ?: '-',
        $r['motivo_provavel']
    );
}
$md[] = '';
$md[] = 'CSV completo: `storage/diagnostico/' . $runDate . '/top20-perda.csv`';
$md[] = '';
$md[] = '## waiting_for_patch / health/actions';
$md[] = '';
if ($patchReport === []) {
    $md[] = 'Nenhum item local com `sub_status` contendo `waiting_for_patch`.';
} else {
    $md[] = '| mlb_id | status | sub_status | média 28d | health/actions |';
    $md[] = '|---|---|---|---:|---|';
    foreach ($patchReport as $p) {
        $md[] = sprintf(
            '| %s | %s | %s | %s | %s |',
            $p['mlb_id'],
            $p['status'],
            $p['sub_status'],
            $p['visitas_media_28d'],
            str_replace('|', '/', $p['health_actions'])
        );
    }
}
$md[] = '';
$md[] = 'Obs.: `under_review` via search live = ' . ($statusLive['under_review'] === null ? 'sem dado' : (string) $statusLive['under_review']) . '. Itens com `waiting_for_patch`+`deleted` não são o mesmo conjunto que under_review operacional.';
$md[] = '';
$md[] = '## Pausados: recuperáveis vs bloqueio ML';
$md[] = '';
$md[] = "| Grupo | Qtd (amostra com visits) | Visita/dia (média 28d) |";
$md[] = '|---|---:|---:|';
$md[] = "| `paused_by_seller` (recuperável pelo seller) | {$pausedSeller} | {$pausedSellerVisita} |";
$md[] = "| outros sub_status / bloqueio | {$pausedOther} | {$pausedOtherVisita} |";
$md[] = "| paused com estoque 0 | {$pausedNoStock} | — |";
$md[] = '';
$md[] = '## Plano de recuperação (NÃO EXECUTAR)';
$md[] = '';
foreach ($plan as $p) {
    $md[] = sprintf(
        '%d. **%s** — visita/dia≈%.2f · esforço=%s · itens: %s  \n   Passo: %s',
        $p['prioridade'],
        $p['acao'],
        $p['visita_dia_recuperavel'],
        $p['esforco'],
        $p['itens'],
        $p['passo']
    );
}
$md[] = '';
$md[] = '## Conclusão da hipótese';
$md[] = '';
$md[] = "**Veredito: {$hipotese}.** {$hipoteseTxt}";
$md[] = '';
$md[] = 'Hipóteses alternativas:';
$md[] = '- **Sazonalidade:** série diária em CSV — inspecionar padrão semanal; não modelado formalmente aqui.';
$md[] = '- **Concentração em poucos SKUs:** ver top 20 — se poucos MLB dominam `perda_media_dia`, a queda é concentrada.';
$md[] = '- **Preço/promo/CTR:** histórico de preço/CTR **sem dado** nesta corrida (não há série de CTR local citada); marcado como lacuna.';
$md[] = '- **Variação natural do baseline:** Fe=0,70 está dentro do clamp; a queda ~30% vs baseline 28d é material, não ruído trivial.';
$md[] = '';
$md[] = '## Artefatos';
$md[] = '';
$md[] = '- `' . $mdPath . '`';
$md[] = '- `storage/diagnostico/' . $runDate . '/serie-diaria.csv`';
$md[] = '- `storage/diagnostico/' . $runDate . '/top20-perda.csv`';
$md[] = '- `storage/diagnostico/' . $runDate . '/itens-perda-completa.csv`';
$md[] = '- `storage/diagnostico/' . $runDate . '/waiting-for-patch.csv`';
$md[] = '';

file_put_contents($mdPath, implode("\n", $md) . "\n");

$summary = [
    'account_id' => $accountId,
    'hipotese' => $hipotese,
    'avg7' => $avg7,
    'avg28prior' => $avg28prior,
    'queda_total_dia' => $quedaTotalDia,
    'balde_a' => $baldeA,
    'balde_b' => $baldeB,
    'paused_seller_visita_dia' => $pausedSellerVisita,
    'inflection' => $inflection,
    'status_live' => $statusLive,
    'plan_top' => $plan[0] ?? null,
    'md' => $mdPath,
    'csv_dir' => $outDir,
];

file_put_contents($outDir . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

fwrite(STDOUT, "OK md={$mdPath}\n");
fwrite(STDOUT, "OK csv_dir={$outDir}\n");
fwrite(STDOUT, 'RESUMO ' . json_encode([
    'hipotese' => $hipotese,
    'queda_dia' => $quedaTotalDia,
    'A' => $baldeA,
    'B' => $baldeB,
    'paused_recuperavel_visita_dia' => $pausedSellerVisita,
], JSON_UNESCAPED_UNICODE) . "\n");

if (isset($opts['json'])) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

exit(0);

#!/usr/bin/env php
<?php
/**
 * Coletor local de rank (T1b) — roda no COMPUTADOR DO USUÁRIO (IP residencial).
 *
 * Fluxo (somente API oficial api.mercadolibre.com — SEM scraping HTML, SEM token ML):
 *  1) GET  {SERVER}/api/rank/assignments?key=...  → lista de keywords
 *  2) GET  https://api.mercadolibre.com/sites/MLB/search?q=... (anônimo, 1 req/2s)
 *  3) POST {SERVER}/api/rank/ingest  (mesma chave + HMAC do body)
 *
 * Uso:
 *   php rank-collector.php --server=https://eskill.com.br --key=SECRETO [--dry]
 *   RANK_COLLECTOR_KEY=... RANK_COLLECTOR_SERVER=... php rank-collector.php
 *
 * Agendar 1×/dia (cron Linux ou Task Scheduler Windows). Máx 30 keywords.
 */
declare(strict_types=1);

$opts = getopt('', ['server:', 'key:', 'hmac:', 'account-id:', 'dry', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Uso: php rank-collector.php --server=URL --key=CHAVE [--hmac=SEGredo] [--dry]\n");
    exit(0);
}

$server = rtrim((string) ($opts['server'] ?? getenv('RANK_COLLECTOR_SERVER') ?: 'https://eskill.com.br'), '/');
$key = (string) ($opts['key'] ?? getenv('RANK_COLLECTOR_KEY') ?: '');
$hmacSecret = (string) ($opts['hmac'] ?? getenv('RANK_COLLECTOR_HMAC_SECRET') ?: $key);
$accountId = (int) ($opts['account-id'] ?? getenv('RANK_COLLECTOR_ACCOUNT_ID') ?: 1335);
$dry = array_key_exists('dry', $opts);
$ua = 'SEO-Optimizer-RankCollector/1.0 (+https://eskill.com.br; local-residential)';

if ($key === '' || strlen($key) < 16) {
    fwrite(STDERR, "Erro: informe --key ou RANK_COLLECTOR_KEY (>=16 chars).\n");
    exit(2);
}

/** HTTP JSON simples via cURL. */
function http_json(string $method, string $url, ?string $body, array $headers, string $ua): array
{
    $ch = curl_init($url);
    $hdrs = array_merge(['Accept: application/json', 'User-Agent: ' . $ua], $headers);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $hdrs,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return [
        'status' => $code,
        'error' => $err !== '' ? $err : null,
        'body' => is_array($decoded) ? $decoded : null,
        'raw' => is_string($raw) ? $raw : '',
    ];
}

$day = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
$assignUrl = $server . '/api/rank/assignments?key=' . rawurlencode($key)
    . '&account_id=' . $accountId . '&max=30';

echo "[1] Buscando assignments em {$server} (account={$accountId})...\n";
$assign = http_json('GET', $assignUrl, null, ['X-Rank-Key: ' . $key], $ua);
if ($assign['status'] !== 200 || !is_array($assign['body']['data']['assignments'] ?? null)) {
    fwrite(STDERR, "Falha assignments HTTP {$assign['status']}: " . substr($assign['raw'], 0, 200) . "\n");
    exit(1);
}
$assignments = $assign['body']['data']['assignments'];
echo '    ' . count($assignments) . " keywords.\n";

$ok = 0;
$fail = 0;
foreach ($assignments as $i => $row) {
    $mlb = strtoupper((string) ($row['mlb_id'] ?? ''));
    $kw = (string) ($row['keyword'] ?? '');
    if ($mlb === '' || $kw === '') {
        continue;
    }

    // Rate limit conservador: 1 req / 2s (somente quando realmente consulta a API)
    if ($i > 0 && !$dry) {
        usleep(2_000_000);
    }

    $searchUrl = 'https://api.mercadolibre.com/sites/MLB/search?q=' . rawurlencode($kw) . '&limit=50';
    echo '[2] Search API oficial q="' . $kw . '" mlb=' . $mlb . ($dry ? ' (dry)' : '') . "...\n";

    if ($dry) {
        echo "    DRY: consultaria {$searchUrl} e faria POST /api/rank/ingest\n";
        $ok++;
        continue;
    }

    $search = http_json('GET', $searchUrl, null, [], $ua);
    $position = null;
    $pagePos = null;
    $total = null;
    $err = null;
    if ($search['status'] === 200 && is_array($search['body']['results'] ?? null)) {
        $total = (int) ($search['body']['paging']['total'] ?? 0);
        foreach ($search['body']['results'] as $idx => $item) {
            if (strtoupper((string) ($item['id'] ?? '')) === $mlb) {
                $pagePos = $idx + 1;
                $position = $pagePos;
                break;
            }
        }
        if ($position === null) {
            $err = 'not_in_top';
        }
    } else {
        $err = 'http_' . $search['status'];
    }

    $payloadArr = [
        'account_id' => $accountId,
        'mlb_id' => $mlb,
        'keyword' => $kw,
        'position' => $position,
        'page' => 1,
        'page_position' => $pagePos,
        'total_results' => $total,
        'error' => $err,
        'day' => $day,
        'position_source' => 'proxy',
    ];
    $body = json_encode($payloadArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sig = hash_hmac('sha256', (string) $body, $hmacSecret);
    $ingest = http_json(
        'POST',
        $server . '/api/rank/ingest?key=' . rawurlencode($key),
        (string) $body,
        [
            'Content-Type: application/json',
            'X-Rank-Key: ' . $key,
            'X-Rank-Signature: ' . $sig,
        ],
        $ua
    );
    if ($ingest['status'] >= 200 && $ingest['status'] < 300) {
        echo "    ingest OK position=" . ($position ?? 'null') . "\n";
        $ok++;
    } else {
        echo "    ingest FAIL HTTP {$ingest['status']}: " . substr($ingest['raw'], 0, 160) . "\n";
        $fail++;
    }
}

echo "[3] Concluído ok={$ok} fail={$fail} dry=" . ($dry ? 'yes' : 'no') . "\n";
exit($fail > 0 ? 1 : 0);

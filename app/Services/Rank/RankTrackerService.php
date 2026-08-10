<?php

declare(strict_types=1);

namespace App\Services\Rank;

use App\Database;
use App\Services\MercadoLivreClient;
use PDO;
use Throwable;

/**
 * Rank tracker orgânico — somente API oficial api.mercadolibre.com.
 *
 * Fontes (position_source):
 * - search: GET /sites/MLB/search com OAuth user-scoped (T1a)
 * - proxy: coletor local residencial (T1b) — sem token ML
 * - trends: /trends + /highlights autenticados (T1c, parcial, sem posição exata de busca)
 * - unavailable: tentativa registrada sem posição
 *
 * NÃO faz scraping HTML. NÃO rotaciona IP para burlar bloqueio.
 */
final class RankTrackerService
{
    public const SOURCE_SEARCH = 'search';
    public const SOURCE_PROXY = 'proxy';
    public const SOURCE_TRENDS = 'trends';
    public const SOURCE_UNAVAILABLE = 'unavailable';

    private PDO $db;
    private int $maxReqPerMin;
    private int $circuitThreshold;
    /** @var callable|null fn(string $site, string $q, int $limit, int $offset): array */
    private $searchFn;

    public function __construct(?PDO $db = null, ?callable $searchFn = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->searchFn = $searchFn;
        $this->maxReqPerMin = max(1, (int) ($_ENV['RANK_TRACKER_MAX_REQ_PER_MIN'] ?? getenv('RANK_TRACKER_MAX_REQ_PER_MIN') ?: 6));
        $this->circuitThreshold = max(1, (int) ($_ENV['RANK_TRACKER_CIRCUIT_THRESHOLD'] ?? getenv('RANK_TRACKER_CIRCUIT_THRESHOLD') ?: 3));
        $this->ensureSchema();
    }

    public function isEnabled(): bool
    {
        return filter_var(
            $_ENV['RANK_TRACKER_ENABLED'] ?? getenv('RANK_TRACKER_ENABLED') ?: 'false',
            FILTER_VALIDATE_BOOLEAN
        );
    }

    public function isLocalCollectorEnabled(): bool
    {
        return filter_var(
            $_ENV['RANK_COLLECTOR_LOCAL'] ?? getenv('RANK_COLLECTOR_LOCAL') ?: 'false',
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Deriva 1–3 keywords a partir do título (tokens significativos).
     *
     * @return list<string>
     */
    public function deriveKeywords(string $title, int $max = 3): array
    {
        $title = mb_strtolower(trim($title));
        $title = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $title) ?? $title;
        $stop = ['de', 'da', 'do', 'das', 'dos', 'para', 'com', 'sem', 'em', 'no', 'na', 'um', 'uma', 'the', 'and', 'or', 'kit', 'par', 'pcs'];
        $parts = preg_split('/\s+/', $title) ?: [];
        $tokens = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if (mb_strlen($p) < 3 || in_array($p, $stop, true)) {
                continue;
            }
            if (preg_match('/^\d+$/', $p)) {
                continue;
            }
            $tokens[] = $p;
            if (count($tokens) >= 8) {
                break;
            }
        }
        if ($tokens === []) {
            return [];
        }
        $kws = [];
        if (count($tokens) >= 2) {
            $kws[] = $tokens[0] . ' ' . $tokens[1];
        }
        if (count($tokens) >= 3) {
            $kws[] = $tokens[0] . ' ' . $tokens[1] . ' ' . $tokens[2];
        }
        $kws[] = $tokens[0];
        $kws = array_values(array_unique($kws));
        return array_slice($kws, 0, max(1, $max));
    }

    /**
     * @return int número de keywords upsertadas
     */
    public function seedKeywordsForAccount(int $accountId, int $limit = 30): int
    {
        $stmt = $this->db->prepare(
            "SELECT ml_item_id AS mlb_id, title FROM items
             WHERE account_id = ? AND status = 'active'
             ORDER BY sold_quantity DESC, id DESC
             LIMIT " . (int) $limit
        );
        $stmt->execute([$accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $n = 0;
        $ins = $this->db->prepare(
            $this->isSqlite()
                ? "INSERT INTO item_rank_keywords (account_id, mlb_id, keyword, source, active, created_at)
                   VALUES (?, ?, ?, ?, 1, datetime('now'))
                   ON CONFLICT(account_id, mlb_id, keyword) DO UPDATE SET active = 1, updated_at = datetime('now')"
                : 'INSERT INTO item_rank_keywords (account_id, mlb_id, keyword, source, active, created_at)
                   VALUES (?, ?, ?, ?, 1, NOW())
                   ON DUPLICATE KEY UPDATE active = 1, updated_at = NOW()'
        );
        foreach ($rows as $row) {
            $mlb = strtoupper((string) $row['mlb_id']);
            foreach ($this->deriveKeywords((string) ($row['title'] ?? '')) as $kw) {
                $ins->execute([$accountId, $mlb, $kw, 'auto_title']);
                $n++;
            }
        }
        return $n;
    }

    /**
     * Assignments para o coletor local (máx 30 = 10 anúncios × 3 kws).
     *
     * @return list<array{mlb_id:string,keyword:string}>
     */
    public function listAssignments(int $accountId, int $max = 30): array
    {
        $max = max(1, min(30, $max));
        $this->seedKeywordsForAccount($accountId, 10);
        $stmt = $this->db->prepare(
            'SELECT mlb_id, keyword FROM item_rank_keywords
             WHERE account_id = ? AND active = 1
             ORDER BY mlb_id, keyword
             LIMIT ' . $max
        );
        $stmt->execute([$accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'mlb_id' => strtoupper((string) $row['mlb_id']),
                'keyword' => (string) $row['keyword'],
            ];
        }
        return $out;
    }

    /**
     * Ingest do coletor local — idempotente por (account, mlb, keyword, dia).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function ingestFromCollector(array $payload): array
    {
        $accountId = (int) ($payload['account_id'] ?? 0);
        $mlb = strtoupper(trim((string) ($payload['mlb_id'] ?? '')));
        $keyword = trim((string) ($payload['keyword'] ?? ''));
        $position = $payload['position'] ?? null;
        $page = $payload['page'] ?? null;
        $pagePosition = $payload['page_position'] ?? null;
        $total = $payload['total_results'] ?? null;
        $error = isset($payload['error']) ? (string) $payload['error'] : null;
        $day = (string) ($payload['day'] ?? '');

        if ($accountId <= 0 || $mlb === '' || $keyword === '') {
            return ['ok' => false, 'error' => 'validation', 'message' => 'account_id, mlb_id e keyword obrigatórios'];
        }
        if (!preg_match('/^MLB[A-Z0-9]+$/i', $mlb)) {
            return ['ok' => false, 'error' => 'validation', 'message' => 'mlb_id inválido'];
        }
        if (mb_strlen($keyword) > 200) {
            return ['ok' => false, 'error' => 'validation', 'message' => 'keyword muito longa'];
        }

        $today = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
        if ($day !== '' && $day !== $today) {
            return ['ok' => false, 'error' => 'validation', 'message' => 'day deve ser o dia atual America/Sao_Paulo'];
        }

        if ($this->hasCaptureToday($accountId, $mlb, $keyword)) {
            return [
                'ok' => true,
                'idempotent' => true,
                'message' => 'já capturado hoje',
                'mlb_id' => $mlb,
                'keyword' => $keyword,
            ];
        }

        $pos = is_numeric($position) ? (int) $position : null;
        if ($pos !== null && $pos < 1) {
            $pos = null;
        }

        $found = [
            'position' => $pos,
            'page' => is_numeric($page) ? (int) $page : null,
            'page_position' => is_numeric($pagePosition) ? (int) $pagePosition : null,
            'total_results' => is_numeric($total) ? (int) $total : null,
            'error' => $error,
            'position_source' => self::SOURCE_PROXY,
        ];
        $this->persistCapture($accountId, $mlb, $keyword, $found);
        if ($pos !== null) {
            $this->recordSuccess($accountId);
        }

        return [
            'ok' => true,
            'idempotent' => false,
            'message' => 'ingest ok',
            'mlb_id' => $mlb,
            'keyword' => $keyword,
            'position' => $pos,
            'position_source' => self::SOURCE_PROXY,
        ];
    }

    /**
     * Coleta diária: search autenticado (se flag) + fallback trends/highlights (sempre).
     *
     * @return array<string, mixed>
     */
    public function collect(int $accountId, ?MercadoLivreClient $client = null): array
    {
        $client ??= new MercadoLivreClient($accountId);
        $searchResult = [
            'ok' => false,
            'skipped' => true,
            'reason' => 'rank_tracker_disabled',
            'captured' => 0,
            'cached_skips' => 0,
            'errors' => 0,
        ];

        if ($this->isEnabled() && !$this->isCircuitOpen($accountId)) {
            $searchResult = $this->collectSearch($accountId, $client);
        } elseif ($this->isEnabled() && $this->isCircuitOpen($accountId)) {
            $searchResult = [
                'ok' => false,
                'skipped' => true,
                'reason' => 'circuit_open',
                'circuit' => $this->circuitState($accountId),
                'captured' => 0,
                'cached_skips' => 0,
                'errors' => 0,
            ];
        }

        // Em testes (searchFn injetado) não dispara HTTP real de trends/highlights.
        $trends = ['ok' => true, 'signals' => 0, 'skipped' => true, 'reason' => 'harness'];
        if ($this->searchFn === null) {
            $trends = $this->collectDemandSignals($accountId, $client);
        }

        $available = ($searchResult['captured'] ?? 0) > 0
            || $this->countRecentCaptures($accountId, 2) > 0
            || ($trends['signals'] ?? 0) > 0;

        return [
            'ok' => (($searchResult['ok'] ?? false) || ($trends['ok'] ?? false)),
            'search' => $searchResult,
            'trends' => $trends,
            'captured' => (int) ($searchResult['captured'] ?? 0),
            'cached_skips' => (int) ($searchResult['cached_skips'] ?? 0),
            'errors' => (int) ($searchResult['errors'] ?? 0),
            'last_error' => $searchResult['last_error'] ?? null,
            'available' => $available,
            'position_source' => $this->resolveActiveSource($accountId),
            'circuit' => $this->circuitState($accountId),
            'rate_limit_per_min' => $this->maxReqPerMin,
            'local_collector' => $this->isLocalCollectorEnabled(),
        ];
    }

    /**
     * T1c: /trends e /highlights autenticados — demanda e top categoria.
     *
     * @return array<string, mixed>
     */
    public function collectDemandSignals(int $accountId, ?MercadoLivreClient $client = null): array
    {
        $client ??= new MercadoLivreClient($accountId);
        $this->seedKeywordsForAccount($accountId, 10);

        $signals = 0;
        $errors = 0;
        $lastError = null;

        // Trends site-wide (auth)
        $trends = $client->get('/trends/MLB', [], 0, false);
        $trendOk = array_is_list($trends) || (isset($trends[0]) && is_array($trends[0]));
        if (!$trendOk && isset($trends['error'])) {
            $errors++;
            $lastError = 'trends_' . (string) ($trends['error'] ?? 'fail');
        } else {
            $list = array_is_list($trends) ? $trends : [];
            // Persiste até 5 keywords de demanda como sinal (sem posição de busca)
            foreach (array_slice($list, 0, 5) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $kw = trim((string) ($row['keyword'] ?? ''));
                if ($kw === '') {
                    continue;
                }
                $mlb = 'TREND';
                if ($this->hasCaptureToday($accountId, $mlb, $kw)) {
                    continue;
                }
                $this->persistCapture($accountId, $mlb, $kw, [
                    'position' => null,
                    'page' => null,
                    'page_position' => null,
                    'total_results' => null,
                    'error' => null,
                    'position_source' => self::SOURCE_TRENDS,
                    'message' => 'demand_signal',
                ]);
                $signals++;
            }
        }

        // Highlights por categoria dos top itens
        $stmt = $this->db->prepare(
            "SELECT ml_item_id AS mlb_id, category_id FROM items
             WHERE account_id = ? AND status = 'active' AND category_id IS NOT NULL AND category_id != ''
             ORDER BY sold_quantity DESC, id DESC
             LIMIT 5"
        );
        try {
            $stmt->execute([$accountId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            $items = [];
        }

        foreach ($items as $item) {
            $mlb = strtoupper((string) ($item['mlb_id'] ?? ''));
            $cat = (string) ($item['category_id'] ?? '');
            if ($mlb === '' || $cat === '') {
                continue;
            }
            $hl = $client->get('/highlights/MLB/category/' . rawurlencode($cat), [], 0, false);
            if (isset($hl['error'])) {
                $errors++;
                $lastError = 'highlights_' . (string) $hl['error'];
                continue;
            }
            $content = $hl['content'] ?? [];
            if (!is_array($content)) {
                continue;
            }
            $foundPos = null;
            foreach ($content as $idx => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $id = strtoupper((string) ($entry['id'] ?? ''));
                // Highlights usa MLBU* (user product); matching aproximado por sufixo numérico do MLB
                $pos = isset($entry['position']) ? (int) $entry['position'] : ((int) $idx + 1);
                if ($id === $mlb || $this->idsLooselyMatch($id, $mlb)) {
                    $foundPos = $pos;
                    break;
                }
            }
            $kw = 'highlight:' . $cat;
            if ($this->hasCaptureToday($accountId, $mlb, $kw)) {
                continue;
            }
            $this->persistCapture($accountId, $mlb, $kw, [
                'position' => $foundPos,
                'page' => 1,
                'page_position' => $foundPos,
                'total_results' => count($content),
                'error' => $foundPos === null ? 'not_in_highlights' : null,
                'position_source' => self::SOURCE_TRENDS,
            ]);
            $signals++;
        }

        return [
            'ok' => $signals > 0 || $errors === 0,
            'signals' => $signals,
            'errors' => $errors,
            'last_error' => $lastError,
            'position_source' => self::SOURCE_TRENDS,
            'note' => 'sem posição exata de busca orgânica — trends/highlights',
        ];
    }

    /**
     * Status para o Pregão / UI / checklist.
     *
     * @return array<string, mixed>
     */
    public function statusForPregao(int $accountId): array
    {
        $source = $this->resolveActiveSource($accountId);
        $sourceLabels = [
            self::SOURCE_SEARCH => 'search autenticada',
            self::SOURCE_PROXY => 'coletor local',
            self::SOURCE_TRENDS => 'trends parcial',
            self::SOURCE_UNAVAILABLE => 'indisponível',
        ];

        $exact = $this->latestCapturesBySources($accountId, [self::SOURCE_SEARCH, self::SOURCE_PROXY], 5);
        if ($exact !== []) {
            $avg = 0;
            $n = 0;
            foreach ($exact as $row) {
                if ($row['position'] !== null) {
                    $avg += (int) $row['position'];
                    $n++;
                }
            }
            $src = (string) ($exact[0]['position_source'] ?? $source);
            return [
                'available' => true,
                'reason' => null,
                'label' => $sourceLabels[$src] ?? $src,
                'position_source' => $src,
                'freshness' => $exact[0]['captured_at'] ?? null,
                'sample' => $exact,
                'avg_position' => $n > 0 ? round($avg / $n, 1) : null,
                'partial' => false,
                'local_collector' => $this->isLocalCollectorEnabled(),
                'search_enabled' => $this->isEnabled(),
            ];
        }

        $trends = $this->latestCapturesBySources($accountId, [self::SOURCE_TRENDS], 5);
        if ($trends !== [] || $this->countRecentBySource($accountId, self::SOURCE_TRENDS, 7) > 0) {
            return [
                'available' => true,
                'reason' => null,
                'label' => 'trends parcial (sem posição exata)',
                'position_source' => self::SOURCE_TRENDS,
                'freshness' => $trends[0]['captured_at'] ?? null,
                'sample' => $trends,
                'avg_position' => null,
                'partial' => true,
                'note' => 'sem posição exata',
                'cause_doc' => 'search 403 datacenter; trends/highlights autenticados ativos',
                'local_collector' => $this->isLocalCollectorEnabled(),
                'search_enabled' => $this->isEnabled(),
            ];
        }

        if (!$this->isEnabled() && !$this->isLocalCollectorEnabled()) {
            return [
                'available' => false,
                'reason' => 'rank_tracker_disabled',
                'label' => 'desativado (flags off; coletor local off)',
                'position_source' => self::SOURCE_UNAVAILABLE,
                'cause_doc' => 'sites/search 403 datacenter — ver docs/ops/RANK_TRACKER.md',
                'partial' => false,
                'local_collector' => false,
                'search_enabled' => false,
            ];
        }

        if ($this->isCircuitOpen($accountId) && !$this->isLocalCollectorEnabled()) {
            $c = $this->circuitState($accountId);
            return [
                'available' => false,
                'reason' => 'circuit_open',
                'label' => 'pausado (circuit breaker search)',
                'detail' => $c,
                'position_source' => self::SOURCE_UNAVAILABLE,
                'cause_doc' => 'API /sites/MLB/search retornou falhas repetidas (tipicamente 403 datacenter)',
                'partial' => false,
                'local_collector' => false,
                'search_enabled' => $this->isEnabled(),
            ];
        }

        return [
            'available' => false,
            'reason' => 'no_captures',
            'label' => $this->isLocalCollectorEnabled()
                ? 'aguardando coletor local'
                : 'sem capturas ainda',
            'position_source' => self::SOURCE_UNAVAILABLE,
            'cause_doc' => 'Aguardando coleta (search/proxy/trends)',
            'partial' => false,
            'local_collector' => $this->isLocalCollectorEnabled(),
            'search_enabled' => $this->isEnabled(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function historyForItem(int $accountId, string $mlbId, int $days = 30): array
    {
        $days = max(1, min(90, $days));
        $since = $this->sinceExpr($days);
        $stmt = $this->db->prepare(
            "SELECT mlb_id, keyword, position, page, page_position, total_results, captured_at, error, position_source
             FROM rank_history
             WHERE account_id = ? AND mlb_id = ?
               AND captured_at >= {$since}
             ORDER BY captured_at DESC, keyword"
        );
        $stmt->execute([$accountId, strtoupper($mlbId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function latestCaptures(int $accountId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare(
            "SELECT mlb_id, keyword, position, page, page_position, total_results, captured_at, error, position_source
             FROM rank_history
             WHERE account_id = ? AND position IS NOT NULL
             ORDER BY captured_at DESC LIMIT {$limit}"
        );
        $stmt->execute([$accountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countRecentCaptures(int $accountId, int $days = 14): int
    {
        $days = max(1, min(90, $days));
        $since = $this->sinceExpr($days);
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM rank_history
             WHERE account_id = ? AND position IS NOT NULL
               AND captured_at >= {$since}"
        );
        $stmt->execute([$accountId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>
     */
    private function collectSearch(int $accountId, MercadoLivreClient $client): array
    {
        $this->seedKeywordsForAccount($accountId, 40);

        $kwStmt = $this->db->prepare(
            'SELECT DISTINCT mlb_id, keyword FROM item_rank_keywords
             WHERE account_id = ? AND active = 1
             ORDER BY mlb_id, keyword
             LIMIT 60'
        );
        $kwStmt->execute([$accountId]);
        $pairs = $kwStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $siteId = 'MLB';
        $sellerId = $this->sellerId($accountId);

        $captured = 0;
        $errors = 0;
        $cached = 0;
        $reqTimestamps = [];
        $lastError = null;

        foreach ($pairs as $pair) {
            $mlb = strtoupper((string) $pair['mlb_id']);
            $kw = (string) $pair['keyword'];

            if ($this->hasCaptureToday($accountId, $mlb, $kw)) {
                $cached++;
                continue;
            }

            $this->throttle($reqTimestamps);
            $reqTimestamps[] = microtime(true);

            $found = $this->locateItem($client, $siteId, $mlb, $kw, $sellerId);
            if (($found['error'] ?? null) !== null) {
                $errors++;
                $lastError = (string) $found['error'];
                $found['position_source'] = self::SOURCE_UNAVAILABLE;
                $this->persistCapture($accountId, $mlb, $kw, $found);
                $this->recordFailure($accountId, $lastError);
                if ($this->isCircuitOpen($accountId)) {
                    break;
                }
                if ($lastError === 'http_429') {
                    usleep(2_000_000);
                }
                continue;
            }

            $found['position_source'] = self::SOURCE_SEARCH;
            $this->recordSuccess($accountId);
            $this->persistCapture($accountId, $mlb, $kw, $found);
            $captured++;
        }

        return [
            'ok' => $errors === 0 || $captured > 0,
            'skipped' => false,
            'captured' => $captured,
            'cached_skips' => $cached,
            'errors' => $errors,
            'last_error' => $lastError,
            'circuit' => $this->circuitState($accountId),
            'position_source' => self::SOURCE_SEARCH,
        ];
    }

    /**
     * @param list<float> $reqTimestamps
     */
    private function throttle(array $reqTimestamps): void
    {
        $window = 60.0;
        $now = microtime(true);
        $recent = array_filter($reqTimestamps, static fn (float $t): bool => ($now - $t) < $window);
        if (count($recent) >= $this->maxReqPerMin) {
            $oldest = min($recent);
            $sleep = max(0.05, $window - ($now - $oldest) + 0.05);
            usleep((int) ($sleep * 1_000_000));
        }
    }

    /**
     * @return array{position:?int,page:?int,page_position:?int,total_results:?int,error?:string,message?:string,position_source?:string}
     */
    private function locateItem(
        MercadoLivreClient $client,
        string $siteId,
        string $mlbId,
        string $keyword,
        string $sellerId
    ): array {
        $limit = 50;
        $maxPages = 4;
        $offset = 0;

        for ($page = 1; $page <= $maxPages; $page++) {
            if ($this->searchFn !== null) {
                $result = ($this->searchFn)($siteId, $keyword, $limit, $offset);
            } else {
                // T1a: busca autenticada (OAuth user-scoped) — NÃO public/app-only
                $result = $client->get("/sites/{$siteId}/search", [
                    'q' => $keyword,
                    'limit' => $limit,
                    'offset' => $offset,
                ], 0, false);
            }

            $status = (int) ($result['status'] ?? 0);
            if ($status === 429) {
                return [
                    'position' => null,
                    'page' => null,
                    'page_position' => null,
                    'total_results' => null,
                    'error' => 'http_429',
                ];
            }
            if (isset($result['error']) || $status === 403) {
                return [
                    'position' => null,
                    'page' => null,
                    'page_position' => null,
                    'total_results' => null,
                    'error' => 'search_forbidden',
                    'message' => (string) ($result['message'] ?? $result['error'] ?? 'forbidden'),
                ];
            }

            $items = $result['results'] ?? [];
            if (!is_array($items) || $items === []) {
                break;
            }
            $total = (int) ($result['paging']['total'] ?? 0);

            foreach ($items as $idx => $item) {
                $id = strtoupper((string) ($item['id'] ?? ''));
                $itemSeller = (string) ($item['seller']['id'] ?? $item['seller_id'] ?? '');
                if ($id === $mlbId || ($sellerId !== '' && $itemSeller === $sellerId && $id === $mlbId)) {
                    $pagePos = (int) $idx + 1;
                    return [
                        'position' => $offset + $pagePos,
                        'page' => $page,
                        'page_position' => $pagePos,
                        'total_results' => $total,
                    ];
                }
            }

            $offset += $limit;
            if ($offset >= $total) {
                break;
            }
        }

        return [
            'position' => null,
            'page' => null,
            'page_position' => null,
            'total_results' => null,
            'error' => 'not_in_top',
        ];
    }

    /**
     * @param array{position:?int,page:?int,page_position:?int,total_results:?int,error?:?string,position_source?:string} $found
     */
    private function persistCapture(int $accountId, string $mlbId, string $keyword, array $found): void
    {
        $source = (string) ($found['position_source'] ?? self::SOURCE_UNAVAILABLE);
        $stmt = $this->db->prepare(
            'INSERT INTO rank_history
             (account_id, mlb_id, keyword, position, page, page_position, total_results, error, position_source, captured_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ' . $this->nowSql() . ')'
        );
        $stmt->execute([
            $accountId,
            $mlbId,
            $keyword,
            $found['position'],
            $found['page'],
            $found['page_position'],
            $found['total_results'],
            $found['error'] ?? null,
            $source,
        ]);

        if ($found['position'] !== null && in_array($source, [self::SOURCE_SEARCH, self::SOURCE_PROXY], true)) {
            try {
                $date = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
                $upsert = $this->db->prepare(
                    'INSERT INTO keyword_ranks (account_id, kw, `date`, pos, delta, updated_at)
                     VALUES (?, ?, ?, ?, NULL, NOW())
                     ON DUPLICATE KEY UPDATE pos = VALUES(pos), updated_at = NOW()'
                );
                $upsert->execute([$accountId, $keyword, $date, (int) $found['position']]);
            } catch (Throwable) {
                // tabela legada opcional
            }
        }
    }

    private function hasCaptureToday(int $accountId, string $mlbId, string $keyword): bool
    {
        $dayPred = $this->isSqlite()
            ? "date(captured_at) = date('now')"
            : 'DATE(captured_at) = CURDATE()';
        $stmt = $this->db->prepare(
            "SELECT 1 FROM rank_history
             WHERE account_id = ? AND mlb_id = ? AND keyword = ?
               AND {$dayPred}
             LIMIT 1"
        );
        $stmt->execute([$accountId, $mlbId, $keyword]);
        return (bool) $stmt->fetchColumn();
    }

    private function sellerId(int $accountId): string
    {
        $stmt = $this->db->prepare('SELECT ml_user_id FROM ml_accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    public function isCircuitOpen(int $accountId): bool
    {
        $c = $this->circuitState($accountId);
        if (($c['state'] ?? '') !== 'open') {
            return false;
        }
        $until = strtotime((string) ($c['open_until'] ?? ''));
        return $until !== false && $until > time();
    }

    /**
     * @return array<string, mixed>
     */
    public function circuitState(int $accountId): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT state, failures, last_error, open_until, updated_at
                 FROM rank_tracker_circuit WHERE account_id = ? LIMIT 1'
            );
            $stmt->execute([$accountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: ['state' => 'closed', 'failures' => 0];
        } catch (Throwable) {
            return ['state' => 'closed', 'failures' => 0];
        }
    }

    private function recordFailure(int $accountId, string $error): void
    {
        $state = $this->circuitState($accountId);
        $failures = (int) ($state['failures'] ?? 0) + 1;
        $open = $failures >= $this->circuitThreshold;
        $until = $open ? date('Y-m-d H:i:s', time() + 3600) : null;
        $now = $this->nowSql();
        if ($this->isSqlite()) {
            $stmt = $this->db->prepare(
                "INSERT INTO rank_tracker_circuit (account_id, state, failures, last_error, open_until, updated_at)
                 VALUES (?, ?, ?, ?, ?, {$now})
                 ON CONFLICT(account_id) DO UPDATE SET
                   state = excluded.state,
                   failures = excluded.failures,
                   last_error = excluded.last_error,
                   open_until = excluded.open_until,
                   updated_at = {$now}"
            );
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO rank_tracker_circuit (account_id, state, failures, last_error, open_until, updated_at)
                 VALUES (?, ?, ?, ?, ?, {$now})
                 ON DUPLICATE KEY UPDATE
                   state = VALUES(state),
                   failures = VALUES(failures),
                   last_error = VALUES(last_error),
                   open_until = VALUES(open_until),
                   updated_at = {$now}"
            );
        }
        $stmt->execute([
            $accountId,
            $open ? 'open' : 'closed',
            $failures,
            $error,
            $until,
        ]);
    }

    private function recordSuccess(int $accountId): void
    {
        $now = $this->nowSql();
        if ($this->isSqlite()) {
            $stmt = $this->db->prepare(
                "INSERT INTO rank_tracker_circuit (account_id, state, failures, last_error, open_until, updated_at)
                 VALUES (?, 'closed', 0, NULL, NULL, {$now})
                 ON CONFLICT(account_id) DO UPDATE SET state = 'closed', failures = 0, last_error = NULL, open_until = NULL, updated_at = {$now}"
            );
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO rank_tracker_circuit (account_id, state, failures, last_error, open_until, updated_at)
                 VALUES (?, 'closed', 0, NULL, NULL, {$now})
                 ON DUPLICATE KEY UPDATE state = 'closed', failures = 0, last_error = NULL, open_until = NULL, updated_at = {$now}"
            );
        }
        $stmt->execute([$accountId]);
    }

    /**
     * @param list<string> $sources
     * @return list<array<string, mixed>>
     */
    private function latestCapturesBySources(int $accountId, array $sources, int $limit): array
    {
        if ($sources === []) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $placeholders = implode(',', array_fill(0, count($sources), '?'));
        $params = array_merge([$accountId], $sources);
        $stmt = $this->db->prepare(
            "SELECT mlb_id, keyword, position, page, page_position, total_results, captured_at, error, position_source
             FROM rank_history
             WHERE account_id = ? AND position_source IN ({$placeholders})
             ORDER BY captured_at DESC LIMIT {$limit}"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function countRecentBySource(int $accountId, string $source, int $days): int
    {
        $since = $this->sinceExpr($days);
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM rank_history
             WHERE account_id = ? AND position_source = ?
               AND captured_at >= {$since}"
        );
        $stmt->execute([$accountId, $source]);
        return (int) $stmt->fetchColumn();
    }

    private function resolveActiveSource(int $accountId): string
    {
        $exact = $this->latestCapturesBySources($accountId, [self::SOURCE_SEARCH, self::SOURCE_PROXY], 1);
        if ($exact !== []) {
            return (string) ($exact[0]['position_source'] ?? self::SOURCE_SEARCH);
        }
        if ($this->countRecentBySource($accountId, self::SOURCE_TRENDS, 7) > 0) {
            return self::SOURCE_TRENDS;
        }
        return self::SOURCE_UNAVAILABLE;
    }

    private function idsLooselyMatch(string $a, string $b): bool
    {
        $na = preg_replace('/\D+/', '', $a) ?? '';
        $nb = preg_replace('/\D+/', '', $b) ?? '';
        return $na !== '' && $na === $nb;
    }

    private function isSqlite(): bool
    {
        return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }

    private function nowSql(): string
    {
        return $this->isSqlite() ? "datetime('now')" : 'NOW()';
    }

    private function sinceExpr(int $days): string
    {
        $days = max(1, (int) $days);
        return $this->isSqlite()
            ? "datetime('now', '-{$days} days')"
            : "DATE_SUB(NOW(), INTERVAL {$days} DAY)";
    }

    private function ensureSchema(): void
    {
        if ($this->isSqlite()) {
            $this->db->exec(
                'CREATE TABLE IF NOT EXISTS rank_history (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id INT NOT NULL,
                    mlb_id TEXT NOT NULL,
                    keyword TEXT NOT NULL,
                    position INT NULL,
                    page INT NULL,
                    page_position INT NULL,
                    total_results INT NULL,
                    error TEXT NULL,
                    position_source TEXT NULL,
                    captured_at TEXT NOT NULL
                )'
            );
            $this->db->exec(
                'CREATE TABLE IF NOT EXISTS item_rank_keywords (
                    account_id INT NOT NULL,
                    mlb_id TEXT NOT NULL,
                    keyword TEXT NOT NULL,
                    source TEXT NOT NULL DEFAULT \'auto_title\',
                    active INT NOT NULL DEFAULT 1,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NULL,
                    PRIMARY KEY (account_id, mlb_id, keyword)
                )'
            );
            $this->db->exec(
                'CREATE TABLE IF NOT EXISTS rank_tracker_circuit (
                    account_id INT NOT NULL PRIMARY KEY,
                    state TEXT NOT NULL DEFAULT \'closed\',
                    failures INT NOT NULL DEFAULT 0,
                    last_error TEXT NULL,
                    open_until TEXT NULL,
                    updated_at TEXT NOT NULL
                )'
            );
            return;
        }

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS rank_history (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                mlb_id VARCHAR(32) NOT NULL,
                keyword VARCHAR(255) NOT NULL,
                position INT NULL,
                page INT NULL,
                page_position INT NULL,
                total_results INT NULL,
                error VARCHAR(64) NULL,
                position_source VARCHAR(32) NULL,
                captured_at DATETIME NOT NULL,
                INDEX idx_account_mlb (account_id, mlb_id, captured_at),
                INDEX idx_kw_day (account_id, keyword, captured_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        try {
            $this->db->exec('ALTER TABLE rank_history ADD COLUMN position_source VARCHAR(32) NULL AFTER error');
        } catch (Throwable) {
            // coluna já existe
        }
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS item_rank_keywords (
                account_id INT NOT NULL,
                mlb_id VARCHAR(32) NOT NULL,
                keyword VARCHAR(255) NOT NULL,
                source VARCHAR(32) NOT NULL DEFAULT \'auto_title\',
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NULL,
                PRIMARY KEY (account_id, mlb_id, keyword)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS rank_tracker_circuit (
                account_id INT NOT NULL PRIMARY KEY,
                state VARCHAR(16) NOT NULL DEFAULT \'closed\',
                failures INT NOT NULL DEFAULT 0,
                last_error VARCHAR(128) NULL,
                open_until DATETIME NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
}

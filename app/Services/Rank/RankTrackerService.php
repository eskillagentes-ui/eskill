<?php

declare(strict_types=1);

namespace App\Services\Rank;

use App\Database;
use App\Services\MercadoLivreClient;
use PDO;
use Throwable;

/**
 * Rank tracker orgânico — somente API oficial /sites/{site}/search.
 *
 * Causa histórica da desativação (NÃO é scraping HTML):
 * o endpoint público /sites/MLB/search retorna 403 forbidden a partir deste
 * host (IP datacenter / PolicyAgent Cloudflare). Ver MercadoLivreClient e
 * config/pregao.php. Scraping (ProxyService) foi removido do repo em outro
 * contexto; o rank tracker sempre usou a API oficial.
 *
 * Este serviço implementa rate limit, cache diário por keyword, backoff 429
 * e circuit breaker (3 falhas → pausa).
 */
final class RankTrackerService
{
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
     * Garante keywords para anúncios ativos da conta (até $limit itens).
     *
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
     * Coleta diária: respeita cache (mesma kw no dia), rate limit e circuit breaker.
     *
     * @return array<string, mixed>
     */
    public function collect(int $accountId, ?MercadoLivreClient $client = null): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'rank_tracker_disabled'];
        }

        if ($this->isCircuitOpen($accountId)) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'circuit_open', 'circuit' => $this->circuitState($accountId)];
        }

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
        $client ??= new MercadoLivreClient($accountId);

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
                // Persiste tentativa (posição null + error) para auditoria — sem inventar ranking
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

            $this->recordSuccess($accountId);
            $this->persistCapture($accountId, $mlb, $kw, $found);
            $captured++;
        }

        $available = $captured > 0 || $this->countRecentCaptures($accountId, 2) > 0;

        return [
            'ok' => $errors === 0 || $captured > 0,
            'captured' => $captured,
            'cached_skips' => $cached,
            'errors' => $errors,
            'last_error' => $lastError,
            'available' => $available,
            'circuit' => $this->circuitState($accountId),
            'rate_limit_per_min' => $this->maxReqPerMin,
        ];
    }

    /**
     * Status para o Pregão / UI.
     *
     * @return array<string, mixed>
     */
    public function statusForPregao(int $accountId): array
    {
        if (!$this->isEnabled()) {
            return [
                'available' => false,
                'reason' => 'rank_tracker_disabled',
                'label' => 'desativado (flag RANK_TRACKER_ENABLED=false)',
                'cause_doc' => 'sites/search 403 datacenter — ver docs/ops/RANK_TRACKER.md',
            ];
        }
        if ($this->isCircuitOpen($accountId)) {
            $c = $this->circuitState($accountId);
            return [
                'available' => false,
                'reason' => 'circuit_open',
                'label' => 'pausado (circuit breaker)',
                'detail' => $c,
                'cause_doc' => 'API /sites/MLB/search retornou falhas repetidas (tipicamente 403 datacenter)',
            ];
        }

        $recent = $this->latestCaptures($accountId, 5);
        if ($recent === []) {
            return [
                'available' => false,
                'reason' => 'no_captures',
                'label' => 'sem capturas ainda',
                'cause_doc' => 'Aguardando coleta ou API search ainda 403 neste host',
            ];
        }

        $avg = 0;
        $n = 0;
        foreach ($recent as $row) {
            if ($row['position'] !== null) {
                $avg += (int) $row['position'];
                $n++;
            }
        }

        return [
            'available' => true,
            'reason' => null,
            'label' => 'disponível',
            'freshness' => $recent[0]['captured_at'] ?? null,
            'sample' => $recent,
            'avg_position' => $n > 0 ? round($avg / $n, 1) : null,
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
            "SELECT mlb_id, keyword, position, page, page_position, total_results, captured_at, error
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
            "SELECT mlb_id, keyword, position, page, page_position, total_results, captured_at, error
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
     * @return array{position:?int,page:?int,page_position:?int,total_results:?int,error?:string}
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
                $result = $client->searchItems([
                    'site_id' => $siteId,
                    'q' => $keyword,
                    'limit' => $limit,
                    'offset' => $offset,
                ], 0);
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
     * @param array{position:?int,page:?int,page_position:?int,total_results:?int,error?:string} $found
     */
    private function persistCapture(int $accountId, string $mlbId, string $keyword, array $found): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO rank_history
             (account_id, mlb_id, keyword, position, page, page_position, total_results, error, captured_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ' . $this->nowSql() . ')'
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
        ]);

        // Compat com keyword_ranks do Pregão (agregado por kw/dia)
        if ($found['position'] !== null) {
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
                captured_at DATETIME NOT NULL,
                INDEX idx_account_mlb (account_id, mlb_id, captured_at),
                INDEX idx_kw_day (account_id, keyword, captured_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
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

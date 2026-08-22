<?php

declare(strict_types=1);

namespace App\Services\Pregao;

use App\Database;
use App\Services\MercadoLivreClient;
use PDO;
use Redis;
use Throwable;

/**
 * Métricas de perguntas (read-only ML): recebidas × respondidas × abertas.
 * Mediana é a métrica principal de tempo; média é secundária.
 */
final class PregaoQuestionsService
{
    public const WINDOW_DAYS = 7;
    public const OPEN_ALERT_SECONDS = 7200;      // 2h
    public const OPEN_CRITICAL_SECONDS = 43200;  // 12h
    public const CACHE_TTL_SECONDS = 60;
    public const VOLUME_DROP_PCT = 50.0;
    public const DAYS_WITHOUT_Q_RED = 7;

    private PDO $db;
    private ?Redis $redis;
    private bool $redisTried = false;

    public function __construct(?PDO $db = null, ?Redis $redis = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->redis = $redis;
        if ($redis !== null) {
            $this->redisTried = true;
        }
    }

    /**
     * @return array{
     *   perguntas_recebidas_7d: int,
     *   perguntas_respondidas_7d: int,
     *   taxa_resposta_7d: float|null,
     *   mediana_resposta_s: int|null,
     *   media_resposta_s: int|null,
     *   perguntas_abertas: int,
     *   card_status: 'verde'|'amarelo'|'vermelho',
     *   abertas: list<array<string, mixed>>,
     *   newly_answered: list<array<string, mixed>>,
     *   source: string,
     *   window_days: int
     * }
     */
    public function collect(int $accountId, ?string $sellerId = null): array
    {
        $cacheKey = 'pregao:questions:snap:' . $accountId;
        $prevOpenIds = $this->loadPrevOpenIds($accountId);

        // Unificado com o tile perguntas_sla: só ml_questions da conta (API-first 403 no datacenter).
        unset($sellerId);
        $local = $this->fetchFromLocal($accountId);
        $volume = $this->computeVolumeSignals($accountId, count($local['rows']), $local['last_created_ts'] ?? null);
        $result = $this->buildResult($local['rows'], $local['open'], $prevOpenIds, $volume);
        $result['source'] = 'ml_questions';
        $this->cacheSet($cacheKey, [
            'rows' => $local['rows'],
            'open' => $local['open'],
        ], self::CACHE_TTL_SECONDS);
        $this->storePrevOpenIds($accountId, array_column($result['abertas'], 'question_id'));
        return $result;
    }

    /**
     * Pure: status do card. Volume é avaliado ANTES de taxa/tempo.
     * Ausência de atividade nunca rende verde.
     *
     * @param list<array{hours_open?: float|null, open_seconds?: int|null}> $abertas
     * @param array{dias_sem_pergunta?: int|null, volume_delta_pct?: float|null, baseline_28d?: float|null}|null $volume
     * @return array{status: 'verde'|'amarelo'|'vermelho', reason: string}
     */
    public static function resolveCardStatusDetailed(
        ?float $taxaPct,
        array $abertas,
        ?array $volume = null
    ): array {
        $diasSem = isset($volume['dias_sem_pergunta']) ? (int) $volume['dias_sem_pergunta'] : null;
        $deltaPct = isset($volume['volume_delta_pct']) && $volume['volume_delta_pct'] !== null
            ? (float) $volume['volume_delta_pct']
            : null;
        $recebidas7d = isset($volume['recebidas_7d']) ? (int) $volume['recebidas_7d'] : null;

        if ($diasSem !== null && $diasSem >= self::DAYS_WITHOUT_Q_RED) {
            return [
                'status' => 'vermelho',
                'reason' => sprintf('sem perguntas há %d dias — verificar exposição', $diasSem),
            ];
        }
        if ($recebidas7d === 0 && ($diasSem === null || $diasSem >= self::DAYS_WITHOUT_Q_RED)) {
            return [
                'status' => 'vermelho',
                'reason' => sprintf(
                    'sem perguntas há %d dias — verificar exposição',
                    $diasSem ?? self::DAYS_WITHOUT_Q_RED
                ),
            ];
        }
        if ($deltaPct !== null && $deltaPct <= -self::VOLUME_DROP_PCT) {
            return [
                'status' => 'amarelo',
                'reason' => sprintf('volume de perguntas em queda (%s%%)', (string) (int) round($deltaPct)),
            ];
        }

        $maxOpen = 0;
        foreach ($abertas as $q) {
            $sec = isset($q['open_seconds'])
                ? (int) $q['open_seconds']
                : (isset($q['hours_open']) ? (int) round(((float) $q['hours_open']) * 3600) : 0);
            $maxOpen = max($maxOpen, $sec);
        }

        if (($taxaPct !== null && $taxaPct < 70.0) || $maxOpen > self::OPEN_CRITICAL_SECONDS) {
            return ['status' => 'vermelho', 'reason' => 'taxa/tempo crítico'];
        }
        if (
            ($taxaPct !== null && $taxaPct < 90.0)
            || $maxOpen > self::OPEN_ALERT_SECONDS
        ) {
            return ['status' => 'amarelo', 'reason' => 'taxa/tempo em atenção'];
        }
        if ($recebidas7d === 0) {
            // 0 recebidas mas <7 dias sem pergunta → ainda não vermelho; não pode ser verde
            return [
                'status' => 'amarelo',
                'reason' => sprintf(
                    'sem perguntas há %d dia(s) — monitorar exposição',
                    max(0, (int) ($diasSem ?? 0))
                ),
            ];
        }
        return ['status' => 'verde', 'reason' => 'volume e taxa ok'];
    }

    /**
     * @param list<array{hours_open?: float|null, open_seconds?: int|null}> $abertas
     * @param array{dias_sem_pergunta?: int|null, volume_delta_pct?: float|null, recebidas_7d?: int}|null $volume
     */
    public static function resolveCardStatus(?float $taxaPct, array $abertas, ?array $volume = null): string
    {
        return self::resolveCardStatusDetailed($taxaPct, $abertas, $volume)['status'];
    }

    /**
     * Mediana de uma lista de segundos (ordenada ou não).
     *
     * @param list<int> $seconds
     */
    public static function median(array $seconds): ?int
    {
        if ($seconds === []) {
            return null;
        }
        sort($seconds);
        $n = count($seconds);
        if ($n % 2 === 1) {
            return $seconds[(int) floor($n / 2)];
        }
        return (int) round(($seconds[$n / 2 - 1] + $seconds[$n / 2]) / 2);
    }

    public static function formatDurationHuman(int $seconds): string
    {
        $s = max(0, $seconds);
        if ($s < 60) {
            return $s . 's';
        }
        if ($s < 3600) {
            return ((int) floor($s / 60)) . 'min';
        }
        if ($s < 86400) {
            $h = (int) floor($s / 3600);
            $m = (int) floor(($s % 3600) / 60);
            return $h . 'h' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
        }
        $d = (int) floor($s / 86400);
        $h = (int) floor(($s % 86400) / 3600);
        return $d . 'd' . ($h > 0 ? $h . 'h' : '');
    }

    public static function mlQuestionLink(string $itemId, string $questionId): string
    {
        $mlb = strtoupper($itemId);
        if (preg_match('/^MLB(\d+)$/', $mlb, $m)) {
            $mlb = 'MLB-' . $m[1];
        }
        // Inbox do vendedor + âncora do item; question_id na query para deep-link quando suportado
        $q = rawurlencode($questionId);
        $item = rawurlencode($mlb);
        return "https://www.mercadolivre.com.br/perguntas?item_id={$item}&question_id={$q}";
    }

    /**
     * @param list<array<string, mixed>> $rows7d
     * @param list<array<string, mixed>> $openNow
     * @param list<string> $prevOpenIds
     * @param array{
     *   perguntas_recebidas_baseline_28d: float,
     *   perguntas_volume_delta_pct: float|null,
     *   dias_sem_pergunta: int,
     *   recebidas_7d: int
     * } $volume
     * @return array<string, mixed>
     */
    private function buildResult(array $rows7d, array $openNow, array $prevOpenIds, array $volume): array
    {
        $recebidas = count($rows7d);
        $respondidas = 0;
        $secs = [];
        foreach ($rows7d as $r) {
            if (!empty($r['answered'])) {
                $respondidas++;
                if (isset($r['response_seconds']) && $r['response_seconds'] !== null) {
                    $secs[] = (int) $r['response_seconds'];
                }
            }
        }
        $taxa = $recebidas > 0 ? round(100.0 * $respondidas / $recebidas, 1) : null;
        $mediana = self::median($secs);
        $media = $secs !== [] ? (int) round(array_sum($secs) / count($secs)) : null;

        $abertas = [];
        $now = time();
        foreach ($openNow as $q) {
            $createdTs = isset($q['created_ts']) ? (int) $q['created_ts'] : $now;
            $openSec = max(0, $now - $createdTs);
            $qid = (string) ($q['question_id'] ?? '');
            $itemId = (string) ($q['item_id'] ?? '');
            $text = (string) ($q['text'] ?? '');
            $abertas[] = [
                'question_id' => $qid,
                'item_id' => $itemId,
                'text' => $text,
                'text_preview' => mb_substr($text, 0, 60),
                'date_created' => (string) ($q['date_created'] ?? ''),
                'open_seconds' => $openSec,
                'hours_open' => round($openSec / 3600, 1),
                'open_human' => self::formatDurationHuman($openSec),
                'ml_url' => self::mlQuestionLink($itemId, $qid),
                'alert_due' => $openSec >= self::OPEN_ALERT_SECONDS,
            ];
        }
        usort(
            $abertas,
            static fn (array $a, array $b): int => ((int) $b['open_seconds']) <=> ((int) $a['open_seconds'])
        );

        $prevSet = array_fill_keys($prevOpenIds, true);
        $newlyAnswered = [];
        foreach ($rows7d as $r) {
            $qid = (string) ($r['question_id'] ?? '');
            if ($qid === '' || empty($r['answered'])) {
                continue;
            }
            if (isset($prevSet[$qid])) {
                $newlyAnswered[] = [
                    'question_id' => $qid,
                    'item_id' => (string) ($r['item_id'] ?? ''),
                    'response_seconds' => isset($r['response_seconds']) ? (int) $r['response_seconds'] : null,
                    'text_preview' => mb_substr((string) ($r['text'] ?? ''), 0, 60),
                ];
            }
        }

        $volCtx = [
            'dias_sem_pergunta' => $volume['dias_sem_pergunta'],
            'volume_delta_pct' => $volume['perguntas_volume_delta_pct'],
            'baseline_28d' => $volume['perguntas_recebidas_baseline_28d'],
            'recebidas_7d' => $volume['recebidas_7d'],
        ];
        $card = self::resolveCardStatusDetailed($taxa, $abertas, $volCtx);

        return [
            'perguntas_recebidas_7d' => $recebidas,
            'perguntas_respondidas_7d' => $respondidas,
            'taxa_resposta_7d' => $taxa,
            'mediana_resposta_s' => $mediana,
            'media_resposta_s' => $media,
            'perguntas_abertas' => count($abertas),
            'perguntas_recebidas_baseline_28d' => $volume['perguntas_recebidas_baseline_28d'],
            'perguntas_volume_delta_pct' => $volume['perguntas_volume_delta_pct'],
            'dias_sem_pergunta' => $volume['dias_sem_pergunta'],
            'card_status' => $card['status'],
            'card_reason' => $card['reason'],
            'abertas' => $abertas,
            'newly_answered' => $newlyAnswered,
            'source' => 'unknown',
            'window_days' => self::WINDOW_DAYS,
        ];
    }

    /**
     * Baseline = média semanal dos 28d anteriores à janela 7d (count[-35d,-7d]/4), min 1.
     *
     * @return array{
     *   perguntas_recebidas_baseline_28d: float,
     *   perguntas_volume_delta_pct: float|null,
     *   dias_sem_pergunta: int,
     *   recebidas_7d: int
     * }
     */
    private function computeVolumeSignals(int $accountId, int $recebidas7d, ?int $lastCreatedTs): array
    {
        $baseline = 1.0;
        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM ml_questions
                 WHERE account_id = ?
                   AND date_created >= DATE_SUB(NOW(), INTERVAL 35 DAY)
                   AND date_created < DATE_SUB(NOW(), INTERVAL 7 DAY)'
            );
            $stmt->execute([$accountId]);
            $prior28 = (float) ($stmt->fetchColumn() ?: 0);
            $baseline = max(round($prior28 / 4.0, 2), 1.0);
        } catch (Throwable $e) {
            $baseline = 1.0;
        }

        $deltaPct = null;
        if ($baseline > 0) {
            $deltaPct = round((($recebidas7d / $baseline) - 1.0) * 100.0, 1);
        }

        $diasSem = 0;
        if ($lastCreatedTs !== null && $lastCreatedTs > 0) {
            $diasSem = (int) floor(max(0, time() - $lastCreatedTs) / 86400);
        } else {
            // Sem histórico conhecido: se 7d=0, assume pelo menos a janela
            $diasSem = $recebidas7d === 0 ? self::DAYS_WITHOUT_Q_RED : 0;
            try {
                $stmt = $this->db->prepare(
                    'SELECT MAX(date_created) FROM ml_questions WHERE account_id = ?'
                );
                $stmt->execute([$accountId]);
                $max = $stmt->fetchColumn();
                if (is_string($max) && $max !== '') {
                    $ts = strtotime($max);
                    if ($ts !== false) {
                        $diasSem = (int) floor(max(0, time() - $ts) / 86400);
                    }
                }
            } catch (Throwable $e) {
                // keep
            }
        }

        return [
            'perguntas_recebidas_baseline_28d' => $baseline,
            'perguntas_volume_delta_pct' => $deltaPct,
            'dias_sem_pergunta' => $diasSem,
            'recebidas_7d' => $recebidas7d,
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, open: list<array<string, mixed>>}|null
     */
    private function fetchFromApi(int $accountId, ?string $sellerId): ?array
    {
        try {
            $sellerId = $sellerId ?? $this->resolveSellerId($accountId);
            if ($sellerId === null || $sellerId === '') {
                return null;
            }
            $client = new MercadoLivreClient($accountId);
            $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))
                ->modify('-' . self::WINDOW_DAYS . ' days')
                ->getTimestamp();

            $open = [];
            foreach ($this->paginateQuestions($client, [
                'seller_id' => $sellerId,
                'status' => 'UNANSWERED',
                'sort' => 'date_created_asc',
            ], null) as $q) {
                $mapped = $this->mapApiQuestion($q);
                if ($mapped !== null) {
                    $open[] = $mapped;
                }
            }

            $rows = [];
            foreach ($this->paginateQuestions($client, [
                'seller_id' => $sellerId,
                'status' => 'ANSWERED',
                'sort' => 'date_created_desc',
            ], $cutoff) as $q) {
                $mapped = $this->mapApiQuestion($q);
                if ($mapped === null) {
                    continue;
                }
                if (($mapped['created_ts'] ?? 0) < $cutoff) {
                    break;
                }
                $rows[] = $mapped + ['answered' => true];
            }

            // Abertas criadas na janela 7d também contam como recebidas
            foreach ($open as $o) {
                if (($o['created_ts'] ?? 0) >= $cutoff) {
                    $rows[] = $o + ['answered' => false, 'response_seconds' => null];
                }
            }

            $lastTs = null;
            foreach (array_merge($rows, $open) as $r) {
                $ts = (int) ($r['created_ts'] ?? 0);
                if ($ts > 0 && ($lastTs === null || $ts > $lastTs)) {
                    $lastTs = $ts;
                }
            }
            if ($lastTs === null) {
                foreach ($this->paginateQuestions($client, [
                    'seller_id' => $sellerId,
                    'status' => 'ANSWERED',
                    'sort' => 'date_created_desc',
                ], null) as $q) {
                    $mapped = $this->mapApiQuestion($q);
                    if ($mapped !== null) {
                        $lastTs = (int) ($mapped['created_ts'] ?? 0);
                    }
                    break;
                }
            }

            return ['rows' => $rows, 'open' => $open, 'last_created_ts' => $lastTs];
        } catch (Throwable $e) {
            log_warning('PregaoQuestionsService: API falhou', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @return array{rows: list<array<string, mixed>>, open: list<array<string, mixed>>, last_created_ts: int|null}
     */
    private function fetchFromLocal(int $accountId): array
    {
        $stmt = $this->db->prepare(
            'SELECT question_id, item_id, question_text, status, date_created, answer_date
             FROM ml_questions
             WHERE account_id = ?
               AND date_created >= DATE_SUB(NOW(), INTERVAL ' . self::WINDOW_DAYS . ' DAY)'
        );
        $stmt->execute([$accountId]);
        $rows = [];
        $open = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $createdTs = strtotime((string) $r['date_created']) ?: time();
            $answered = !empty($r['answer_date']) && strtoupper((string) $r['status']) !== 'UNANSWERED';
            $resp = null;
            if ($answered && !empty($r['answer_date'])) {
                $resp = max(0, (strtotime((string) $r['answer_date']) ?: $createdTs) - $createdTs);
            }
            $mapped = [
                'question_id' => (string) $r['question_id'],
                'item_id' => (string) $r['item_id'],
                'text' => (string) $r['question_text'],
                'date_created' => (string) $r['date_created'],
                'created_ts' => $createdTs,
                'answered' => $answered,
                'response_seconds' => $resp,
            ];
            $rows[] = $mapped;
            if (!$answered) {
                $open[] = $mapped;
            }
        }

        // Abertas antigas (fora da janela 7d) ainda urgentes
        $openStmt = $this->db->prepare(
            "SELECT question_id, item_id, question_text, date_created
             FROM ml_questions
             WHERE account_id = ?
               AND (answer_date IS NULL OR status = 'UNANSWERED')"
        );
        $openStmt->execute([$accountId]);
        $openIds = array_column($open, 'question_id');
        foreach ($openStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $qid = (string) $r['question_id'];
            if (in_array($qid, $openIds, true)) {
                continue;
            }
            $createdTs = strtotime((string) $r['date_created']) ?: time();
            $open[] = [
                'question_id' => $qid,
                'item_id' => (string) $r['item_id'],
                'text' => (string) $r['question_text'],
                'date_created' => (string) $r['date_created'],
                'created_ts' => $createdTs,
            ];
        }

        $lastTs = null;
        try {
            $maxStmt = $this->db->prepare('SELECT MAX(date_created) FROM ml_questions WHERE account_id = ?');
            $maxStmt->execute([$accountId]);
            $max = $maxStmt->fetchColumn();
            if (is_string($max) && $max !== '') {
                $ts = strtotime($max);
                $lastTs = $ts !== false ? $ts : null;
            }
        } catch (Throwable $e) {
            $lastTs = null;
        }

        return ['rows' => $rows, 'open' => $open, 'last_created_ts' => $lastTs];
    }

    /**
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    private function paginateQuestions(MercadoLivreClient $client, array $params, ?int $cutoffTs): \Generator
    {
        $offset = 0;
        $limit = 50;
        $pages = 0;
        while ($pages < 30) {
            $pages++;
            $res = $client->get('/questions/search', array_merge($params, [
                'limit' => $limit,
                'offset' => $offset,
            ]));
            if (isset($res['error']) || (isset($res['status']) && (int) $res['status'] === 429)) {
                usleep(500000);
                $res = $client->get('/questions/search', array_merge($params, [
                    'limit' => $limit,
                    'offset' => $offset,
                ]));
            }
            if (isset($res['error'])) {
                break;
            }
            $body = $res['body'] ?? $res;
            $questions = $body['questions'] ?? [];
            if (!is_array($questions) || $questions === []) {
                break;
            }
            foreach ($questions as $q) {
                if (!is_array($q)) {
                    continue;
                }
                if ($cutoffTs !== null && !empty($q['date_created'])) {
                    try {
                        $ts = (new \DateTimeImmutable((string) $q['date_created']))->getTimestamp();
                        if ($ts < $cutoffTs && (($params['status'] ?? '') === 'ANSWERED')) {
                            return;
                        }
                    } catch (Throwable $e) {
                        // segue
                    }
                }
                yield $q;
            }
            $total = (int) ($body['total'] ?? 0);
            $offset += $limit;
            if ($offset >= $total || count($questions) < $limit) {
                break;
            }
            usleep(150000);
        }
    }

    /**
     * @param array<string, mixed> $q
     * @return array<string, mixed>|null
     */
    private function mapApiQuestion(array $q): ?array
    {
        $qid = (string) ($q['id'] ?? '');
        if ($qid === '') {
            return null;
        }
        $createdRaw = (string) ($q['date_created'] ?? '');
        try {
            $createdTs = (new \DateTimeImmutable($createdRaw))->getTimestamp();
        } catch (Throwable $e) {
            $createdTs = time();
        }
        $answerRaw = null;
        if (isset($q['answer']) && is_array($q['answer'])) {
            $answerRaw = $q['answer']['date_created'] ?? ($q['answer']['date'] ?? null);
        }
        $resp = null;
        $answered = is_string($answerRaw) && $answerRaw !== '';
        if ($answered) {
            try {
                $resp = max(0, (new \DateTimeImmutable((string) $answerRaw))->getTimestamp() - $createdTs);
            } catch (Throwable $e) {
                $resp = null;
            }
        }

        return [
            'question_id' => $qid,
            'item_id' => (string) ($q['item_id'] ?? ''),
            'text' => (string) ($q['text'] ?? ''),
            'date_created' => $createdRaw,
            'created_ts' => $createdTs,
            'answered' => $answered,
            'response_seconds' => $resp,
            'status' => (string) ($q['status'] ?? ''),
        ];
    }

    private function resolveSellerId(int $accountId): ?string
    {
        $stmt = $this->db->prepare('SELECT ml_user_id FROM ml_accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $id = $stmt->fetchColumn();
        return $id !== false && $id !== null && $id !== '' ? (string) $id : null;
    }

    /** @return list<string> */
    private function loadPrevOpenIds(int $accountId): array
    {
        $raw = $this->cacheGet('pregao:questions:open_ids:' . $accountId);
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_map('strval', $raw));
    }

    /** @param list<string> $ids */
    private function storePrevOpenIds(int $accountId, array $ids): void
    {
        $this->cacheSet('pregao:questions:open_ids:' . $accountId, array_values($ids), 86400);
    }

    private function cacheGet(string $key): mixed
    {
        $redis = $this->redis();
        if ($redis === null) {
            return null;
        }
        try {
            $val = $redis->get($key);
            if (!is_string($val) || $val === '') {
                return null;
            }
            return json_decode($val, true);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function cacheSet(string $key, mixed $value, int $ttl): void
    {
        $redis = $this->redis();
        if ($redis === null) {
            return;
        }
        try {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $redis->setex($key, $ttl, $json);
        } catch (Throwable $e) {
            // ignore
        }
    }

    private function redis(): ?Redis
    {
        if ($this->redisTried) {
            return $this->redis;
        }
        $this->redisTried = true;
        if (!class_exists('Redis')) {
            return null;
        }
        try {
            $redis = new Redis();
            $host = (string) ($_ENV['REDIS_HOST'] ?? '127.0.0.1');
            $port = (int) ($_ENV['REDIS_PORT'] ?? 6379);
            $redis->connect($host, $port, 1.0);
            $pass = $_ENV['REDIS_PASSWORD'] ?? '';
            if (!empty($pass) && $pass !== 'null') {
                $redis->auth($pass);
            }
            $redis->select((int) ($_ENV['REDIS_DB'] ?? 0));
            $this->redis = $redis;
        } catch (Throwable $e) {
            $this->redis = null;
        }
        return $this->redis;
    }
}

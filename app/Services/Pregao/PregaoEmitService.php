<?php

declare(strict_types=1);

namespace App\Services\Pregao;

use App\Database;
use PDO;
use Redis;
use Throwable;

/**
 * Emissor único do Pregão: persiste em pregao_events e publica no Redis Pub/Sub.
 *
 * Canal Redis: "pregao"
 * Envelope tenant-bound: { v, type, ts, payload, account_id }
 */
final class PregaoEmitService
{
    public const CHANNEL = 'pregao';
    /** v2: `ranks` oficial no snapshot; `keywords` alias deprecado. */
    public const VERSION = 2;

    /** Heartbeat de op por coletor (robô): no máximo 1× por hora. */
    public const OP_HEARTBEAT_TTL_SECONDS = 3600;

    /** @var list<string> */
    public const VALID_TYPES = [
        'index.tick',
        'index.candle',
        'metric.update',
        'op',
        'sale',
        'keyword.rank',
        'qa.status',
        'agent.status',
        'account.semaforo',
    ];

    /** @var list<string> */
    private const AGENT_STATUS_KEYS = [
        'agent',
        'attempts',
        'correlation_id',
        'ml_write_automation',
        'reason',
        'state_changed',
        'status',
    ];

    /** @var list<string> */
    private const KEYWORD_RANK_KEYS = ['delta', 'kw', 'pos'];

    /** @var list<string> */
    private const QA_STATUS_REQUIRED_KEYS = ['result', 'running', 'suite', 'test'];

    /** @var list<string> */
    private const QA_STATUS_ALLOWED_KEYS = [
        'result',
        'running',
        'stream_url',
        'suite',
        'test',
        'video_url',
    ];

    /** @var list<string> */
    private const QA_STATUS_RESULTS = ['error', 'failed', 'passed', 'running', 'skipped'];

    /** @var list<string> */
    private const AGENT_STATUS_AGENTS = [
        'sentinela',
        'collector',
        'financeiro',
        'otimizador',
        'orquestrador',
    ];

    /** @var list<string> */
    private const AGENT_STATUSES = ['success', 'skipped', 'blocked', 'failed'];

    /** @var list<string> */
    private const AGENT_STATUS_REASONS = [
        'aggregated',
        'agent_blocked',
        'agent_exception',
        'agent_failed',
        'collector_unavailable',
        'cost_validation_blocked',
        'financeiro_unavailable',
        'incomplete_legacy_payload',
        'invalid_legacy_payload',
        'invalid_optimizer_cost_snapshot',
        'invalid_optimizer_observation_snapshot',
        'legacy_error',
        'legacy_read_complete',
        'read_only_violation',
        'recommendations_ready',
        'runtime_exception',
        'sentinela_unavailable',
    ];

    private ?PDO $db = null;
    private ?Redis $redis = null;
    private bool $redisTried = false;

    /** @var array<string, string> fallback in-process quando Redis indisponível */
    private array $lastStateMemory = [];

    /** @var array<string, int> unix ts do último heartbeat por chave */
    private array $lastHeartbeatMemory = [];

    public function __construct(?PDO $db = null, ?Redis $redis = null)
    {
        $this->db = $db;
        $this->redis = $redis;
        if ($redis !== null) {
            $this->redisTried = true;
        }
    }

    /**
     * Emite um evento no envelope canônico.
     *
     * @param array<string, mixed> $payload
     * @param 'live'|'seed' $source
     * @return array{v: int, type: string, ts: string, payload: array<string, mixed>, source: string, account_id: int}
     */
    public function emit(string $type, array $payload, ?int $accountId = null, string $source = 'live'): array
    {
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException("Tipo de evento pregao inválido: {$type}");
        }
        if ($accountId === null || $accountId <= 0) {
            throw new \InvalidArgumentException('Conta do evento pregao inválida');
        }
        if ($type === 'agent.status') {
            $this->assertAgentStatusPayload($payload, $accountId);
        } elseif ($type === 'keyword.rank') {
            $this->assertKeywordRankPayload($payload);
        } elseif ($type === 'qa.status') {
            $validatedQa = self::validateQaStatusPayload($payload);
            if ($validatedQa === null) {
                throw new \InvalidArgumentException('Payload qa.status inválido');
            }
            throw new \RuntimeException(
                'qa.status bloqueado: produtor confiável com evidência verificável não configurado'
            );
        }

        $source = $source === 'seed' ? 'seed' : 'live';
        if ($source === 'seed' && !$this->seedEnabled()) {
            throw new \RuntimeException('PREGAO_SEED=false — emissão seed bloqueada');
        }

        $ts = $this->nowIso();
        $event = [
            'v' => self::VERSION,
            'type' => $type,
            'ts' => $ts,
            'payload' => $payload,
            'source' => $source,
        ];
        $event['account_id'] = $accountId;

        $persisted = $this->persist($event);
        $this->persistSideEffects($event);
        $published = $this->publish($event);
        if ($type === 'agent.status' && !$persisted && !$published) {
            throw new \RuntimeException('Falha ao entregar agent.status');
        }

        return $event;
    }

    /**
     * @param array<string, mixed>|string|null $raw
     * @return array{running:bool,suite:string,test:string,result:string,video_url:?string,stream_url:?string}|null
     */
    public static function validateQaStatusPayload(array|string|null $raw): ?array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return null;
            }
            $raw = $decoded;
        }
        if (!is_array($raw)) {
            return null;
        }

        $keys = array_keys($raw);
        sort($keys, SORT_STRING);
        if (array_diff($keys, self::QA_STATUS_ALLOWED_KEYS) !== []
            || array_diff(self::QA_STATUS_REQUIRED_KEYS, $keys) !== []
            || !is_bool($raw['running'])
            || !is_string($raw['suite'])
            || trim($raw['suite']) === ''
            || strlen($raw['suite']) > 100
            || !is_string($raw['test'])
            || trim($raw['test']) === ''
            || strlen($raw['test']) > 200
            || !is_string($raw['result'])
            || !in_array($raw['result'], self::QA_STATUS_RESULTS, true)
            || ($raw['running'] === true) !== ($raw['result'] === 'running')
        ) {
            return null;
        }

        $videoUrl = self::validateQaMediaPath($raw['video_url'] ?? null);
        $streamUrl = self::validateQaMediaPath($raw['stream_url'] ?? null);
        if ((array_key_exists('video_url', $raw) && $videoUrl === null && $raw['video_url'] !== null)
            || (array_key_exists('stream_url', $raw) && $streamUrl === null && $raw['stream_url'] !== null)
            || ($videoUrl !== null && $streamUrl !== null)
        ) {
            return null;
        }

        return [
            'running' => $raw['running'],
            'suite' => trim($raw['suite']),
            'test' => trim($raw['test']),
            'result' => $raw['result'],
            'video_url' => $videoUrl,
            'stream_url' => $streamUrl,
        ];
    }

    private static function validateQaMediaPath(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)
            || str_contains($value, '..')
            || preg_match('#^/(?:qa|storage/qa)/[A-Za-z0-9_./-]+$#D', $value) !== 1
        ) {
            return null;
        }

        return $value;
    }

    private function assertAgentStatusPayload(array $payload, ?int $accountId): void
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if ($accountId === null
            || $accountId <= 0
            || $keys !== self::AGENT_STATUS_KEYS
            || !is_string($payload['agent'])
            || !in_array($payload['agent'], self::AGENT_STATUS_AGENTS, true)
            || !is_string($payload['status'])
            || !in_array($payload['status'], self::AGENT_STATUSES, true)
            || !is_string($payload['reason'])
            || !$this->isAgentStatusReason($payload['reason'])
            || !PregaoAgentStatusService::isStatusReasonCoherent($payload['status'], $payload['reason'])
            || !is_string($payload['correlation_id'])
            || preg_match(
                '/^agent24x7-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}:'
                    . preg_quote((string) $accountId, '/') . '$/D',
                $payload['correlation_id']
            ) !== 1
            || !is_int($payload['attempts'])
            || $payload['attempts'] < 1
            || $payload['attempts'] > 3
            || $payload['state_changed'] !== false
            || $payload['ml_write_automation'] !== false
        ) {
            throw new \InvalidArgumentException('Payload agent.status inválido');
        }
    }

    private function isAgentStatusReason(string $reason): bool
    {
        return in_array($reason, self::AGENT_STATUS_REASONS, true)
            || preg_match('/^legacy_http_[1345][0-9]{2}$/D', $reason) === 1;
    }

    /** @param array<string, mixed> $payload */
    private function assertKeywordRankPayload(array $payload): void
    {
        if (!self::isKeywordRankPayloadValid($payload)) {
            throw new \InvalidArgumentException('Payload keyword.rank inválido');
        }
    }

    /** @param array<string, mixed> $payload */
    public static function isKeywordRankPayloadValid(array $payload): bool
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        return $keys !== self::KEYWORD_RANK_KEYS
            || !is_string($payload['kw'])
            ? false
            : trim($payload['kw']) !== ''
                && mb_strlen($payload['kw']) <= 200
                && is_int($payload['pos'])
                && $payload['pos'] >= 1
                && ($payload['delta'] === null || is_int($payload['delta']));
    }

    public function seedEnabled(): bool
    {
        $raw = $_ENV['PREGAO_SEED'] ?? getenv('PREGAO_SEED') ?: 'false';
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Emite `op` somente em transição de estado (fingerprint diferente do último).
     * Exceção: heartbeat info no máximo 1×/hora por chave/robô, para provar que o coletor está vivo.
     *
     * Chave Redis: pregao:last_state:{accountId}:{stateKey}
     *
     * @param array<string, mixed> $payload payload do op (robot, level, icon, msg, …)
     * @param array<string, mixed>|string $stateFingerprint valor comparado com o último persistido
     * @param 'live'|'seed' $source
     * @return array<string, mixed>|null evento emitido, ou null se silenciado
     */
    public function emitOpOnTransition(
        string $stateKey,
        array $payload,
        array|string $stateFingerprint,
        ?int $accountId = null,
        string $source = 'live'
    ): ?array {
        $accountPart = ($accountId !== null && $accountId > 0) ? (string) $accountId : '0';
        $cacheKey = 'pregao:last_state:' . $accountPart . ':' . $stateKey;
        $fingerprint = is_string($stateFingerprint)
            ? $stateFingerprint
            : (string) json_encode($stateFingerprint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $previous = $this->readLastState($cacheKey);
        $changed = $previous === null || $previous !== $fingerprint;
        $robot = (string) ($payload['robot'] ?? 'SISTEMA');
        // P0: heartbeat ≤1×/h por coletor (robô), não por stateKey/SKU
        $hbKey = 'pregao:heartbeat:' . $accountPart . ':robot:' . $robot;

        if ($changed) {
            $this->writeLastState($cacheKey, $fingerprint);
            // reinicia janela de heartbeat do coletor — próximo só após 1h sem transição
            $this->markHeartbeat($hbKey);
            return $this->emit('op', $payload, $accountId, $source);
        }

        if ($this->shouldEmitHeartbeat($hbKey)) {
            $hbPayload = [
                'robot' => $robot,
                'level' => 'info',
                'icon' => (string) ($payload['icon'] ?? '•'),
                'heartbeat' => true,
                // msg neutra — não reaproveitar texto de alerta (evita "candidato a pausa · heartbeat")
                'msg' => $robot . ' · heartbeat (coletor vivo)',
            ];
            $this->markHeartbeat($hbKey);
            return $this->emit('op', $hbPayload, $accountId, $source);
        }

        return null;
    }

    private function readLastState(string $cacheKey): ?string
    {
        $redis = $this->redis();
        if ($redis !== null) {
            try {
                $val = $redis->get($cacheKey);
                if (is_string($val) && $val !== '') {
                    return $val;
                }
            } catch (Throwable) {
                log_warning('PregaoEmitService: falha ao ler last_state', ['reason' => 'last_state_read_failed']);
            }
        }

        return $this->lastStateMemory[$cacheKey] ?? null;
    }

    private function writeLastState(string $cacheKey, string $fingerprint): void
    {
        $this->lastStateMemory[$cacheKey] = $fingerprint;
        $redis = $this->redis();
        if ($redis === null) {
            return;
        }
        try {
            $redis->set($cacheKey, $fingerprint);
        } catch (Throwable) {
            log_warning('PregaoEmitService: falha ao gravar last_state', ['reason' => 'last_state_write_failed']);
        }
    }

    private function shouldEmitHeartbeat(string $hbKey): bool
    {
        $now = time();
        $redis = $this->redis();
        if ($redis !== null) {
            try {
                $last = $redis->get($hbKey);
                if (is_string($last) && $last !== '') {
                    return ($now - (int) $last) >= self::OP_HEARTBEAT_TTL_SECONDS;
                }
            } catch (Throwable) {
                log_warning('PregaoEmitService: falha heartbeat Redis', ['reason' => 'heartbeat_read_failed']);
            }
        }

        $lastMem = $this->lastHeartbeatMemory[$hbKey] ?? 0;
        return ($now - $lastMem) >= self::OP_HEARTBEAT_TTL_SECONDS;
    }

    private function markHeartbeat(string $hbKey): void
    {
        $now = time();
        $this->lastHeartbeatMemory[$hbKey] = $now;
        $redis = $this->redis();
        if ($redis === null) {
            return;
        }
        try {
            $redis->set($hbKey, (string) $now, ['ex' => self::OP_HEARTBEAT_TTL_SECONDS * 2]);
        } catch (Throwable) {
            log_warning('PregaoEmitService: falha ao gravar heartbeat', ['reason' => 'heartbeat_write_failed']);
        }
    }

    /**
     * Efeitos colaterais de persistência tipados (ranks, etc.).
     *
     * @param array{v: int, type: string, ts: string, payload: array<string, mixed>, account_id?: int} $event
     */
    private function persistSideEffects(array $event): void
    {
        if (($event['type'] ?? '') !== 'keyword.rank') {
            return;
        }
        $accountId = isset($event['account_id']) ? (int) $event['account_id'] : 0;
        if ($accountId <= 0) {
            return;
        }
        $kw = (string) ($event['payload']['kw'] ?? '');
        $pos = (int) ($event['payload']['pos'] ?? 0);
        if ($kw === '' || $pos <= 0) {
            return;
        }
        $delta = isset($event['payload']['delta']) ? (int) $event['payload']['delta'] : null;
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
        try {
            $this->pdo()->prepare(
                'INSERT INTO keyword_ranks (account_id, kw, `date`, pos, delta)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE pos = VALUES(pos), delta = VALUES(delta)'
            )->execute([$accountId, $kw, $date, $pos, $delta]);
        } catch (Throwable) {
            log_warning('PregaoEmitService: falha ao persistir keyword.rank', ['reason' => 'keyword_rank_persist_failed']);
        }
    }

    /**
     * Processa venda confirmada: sale + metric.update derivados + op VENDA.
     *
     * @param array{order_id: string, valor: float|int|string, titulo?: string, sku?: string} $sale
     * @param 'live'|'seed' $source
     * @return list<array<string, mixed>>
     */
    public function emitSale(array $sale, ?int $accountId = null, string $source = 'live'): array
    {
        $orderId = (string) ($sale['order_id'] ?? '');
        $valor = (float) ($sale['valor'] ?? 0);
        $titulo = (string) ($sale['titulo'] ?? '');
        $sku = isset($sale['sku']) ? (string) $sale['sku'] : null;

        $events = [];
        $events[] = $this->emit('sale', array_filter([
            'order_id' => $orderId,
            'valor' => $valor,
            'titulo' => $titulo,
            'sku' => $sku,
        ], static fn ($v) => $v !== null && $v !== ''), $accountId, $source);

        $metrics = $this->bumpSaleMetrics($accountId, $valor);

        $events[] = $this->emit('metric.update', [
            'key' => 'vendas_hoje',
            'value' => $metrics['vendas_hoje'],
            'delta' => '+1',
            'flash' => 'green',
        ], $accountId, $source);

        $events[] = $this->emit('metric.update', [
            'key' => 'receita_hoje',
            'value' => $metrics['receita_hoje'],
            'delta' => '+' . number_format($valor, 2, '.', ''),
            'flash' => 'green',
        ], $accountId, $source);

        $events[] = $this->emit('metric.update', [
            'key' => 'ticket_medio',
            'value' => $metrics['ticket_medio'],
            'delta' => null,
            'flash' => 'green',
        ], $accountId, $source);

        $msg = sprintf(
            'VENDA — +1 · R$ %s · %s',
            number_format($valor, 2, ',', '.'),
            $titulo !== '' ? $titulo : $orderId
        );

        $events[] = $this->emit('op', array_filter([
            'robot' => 'VENDA',
            'level' => 'success',
            'icon' => '🛒',
            'msg' => $msg,
            'sku' => $sku,
            'meta' => ['order_id' => $orderId, 'valor' => $valor],
        ], static fn ($v) => $v !== null), $accountId, $source);

        return $events;
    }

    /**
     * @return array{vendas_hoje: int, receita_hoje: float, ticket_medio: float}
     */
    private function bumpSaleMetrics(?int $accountId, float $valor): array
    {
        $defaults = [
            'vendas_hoje' => 1,
            'receita_hoje' => round($valor, 2),
            'ticket_medio' => round($valor, 2),
        ];

        if ($accountId === null || $accountId <= 0) {
            return $defaults;
        }

        try {
            $pdo = $this->pdo();
            $pdo->prepare(
                'INSERT INTO account_index_metrics (account_id, vendas_hoje, receita_hoje, ticket_medio)
                 VALUES (?, 1, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   vendas_hoje = vendas_hoje + 1,
                   receita_hoje = receita_hoje + VALUES(receita_hoje),
                   ticket_medio = ROUND((receita_hoje + VALUES(receita_hoje)) / (vendas_hoje + 1), 2)'
            )->execute([$accountId, $valor, $valor]);

            $stmt = $pdo->prepare(
                'SELECT vendas_hoje, receita_hoje, ticket_medio FROM account_index_metrics WHERE account_id = ?'
            );
            $stmt->execute([$accountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return [
                    'vendas_hoje' => (int) $row['vendas_hoje'],
                    'receita_hoje' => (float) $row['receita_hoje'],
                    'ticket_medio' => (float) $row['ticket_medio'],
                ];
            }
        } catch (Throwable) {
            log_warning('PregaoEmitService: falha ao atualizar métricas de venda', [
                'account_id' => $accountId,
                'reason' => 'sale_metrics_update_failed',
            ]);
        }

        return $defaults;
    }

    /**
     * @param array{v: int, type: string, ts: string, payload: array<string, mixed>, source?: string, account_id?: int} $event
     */
    private function persist(array $event): bool
    {
        try {
            $pdo = $this->pdo();
            $tsMysql = $this->isoToMysql($event['ts']);
            $payloadJson = json_encode($event['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $source = (string) ($event['source'] ?? 'live');

            if ($this->hasSourceColumn()) {
                $stmt = $pdo->prepare(
                    'INSERT INTO pregao_events (account_id, type, ts, payload, source) VALUES (?, ?, ?, ?, ?)'
                );
                return $stmt->execute([
                    $event['account_id'] ?? null,
                    $event['type'],
                    $tsMysql,
                    $payloadJson,
                    $source,
                ]);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO pregao_events (account_id, type, ts, payload) VALUES (?, ?, ?, ?)'
            );
            return $stmt->execute([
                $event['account_id'] ?? null,
                $event['type'],
                $tsMysql,
                $payloadJson,
            ]);
        } catch (Throwable) {
            // Persistência não deve derrubar o worker; o canal Redis ainda pode entregar.
            log_warning('PregaoEmitService: falha ao persistir evento', [
                'type' => $event['type'],
                'reason' => 'persist_exception',
            ]);
            return false;
        }
    }

    private function hasSourceColumn(): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        try {
            $stmt = $this->pdo()->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'pregao_events'
                   AND COLUMN_NAME = 'source'"
            );
            if (!is_object($stmt)) {
                $has = false;
                return false;
            }
            $has = ((int) $stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            $has = false;
        }
        return $has;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function publish(array $event): bool
    {
        try {
            $redis = $this->redis();
            if ($redis === null) {
                return false;
            }
            $json = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $redis->publish(self::CHANNEL, $json);
            // Fan-out para o gateway WS (polling LPOP) — lista limitada
            $queued = $redis->lPush('pregao:fanout', $json);
            $redis->lTrim('pregao:fanout', 0, 999);
            return $queued !== false;
        } catch (Throwable) {
            log_warning('PregaoEmitService: falha ao publicar no Redis', [
                'type' => $event['type'] ?? null,
                'reason' => 'publish_exception',
            ]);
            return false;
        }
    }

    private function pdo(): PDO
    {
        if ($this->db === null) {
            $this->db = Database::getInstance();
        }
        return $this->db;
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
            $redis->connect($host, $port, 1.5);
            $pass = $_ENV['REDIS_PASSWORD'] ?? '';
            if (!empty($pass) && $pass !== 'null') {
                $redis->auth($pass);
            }
            $db = (int) ($_ENV['REDIS_DB'] ?? 0);
            $redis->select($db);
            $this->redis = $redis;
        } catch (Throwable) {
            log_warning('PregaoEmitService: Redis indisponível', ['reason' => 'redis_connection_failed']);
            $this->redis = null;
        }

        return $this->redis;
    }

    private function nowIso(): string
    {
        $dt = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        return $dt->format('Y-m-d\TH:i:sP');
    }

    private function isoToMysql(string $iso): string
    {
        try {
            $dt = new \DateTimeImmutable($iso);
            return $dt->format('Y-m-d H:i:s.v');
        } catch (Throwable $e) {
            return (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s.v');
        }
    }
}

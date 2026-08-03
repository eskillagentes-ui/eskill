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
 * Envelope: { v, type, ts, payload, account_id? }
 */
final class PregaoEmitService
{
    public const CHANNEL = 'pregao';
    /** v2: `ranks` oficial no snapshot; `keywords` alias deprecado. */
    public const VERSION = 2;

    /** Heartbeat de op por coletor: no máximo 1× por hora. */
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
        'account.semaforo',
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
     * @return array{v: int, type: string, ts: string, payload: array<string, mixed>, source: string, account_id?: int}
     */
    public function emit(string $type, array $payload, ?int $accountId = null, string $source = 'live'): array
    {
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException("Tipo de evento pregao inválido: {$type}");
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
        if ($accountId !== null && $accountId > 0) {
            $event['account_id'] = $accountId;
        }

        $this->persist($event);
        $this->persistSideEffects($event);
        $this->publish($event);

        return $event;
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

        if ($changed) {
            $this->writeLastState($cacheKey, $fingerprint);
            // reinicia janela de heartbeat — próximo heartbeat só após 1h sem mudança
            $this->markHeartbeat('pregao:heartbeat:' . $accountPart . ':' . $stateKey);
            return $this->emit('op', $payload, $accountId, $source);
        }

        $hbKey = 'pregao:heartbeat:' . $accountPart . ':' . $stateKey;
        if ($this->shouldEmitHeartbeat($hbKey)) {
            $hbPayload = $payload;
            $hbPayload['level'] = 'info';
            $hbPayload['heartbeat'] = true;
            $msg = (string) ($payload['msg'] ?? '');
            $hbPayload['msg'] = ($msg !== '' ? $msg . ' · ' : '') . 'heartbeat (coletor vivo)';
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
            } catch (Throwable $e) {
                log_warning('PregaoEmitService: falha ao ler last_state', ['error' => $e->getMessage()]);
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
        } catch (Throwable $e) {
            log_warning('PregaoEmitService: falha ao gravar last_state', ['error' => $e->getMessage()]);
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
            } catch (Throwable $e) {
                log_warning('PregaoEmitService: falha heartbeat Redis', ['error' => $e->getMessage()]);
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
        } catch (Throwable $e) {
            log_warning('PregaoEmitService: falha ao gravar heartbeat', ['error' => $e->getMessage()]);
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
        } catch (Throwable $e) {
            log_warning('PregaoEmitService: falha ao persistir keyword.rank', ['error' => $e->getMessage()]);
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
        } catch (Throwable $e) {
            log_warning('PregaoEmitService: falha ao atualizar métricas de venda', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
        }

        return $defaults;
    }

    /**
     * @param array{v: int, type: string, ts: string, payload: array<string, mixed>, source?: string, account_id?: int} $event
     */
    private function persist(array $event): void
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
                $stmt->execute([
                    $event['account_id'] ?? null,
                    $event['type'],
                    $tsMysql,
                    $payloadJson,
                    $source,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO pregao_events (account_id, type, ts, payload) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([
                    $event['account_id'] ?? null,
                    $event['type'],
                    $tsMysql,
                    $payloadJson,
                ]);
            }
        } catch (Throwable $e) {
            // Persistência não deve derrubar o worker; o canal Redis ainda pode entregar.
            log_warning('PregaoEmitService: falha ao persistir evento', [
                'type' => $event['type'],
                'error' => $e->getMessage(),
            ]);
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
    private function publish(array $event): void
    {
        try {
            $redis = $this->redis();
            if ($redis === null) {
                return;
            }
            $json = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $redis->publish(self::CHANNEL, $json);
            // Fan-out para o gateway WS (polling LPOP) — lista limitada
            $redis->lPush('pregao:fanout', $json);
            $redis->lTrim('pregao:fanout', 0, 999);
        } catch (Throwable $e) {
            log_warning('PregaoEmitService: falha ao publicar no Redis', [
                'type' => $event['type'] ?? null,
                'error' => $e->getMessage(),
            ]);
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
        } catch (Throwable $e) {
            log_warning('PregaoEmitService: Redis indisponível', ['error' => $e->getMessage()]);
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

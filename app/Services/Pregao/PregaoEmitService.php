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
    public const VERSION = 1;

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
     * @return array{v: int, type: string, ts: string, payload: array<string, mixed>, account_id?: int}
     */
    public function emit(string $type, array $payload, ?int $accountId = null): array
    {
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException("Tipo de evento pregao inválido: {$type}");
        }

        $ts = $this->nowIso();
        $event = [
            'v' => self::VERSION,
            'type' => $type,
            'ts' => $ts,
            'payload' => $payload,
        ];
        if ($accountId !== null && $accountId > 0) {
            $event['account_id'] = $accountId;
        }

        $this->persist($event);
        $this->publish($event);

        return $event;
    }

    /**
     * Processa venda confirmada: sale + metric.update derivados + op VENDA.
     *
     * @param array{order_id: string, valor: float|int|string, titulo?: string, sku?: string} $sale
     * @return list<array<string, mixed>>
     */
    public function emitSale(array $sale, ?int $accountId = null): array
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
        ], static fn ($v) => $v !== null && $v !== ''), $accountId);

        $metrics = $this->bumpSaleMetrics($accountId, $valor);

        $events[] = $this->emit('metric.update', [
            'key' => 'vendas_hoje',
            'value' => $metrics['vendas_hoje'],
            'delta' => '+1',
            'flash' => 'green',
        ], $accountId);

        $events[] = $this->emit('metric.update', [
            'key' => 'receita_hoje',
            'value' => $metrics['receita_hoje'],
            'delta' => '+' . number_format($valor, 2, '.', ''),
            'flash' => 'green',
        ], $accountId);

        $events[] = $this->emit('metric.update', [
            'key' => 'ticket_medio',
            'value' => $metrics['ticket_medio'],
            'delta' => null,
            'flash' => 'green',
        ], $accountId);

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
        ], static fn ($v) => $v !== null), $accountId);

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
     * @param array{v: int, type: string, ts: string, payload: array<string, mixed>, account_id?: int} $event
     */
    private function persist(array $event): void
    {
        try {
            $pdo = $this->pdo();
            $stmt = $pdo->prepare(
                'INSERT INTO pregao_events (account_id, type, ts, payload) VALUES (?, ?, ?, ?)'
            );
            $tsMysql = $this->isoToMysql($event['ts']);
            $stmt->execute([
                $event['account_id'] ?? null,
                $event['type'],
                $tsMysql,
                json_encode($event['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $e) {
            // Persistência não deve derrubar o worker; o canal Redis ainda pode entregar.
            log_warning('PregaoEmitService: falha ao persistir evento', [
                'type' => $event['type'],
                'error' => $e->getMessage(),
            ]);
        }
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
            $redis->publish(
                self::CHANNEL,
                json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
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

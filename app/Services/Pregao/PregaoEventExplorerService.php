<?php

declare(strict_types=1);

namespace App\Services\Pregao;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

/**
 * Consulta paginada e somente leitura dos eventos persistidos do Pregão.
 * O payload bruto nunca atravessa este contrato; somente campos explicitamente permitidos.
 */
final class PregaoEventExplorerService
{
    public const DEFAULT_PER_PAGE = 50;
    public const MAX_PER_PAGE = 100;

    /** @var list<string> */
    private const SOURCES = ['live', 'seed'];

    /** @var array<string, list<string>> */
    private const SAFE_DETAIL_KEYS = [
        'account.semaforo' => ['status'],
        'agent.status' => [
            'agent',
            'attempts',
            'correlation_id',
            'ml_write_automation',
            'reason',
            'state_changed',
            'status',
        ],
        'index.candle' => ['c', 'date', 'h', 'l', 'o'],
        'index.tick' => ['factors_active', 'factors_total', 'label', 'value'],
        'keyword.rank' => ['delta', 'kw', 'pos'],
        'metric.update' => ['available', 'flash', 'key', 'message', 'reason', 'source', 'value'],
        'op' => ['heartbeat', 'icon', 'level', 'msg', 'robot'],
        'qa.status' => ['result', 'running', 'suite', 'test'],
        'sale' => ['currency_id', 'item_id', 'quantity', 'sku', 'total'],
    ];

    private PDO $db;
    private bool $seedEnabled;

    public function __construct(PDO $db, bool $seedEnabled = false)
    {
        $this->db = $db;
        $this->seedEnabled = $seedEnabled;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *   items:list<array{id:int,type:string,ts:string|null,source:string,details:array<string,int|float|string|bool|null>}>,
     *   pagination:array{page:int,per_page:int,total:int,pages:int,has_previous:bool,has_next:bool},
     *   read_only:true
     * }
     */
    public function listForAccount(int $accountId, array $filters = []): array
    {
        if ($accountId <= 0) {
            throw new InvalidArgumentException('Conta inválida para eventos do Pregão');
        }

        $page = $this->positiveInteger($filters['page'] ?? 1, 'page');
        $perPage = $this->positiveInteger($filters['per_page'] ?? self::DEFAULT_PER_PAGE, 'per_page');
        if ($perPage > self::MAX_PER_PAGE) {
            throw new InvalidArgumentException('per_page acima do limite');
        }

        $type = $this->optionalString($filters['type'] ?? null);
        if ($type !== null && !in_array($type, PregaoEmitService::VALID_TYPES, true)) {
            throw new InvalidArgumentException('Tipo de evento inválido');
        }

        $source = $this->optionalString($filters['source'] ?? null);
        if ($source !== null && !in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Origem de evento inválida');
        }
        if ($source === 'seed' && !$this->seedEnabled) {
            throw new InvalidArgumentException('Eventos seed indisponíveis');
        }

        $from = $this->optionalDate($filters['from'] ?? null, 'from');
        $to = $this->optionalDate($filters['to'] ?? null, 'to');
        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidArgumentException('Intervalo de datas inválido');
        }

        $where = ['account_id = :account_id'];
        $params = [':account_id' => $accountId];
        if (!$this->seedEnabled) {
            $where[] = 'source <> :blocked_source';
            $params[':blocked_source'] = 'seed';
        }
        if ($type !== null) {
            $where[] = 'type = :event_type';
            $params[':event_type'] = $type;
        }
        if ($source !== null) {
            $where[] = 'source = :event_source';
            $params[':event_source'] = $source;
        }
        if ($from !== null) {
            $where[] = 'ts >= :from_ts';
            $params[':from_ts'] = $from . ' 00:00:00';
        }
        if ($to !== null) {
            $where[] = 'ts <= :to_ts';
            $params[':to_ts'] = $to . ' 23:59:59.999999';
        }
        $whereSql = implode(' AND ', $where);

        $count = $this->db->prepare('SELECT COUNT(*) FROM pregao_events WHERE ' . $whereSql);
        $count->execute($params);
        $total = max(0, (int) $count->fetchColumn());
        $pages = $total === 0 ? 0 : (int) ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare(
            'SELECT id, type, ts, payload, source
             FROM pregao_events
             WHERE ' . $whereSql . '
             ORDER BY ts DESC, id DESC
             LIMIT :event_limit OFFSET :event_offset'
        );
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':event_limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':event_offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $eventType = is_string($row['type'] ?? null) ? $row['type'] : '';
            if (!in_array($eventType, PregaoEmitService::VALID_TYPES, true)) {
                continue;
            }
            $payload = is_string($row['payload'] ?? null)
                ? json_decode($row['payload'], true)
                : $row['payload'];
            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'type' => $eventType,
                'ts' => $this->mysqlToIso(is_string($row['ts'] ?? null) ? $row['ts'] : ''),
                'source' => ($row['source'] ?? null) === 'seed' ? 'seed' : 'live',
                'details' => $this->sanitizeDetails($eventType, is_array($payload) ? $payload : []),
            ];
        }

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => $pages,
                'has_previous' => $page > 1 && $total > 0,
                'has_next' => $pages > 0 && $page < $pages,
            ],
            'read_only' => true,
        ];
    }

    /** @return array<string, int|float|string|bool|null> */
    private function sanitizeDetails(string $type, array $payload): array
    {
        $details = [];
        foreach (self::SAFE_DETAIL_KEYS[$type] ?? [] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if (!is_scalar($value) && $value !== null) {
                continue;
            }
            if (is_string($value)) {
                $value = mb_substr($value, 0, 300);
            }
            $details[$key] = $value;
        }
        return $details;
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $parsed = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $parsed = (int) $value;
        } else {
            throw new InvalidArgumentException("{$field} inválido");
        }
        if ($parsed <= 0) {
            throw new InvalidArgumentException("{$field} inválido");
        }
        return $parsed;
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Filtro textual inválido');
        }
        return $value;
    }

    private function optionalDate(mixed $value, string $field): ?string
    {
        $value = $this->optionalString($value);
        if ($value === null) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $value
        ) {
            throw new InvalidArgumentException("{$field} inválido");
        }
        return $value;
    }

    private function mysqlToIso(string $timestamp): ?string
    {
        $timezone = new DateTimeZone('America/Sao_Paulo');
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i:s.v', 'Y-m-d H:i:s.u'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!' . $format, $timestamp, $timezone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed !== false
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $parsed->format($format) === $timestamp
            ) {
                return $parsed->format('Y-m-d\TH:i:sP');
            }
        }
        return null;
    }
}

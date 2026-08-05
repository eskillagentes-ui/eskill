<?php

declare(strict_types=1);

namespace App\Services\Pregao;

use App\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

/**
 * Event Explorer read-only do Pregão: histórico paginado de pregao_events.
 *
 * Payloads atravessam somente allowlists por tipo — campos internos,
 * URLs de mídia e segredos nunca chegam ao dashboard.
 */
final class PregaoEventExplorerService
{
    public const MAX_PER_PAGE = 100;
    public const DEFAULT_PER_PAGE = 25;
    private const MAX_PAYLOAD_STRING_BYTES = 500;

    /** @var list<string> */
    private const SOURCES = ['live', 'seed'];

    /**
     * Campos escalares permitidos por tipo (agent.status usa o contrato
     * completo de PregaoAgentStatusService::validatePayload).
     *
     * @var array<string, list<string>>
     */
    private const PAYLOAD_ALLOWLIST = [
        'op' => ['icon', 'level', 'msg', 'robot', 'sku'],
        'sale' => ['order_id', 'sku', 'titulo', 'valor'],
        'index.tick' => ['value'],
        'index.candle' => ['c', 'date', 'h', 'l', 'o'],
        'metric.update' => ['flash', 'key', 'value'],
        'keyword.rank' => ['delta', 'kw', 'pos'],
        'qa.status' => ['result', 'running', 'suite', 'test'],
        'account.semaforo' => ['status'],
    ];

    private PDO $db;

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(?PDO $db = null, ?array $config = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->config = $config ?? (require dirname(__DIR__, 3) . '/config/pregao.php');
    }

    /**
     * @param array<string, mixed> $filters type|source|from|to|page|per_page
     * @return array{
     *   events: list<array{id: int, type: string, source: string, ts: string|null, payload: array<string, mixed>|null}>,
     *   pagination: array{page: int, per_page: int, total: int, pages: int, has_prev: bool, has_next: bool},
     *   read_only: true
     * }
     */
    public function list(int $accountId, array $filters = []): array
    {
        if ($accountId <= 0) {
            throw new InvalidArgumentException('Conta inválida para o Event Explorer');
        }

        $page = $this->normalizePositiveInteger($filters['page'] ?? null, 1, 'page');
        $perPage = min(
            self::MAX_PER_PAGE,
            $this->normalizePositiveInteger(
                $filters['per_page'] ?? null,
                self::DEFAULT_PER_PAGE,
                'per_page'
            )
        );

        $type = $this->normalizeType($filters['type'] ?? null);
        $source = $this->normalizeSource($filters['source'] ?? null);
        $from = $this->normalizeDate($filters['from'] ?? null, false);
        $to = $this->normalizeDate($filters['to'] ?? null, true);
        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidArgumentException('Intervalo de datas inválido');
        }

        $conditions = ['account_id = ?'];
        $params = [$accountId];
        if ($type !== null) {
            $conditions[] = 'type = ?';
            $params[] = $type;
        }
        if ($source !== null) {
            $conditions[] = 'source = ?';
            $params[] = $source;
        }
        if (!(bool) ($this->config['seed_enabled'] ?? false)) {
            $conditions[] = "source <> 'seed'";
        }
        if ($from !== null) {
            $conditions[] = 'ts >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $conditions[] = 'ts <= ?';
            $params[] = $to;
        }
        $where = implode(' AND ', $conditions);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM pregao_events WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $pages = $total > 0 ? (int) ceil($total / $perPage) : 0;
        $events = [];
        if ($page <= $pages) {
            $stmt = $this->db->prepare(
                "SELECT id, type, ts, source, payload
                 FROM pregao_events
                 WHERE {$where}
                 ORDER BY ts DESC, id DESC
                 LIMIT ? OFFSET ?"
            );
            $position = 1;
            foreach ($params as $param) {
                $stmt->bindValue($position++, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->bindValue($position++, $perPage, PDO::PARAM_INT);
            $stmt->bindValue($position, ($page - 1) * $perPage, PDO::PARAM_INT);
            $stmt->execute();

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rowType = (string) $row['type'];
                $events[] = [
                    'id' => (int) $row['id'],
                    'type' => $rowType,
                    'source' => (string) ($row['source'] ?? 'live'),
                    'ts' => $this->mysqlToIso((string) $row['ts']),
                    'payload' => $this->sanitizePayload($rowType, $row['payload'], $accountId),
                ];
            }
        }

        return [
            'events' => $events,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => $pages,
                'has_prev' => $page > 1,
                'has_next' => $page < $pages,
            ],
            'read_only' => true,
        ];
    }

    private function normalizeType(mixed $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }
        if (!is_string($type) || !in_array($type, PregaoEmitService::VALID_TYPES, true)) {
            throw new InvalidArgumentException('Tipo de evento inválido');
        }
        return $type;
    }

    private function normalizeSource(mixed $source): ?string
    {
        if ($source === null || $source === '') {
            return null;
        }
        if (!is_string($source) || !in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Fonte de evento inválida');
        }
        return $source;
    }

    /**
     * Datas de filtro chegam como Y-m-d (inputs de data) no fuso America/Sao_Paulo,
     * mesmo fuso em que pregao_events.ts é persistido.
     */
    private function normalizeDate(mixed $date, bool $endOfDay): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        if (!is_string($date)) {
            throw new InvalidArgumentException('Data de filtro inválida');
        }
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $date,
            new DateTimeZone('America/Sao_Paulo')
        );
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $date
        ) {
            throw new InvalidArgumentException('Data de filtro inválida');
        }
        return $parsed->format('Y-m-d') . ($endOfDay ? ' 23:59:59.999' : ' 00:00:00');
    }

    private function normalizePositiveInteger(mixed $value, int $default, string $field): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value)) {
            $validated = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if (is_int($validated)) {
                return $validated;
            }
        }

        throw new InvalidArgumentException("Filtro {$field} inválido");
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sanitizePayload(string $type, mixed $rawPayload, int $accountId): ?array
    {
        $payload = is_string($rawPayload)
            ? (json_decode($rawPayload, true) ?: [])
            : (is_array($rawPayload) ? $rawPayload : []);

        if ($type === 'qa.status') {
            // Histórico sem recibo de produtor confiável não prova execução.
            return null;
        }
        if ($type === 'agent.status') {
            return PregaoAgentStatusService::validatePayload($payload, $accountId);
        }

        $allowed = self::PAYLOAD_ALLOWLIST[$type] ?? null;
        if ($allowed === null) {
            return null;
        }

        $safe = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if ($value === null || is_scalar($value)) {
                $safe[$key] = $this->sanitizePayloadValue($value);
            }
        }
        return $safe;
    }

    private function sanitizePayloadValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        if (preg_match('//u', $value) !== 1) {
            return '[INVALID TEXT]';
        }

        $secretValue = '(?:"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|[^\s,;]+)';
        $secretKey = '(?:authorization|api[_-]?key|(?:[a-z0-9]+[_-])*(?:token|secret|password))';
        $redacted = preg_replace(
            '/\bAuthorization\s*:\s*(?:Basic|Bearer)\s+' . $secretValue . '/iu',
            'Authorization: [REDACTED]',
            $value
        );
        $redacted = preg_replace(
            '/\b(?:Basic|Bearer)\s+' . $secretValue . '/iu',
            '[REDACTED]',
            $redacted ?? ''
        );
        $redacted = preg_replace(
            '/\b(' . $secretKey . ')\s*[:=]\s*' . $secretValue . '/iu',
            '$1=[REDACTED]',
            $redacted ?? ''
        );
        $redacted ??= '';

        if (strlen($redacted) <= self::MAX_PAYLOAD_STRING_BYTES) {
            return $redacted;
        }

        return function_exists('mb_strcut')
            ? mb_strcut($redacted, 0, self::MAX_PAYLOAD_STRING_BYTES, 'UTF-8')
            : substr($redacted, 0, self::MAX_PAYLOAD_STRING_BYTES);
    }

    private function mysqlToIso(string $mysqlTs): ?string
    {
        $timezone = new DateTimeZone('America/Sao_Paulo');
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i:s.v', 'Y-m-d H:i:s.u'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!' . $format, $mysqlTs, $timezone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed !== false
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $parsed->format($format) === $mysqlTs
            ) {
                $futureLimit = new DateTimeImmutable('+60 seconds', $timezone);
                if ($parsed > $futureLimit) {
                    return null;
                }
                return $parsed->format('Y-m-d\TH:i:sP');
            }
        }
        return null;
    }
}

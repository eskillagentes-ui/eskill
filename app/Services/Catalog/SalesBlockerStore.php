<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use PDO;
use PDOException;
use Throwable;

/**
 * Persistência local de bloqueios de venda (moderações ML).
 *
 * Schema live (já existente): queue/source_status/severity/wordings_json/updated_at,
 * unique (account_id, item_id, queue). Colunas extras são opcionais.
 */
final class SalesBlockerStore
{
    /** @var array<string, true>|null */
    private ?array $columns = null;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array{
     *     item_id: string,
     *     reason?: ?string,
     *     remedy?: ?string,
     *     filter_subgroup?: ?string,
     *     item_status?: ?string,
     *     sub_status?: list<string>|array<int, mixed>|null,
     *     infraction_id?: ?string,
     *     performance_json?: array<string, mixed>|null,
     *     scanned_by?: ?string
     * } $row
     */
    public function upsert(int $accountId, array $row): void
    {
        $itemId = strtoupper(trim((string) ($row['item_id'] ?? '')));
        if ($accountId <= 0 || $itemId === '') {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $reason = $this->nullableString(isset($row['reason']) ? (string) $row['reason'] : null);
        $remedy = $this->nullableString(isset($row['remedy']) ? (string) $row['remedy'] : null);
        $filter = $this->nullableString(isset($row['filter_subgroup']) ? (string) $row['filter_subgroup'] : null);
        $itemStatus = $this->nullableString(isset($row['item_status']) ? (string) $row['item_status'] : null) ?? 'pending';
        $infractionId = $this->nullableString(isset($row['infraction_id']) ? (string) $row['infraction_id'] : null);
        $scannedBy = $this->nullableString(isset($row['scanned_by']) ? (string) $row['scanned_by'] : null);

        $subStatus = $row['sub_status'] ?? null;
        $subStatusList = [];
        if (is_array($subStatus)) {
            $subStatusList = array_values(array_map('strval', $subStatus));
        }
        $subStatusJson = $subStatusList === [] ? null : json_encode($subStatusList, JSON_UNESCAPED_UNICODE);

        $perf = $row['performance_json'] ?? null;
        $perfJson = null;
        if (is_array($perf)) {
            $encodedPerf = json_encode($perf, JSON_UNESCAPED_UNICODE);
            $perfJson = $encodedPerf === false ? null : $encodedPerf;
        }

        $wordings = json_encode([
            'filter_subgroup' => $filter,
            'infraction_id' => $infractionId,
            'scanned_by' => $scannedBy,
            'sub_status' => $subStatusList,
        ], JSON_UNESCAPED_UNICODE);

        $values = [
            'account_id' => $accountId,
            'item_id' => $itemId,
            'reason' => $reason,
            'remedy' => $remedy,
            'scanned_at' => $now,
            'resolved_at' => null,
        ];

        $this->putIfColumn($values, 'queue', 'urgent');
        $this->putIfColumn($values, 'source_status', $itemStatus);
        $this->putIfColumn($values, 'severity', 'block');
        $this->putIfColumn($values, 'updated_at', $now);
        $this->putIfColumn($values, 'wordings_json', $wordings === false ? null : $wordings);
        $this->putIfColumn($values, 'performance_json', $perfJson);
        $this->putIfColumn($values, 'filter_subgroup', $filter);
        $this->putIfColumn($values, 'item_status', $itemStatus);
        $this->putIfColumn($values, 'sub_status_json', $subStatusJson === false ? null : $subStatusJson);
        $this->putIfColumn($values, 'infraction_id', $infractionId);
        $this->putIfColumn($values, 'scanned_by', $scannedBy);

        $columns = array_keys($values);
        $placeholders = [];
        foreach ($columns as $col) {
            $placeholders[] = ':' . $col;
        }

        $updates = [];
        foreach ($columns as $col) {
            if ($col === 'account_id' || $col === 'item_id' || $col === 'queue') {
                continue;
            }
            if ($this->isSqlite()) {
                $updates[] = $col . ' = excluded.' . $col;
            } else {
                $updates[] = $col . ' = VALUES(' . $col . ')';
            }
        }
        $updates[] = $this->isSqlite() ? 'resolved_at = NULL' : 'resolved_at = NULL';

        $insert = 'INSERT INTO ml_sales_blockers (' . implode(', ', $columns) . ')
            VALUES (' . implode(', ', $placeholders) . ')';

        if ($this->isSqlite()) {
            $conflict = $this->hasColumn('queue')
                ? 'account_id, item_id, queue'
                : 'account_id, item_id';
            $sql = $insert . ' ON CONFLICT(' . $conflict . ') DO UPDATE SET ' . implode(', ', $updates);
        } else {
            $sql = $insert . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
    }

    public function schemaReady(): bool
    {
        return $this->hasColumn('account_id') && $this->hasColumn('item_id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOpen(int $accountId): array
    {
        if ($accountId <= 0) {
            return [];
        }

        $wanted = [
            'item_id', 'reason', 'remedy', 'filter_subgroup', 'item_status',
            'source_status', 'queue', 'sub_status_json', 'wordings_json',
            'infraction_id', 'performance_json', 'scanned_by', 'scanned_at', 'resolved_at',
        ];
        $select = [];
        foreach ($wanted as $col) {
            if ($this->hasColumn($col)) {
                $select[] = $col;
            }
        }
        if ($select === [] || !$this->hasColumn('item_id')) {
            return [];
        }

        $where = ['account_id = :account_id'];
        if ($this->hasColumn('resolved_at')) {
            $where[] = 'resolved_at IS NULL';
        }
        $order = $this->hasColumn('scanned_at') ? ' ORDER BY scanned_at DESC' : '';

        try {
            $stmt = $this->pdo->prepare(
                'SELECT ' . implode(', ', $select) . '
                 FROM ml_sales_blockers
                 WHERE ' . implode(' AND ', $where) . $order
            );
            $stmt->execute(['account_id' => $accountId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            log_error('SalesBlockerStore::listOpen falhou', ['error' => $e->getMessage()]);
            throw $e;
        }

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = $this->normalizeRow($row);
        }

        return $out;
    }

    /**
     * @param list<string> $seenItemIds
     */
    public function markResolvedIfMissing(int $accountId, array $seenItemIds): int
    {
        if ($accountId <= 0) {
            return 0;
        }

        $normalized = [];
        foreach ($seenItemIds as $id) {
            $id = strtoupper(trim($id));
            if ($id !== '') {
                $normalized[$id] = true;
            }
        }
        $ids = array_keys($normalized);
        $now = date('Y-m-d H:i:s');

        $setUpdated = $this->hasColumn('updated_at') ? ', updated_at = :updated_at' : '';

        if ($ids === []) {
            $stmt = $this->pdo->prepare(
                'UPDATE ml_sales_blockers
                 SET resolved_at = :resolved_at' . $setUpdated . '
                 WHERE account_id = :account_id AND resolved_at IS NULL'
            );
            $params = [
                'resolved_at' => $now,
                'account_id' => $accountId,
            ];
            if ($setUpdated !== '') {
                $params['updated_at'] = $now;
            }
            $stmt->execute($params);

            return $stmt->rowCount();
        }

        $placeholders = [];
        $params = [
            'resolved_at' => $now,
            'account_id' => $accountId,
        ];
        if ($setUpdated !== '') {
            $params['updated_at'] = $now;
        }
        foreach ($ids as $i => $id) {
            $key = 'id' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $sql = 'UPDATE ml_sales_blockers
                SET resolved_at = :resolved_at' . $setUpdated . '
                WHERE account_id = :account_id
                  AND resolved_at IS NULL
                  AND item_id NOT IN (' . implode(', ', $placeholders) . ')';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $wordings = [];
        if (isset($row['wordings_json'])) {
            $wordings = $this->decodeJsonValue($row['wordings_json']);
        }
        $subStatus = [];
        if (isset($row['sub_status_json'])) {
            $subStatus = $this->decodeJsonValue($row['sub_status_json']);
        }
        if ($subStatus === [] && isset($wordings['sub_status']) && is_array($wordings['sub_status'])) {
            $subStatus = $wordings['sub_status'];
        }

        return [
            'item_id' => (string) ($row['item_id'] ?? ''),
            'reason' => $row['reason'] ?? null,
            'remedy' => $row['remedy'] ?? null,
            'filter_subgroup' => $row['filter_subgroup'] ?? ($wordings['filter_subgroup'] ?? ($row['queue'] ?? null)),
            'item_status' => $row['item_status'] ?? ($row['source_status'] ?? null),
            'sub_status_json' => json_encode(array_values($subStatus), JSON_UNESCAPED_UNICODE),
            'infraction_id' => $row['infraction_id'] ?? ($wordings['infraction_id'] ?? null),
            'performance_json' => $row['performance_json'] ?? null,
            'scanned_by' => $row['scanned_by'] ?? ($wordings['scanned_by'] ?? null),
            'scanned_at' => $row['scanned_at'] ?? null,
            'resolved_at' => $row['resolved_at'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    private function decodeJsonValue(string|array|null $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, string|int|null> $values
     */
    private function putIfColumn(array &$values, string $column, string|int|null $value): void
    {
        if ($this->hasColumn($column)) {
            $values[$column] = $value;
        }
    }

    private function hasColumn(string $name): bool
    {
        return isset($this->columns()[$name]);
    }

    /**
     * @return array<string, true>
     */
    private function columns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }

        $this->columns = [];
        try {
            if ($this->isSqlite()) {
                $rows = $this->pdo->query('PRAGMA table_info(ml_sales_blockers)');
                $list = $rows ? $rows->fetchAll(PDO::FETCH_ASSOC) : [];
                foreach ($list as $row) {
                    $this->columns[(string) ($row['name'] ?? '')] = true;
                }
            } else {
                $rows = $this->pdo->query('SHOW COLUMNS FROM ml_sales_blockers');
                $list = $rows ? $rows->fetchAll(PDO::FETCH_ASSOC) : [];
                foreach ($list as $row) {
                    $this->columns[(string) ($row['Field'] ?? '')] = true;
                }
            }
        } catch (Throwable $e) {
            log_warning('SalesBlockerStore: schema indisponível', ['error' => $e->getMessage()]);
            $this->columns = [];
        }

        unset($this->columns['']);

        return $this->columns;
    }

    private function isSqlite(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }

    private function nullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim($value);

        return $text === '' ? null : $text;
    }
}

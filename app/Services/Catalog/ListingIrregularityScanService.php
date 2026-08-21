<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Services\MercadoLivreClient;
use PDO;
use Throwable;

/**
 * Coleta read-only de anúncios moderados (API oficial ML) e persiste em ml_sales_blockers.
 */
final class ListingIrregularityScanService
{
    private const DEFAULT_LIMIT = 400;
    private const MAX_LIMIT = 400;
    private const SEARCH_PAGE_SIZE = 50;
    private const INFRACTIONS_PAGE_SIZE = 20;
    /** @var callable(int): MlListingReadClient */
    private $clientFactory;

    /**
     * @param callable(int): MlListingReadClient|null $clientFactory
     */
    public function __construct(
        private PDO $pdo,
        private SalesBlockerStore $store,
        ?callable $clientFactory = null
    ) {
        $this->clientFactory = $clientFactory ?? static function (int $accountId): MlListingReadClient {
            return new MercadoLivreClient($accountId);
        };
    }

    /**
     * @return array{
     *     account_id: int,
     *     scanned: int,
     *     upserted: int,
     *     resolved: int,
     *     pending_total: int,
     *     errors: list<string>
     * }
     */
    public function scanAccount(int $accountId, int $limit = self::DEFAULT_LIMIT, string $scannedBy = 'scan'): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $errors = [];
        $client = ($this->clientFactory)($accountId);

        $pending = $this->collectPendingIds($client, $limit);
        $searchOk = $pending['ok'];
        $itemIds = $pending['ids'];
        $pendingTotal = $pending['total'];
        if ($pending['error'] !== null) {
            $errors[] = $pending['error'];
        }

        foreach ($this->localModeratedItemIds($accountId, $limit) as $localId) {
            $itemIds[$localId] = true;
        }

        foreach ($this->infractionItemIds($client, $limit) as $infractionId) {
            $itemIds[$infractionId] = true;
        }

        $ordered = [];
        foreach (array_keys($itemIds) as $rawId) {
            if (!is_int($rawId) && !is_string($rawId)) {
                continue;
            }
            $normalized = $this->normalizeItemId($rawId);
            if ($normalized === null) {
                log_warning('ListingIrregularityScanService: item_id ignorado', [
                    'account_id' => $accountId,
                    'raw' => $rawId,
                ]);
                continue;
            }
            $ordered[$normalized] = true;
        }
        $ordered = array_slice(array_keys($ordered), 0, $limit);

        $upserted = 0;
        $seen = [];
        foreach ($ordered as $itemId) {
            try {
                $this->store->upsert($accountId, $this->buildRow($client, $itemId, $scannedBy));
                $seen[] = $itemId;
                $upserted++;
            } catch (Throwable $e) {
                $errors[] = $itemId . ': ' . $e->getMessage();
                log_warning('ListingIrregularityScanService: falha ao persistir blocker', [
                    'account_id' => $accountId,
                    'item_id' => $itemId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $resolved = 0;
        if ($searchOk) {
            $resolved = $this->store->markResolvedIfMissing($accountId, $seen);
        }

        log_info('ListingIrregularityScanService: scan concluído', [
            'account_id' => $accountId,
            'scanned' => count($ordered),
            'upserted' => $upserted,
            'resolved' => $resolved,
            'pending_total' => $pendingTotal,
        ]);

        return [
            'account_id' => $accountId,
            'scanned' => count($ordered),
            'upserted' => $upserted,
            'resolved' => $resolved,
            'pending_total' => $pendingTotal,
            'errors' => $errors,
        ];
    }

    /**
     * @return array{ok: bool, ids: array<string, true>, total: int, error: ?string}
     */
    private function collectPendingIds(MlListingReadClient $client, int $limit): array
    {
        $ids = [];
        $offset = 0;
        $pageSize = self::SEARCH_PAGE_SIZE;
        $total = 0;
        $pages = 0;

        do {
            $response = $client->getMyItems([
                'status' => 'pending',
                'limit' => min($pageSize, max(1, $limit - count($ids))),
                'offset' => $offset,
            ]);

            if (isset($response['error'])) {
                if ($pages === 0) {
                    return [
                        'ok' => false,
                        'ids' => [],
                        'total' => 0,
                        'error' => (string) $response['error'],
                    ];
                }
                break;
            }

            $pageIds = 0;
            foreach ($response['results'] ?? [] as $rawId) {
                if (is_array($rawId)) {
                    $rawId = $rawId['id'] ?? $rawId['item_id'] ?? '';
                }
                if (!is_int($rawId) && !is_string($rawId)) {
                    continue;
                }
                $id = $this->normalizeItemId($rawId);
                if ($id !== null) {
                    $ids[$id] = true;
                    $pageIds++;
                }
            }

            $paging = is_array($response['paging'] ?? null) ? $response['paging'] : [];
            $total = (int) ($paging['total'] ?? max($total, count($ids)));
            $got = is_array($response['results'] ?? null) ? count($response['results']) : 0;
            $offset += $got > 0 ? $got : $pageSize;
            $pages++;

            if ($got === 0 || $pageIds === 0 || count($ids) >= $limit || ($total > 0 && $offset >= $total)) {
                break;
            }
        } while ($pages < 20 && count($ids) < $limit);

        return [
            'ok' => true,
            'ids' => $ids,
            'total' => $total > 0 ? $total : count($ids),
            'error' => null,
        ];
    }

    /**
     * @return array<string, true>
     */
    private function infractionItemIds(MlListingReadClient $client, int $limit): array
    {
        $ids = [];
        try {
            $offset = 0;
            $pageSize = self::INFRACTIONS_PAGE_SIZE;
            $pages = 0;
            do {
                $payload = $client->getSellerInfractions([
                    'language' => 'PT',
                    'limit' => min($pageSize, max(1, $limit - count($ids))),
                    'offset' => $offset,
                ]);
                if (isset($payload['error'])) {
                    break;
                }
                $list = $payload['infractions'] ?? [];
                if (!is_array($list) || $list === []) {
                    break;
                }
                foreach ($list as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $type = strtoupper((string) ($row['element_type'] ?? 'ITM'));
                    if ($type !== 'ITM') {
                        continue;
                    }
                    $rawItemId = $row['related_item_id'] ?? $row['element_id'] ?? '';
                    if (!is_int($rawItemId) && !is_string($rawItemId)) {
                        continue;
                    }
                    $itemId = $this->normalizeItemId($rawItemId);
                    if ($itemId !== null) {
                        $ids[$itemId] = true;
                    }
                }
                $got = count($list);
                $offset += $got;
                $paging = is_array($payload['paging'] ?? null) ? $payload['paging'] : [];
                $total = (int) ($paging['total'] ?? 0);
                $pages++;
                if ($got < $pageSize || count($ids) >= $limit || ($total > 0 && $offset >= $total)) {
                    break;
                }
            } while ($pages < 20 && count($ids) < $limit);
        } catch (Throwable $e) {
            log_warning('ListingIrregularityScanService: infractions indisponível', [
                'error' => $e->getMessage(),
            ]);
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function localModeratedItemIds(int $accountId, int $limit): array
    {
        $sqlVariants = [
            "SELECT ml_item_id AS item_id FROM items
             WHERE account_id = :account_id AND status IN ('under_review', 'inactive')
             LIMIT :limit",
            "SELECT ml_item_id AS item_id FROM ml_items
             WHERE account_id = :account_id AND status IN ('under_review', 'inactive')
             LIMIT :limit",
        ];

        foreach ($sqlVariants as $sql) {
            try {
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $ids = [];
                foreach ($rows as $row) {
                    $raw = $row['item_id'] ?? '';
                    if (!is_int($raw) && !is_string($raw)) {
                        continue;
                    }
                    $id = $this->normalizeItemId($raw);
                    if ($id !== null) {
                        $ids[] = $id;
                    }
                }
                return $ids;
            } catch (Throwable $e) {
                continue;
            }
        }

        return [];
    }

    /**
     * PHP converte chave de array "1" em int; PK local também não é MLB.
     */
    private function normalizeItemId(int|string $raw): ?string
    {
        $id = strtoupper(trim((string) $raw));
        if (preg_match('/^ML[A-Z]{1,3}\d+$/', $id) !== 1) {
            return null;
        }

        return $id;
    }

    /**
     * @return array{
     *     item_id: string,
     *     reason: ?string,
     *     remedy: ?string,
     *     filter_subgroup: ?string,
     *     item_status: ?string,
     *     sub_status: list<string>,
     *     infraction_id: ?string,
     *     performance_json: array<string, mixed>,
     *     scanned_by: string
     * }
     */
    private function buildRow(MlListingReadClient $client, string $itemId, string $scannedBy): array
    {
        $moderation = $this->firstModeration($client->getLastModeration($itemId));
        $item = $this->safeItem($client, $itemId);

        $subStatus = $item['sub_status'] ?? [];
        if (!is_array($subStatus)) {
            $subStatus = $subStatus !== null && $subStatus !== '' ? [(string) $subStatus] : [];
        }
        $subStatus = array_values(array_map('strval', $subStatus));

        $reason = $this->wording($moderation, 'REASON');
        $remedy = $this->wording($moderation, 'REMEDY');
        $filterName = isset($moderation['name']) ? (string) $moderation['name'] : null;
        $infractionId = isset($moderation['id']) ? (string) $moderation['id'] : null;
        if ($remedy === null && $filterName !== null && str_contains($filterName, 'DUPLICATE')) {
            $remedy = 'Anúncio duplicado: edite ou pause no painel do ML. Este filtro não envia texto de correção na API.';
        }

        if ($reason === null && $item !== []) {
            $status = (string) ($item['status'] ?? '');
            $reason = $status !== ''
                ? 'status=' . $status . ($subStatus !== [] ? ' · sub_status: ' . implode(', ', $subStatus) : '')
                : 'Moderação ativa (detalhe indisponível na API)';
        }

        $title = (string) ($item['title'] ?? $itemId);
        $playbook = [
            'seller_edit_url' => 'https://www.mercadolivre.com.br/publicacoes/' . $itemId . '/modificar',
            'filter_name' => $filterName,
        ];

        return [
            'item_id' => $itemId,
            'reason' => $reason,
            'remedy' => $remedy,
            'filter_subgroup' => $filterName,
            'item_status' => isset($item['status']) ? (string) $item['status'] : null,
            'sub_status' => $subStatus,
            'infraction_id' => $infractionId,
            'performance_json' => [
                'playbook' => $playbook,
                'item_snapshot' => [
                    'title' => $title,
                    'status' => $item['status'] ?? null,
                    'sub_status' => $subStatus,
                    'permalink' => $item['permalink'] ?? null,
                ],
                'moderation' => $moderation,
            ],
            'scanned_by' => $scannedBy,
        ];
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     * @return array<string, mixed>
     */
    private function firstModeration(array $payload): array
    {
        if (isset($payload['error'])) {
            return [];
        }
        if (isset($payload['body']) && is_array($payload['body'])) {
            $payload = $payload['body'];
        }
        if ($payload === []) {
            return [];
        }
        if (array_is_list($payload) && isset($payload[0]) && is_array($payload[0])) {
            return $payload[0];
        }
        if (isset($payload['name']) || isset($payload['wordings'])) {
            return $payload;
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function safeItem(MlListingReadClient $client, string $itemId): array
    {
        try {
            $item = $client->get('/items/' . $itemId);
            if (!is_array($item) || isset($item['error'])) {
                return [];
            }
            if (isset($item['body']) && is_array($item['body']) && !isset($item['id'])) {
                $item = $item['body'];
            }

            return $item;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $moderation
     */
    private function wording(array $moderation, string $type): ?string
    {
        $wordings = $moderation['wordings'] ?? [];
        if (!is_array($wordings)) {
            return null;
        }
        foreach ($wordings as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (strtoupper((string) ($row['type'] ?? '')) === $type) {
                $value = trim((string) ($row['value'] ?? ''));
                return $value === '' ? null : $value;
            }
        }

        return null;
    }
}

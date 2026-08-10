<?php

declare(strict_types=1);

namespace App\Services\Pregao;

use App\Database;
use App\Services\MercadoLivreClient;
use PDO;
use Throwable;

/**
 * Watchlist de concorrentes — multiget /items?ids= (lotes de 20).
 *
 * Emite op na fita quando: preço muda >5%, pausa/zera estoque, ou acelera vendas.
 */
final class PregaoWatchlistCollector
{
    private const BATCH_SIZE = 20;
    private const PRICE_DELTA_PCT = 0.05;

    private PDO $db;
    private PregaoEmitService $emitter;

    public function __construct(?PDO $db = null, ?PregaoEmitService $emitter = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->emitter = $emitter ?? new PregaoEmitService($this->db);
    }

    /**
     * @return array{checked: int, alerts: int, errors: list<string>}
     */
    public function collect(int $accountId): array
    {
        $rows = $this->loadActive($accountId);
        if ($rows === []) {
            return ['checked' => 0, 'alerts' => 0, 'errors' => []];
        }

        $client = new MercadoLivreClient($accountId);
        $ids = array_map(static fn (array $r): string => (string) $r['mlb_id'], $rows);
        $byId = [];
        foreach ($rows as $r) {
            $byId[(string) $r['mlb_id']] = $r;
        }

        $alerts = 0;
        $errors = [];
        $checked = 0;

        foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
            try {
                $details = $client->getMultiItemDetails($chunk, [
                    'id', 'title', 'price', 'sold_quantity', 'status', 'available_quantity',
                ]);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
                log_warning('PregaoWatchlistCollector: multiget falhou', [
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($chunk as $mlbId) {
                $body = $details[$mlbId] ?? null;
                if (!is_array($body)) {
                    $errors[] = "item {$mlbId} não retornado";
                    continue;
                }
                $prev = $byId[$mlbId];
                $alerts += $this->applySnapshot($accountId, $prev, $body);
                $checked++;
            }

            usleep(150000);
        }

        return ['checked' => $checked, 'alerts' => $alerts, 'errors' => $errors];
    }

    /**
     * @param array{mlb_id?: string, apelido?: string, keyword_alvo?: string|null, seller_id?: string|int|null} $data
     */
    public function upsert(int $accountId, array $data): int
    {
        $mlbId = strtoupper(trim((string) ($data['mlb_id'] ?? '')));
        if ($mlbId === '' || !preg_match('/^MLB\d+$/', $mlbId)) {
            throw new \InvalidArgumentException('mlb_id inválido');
        }
        $apelido = trim((string) ($data['apelido'] ?? $mlbId));
        $keyword = isset($data['keyword_alvo']) ? trim((string) $data['keyword_alvo']) : null;
        $sellerId = isset($data['seller_id']) ? (string) $data['seller_id'] : '';

        if ($sellerId === '') {
            try {
                $client = new MercadoLivreClient($accountId);
                $details = $client->getMultiItemDetails([$mlbId], ['id', 'seller_id', 'title', 'price', 'status']);
                $body = $details[$mlbId] ?? [];
                $sellerId = (string) ($body['seller_id'] ?? ($body['seller']['id'] ?? '0'));
                if ($apelido === $mlbId && !empty($body['title'])) {
                    $apelido = mb_substr((string) $body['title'], 0, 80);
                }
            } catch (Throwable $e) {
                $sellerId = '0';
            }
        }

        $this->db->prepare(
            'INSERT INTO competitor_items
               (account_id, ml_item_id, seller_id, apelido, keyword_alvo, title, status, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
               apelido = VALUES(apelido),
               keyword_alvo = VALUES(keyword_alvo),
               seller_id = IF(VALUES(seller_id) = \'0\', seller_id, VALUES(seller_id)),
               active = 1,
               updated_at = CURRENT_TIMESTAMP'
        )->execute([
            $accountId,
            $mlbId,
            $sellerId,
            $apelido,
            $keyword !== '' ? $keyword : null,
            $apelido,
            'active',
        ]);

        $stmt = $this->db->prepare(
            'SELECT id FROM competitor_items WHERE account_id = ? AND ml_item_id = ?'
        );
        $stmt->execute([$accountId, $mlbId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadActive(int $accountId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, account_id, ml_item_id AS mlb_id, apelido, keyword_alvo,
                    price, sold_quantity, available_quantity, status, last_sold_delta
             FROM competitor_items
             WHERE account_id = ? AND COALESCE(active, 1) = 1'
        );
        $stmt->execute([$accountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string, mixed> $prev
     * @param array<string, mixed> $body
     */
    private function applySnapshot(int $accountId, array $prev, array $body): int
    {
        $mlbId = (string) ($body['id'] ?? $prev['mlb_id']);
        $price = isset($body['price']) ? (float) $body['price'] : null;
        $sold = isset($body['sold_quantity']) ? (int) $body['sold_quantity'] : null;
        $status = isset($body['status']) ? (string) $body['status'] : null;
        $available = isset($body['available_quantity']) ? (int) $body['available_quantity'] : null;
        $apelido = (string) (($prev['apelido'] ?? '') !== '' ? $prev['apelido'] : $mlbId);
        $itemPk = (int) $prev['id'];

        $prevPrice = $prev['price'] !== null ? (float) $prev['price'] : null;
        $prevSold = $prev['sold_quantity'] !== null ? (int) $prev['sold_quantity'] : null;
        $prevStatus = $prev['status'] !== null ? (string) $prev['status'] : null;
        $prevAvailable = $prev['available_quantity'] !== null ? (int) $prev['available_quantity'] : null;
        $prevDelta = (int) ($prev['last_sold_delta'] ?? 0);

        $alerts = 0;
        $hasPrev = $prevPrice !== null || $prevSold !== null || $prevStatus !== null;

        if ($hasPrev) {
            if ($price !== null && $prevPrice !== null && $prevPrice > 0) {
                $pct = abs($price - $prevPrice) / $prevPrice;
                if ($pct > self::PRICE_DELTA_PCT) {
                    $dir = $price > $prevPrice ? '↑' : '↓';
                    $this->emitAlert($accountId, 'alert', '💸', sprintf(
                        'WATCH %s — preço %s %.1f%% (R$ %s → R$ %s)',
                        $apelido,
                        $dir,
                        $pct * 100,
                        number_format($prevPrice, 2, ',', '.'),
                        number_format($price, 2, ',', '.')
                    ), ['mlb_id' => $mlbId, 'kind' => 'price']);
                    $alerts++;
                }
            }

            $paused = $status !== null && in_array(strtolower($status), ['paused', 'closed'], true)
                && ($prevStatus === null || strtolower($prevStatus) !== strtolower($status));
            $zeroStock = $available !== null && $available <= 0
                && ($prevAvailable === null || $prevAvailable > 0);

            if ($paused || $zeroStock) {
                $reason = $paused
                    ? sprintf('status %s', $status ?? '?')
                    : 'estoque zerado';
                $this->emitAlert($accountId, 'alert', '⛔', sprintf(
                    'WATCH %s — %s',
                    $apelido,
                    $reason
                ), ['mlb_id' => $mlbId, 'kind' => 'stock_or_pause']);
                $alerts++;
            }

            if ($sold !== null && $prevSold !== null && $sold > $prevSold) {
                $delta = $sold - $prevSold;
                $accelerated = $delta >= 5 && ($prevDelta <= 0 || $delta >= max(3, (int) ceil($prevDelta * 2)));
                if ($accelerated) {
                    $this->emitAlert($accountId, 'info', '🚀', sprintf(
                        'WATCH %s — vendas aceleraram +%d (antes +%d)',
                        $apelido,
                        $delta,
                        $prevDelta
                    ), ['mlb_id' => $mlbId, 'kind' => 'sales_accel', 'delta' => $delta]);
                    $alerts++;
                }
            }
        }

        $newDelta = ($sold !== null && $prevSold !== null && $sold >= $prevSold)
            ? ($sold - $prevSold)
            : 0;

        $this->db->prepare(
            'UPDATE competitor_items
             SET price = ?, sold_quantity = ?, available_quantity = ?, status = ?,
                 last_sold_delta = ?, title = COALESCE(NULLIF(title, \'\'), ?),
                 last_checked_at = NOW(), updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        )->execute([
            $price,
            $sold,
            $available,
            $status,
            $newDelta,
            (string) ($body['title'] ?? $apelido),
            $itemPk,
        ]);

        $this->db->prepare(
            'INSERT INTO competitor_item_snapshots
               (account_id, competitor_item_id, mlb_id, price, sold_quantity, available_quantity, status, captured_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(3))'
        )->execute([$accountId, $itemPk, $mlbId, $price, $sold, $available, $status]);

        return $alerts;
    }

    /**
     * Sugere e opcionalmente insere top anúncios concorrentes.
     *
     * Estratégia (Onda 3.5): a API /sites/MLB/search está forbidden para o app ML atual.
     * Usamos highlights por categoria + /products/{id}/items (read-only) como fonte.
     * Keywords são mapeadas para category_id dos anúncios da própria conta quando possível.
     *
     * @param list<string> $keywords
     * @return array{suggested: list<array<string, mixed>>, inserted: list<string>, errors: list<string>}
     */
    public function seedFromKeywords(int $accountId, array $keywords, int $perKeyword = 5, bool $insert = true): array
    {
        $perKeyword = max(1, min(10, $perKeyword));
        $client = new MercadoLivreClient($accountId);
        $suggested = [];
        $inserted = [];
        $errors = [];
        $seen = [];
        $ownSellerId = $this->resolveOwnSellerId($accountId);

        $categoryMap = $this->mapKeywordsToCategories($accountId, $keywords);
        if ($categoryMap === []) {
            // Fallback: top categorias da conta
            $categoryMap = $this->topAccountCategories($accountId, 3);
        }

        foreach ($categoryMap as $label => $categoryId) {
            try {
                $highlights = $client->get('/highlights/MLB/category/' . $categoryId, [], 120, false);
            } catch (Throwable $e) {
                $errors[] = "highlights {$categoryId}: " . $e->getMessage();
                continue;
            }
            if (isset($highlights['error'])) {
                $errors[] = "highlights {$categoryId}: " . (string) ($highlights['error']);
                continue;
            }

            $content = is_array($highlights['content'] ?? null) ? $highlights['content'] : [];
            $added = 0;
            foreach ($content as $row) {
                if ($added >= $perKeyword) {
                    break;
                }
                if (!is_array($row)) {
                    continue;
                }
                $type = strtoupper((string) ($row['type'] ?? ''));
                $id = (string) ($row['id'] ?? '');
                $candidates = [];

                if ($type === 'ITEM' && preg_match('/^MLB\d+$/', $id)) {
                    $candidates[] = [
                        'mlb_id' => $id,
                        'seller_id' => '0',
                        'price' => null,
                        'title' => $id,
                    ];
                } elseif ($type === 'PRODUCT' && $id !== '') {
                    try {
                        $prodItems = $client->get('/products/' . $id . '/items', [], 60, false);
                        $results = is_array($prodItems['results'] ?? null) ? $prodItems['results'] : [];
                        foreach ($results as $pi) {
                            if (!is_array($pi)) {
                                continue;
                            }
                            $itemId = strtoupper((string) ($pi['item_id'] ?? ''));
                            if (!preg_match('/^MLB\d+$/', $itemId)) {
                                continue;
                            }
                            $candidates[] = [
                                'mlb_id' => $itemId,
                                'seller_id' => (string) ($pi['seller_id'] ?? '0'),
                                'price' => isset($pi['price']) ? (float) $pi['price'] : null,
                                'title' => $itemId . ' (catálogo ' . $id . ')',
                            ];
                        }
                    } catch (Throwable $e) {
                        $errors[] = "product {$id}: " . $e->getMessage();
                    }
                }

                foreach ($candidates as $entry) {
                    if ($added >= $perKeyword) {
                        break;
                    }
                    $mlbId = $entry['mlb_id'];
                    if (isset($seen[$mlbId])) {
                        continue;
                    }
                    if ($ownSellerId !== null && (string) $entry['seller_id'] === (string) $ownSellerId) {
                        continue;
                    }
                    $seen[$mlbId] = true;
                    $payload = [
                        'mlb_id' => $mlbId,
                        'title' => $entry['title'],
                        'price' => $entry['price'],
                        'sold_quantity' => null,
                        'seller_id' => $entry['seller_id'],
                        'keyword_alvo' => (string) $label,
                        'permalink' => null,
                    ];
                    $suggested[] = $payload;
                    $added++;

                    if ($insert) {
                        try {
                            $this->upsert($accountId, [
                                'mlb_id' => $mlbId,
                                'apelido' => mb_substr((string) $entry['title'], 0, 80),
                                'keyword_alvo' => (string) $label,
                                'seller_id' => $entry['seller_id'],
                            ]);
                            $this->db->prepare(
                                'UPDATE competitor_items
                                 SET price = COALESCE(?, price),
                                     title = COALESCE(?, title),
                                     last_checked_at = NOW(),
                                     updated_at = NOW()
                                 WHERE account_id = ? AND ml_item_id = ?'
                            )->execute([
                                $entry['price'],
                                $entry['title'],
                                $accountId,
                                $mlbId,
                            ]);
                            $inserted[] = $mlbId;
                        } catch (Throwable $e) {
                            $errors[] = "upsert {$mlbId}: " . $e->getMessage();
                        }
                    }
                }
            }
        }

        if ($suggested === [] && $errors === []) {
            $errors[] = 'Nenhum concorrente encontrado via highlights (search API forbidden neste app ML)';
        }

        return [
            'suggested' => $suggested,
            'inserted' => $inserted,
            'errors' => $errors,
        ];
    }

    /**
     * @param list<string> $keywords
     * @return array<string, string> label => category_id
     */
    private function mapKeywordsToCategories(int $accountId, array $keywords): array
    {
        $map = [];
        foreach ($keywords as $keyword) {
            $keyword = trim((string) $keyword);
            if ($keyword === '') {
                continue;
            }
            $like = '%' . str_replace(' ', '%', mb_strtolower($keyword)) . '%';
            try {
                $stmt = $this->db->prepare(
                    'SELECT category_id FROM ml_items
                     WHERE account_id = ? AND category_id IS NOT NULL AND category_id != \'\'
                       AND LOWER(title) LIKE ?
                     GROUP BY category_id
                     ORDER BY COUNT(*) DESC
                     LIMIT 1'
                );
                $stmt->execute([$accountId, $like]);
                $cat = $stmt->fetchColumn();
                if (is_string($cat) && $cat !== '') {
                    $map[$keyword] = $cat;
                }
            } catch (Throwable) {
                // ignore
            }
        }
        return $map;
    }

    /**
     * @return array<string, string> label => category_id
     */
    private function topAccountCategories(int $accountId, int $limit = 3): array
    {
        $stmt = $this->db->prepare(
            'SELECT category_id, COUNT(*) AS c FROM ml_items
             WHERE account_id = ? AND category_id IS NOT NULL AND category_id != \'\'
             GROUP BY category_id ORDER BY c DESC LIMIT ?'
        );
        $stmt->bindValue(1, $accountId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $cid = (string) $row['category_id'];
            $map['categoria:' . $cid] = $cid;
        }
        return $map;
    }

    public function deactivate(int $accountId, string $mlbId): bool
    {
        $mlbId = strtoupper(trim($mlbId));
        $stmt = $this->db->prepare(
            'UPDATE competitor_items SET active = 0, updated_at = NOW()
             WHERE account_id = ? AND ml_item_id = ?'
        );
        $stmt->execute([$accountId, $mlbId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActive(int $accountId): array
    {
        return $this->loadActive($accountId);
    }

    private function resolveOwnSellerId(int $accountId): ?string
    {
        try {
            $stmt = $this->db->prepare('SELECT seller_id FROM ml_accounts WHERE id = ? LIMIT 1');
            $stmt->execute([$accountId]);
            $sellerId = $stmt->fetchColumn();
            return $sellerId !== false && $sellerId !== null && (string) $sellerId !== ''
                ? (string) $sellerId
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function emitAlert(int $accountId, string $level, string $icon, string $msg, array $meta): void
    {
        $this->emitter->emit('op', [
            'robot' => 'WATCH',
            'level' => $level,
            'icon' => $icon,
            'msg' => $msg,
            'meta' => $meta,
        ], $accountId, 'live');
    }
}

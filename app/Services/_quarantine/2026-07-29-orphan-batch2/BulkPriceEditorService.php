<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;

/**
 * Bulk Price Editor Service
 *
 * Edição em massa de preços:
 * - Preview antes de aplicar
 * - Filtros avançados (categoria, margem, status)
 * - Modificadores: percentual, fixo, arredondar
 * - Rollback completo
 * - Validações e limites de segurança
 *
 * @package App\Services
 */
class BulkPriceEditorService
{
    private int $accountId;
    private PDO $db;
    private MercadoLivreClient $mlClient;

    // Tipos de operação
    public const OP_PERCENT_INCREASE = 'percent_increase';
    public const OP_PERCENT_DECREASE = 'percent_decrease';
    public const OP_FIXED_INCREASE = 'fixed_increase';
    public const OP_FIXED_DECREASE = 'fixed_decrease';
    public const OP_SET_PRICE = 'set_price';
    public const OP_MATCH_COMPETITOR = 'match_competitor';
    public const OP_SET_MARGIN = 'set_margin';
    public const OP_ROUND_PRICE = 'round_price';

    // Limites de segurança
    private const MAX_PERCENT_CHANGE = 50; // máximo 50% de mudança
    private const MIN_PRICE = 1.00; // preço mínimo
    private const MAX_ITEMS_PER_BATCH = 100;

    public function __construct(int $accountId)
    {
        $this->accountId = $accountId;
        $this->db = Database::getInstance();
        $this->mlClient = new MercadoLivreClient($accountId);
    }

    /**
     * Preview de edição em massa
     */
    public function preview(array $filters, array $operation): array
    {
        // Buscar itens que serão afetados
        $items = $this->getFilteredItems($filters);

        if (count($items) === 0) {
            return [
                'success' => false,
                'message' => 'Nenhum item encontrado com os filtros aplicados'
            ];
        }

        $preview = [];
        $warnings = [];
        $errors = [];
        $totalOriginal = 0;
        $totalNew = 0;

        foreach ($items as $item) {
            $currentPrice = (float)$item['current_price'];
            $newPrice = $this->calculateNewPrice($item, $operation);

            // Validar
            $validation = $this->validatePriceChange($currentPrice, $newPrice, $item);

            if (!$validation['valid']) {
                $errors[] = [
                    'item_id' => $item['item_id'],
                    'title' => $item['item_title'],
                    'error' => $validation['error']
                ];
                continue;
            }

            if ($validation['warning']) {
                $warnings[] = [
                    'item_id' => $item['item_id'],
                    'title' => $item['item_title'],
                    'warning' => $validation['warning']
                ];
            }

            $changePercent = $currentPrice > 0
                ? (($newPrice - $currentPrice) / $currentPrice) * 100
                : 0;

            $preview[] = [
                'item_id' => $item['item_id'],
                'title' => $item['item_title'],
                'current_price' => $currentPrice,
                'new_price' => $newPrice,
                'change' => $newPrice - $currentPrice,
                'change_percent' => round($changePercent, 2),
                'current_margin' => $item['margin_percent'] ?? null,
                'new_margin' => $this->calculateNewMargin($item, $newPrice)
            ];

            $totalOriginal += $currentPrice;
            $totalNew += $newPrice;
        }

        return [
            'success' => true,
            'operation' => $operation,
            'filters' => $filters,
            'summary' => [
                'total_items' => count($items),
                'items_to_update' => count($preview),
                'items_with_errors' => count($errors),
                'items_with_warnings' => count($warnings),
                'total_original_value' => round($totalOriginal, 2),
                'total_new_value' => round($totalNew, 2),
                'total_change' => round($totalNew - $totalOriginal, 2),
                'avg_change_percent' => count($preview) > 0
                    ? round(array_sum(array_column($preview, 'change_percent')) / count($preview), 2)
                    : 0
            ],
            'preview' => $preview,
            'warnings' => $warnings,
            'errors' => $errors
        ];
    }

    /**
     * Aplicar edição em massa
     */
    public function apply(array $filters, array $operation, bool $createRollback = true): array
    {
        // Primeiro fazer preview
        $previewResult = $this->preview($filters, $operation);

        if (!$previewResult['success']) {
            return $previewResult;
        }

        if (count($previewResult['preview']) === 0) {
            return [
                'success' => false,
                'message' => 'Nenhum item válido para atualizar'
            ];
        }

        // Criar registro do batch
        $batchId = $this->createBatchRecord($operation, $filters, $previewResult['summary']);

        // Criar rollback se solicitado
        if ($createRollback) {
            $this->createRollbackData($batchId, $previewResult['preview']);
        }

        $results = [
            'batch_id' => $batchId,
            'success_count' => 0,
            'error_count' => 0,
            'details' => []
        ];

        // Processar em chunks
        $chunks = array_chunk($previewResult['preview'], self::MAX_ITEMS_PER_BATCH);

        foreach ($chunks as $chunk) {
            foreach ($chunk as $item) {
                try {
                    $result = $this->mlClient->put("/items/{$item['item_id']}", [
                        'price' => $item['new_price']
                    ]);

                    if (!$result || isset($result['error'])) {
                        throw new \Exception($result['error'] ?? 'Erro ao atualizar');
                    }

                    $results['success_count']++;
                    $results['details'][] = [
                        'item_id' => $item['item_id'],
                        'status' => 'success',
                        'new_price' => $item['new_price']
                    ];

                    // Registrar no histórico
                    $this->recordPriceHistory($item, $batchId);

                    // Atualizar cache local
                    $this->updateLocalCache($item);

                } catch (\Exception $e) {
                    $results['error_count']++;
                    $results['details'][] = [
                        'item_id' => $item['item_id'],
                        'status' => 'error',
                        'error' => $e->getMessage()
                    ];
                }

                // Rate limiting
                usleep(100000); // 100ms entre requests
            }
        }

        // Atualizar status do batch
        $this->updateBatchStatus($batchId, $results);

        return [
            'success' => true,
            'batch_id' => $batchId,
            'results' => $results,
            'rollback_available' => $createRollback
        ];
    }

    /**
     * Rollback de batch
     */
    public function rollback(int $batchId): array
    {
        // Verificar se batch existe e tem rollback
        $stmt = $this->db->prepare("
            SELECT * FROM pricing_bulk_batches
            WHERE id = :id AND account_id = :account_id
        ");
        $stmt->execute([
            'id' => $batchId,
            'account_id' => $this->accountId
        ]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$batch) {
            return ['success' => false, 'message' => 'Batch não encontrado'];
        }

        if ($batch['rolled_back']) {
            return ['success' => false, 'message' => 'Batch já foi revertido'];
        }

        // Buscar dados de rollback
        $stmt = $this->db->prepare("
            SELECT * FROM pricing_bulk_rollback
            WHERE batch_id = :batch_id
        ");
        $stmt->execute(['batch_id' => $batchId]);
        $rollbackData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rollbackData) === 0) {
            return ['success' => false, 'message' => 'Dados de rollback não disponíveis'];
        }

        $results = [
            'success_count' => 0,
            'error_count' => 0,
            'details' => []
        ];

        foreach ($rollbackData as $item) {
            try {
                $result = $this->mlClient->put("/items/{$item['item_id']}", [
                    'price' => (float)$item['original_price']
                ]);

                if (!$result || isset($result['error'])) {
                    throw new \Exception($result['error'] ?? 'Erro ao reverter');
                }

                $results['success_count']++;
                $results['details'][] = [
                    'item_id' => $item['item_id'],
                    'status' => 'success',
                    'restored_price' => $item['original_price']
                ];

            } catch (\Exception $e) {
                $results['error_count']++;
                $results['details'][] = [
                    'item_id' => $item['item_id'],
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];
            }

            usleep(100000);
        }

        // Marcar batch como revertido
        $stmt = $this->db->prepare("
            UPDATE pricing_bulk_batches
            SET rolled_back = 1, rolled_back_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute(['id' => $batchId]);

        return [
            'success' => true,
            'batch_id' => $batchId,
            'results' => $results
        ];
    }

    /**
     * Buscar itens filtrados
     */
    private function getFilteredItems(array $filters): array
    {
        $where = ['account_id = :account_id'];
        $params = ['account_id' => $this->accountId];

        // Filtro por categoria
        if (!empty($filters['category_id'])) {
            $where[] = 'category_id = :category_id';
            $params['category_id'] = $filters['category_id'];
        }

        // Filtro por margem
        if (isset($filters['margin_min'])) {
            $where[] = 'margin_percent >= :margin_min';
            $params['margin_min'] = $filters['margin_min'];
        }
        if (isset($filters['margin_max'])) {
            $where[] = 'margin_percent <= :margin_max';
            $params['margin_max'] = $filters['margin_max'];
        }

        // Filtro por preço
        if (isset($filters['price_min'])) {
            $where[] = 'current_price >= :price_min';
            $params['price_min'] = $filters['price_min'];
        }
        if (isset($filters['price_max'])) {
            $where[] = 'current_price <= :price_max';
            $params['price_max'] = $filters['price_max'];
        }

        // Filtro por IDs específicos
        if (!empty($filters['item_ids']) && is_array($filters['item_ids'])) {
            $placeholders = [];
            foreach ($filters['item_ids'] as $i => $id) {
                $key = "item_id_{$i}";
                $placeholders[] = ":{$key}";
                $params[$key] = $id;
            }
            $where[] = 'item_id IN (' . implode(',', $placeholders) . ')';
        }

        // Filtro por título
        if (!empty($filters['title_contains'])) {
            $where[] = 'item_title LIKE :title';
            $params['title'] = '%' . $filters['title_contains'] . '%';
        }

        // Filtro por SKU
        if (!empty($filters['sku'])) {
            $where[] = 'sku = :sku';
            $params['sku'] = $filters['sku'];
        }

        $whereClause = implode(' AND ', $where);
        $limit = min($filters['limit'] ?? 500, 500);
        $limitSql = max(1, min((int)$limit, 500));

        $stmt = $this->db->prepare("
            SELECT * FROM item_costs
            WHERE {$whereClause}
            LIMIT {$limitSql}
        ");
        foreach ($params as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Calcular novo preço baseado na operação
     */
    private function calculateNewPrice(array $item, array $operation): float
    {
        $currentPrice = (float)$item['current_price'];
        $value = (float)($operation['value'] ?? 0);
        $type = $operation['type'] ?? self::OP_PERCENT_INCREASE;

        $newPrice = match ($type) {
            self::OP_PERCENT_INCREASE => $currentPrice * (1 + ($value / 100)),
            self::OP_PERCENT_DECREASE => $currentPrice * (1 - ($value / 100)),
            self::OP_FIXED_INCREASE => $currentPrice + $value,
            self::OP_FIXED_DECREASE => $currentPrice - $value,
            self::OP_SET_PRICE => $value,
            self::OP_SET_MARGIN => $this->calculatePriceForMargin($item, $value),
            self::OP_ROUND_PRICE => $this->roundPrice($currentPrice, $operation['round_to'] ?? 'nearest'),
            self::OP_MATCH_COMPETITOR => $this->getCompetitorPrice($item, $operation),
            default => $currentPrice
        };

        // Aplicar arredondamento se solicitado
        if (!empty($operation['round_to']) && $type !== self::OP_ROUND_PRICE) {
            $newPrice = $this->roundPrice($newPrice, $operation['round_to']);
        }

        return max(self::MIN_PRICE, round($newPrice, 2));
    }

    /**
     * Calcular preço para atingir margem alvo
     */
    private function calculatePriceForMargin(array $item, float $targetMargin): float
    {
        $cost = (float)($item['product_cost'] ?? 0);
        $commission = (float)($item['ml_commission'] ?? 16) / 100;
        $tax = (float)($item['tax_rate'] ?? 9) / 100;
        $shipping = (float)($item['shipping_cost'] ?? 0);
        $packaging = (float)($item['packaging_cost'] ?? 0);

        $totalCost = $cost + $shipping + $packaging;
        $targetMarginRate = $targetMargin / 100;

        // price * (1 - commission - tax - targetMargin) = totalCost
        $denominator = 1 - $commission - $tax - $targetMarginRate;

        if ($denominator <= 0) {
            return (float)$item['current_price']; // Margem impossível
        }

        return $totalCost / $denominator;
    }

    /**
     * Arredondar preço
     */
    private function roundPrice(float $price, string $roundTo): float
    {
        return match ($roundTo) {
            'nearest' => round($price),
            'up' => ceil($price),
            'down' => floor($price),
            '0.90' => floor($price) + 0.90,
            '0.99' => floor($price) + 0.99,
            '5' => round($price / 5) * 5,
            '10' => round($price / 10) * 10,
            default => round($price, 2)
        };
    }

    /**
     * Obter preço do concorrente
     */
    private function getCompetitorPrice(array $item, array $operation): float
    {
        $categoryId = $item['category_id'] ?? null;
        if (!$categoryId) {
            return (float)$item['current_price'];
        }

        $searchResult = $this->mlClient->get(
            "/sites/MLB/search?category={$categoryId}&sort=price_asc&limit=10"
        );

        $results = $searchResult['results'] ?? [];
        $adjustment = $operation['competitor_adjustment'] ?? 0;

        foreach ($results as $result) {
            if ($result['id'] !== $item['item_id']) {
                $competitorPrice = (float)($result['price'] ?? 0);
                return $competitorPrice * (1 - ($adjustment / 100));
            }
        }

        return (float)$item['current_price'];
    }

    /**
     * Validar mudança de preço
     */
    private function validatePriceChange(float $currentPrice, float $newPrice, array $item): array
    {
        $result = ['valid' => true, 'error' => null, 'warning' => null];

        // Verificar preço mínimo
        if ($newPrice < self::MIN_PRICE) {
            $result['valid'] = false;
            $result['error'] = "Preço abaixo do mínimo permitido (R$ " . self::MIN_PRICE . ")";
            return $result;
        }

        // Verificar preço atual válido para cálculo percentual
        if ($currentPrice <= 0) {
            $result['valid'] = false;
            $result['error'] = "Preço atual inválido (R$ 0.00)";
            return $result;
        }

        // Verificar mudança percentual máxima
        $changePercent = abs((($newPrice - $currentPrice) / $currentPrice) * 100);
        if ($changePercent > self::MAX_PERCENT_CHANGE) {
            $result['valid'] = false;
            $result['error'] = "Mudança excede limite de " . self::MAX_PERCENT_CHANGE . "% (atual: {$changePercent}%)";
            return $result;
        }

        // Verificar margem negativa
        $newMargin = $this->calculateNewMargin($item, $newPrice);
        if ($newMargin !== null && $newMargin < 0) {
            $result['warning'] = "Nova margem será negativa ({$newMargin}%)";
        }

        // Aviso para mudanças grandes
        if ($changePercent > 20) {
            $result['warning'] = $result['warning'] ?? "Mudança significativa de preço ({$changePercent}%)";
        }

        return $result;
    }

    /**
     * Calcular nova margem
     */
    private function calculateNewMargin(array $item, float $newPrice): ?float
    {
        $cost = (float)($item['product_cost'] ?? 0);
        if ($cost <= 0 || $newPrice <= 0) {
            return null;
        }

        $commission = (float)($item['ml_commission'] ?? 16) / 100;
        $tax = (float)($item['tax_rate'] ?? 9) / 100;
        $shipping = (float)($item['shipping_cost'] ?? 0);
        $packaging = (float)($item['packaging_cost'] ?? 0);

        $totalDeductions = ($newPrice * $commission) + ($newPrice * $tax) + $shipping + $packaging + $cost;
        $profit = $newPrice - $totalDeductions;

        return round(($profit / $newPrice) * 100, 2);
    }

    /**
     * Criar registro do batch
     */
    private function createBatchRecord(array $operation, array $filters, array $summary): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO pricing_bulk_batches
            (account_id, operation, filters, total_items, success_count,
             error_count, status, created_at)
            VALUES
            (:account_id, :operation, :filters, :total_items, 0, 0, 'processing', NOW())
        ");

        $stmt->execute([
            'account_id' => $this->accountId,
            'operation' => json_encode($operation),
            'filters' => json_encode($filters),
            'total_items' => $summary['items_to_update']
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Criar dados de rollback
     */
    private function createRollbackData(int $batchId, array $items): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO pricing_bulk_rollback
            (batch_id, item_id, original_price, new_price)
            VALUES (:batch_id, :item_id, :original_price, :new_price)
        ");

        foreach ($items as $item) {
            $stmt->execute([
                'batch_id' => $batchId,
                'item_id' => $item['item_id'],
                'original_price' => $item['current_price'],
                'new_price' => $item['new_price']
            ]);
        }
    }

    /**
     * Atualizar status do batch
     */
    private function updateBatchStatus(int $batchId, array $results): void
    {
        $stmt = $this->db->prepare("
            UPDATE pricing_bulk_batches
            SET success_count = :success_count,
                error_count = :error_count,
                status = :status,
                completed_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            'success_count' => $results['success_count'],
            'error_count' => $results['error_count'],
            'status' => $results['error_count'] > 0 ? 'completed_with_errors' : 'completed',
            'id' => $batchId
        ]);
    }

    /**
     * Registrar no histórico de preços
     */
    private function recordPriceHistory(array $item, int $batchId): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO pricing_history
            (account_id, item_id, old_price, new_price, change_type,
             change_source, batch_id, created_at)
            VALUES
            (:account_id, :item_id, :old_price, :new_price, :change_type,
             'bulk_edit', :batch_id, NOW())
        ");

        $changeType = $item['new_price'] > $item['current_price'] ? 'increase' : 'decrease';

        $stmt->execute([
            'account_id' => $this->accountId,
            'item_id' => $item['item_id'],
            'old_price' => $item['current_price'],
            'new_price' => $item['new_price'],
            'change_type' => $changeType,
            'batch_id' => $batchId
        ]);
    }

    /**
     * Atualizar cache local
     */
    private function updateLocalCache(array $item): void
    {
        $stmt = $this->db->prepare("
            UPDATE item_costs
            SET current_price = :new_price,
                updated_at = NOW()
            WHERE account_id = :account_id AND item_id = :item_id
        ");

        $stmt->execute([
            'new_price' => $item['new_price'],
            'account_id' => $this->accountId,
            'item_id' => $item['item_id']
        ]);
    }

    /**
     * Listar batches
     */
    public function listBatches(array $filters = []): array
    {
        $where = ['account_id = :account_id'];
        $params = ['account_id' => $this->accountId];

        if (isset($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }

        $whereClause = implode(' AND ', $where);

        $limit = $filters['limit'] ?? 20;
        $offset = $filters['offset'] ?? 0;
        $limitSql = max(1, min((int)$limit, 200));
        $offsetSql = max(0, (int)$offset);

        $stmt = $this->db->prepare("
            SELECT * FROM pricing_bulk_batches
            WHERE {$whereClause}
            ORDER BY created_at DESC
            LIMIT {$limitSql} OFFSET {$offsetSql}
        ");

        foreach ($params as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }

        $stmt->execute();
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($batches as &$batch) {
            $batch['operation'] = json_decode($batch['operation'], true);
            $batch['filters'] = json_decode($batch['filters'], true);
        }

        return [
            'success' => true,
            'batches' => $batches
        ];
    }

    /**
     * Obter detalhes de um batch
     */
    public function getBatch(int $batchId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM pricing_bulk_batches
            WHERE id = :id AND account_id = :account_id
        ");
        $stmt->execute([
            'id' => $batchId,
            'account_id' => $this->accountId
        ]);

        $batch = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$batch) {
            return null;
        }

        $batch['operation'] = json_decode($batch['operation'], true);
        $batch['filters'] = json_decode($batch['filters'], true);

        // Buscar itens afetados
        $stmt = $this->db->prepare("
            SELECT * FROM pricing_bulk_rollback
            WHERE batch_id = :batch_id
        ");
        $stmt->execute(['batch_id' => $batchId]);
        $batch['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $batch;
    }

    /**
     * Obter templates de operações
     */
    public function getOperationTemplates(): array
    {
        return [
            [
                'name' => 'Aumento Percentual',
                'type' => self::OP_PERCENT_INCREASE,
                'description' => 'Aumentar preço em X%',
                'value_label' => 'Percentual (%)',
                'example' => ['type' => self::OP_PERCENT_INCREASE, 'value' => 10]
            ],
            [
                'name' => 'Desconto Percentual',
                'type' => self::OP_PERCENT_DECREASE,
                'description' => 'Reduzir preço em X%',
                'value_label' => 'Percentual (%)',
                'example' => ['type' => self::OP_PERCENT_DECREASE, 'value' => 15]
            ],
            [
                'name' => 'Aumento Fixo',
                'type' => self::OP_FIXED_INCREASE,
                'description' => 'Aumentar preço em R$ X',
                'value_label' => 'Valor (R$)',
                'example' => ['type' => self::OP_FIXED_INCREASE, 'value' => 10]
            ],
            [
                'name' => 'Desconto Fixo',
                'type' => self::OP_FIXED_DECREASE,
                'description' => 'Reduzir preço em R$ X',
                'value_label' => 'Valor (R$)',
                'example' => ['type' => self::OP_FIXED_DECREASE, 'value' => 5]
            ],
            [
                'name' => 'Definir Preço',
                'type' => self::OP_SET_PRICE,
                'description' => 'Definir preço fixo para todos os itens',
                'value_label' => 'Novo Preço (R$)',
                'example' => ['type' => self::OP_SET_PRICE, 'value' => 99.90]
            ],
            [
                'name' => 'Definir Margem',
                'type' => self::OP_SET_MARGIN,
                'description' => 'Ajustar preço para atingir margem alvo',
                'value_label' => 'Margem Alvo (%)',
                'example' => ['type' => self::OP_SET_MARGIN, 'value' => 20]
            ],
            [
                'name' => 'Igualar Concorrente',
                'type' => self::OP_MATCH_COMPETITOR,
                'description' => 'Igualar ou ficar abaixo do menor concorrente',
                'value_label' => 'Ajuste abaixo (%)',
                'example' => ['type' => self::OP_MATCH_COMPETITOR, 'competitor_adjustment' => 5]
            ],
            [
                'name' => 'Arredondar Preço',
                'type' => self::OP_ROUND_PRICE,
                'description' => 'Arredondar preço para valor específico',
                'value_label' => 'Tipo de arredondamento',
                'example' => ['type' => self::OP_ROUND_PRICE, 'round_to' => '0.99']
            ]
        ];
    }
}

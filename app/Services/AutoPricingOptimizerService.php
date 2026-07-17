<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;

/**
 * Auto Pricing Optimizer Service
 *
 * Serviço para otimização automática de preços baseado em:
 * - Monitoramento de concorrentes
 * - Regras de margem mínima
 * - Estratégias de precificação dinâmica
 * - Análise de demanda e sazonalidade
 */
class AutoPricingOptimizerService
{
    private int $accountId;
    private PDO $db;
    private MercadoLivreClient $mlClient;
    private MarginCalculatorService $marginService;
    private PricingStrategyService $strategyService;

    public function __construct(int $accountId)
    {
        $this->accountId = $accountId;
        $this->db = Database::getInstance();
        $this->mlClient = new MercadoLivreClient($accountId);
        $this->marginService = new MarginCalculatorService($accountId);
        $this->strategyService = new PricingStrategyService($accountId);
    }

    /**
     * Obtém configuração de auto-otimização da conta
     */
    public function getConfig(): array
    {
        $this->ensureConfigTable();

        $stmt = $this->db->prepare("
            SELECT * FROM pricing_auto_optimizer_config
            WHERE account_id = :account_id
        ");
        $stmt->execute(['account_id' => $this->accountId]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$config) {
            // Configuração padrão
            return [
                'enabled' => false,
                'mode' => 'suggest', // suggest | auto_apply
                'check_interval_minutes' => 60,
                'min_margin_percent' => 10,
                'max_price_increase_percent' => 8,
                'max_price_decrease_percent' => 15,
                'competitor_strategy' => 'match_lowest', // match_lowest | stay_below | stay_above
                'competitor_margin_buffer' => 2,
                'notify_email' => true,
                'notify_changes' => true,
                'exclude_items' => [],
                'include_only_items' => [],
                'last_run' => null,
                'total_adjustments' => 0
            ];
        }

        $config['exclude_items'] = json_decode($config['exclude_items'] ?? '[]', true) ?: [];
        $config['include_only_items'] = json_decode($config['include_only_items'] ?? '[]', true) ?: [];

        return $config;
    }

    /**
     * Salva configuração de auto-otimização
     */
    public function saveConfig(array $config): array
    {
        $this->ensureConfigTable();

        $stmt = $this->db->prepare("
            INSERT INTO pricing_auto_optimizer_config
            (account_id, enabled, mode, check_interval_minutes, min_margin_percent,
             max_price_increase_percent, max_price_decrease_percent, competitor_strategy,
             competitor_margin_buffer, notify_email, notify_changes, exclude_items, include_only_items)
            VALUES
            (:account_id, :enabled, :mode, :interval, :min_margin,
             :max_increase, :max_decrease, :strategy, :buffer, :notify_email, :notify_changes,
             :exclude, :include_only)
            ON DUPLICATE KEY UPDATE
            enabled = VALUES(enabled),
            mode = VALUES(mode),
            check_interval_minutes = VALUES(check_interval_minutes),
            min_margin_percent = VALUES(min_margin_percent),
            max_price_increase_percent = VALUES(max_price_increase_percent),
            max_price_decrease_percent = VALUES(max_price_decrease_percent),
            competitor_strategy = VALUES(competitor_strategy),
            competitor_margin_buffer = VALUES(competitor_margin_buffer),
            notify_email = VALUES(notify_email),
            notify_changes = VALUES(notify_changes),
            exclude_items = VALUES(exclude_items),
            include_only_items = VALUES(include_only_items),
            updated_at = NOW()
        ");

        $stmt->execute([
            'account_id' => $this->accountId,
            'enabled' => $config['enabled'] ? 1 : 0,
            'mode' => $config['mode'] ?? 'suggest',
            'interval' => $config['check_interval_minutes'] ?? 60,
            'min_margin' => $config['min_margin_percent'] ?? 10,
            'max_increase' => $config['max_price_increase_percent'] ?? 8,
            'max_decrease' => $config['max_price_decrease_percent'] ?? 15,
            'strategy' => $config['competitor_strategy'] ?? 'match_lowest',
            'buffer' => $config['competitor_margin_buffer'] ?? 2,
            'notify_email' => ($config['notify_email'] ?? true) ? 1 : 0,
            'notify_changes' => ($config['notify_changes'] ?? true) ? 1 : 0,
            'exclude' => json_encode($config['exclude_items'] ?? []),
            'include_only' => json_encode($config['include_only_items'] ?? [])
        ]);

        return ['success' => true, 'message' => 'Configuração salva'];
    }

    /**
     * Executa análise de otimização para todos os itens elegíveis
     */
    public function runOptimization(): array
    {
        $config = $this->getConfig();

        if (!$config['enabled']) {
            return [
                'success' => false,
                'message' => 'Auto-otimização está desativada'
            ];
        }

        $this->ensureLogTable();

        $items = $this->getEligibleItems($config);

        $results = [
            'total_analyzed' => 0,
            'suggestions' => [],
            'applied' => [],
            'skipped' => [],
            'errors' => []
        ];

        foreach ($items as $item) {
            $results['total_analyzed']++;

            try {
                $suggestion = $this->analyzeItem($item, $config);

                if (!$suggestion['should_adjust']) {
                    $results['skipped'][] = [
                        'item_id' => $item['id'],
                        'reason' => $suggestion['reason']
                    ];
                    continue;
                }

                // Registrar sugestão
                $this->logSuggestion($item['id'], $suggestion);

                if ($config['mode'] === 'auto_apply') {
                    // Aplicar automaticamente
                    $applied = $this->applyPriceChange($item['id'], $suggestion['suggested_price']);

                    if ($applied['success']) {
                        $results['applied'][] = [
                            'item_id' => $item['id'],
                            'old_price' => $suggestion['current_price'],
                            'new_price' => $suggestion['suggested_price'],
                            'reason' => $suggestion['reason']
                        ];
                    } else {
                        $results['errors'][] = [
                            'item_id' => $item['id'],
                            'error' => $applied['message']
                        ];
                    }
                } else {
                    // Apenas sugerir
                    $results['suggestions'][] = [
                        'item_id' => $item['id'],
                        'title' => $item['title'] ?? '',
                        'current_price' => $suggestion['current_price'],
                        'suggested_price' => $suggestion['suggested_price'],
                        'change_percent' => $suggestion['change_percent'],
                        'current_margin' => $suggestion['current_margin'],
                        'new_margin' => $suggestion['new_margin'],
                        'reason' => $suggestion['reason'],
                        'competitor_price' => $suggestion['competitor_price'] ?? null
                    ];
                }
            } catch (\Throwable $e) {
                $results['errors'][] = [
                    'item_id' => $item['id'],
                    'error' => $e->getMessage()
                ];
            }
        }

        // Atualizar última execução
        $this->updateLastRun(count($results['applied']));

        return [
            'success' => true,
            'results' => $results,
            'config' => [
                'mode' => $config['mode'],
                'strategy' => $config['competitor_strategy']
            ]
        ];
    }

    /**
     * Analisa um item específico e sugere ajuste de preço
     */
    public function analyzeItem(array $item, ?array $config = null): array
    {
        $config = $config ?? $this->getConfig();

        $itemId = $item['id'];
        $currentPrice = (float)$item['price'];
        $categoryId = $item['category_id'] ?? null;

        // Buscar custos do item
        $custos = $this->marginService->getCustosProduto($itemId);

        if (!$custos) {
            return [
                'should_adjust' => false,
                'reason' => 'Custos não cadastrados',
                'current_price' => $currentPrice
            ];
        }

        // Calcular margem atual
        $margemAtual = $this->marginService->calcularMargem($currentPrice, $custos);

        // Buscar preços dos concorrentes
        $competitorPrice = null;
        $competitorData = [];

        if ($categoryId) {
            try {
                $competitors = $this->strategyService->analyzeCompetitorPrices($categoryId);

                if (!empty($competitors['competitors'])) {
                    $competitorData = $competitors;

                    switch ($config['competitor_strategy']) {
                        case 'match_lowest':
                            $competitorPrice = $competitors['statistics']['min_price'] ?? null;
                            break;
                        case 'stay_below':
                            $avgPrice = $competitors['statistics']['avg_price'] ?? null;
                            $competitorPrice = $avgPrice ? $avgPrice * 0.95 : null;
                            break;
                        case 'stay_above':
                            $minPrice = $competitors['statistics']['min_price'] ?? null;
                            $competitorPrice = $minPrice ? $minPrice * 1.05 : null;
                            break;
                    }
                }
            } catch (\Throwable $e) {
                // Ignorar erro de concorrentes
            }
        }

        // Determinar preço sugerido
        $suggestedPrice = $currentPrice;
        $reason = '';
        $shouldAdjust = false;

        // Verificar margem mínima
        if ($margemAtual['margem_real'] < $config['min_margin_percent']) {
            // Calcular preço para atingir margem mínima
            $precoMinimo = $this->marginService->calcularPrecoMinimo($custos, $config['min_margin_percent']);
            $precoMinimoValor = $precoMinimo['preco_minimo'] ?? $currentPrice;

            if ($precoMinimoValor > $currentPrice) {
                $changePercent = (($precoMinimoValor - $currentPrice) / $currentPrice) * 100;

                if ($changePercent <= $config['max_price_increase_percent']) {
                    $suggestedPrice = $precoMinimoValor;
                    $reason = "Margem atual ({$margemAtual['margem_real']}%) abaixo do mínimo ({$config['min_margin_percent']}%)";
                    $shouldAdjust = true;
                } else {
                    return [
                        'should_adjust' => false,
                        'reason' => "Aumento necessário ({$changePercent}%) excede limite ({$config['max_price_increase_percent']}%)",
                        'current_price' => $currentPrice,
                        'current_margin' => $margemAtual['margem_real']
                    ];
                }
            }
        }

        // Verificar se concorrente está mais barato (e ainda mantemos margem)
        if ($competitorPrice && $competitorPrice < $currentPrice && !$shouldAdjust) {
            // Calcular preço competitivo com buffer
            $targetPrice = $competitorPrice * (1 - ($config['competitor_margin_buffer'] / 100));

            // Verificar se nova margem é aceitável
            $newMargin = $this->marginService->calcularMargem($targetPrice, $custos);

            if ($newMargin['margem_real'] >= $config['min_margin_percent']) {
                $changePercent = (($currentPrice - $targetPrice) / $currentPrice) * 100;

                if ($changePercent <= $config['max_price_decrease_percent']) {
                    $suggestedPrice = round($targetPrice, 2);
                    $reason = "Ajuste para competir (concorrente: R$ " . number_format($competitorPrice, 2, ',', '.') . ")";
                    $shouldAdjust = true;
                }
            }
        }

        // Calcular nova margem
        $newMargin = $this->marginService->calcularMargem($suggestedPrice, $custos);

        return [
            'should_adjust' => $shouldAdjust,
            'current_price' => $currentPrice,
            'suggested_price' => round($suggestedPrice, 2),
            'change_percent' => $currentPrice > 0 ? round((($suggestedPrice - $currentPrice) / $currentPrice) * 100, 2) : 0,
            'current_margin' => $margemAtual['margem_real'],
            'new_margin' => $newMargin['margem_real'],
            'reason' => $reason,
            'competitor_price' => $competitorPrice,
            'competitor_data' => $competitorData
        ];
    }

    /**
     * Obtém itens elegíveis para otimização
     */
    private function getEligibleItems(array $config): array
    {
        try {
            // Buscar itens ativos do ML
            $response = $this->mlClient->getMyItems([
                'status' => 'active',
                'limit' => 100
            ]);

            if (!$response || empty($response['results'])) {
                return [];
            }

            $itemIds = $response['results'];

            // Aplicar filtros de exclusão/inclusão
            if (!empty($config['include_only_items'])) {
                $itemIds = array_intersect($itemIds, $config['include_only_items']);
            }

            if (!empty($config['exclude_items'])) {
                $itemIds = array_diff($itemIds, $config['exclude_items']);
            }

            // Buscar detalhes dos itens
            $items = [];
            $chunks = array_chunk($itemIds, 20);

            foreach ($chunks as $chunk) {
                $ids = implode(',', $chunk);
                $itemsData = $this->mlClient->get('/items', ['ids' => $ids]);

                if ($itemsData) {
                    foreach ($itemsData as $itemResponse) {
                        if (isset($itemResponse['body'])) {
                            $items[] = $itemResponse['body'];
                        }
                    }
                }
            }

            return $items;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Aplica alteração de preço no ML
     */
    private function applyPriceChange(string $itemId, float $newPrice): array
    {
        $guard = \App\Helpers\MlWriteAutomation::guard('AutoPricingOptimizerService::applyPriceChange');
        if (!$guard['allowed']) {
            return [
                'success' => false,
                'blocked' => true,
                'message' => $guard['reason'],
                'mode' => 'recommend_only',
            ];
        }

        try {
            $response = $this->mlClient->put("/items/{$itemId}", [
                'price' => $newPrice
            ]);

            if ($response && isset($response['id'])) {
                // Registrar no histórico
                $this->logPriceChange($itemId, $newPrice, 'auto_optimizer');

                return ['success' => true];
            }

            return ['success' => false, 'message' => 'Resposta inválida da API'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Registra alteração no histórico
     */
    private function logPriceChange(string $itemId, float $newPrice, string $source): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO pricing_history
                (account_id, item_id, preco_novo, motivo, created_at)
                VALUES (:account_id, :item_id, :preco, :motivo, NOW())
            ");
            $stmt->execute([
                'account_id' => $this->accountId,
                'item_id' => $itemId,
                'preco' => $newPrice,
                'motivo' => $source
            ]);
        } catch (\Throwable $e) {
            // Ignorar erro de log
        }
    }

    /**
     * Registra sugestão de otimização
     */
    private function logSuggestion(string $itemId, array $suggestion): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO pricing_optimizer_log
                (account_id, item_id, current_price, suggested_price, change_percent,
                 current_margin, new_margin, reason, competitor_price, created_at)
                VALUES
                (:account_id, :item_id, :current, :suggested, :change_pct,
                 :current_margin, :new_margin, :reason, :competitor, NOW())
            ");
            $stmt->execute([
                'account_id' => $this->accountId,
                'item_id' => $itemId,
                'current' => $suggestion['current_price'],
                'suggested' => $suggestion['suggested_price'],
                'change_pct' => $suggestion['change_percent'],
                'current_margin' => $suggestion['current_margin'],
                'new_margin' => $suggestion['new_margin'],
                'reason' => $suggestion['reason'],
                'competitor' => $suggestion['competitor_price']
            ]);
        } catch (\Throwable $e) {
            // Ignorar erro de log
        }
    }

    /**
     * Atualiza timestamp da última execução
     */
    private function updateLastRun(int $adjustments): void
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE pricing_auto_optimizer_config
                SET last_run = NOW(),
                    total_adjustments = total_adjustments + :adj
                WHERE account_id = :account_id
            ");
            $stmt->execute([
                'account_id' => $this->accountId,
                'adj' => $adjustments
            ]);
        } catch (\Throwable $e) {
            // Ignorar
        }
    }

    /**
     * Obtém histórico de otimizações
     */
    public function getOptimizationHistory(int $days = 30, int $limit = 100): array
    {
        $this->ensureLogTable();

        $limitSql = max(1, min((int)$limit, 500));

        $stmt = $this->db->prepare("
            SELECT * FROM pricing_optimizer_log
            WHERE account_id = :account_id
            AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            ORDER BY created_at DESC
            LIMIT {$limitSql}
        ");
        $stmt->bindValue('account_id', $this->accountId, PDO::PARAM_INT);
        $stmt->bindValue('days', $days, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtém estatísticas do otimizador
     */
    public function getStats(): array
    {
        $this->ensureLogTable();

        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) as total_suggestions,
                SUM(CASE WHEN change_percent > 0 THEN 1 ELSE 0 END) as increases,
                SUM(CASE WHEN change_percent < 0 THEN 1 ELSE 0 END) as decreases,
                AVG(ABS(change_percent)) as avg_change_percent,
                AVG(new_margin - current_margin) as avg_margin_improvement
            FROM pricing_optimizer_log
            WHERE account_id = :account_id
            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute(['account_id' => $this->accountId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        $config = $this->getConfig();

        return [
            'config' => [
                'enabled' => (bool)$config['enabled'],
                'mode' => $config['mode'],
                'strategy' => $config['competitor_strategy'],
                'last_run' => $config['last_run'],
                'total_adjustments' => $config['total_adjustments'] ?? 0
            ],
            'last_30_days' => [
                'total_suggestions' => (int)($stats['total_suggestions'] ?? 0),
                'increases' => (int)($stats['increases'] ?? 0),
                'decreases' => (int)($stats['decreases'] ?? 0),
                'avg_change_percent' => round((float)($stats['avg_change_percent'] ?? 0), 2),
                'avg_margin_improvement' => round((float)($stats['avg_margin_improvement'] ?? 0), 2)
            ]
        ];
    }

    /**
     * Garante que a tabela de configuração existe
     */
    private function ensureConfigTable(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS pricing_auto_optimizer_config (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL UNIQUE,
                enabled TINYINT(1) DEFAULT 0,
                mode ENUM('suggest', 'auto_apply') DEFAULT 'suggest',
                check_interval_minutes INT DEFAULT 60,
                min_margin_percent DECIMAL(5,2) DEFAULT 10,
                max_price_increase_percent DECIMAL(5,2) DEFAULT 8,
                max_price_decrease_percent DECIMAL(5,2) DEFAULT 15,
                competitor_strategy VARCHAR(50) DEFAULT 'match_lowest',
                competitor_margin_buffer DECIMAL(5,2) DEFAULT 2,
                notify_email TINYINT(1) DEFAULT 1,
                notify_changes TINYINT(1) DEFAULT 1,
                exclude_items JSON,
                include_only_items JSON,
                last_run DATETIME,
                total_adjustments INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_account (account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /**
     * Garante que a tabela de log existe
     */
    private function ensureLogTable(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS pricing_optimizer_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                item_id VARCHAR(50) NOT NULL,
                current_price DECIMAL(10,2),
                suggested_price DECIMAL(10,2),
                change_percent DECIMAL(5,2),
                current_margin DECIMAL(5,2),
                new_margin DECIMAL(5,2),
                reason VARCHAR(255),
                competitor_price DECIMAL(10,2),
                applied TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_account (account_id),
                KEY idx_item (item_id),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

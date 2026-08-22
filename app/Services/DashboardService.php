<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Helpers\Log;
use App\Helpers\RevenueHelper;
use App\Services\Financial\MissingCogsPolicy;
use App\Services\MercadoLivreClient;
use PDO;
use Throwable;

class DashboardService
{
    /**
     * Obtém métricas consolidadas do dashboard
     */
    public function getMetrics(?int $accountId = null): array
    {
        try {
            $db = Database::getInstance();
            $this->ensureOrdersTable($db);
        } catch (Throwable $e) {
            Log::warning('DashboardService: DB indisponível, retornando métricas em modo degradado', [
                'service' => 'DashboardService',
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            return $this->buildDegradedMetrics($accountId);
        }

        $params = [];
        $ordersWhere = "WHERE date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

        if ($accountId) {
            $ordersWhere .= " AND ml_account_id = :account_id";
            $params['account_id'] = $accountId;
        }

        // Fonte única de receita (Onda 2 / T1): pedidos pagos/em cumprimento,
        // excluindo cancelados — mesmo critério de Analytics e Vendas. Usado
        // apenas para revenue/profit; a distribuição por status (abaixo)
        // continua contando TODOS os status, inclusive cancelados.
        $ordersWhereRevenue = $ordersWhere . " AND " . RevenueHelper::paidStatusesSql();

        // Total de pedidos, receita e lucro (últimos 30 dias)
        $stmt = $db->prepare("SELECT COUNT(*) as total, COALESCE(SUM(total_amount), 0) as revenue, COALESCE(SUM(net_profit), 0) as profit FROM ml_orders {$ordersWhereRevenue}");
        $stmt->execute($params);
        $ordersSummary = $stmt->fetch() ?: ['total' => 0, 'revenue' => 0, 'profit' => 0];

        // Pedidos por status (todos os status, inclusive cancelados)
        $stmt = $db->prepare("SELECT status, COUNT(*) as count FROM ml_orders {$ordersWhere} GROUP BY status");
        $stmt->execute($params);
        $ordersByStatus = $stmt->fetchAll() ?: [];

        // Vendas por dia (Revenue e Profit) — mesma fonte única de receita
        $stmt = $db->prepare("SELECT DATE(date_created) as date, COALESCE(SUM(total_amount), 0) as total, COALESCE(SUM(net_profit), 0) as profit FROM ml_orders {$ordersWhereRevenue} GROUP BY DATE(date_created) ORDER BY date ASC");
        $stmt->execute($params);
        $salesOverTime = $stmt->fetchAll() ?: [];

        $cogsComplete = $this->profitCogsComplete($db, $accountId);

        // Itens - usar ItemService para obter stats consistentes
        $itemService = new \App\Services\ItemService($accountId);
        $itemsStats = $itemService->getItemsStats();
        $itemsData = [
            'total' => $itemsStats['total'] ?? 0,
            'active' => $itemsStats['active'] ?? 0
        ];

        // Perguntas pendentes: ml_questions local (mesmo contrato do tile perguntas_sla).
        $pendingQuestions = $this->resolvePendingQuestions($db, $accountId);

        // Tokens Expirando (Next 3 days)
        $expiringTokens = 0;
        $tWhere = "WHERE token_expires_at < DATE_ADD(NOW(), INTERVAL 3 DAY)";
        $tParams = [];
        if ($accountId) {
            $tWhere .= " AND id = :account_id";
            $tParams['account_id'] = $accountId;
        }
        $stmt = $db->prepare("SELECT COUNT(*) FROM ml_accounts {$tWhere}");
        $stmt->execute($tParams);
        $expiringTokens = (int)$stmt->fetchColumn();

        $reputationMetrics = null;
        if ($accountId) {
            try {
                $client = new MercadoLivreClient($accountId);
                $user = $this->unwrapMlResponse($client->get('/users/me'));
                $reputation = $user['seller_reputation'] ?? null;
                if (is_array($reputation)) {
                    $reputationMetrics = [
                        'level_id' => $reputation['level_id'] ?? null,
                        'claims_rate' => $reputation['metrics']['claims']['rate'] ?? null,
                        'cancellations_rate' => $reputation['metrics']['cancellations']['rate'] ?? null,
                        'delayed_rate' => $reputation['metrics']['delayed_handling_time']['rate'] ?? null,
                        'sales_count' => $reputation['transactions']['total'] ?? null
                    ];
                }
            } catch (\Exception $e) {
                $reputationMetrics = null;
            }
        }

        return [
            'recent_orders_count' => (int)($ordersSummary['total'] ?? 0),
            'total_revenue' => (float)($ordersSummary['revenue'] ?? 0),
            'net_profit' => MissingCogsPolicy::presentNetProfit(
                isset($ordersSummary['profit']) ? (float)$ordersSummary['profit'] : 0.0,
                $cogsComplete
            ),
            'has_real_cogs' => $cogsComplete,
            'cogs_status' => $cogsComplete ? 'real' : 'sem_cmv',
            'orders_by_status' => array_map(function ($row) {
                return [
                    'status' => $row['status'],
                    'count' => (int) $row['count'],
                ];
            }, $ordersByStatus),
            'sales_over_time' => MissingCogsPolicy::presentSalesOverTimeProfit(array_map(function ($row) {
                return [
                    'date' => $row['date'],
                    'total' => (float)$row['total'],
                    'profit' => (float)($row['profit'] ?? 0),
                ];
            }, $salesOverTime), $cogsComplete),
            'total_items' => (int)($itemsData['total'] ?? 0),
            'active_items' => (int)($itemsData['active'] ?? 0),
            'pending_questions' => $pendingQuestions,
            'expiring_tokens' => (int)$expiringTokens,
            'reputation_metrics' => $reputationMetrics
        ];
    }

    // ...

    /**
     * Garante que a tabela de pedidos existe
     */
    private function ensureOrdersTable(PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS ml_orders (
                id INT PRIMARY KEY AUTO_INCREMENT,
                ml_order_id BIGINT UNIQUE NOT NULL,
                ml_account_id INT NOT NULL,
                user_id INT,
                order_data JSON NOT NULL,
                status VARCHAR(50) NOT NULL,
                total_amount DECIMAL(10,2) DEFAULT 0,
                date_created DATETIME NOT NULL,
                synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                subtotal DECIMAL(10,2) DEFAULT 0,
                ml_commission DECIMAL(10,2) DEFAULT 0,
                payment_fee DECIMAL(10,2) DEFAULT 0,
                fixed_fee DECIMAL(10,2) DEFAULT 0,
                shipping_cost DECIMAL(10,2) DEFAULT 0,
                discount_amount DECIMAL(10,2) DEFAULT 0,
                taxes DECIMAL(10,2) DEFAULT 0,
                product_cost DECIMAL(10,2) DEFAULT 0,
                total_costs DECIMAL(10,2) DEFAULT 0,
                net_revenue DECIMAL(10,2) DEFAULT 0,
                gross_margin DECIMAL(10,2) DEFAULT 0,
                net_profit DECIMAL(10,2) DEFAULT 0,
                roi DECIMAL(10,2) DEFAULT 0,
                is_profitable TINYINT(1) DEFAULT 1,

                is_full TINYINT(1) DEFAULT 0,
                is_flex TINYINT(1) DEFAULT 0,
                free_shipping TINYINT(1) DEFAULT 0,
                listing_type VARCHAR(50),
                payment_method VARCHAR(50),
                installments INT DEFAULT 1,
                items_count INT DEFAULT 0,
                shipped_at DATETIME,
                delivered_at DATETIME,
                handling_time INT,
                delivery_time INT,
                is_delayed TINYINT(1) DEFAULT 0,

                FOREIGN KEY (ml_account_id) REFERENCES ml_accounts(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_account_id (ml_account_id),
                INDEX idx_user_id (user_id),
                INDEX idx_status (status),
                INDEX idx_date_created (date_created),
                INDEX idx_synced_at (synced_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function resolvePendingQuestions(PDO $db, ?int $accountId): int
    {
        if ($accountId === null || $accountId <= 0) {
            return 0;
        }

        $sql = "SELECT COUNT(*) FROM ml_questions WHERE status = :status AND account_id = :account_id";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            'status' => 'UNANSWERED',
            'account_id' => $accountId,
        ]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * True only if every paid unit in the last 30d has a known unit cost.
     * Unscoped (null account) is never "CMV real".
     */
    private function profitCogsComplete(PDO $db, ?int $accountId): bool
    {
        if ($accountId === null || $accountId <= 0) {
            return false;
        }

        try {
            $sql = "SELECT jt.item_id AS item_id, jt.qty AS qty
                    FROM ml_orders o
                    INNER JOIN JSON_TABLE(
                        o.order_data,
                        '$.order_items[*]' COLUMNS (
                            item_id VARCHAR(32) PATH '$.item.id',
                            qty INT PATH '$.quantity'
                        )
                    ) AS jt
                    WHERE " . RevenueHelper::paidStatusesSql('o.status') . "
                      AND o.date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND o.ml_account_id = :account_id";
            $stmt = $db->prepare($sql);
            $stmt->execute(['account_id' => $accountId]);
            $lines = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            if ($lines === []) {
                return false;
            }

            $itemIds = [];
            foreach ($lines as $line) {
                $id = trim((string)($line['item_id'] ?? ''));
                if ($id !== '') {
                    $itemIds[$id] = true;
                }
            }
            $ids = array_keys($itemIds);
            if ($ids === []) {
                return false;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $costSql = "SELECT mlb_id, custo_produto FROM sku_custos
                 WHERE account_id = ? AND custo_produto > 0 AND mlb_id IN ({$placeholders})";
            $costStmt = $db->prepare($costSql);
            $costStmt->execute([$accountId, ...$ids]);
            $costByMlb = [];
            foreach ($costStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $mlb = trim((string)($row['mlb_id'] ?? ''));
                if ($mlb !== '') {
                    $costByMlb[$mlb] = (float)$row['custo_produto'];
                }
            }

            $with = 0;
            $without = 0;
            foreach ($lines as $line) {
                $id = trim((string)($line['item_id'] ?? ''));
                $qty = (int)($line['qty'] ?? 0);
                if ($id === '' || $qty <= 0) {
                    continue;
                }
                if (($costByMlb[$id] ?? 0) > 0) {
                    $with += $qty;
                } else {
                    $without += $qty;
                }
            }

            return MissingCogsPolicy::hasRealCogs($with, $without);
        } catch (Throwable $e) {
            return false;
        }
    }

    private function buildDegradedMetrics(?int $accountId): array
    {
        $itemsTotal = 0;
        $itemsActive = 0;

        try {
            $itemService = new ItemService($accountId);
            $itemsStats = $itemService->getItemsStats();
            $itemsTotal = (int)($itemsStats['total'] ?? 0);
            $itemsActive = (int)($itemsStats['active'] ?? 0);
        } catch (Throwable $e) {
            // Sem DB e sem API de itens, manter zero.
        }

        return [
            'recent_orders_count' => 0,
            'total_revenue' => 0.0,
            'net_profit' => null,
            'has_real_cogs' => false,
            'cogs_status' => 'sem_cmv',
            'orders_by_status' => [],
            'sales_over_time' => [],
            'total_items' => $itemsTotal,
            'active_items' => $itemsActive,
            'pending_questions' => 0,
            'expiring_tokens' => 0,
            'reputation_metrics' => null,
            'warning' => 'Banco indisponível; métricas financeiras exibidas em modo degradado.',
        ];
    }

    private function unwrapMlResponse(array $response): array
    {
        if (isset($response['error'])) {
            return $response;
        }

        if (isset($response['body']) && is_array($response['body'])) {
            return $response['body'];
        }

        return $response;
    }
}

<?php
declare(strict_types=1);

namespace App\Controllers;

/**
 * AdvancedReportController - Relatórios Avançados para Dashboard
 * 
 * Endpoints para:
 * - Timeline de vendas
 * - Top produtos
 * - Métricas por hora
 * - Exportação de dados
 */
class AdvancedReportController extends BaseController
{
    private \PDO $db;

    public function __construct()
    {
        parent::__construct();
        $this->db = \App\Database::getInstance();
    }

    /**
     * Timeline de vendas
     * GET /api/reports/sales-timeline
     */
    /**
     * Timeline de vendas
     * GET /api/reports/sales-timeline
     */
    public function salesTimeline(): void
    {
        header('Content-Type: application/json');

        $period = $this->request->getInt('period', 30);
        $accountId = $this->request->get('account', 'all');
        $dateStart = $this->request->get('date_start');
        $dateEnd = $this->request->get('date_end');

        try {
            $data = $this->getTimelineData($period, $accountId, $dateStart, $dateEnd);
            echo json_encode(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Top produtos mais vendidos
     * GET /api/reports/top-products
     */
    public function topProducts(): void
    {
        header('Content-Type: application/json');

        $period = $this->request->getInt('period', 30);
        $limit = $this->request->getIntClamped('limit', 10, 1, 20);
        $accountId = $this->request->get('account', 'all');

        try {
            $data = $this->getTopProductsData($limit, $accountId);
            echo json_encode(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Vendas por hora do dia
     * GET /api/reports/hourly
     */
    public function hourly(): void
    {
        header('Content-Type: application/json');

        $period = $this->request->getInt('period', 30);
        $accountId = $this->request->get('account', 'all');

        try {
            $data = $this->getHourlyData($period, $accountId);
            echo json_encode(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Relatório consolidado
     * GET /api/reports/consolidated
     */
    public function consolidated(): void
    {
        header('Content-Type: application/json');

        $period = $this->request->getInt('period', 30);
        $accountId = $this->request->get('account', 'all');

        try {
            // Período atual
            $current = $this->getPeriodStats($period, $accountId);

            // Período anterior (para comparação)
            $previous = $this->getPeriodStats($period, $accountId, true);

            // Calcular variações
            $changes = [];
            if ($previous['total_sales'] > 0) {
                $changes['sales'] = (($current['total_sales'] - $previous['total_sales']) / $previous['total_sales']) * 100;
            }
            if ($previous['total_revenue'] > 0) {
                $changes['revenue'] = (($current['total_revenue'] - $previous['total_revenue']) / $previous['total_revenue']) * 100;
            }
            if ($previous['active_listings'] > 0) {
                $changes['listings'] = (($current['active_listings'] - $previous['active_listings']) / $previous['active_listings']) * 100;
            }
            if ($previous['avg_ticket'] > 0) {
                $changes['ticket'] = (($current['avg_ticket'] - $previous['avg_ticket']) / $previous['avg_ticket']) * 100;
            }

            echo json_encode([
                'success' => true,
                'data' => array_merge($current, ['changes' => $changes])
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Exportar dados do dashboard
     * GET /api/export/dashboard
     */
    public function export(): void
    {
        $format = $this->request->get('format', 'csv');
        $period = $this->request->getInt('period', 30);
        $accountId = $this->request->get('account', 'all');

        // Coletar todos os dados
        $data = [
            'period' => $period,
            'generated_at' => date('Y-m-d H:i:s'),
            'kpis' => $this->getPeriodStats($period, $accountId),
            'timeline' => $this->getTimelineData($period, $accountId),
            'top_products' => $this->getTopProductsData(20, $accountId),
            'hourly' => $this->getHourlyData($period, $accountId)
        ];

        if ($format === 'json') {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="dashboard_report_' . date('Y-m-d') . '.json"');
            echo json_encode($data, JSON_PRETTY_PRINT);
            return;
        }

        if ($format === 'csv') {
            $this->exportCsv($data);
            return;
        }

        if ($format === 'pdf') {
            // PDF seria gerado com biblioteca externa (TCPDF, DOMPDF, etc)
            header('Content-Type: application/json');
            echo json_encode(['error' => 'PDF export não implementado ainda']);
        }
    }

    /**
     * Relatório por categoria
     * GET /api/reports/by-category
     */
    public function byCategory(): void
    {
        header('Content-Type: application/json');

        $accountId = $this->request->get('account', 'all');

        try {
            $data = $this->getSalesByCategory($accountId);
            echo json_encode(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // === Métodos privados ===

    private function getPeriodStats(int $period, string $accountId, bool $previous = false): array
    {
        $dateOffset = $previous ? $period : 0;

        $where = 'date_created BETWEEN DATE_SUB(NOW(), INTERVAL :end_offset DAY) AND DATE_SUB(NOW(), INTERVAL :start_offset DAY)';
        $params = [
            'start_offset' => $dateOffset,
            'end_offset' => $dateOffset + $period
        ];

        if ($accountId !== 'all' && $accountId) {
            $where .= ' AND ml_account_id = :account_id';
            $params['account_id'] = $accountId;
        }

        try {
            // Vendas
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_sales,
                    COALESCE(SUM(total_amount), 0) as total_revenue,
                    COALESCE(AVG(total_amount), 0) as avg_ticket
                FROM ml_orders
                WHERE {$where}
            ");
            $stmt->execute($params);
            $orderStats = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Anúncios ativos
            $itemWhere = $accountId !== 'all' && $accountId ? 'AND account_id = :account_id' : '';
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as active_listings
                FROM items
                WHERE status = 'active' {$itemWhere}
            ");
            $stmt->execute($accountId !== 'all' && $accountId ? ['account_id' => $accountId] : []);
            $itemStats = $stmt->fetch(\PDO::FETCH_ASSOC);

            return [
                'total_sales' => (int)($orderStats['total_sales'] ?? 0),
                'total_revenue' => (float)($orderStats['total_revenue'] ?? 0),
                'avg_ticket' => (float)($orderStats['avg_ticket'] ?? 0),
                'active_listings' => (int)($itemStats['active_listings'] ?? 0)
            ];
        } catch (\Exception $e) {
            return [
                'total_sales' => 0,
                'total_revenue' => 0,
                'avg_ticket' => 0,
                'active_listings' => 0
            ];
        }
    }

    private function getTimelineData(int $period, string $accountId, ?string $dateStart = null, ?string $dateEnd = null): array
    {
        try {
            $where = '1=1';
            $params = [];

            if ($dateStart && $dateEnd) {
                $where .= ' AND DATE(o.date_created) BETWEEN :date_start AND :date_end';
                $params['date_start'] = $dateStart;
                $params['date_end'] = $dateEnd;
            } else {
                $where .= ' AND o.date_created >= DATE_SUB(NOW(), INTERVAL :period DAY)';
                $params['period'] = $period;
            }

            if ($accountId !== 'all' && $accountId) {
                $where .= ' AND o.ml_account_id = :account_id';
                $params['account_id'] = $accountId;
            }

            $sql = "SELECT 
                        DATE(o.date_created) as date,
                        COUNT(*) as sales,
                        SUM(o.total_amount) as revenue
                    FROM ml_orders o
                    WHERE {$where}
                    GROUP BY DATE(o.date_created)
                    ORDER BY date ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Se a tabela não existir, retorna vazio (comportamento correto para sistema novo)
            return [];
        }
    }

    private function getTopProductsData(int $limit, string $accountId): array
    {
        try {
            $where = 'i.status = "active"';
            $limitSql = max(1, min(200, (int)$limit));
            $params = [];

            if ($accountId !== 'all' && $accountId) {
                $where .= ' AND i.account_id = :account_id';
                $params['account_id'] = $accountId;
            }

            // Tenta selecionar colunas padrão. Se thumbnail falhar, o catch pegará.
            $sql = "SELECT 
                        i.id,
                        i.ml_item_id,
                        i.title,
                        i.price,
                        i.sold_quantity as sales,
                        i.available_quantity as stock,
                        (i.sold_quantity * i.price) as revenue
                    FROM items i
                    WHERE {$where}
                    ORDER BY i.sold_quantity DESC
                    LIMIT {$limitSql}";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
    }

    private function getHourlyData(int $period, string $accountId): array
    {
        try {
            $where = 'date_created >= DATE_SUB(NOW(), INTERVAL :period DAY)';
            $params = ['period' => $period];

            if ($accountId !== 'all' && $accountId) {
                $where .= ' AND ml_account_id = :account_id';
                $params['account_id'] = $accountId;
            }

            $sql = "SELECT 
                        HOUR(date_created) as hour,
                        COUNT(*) as sales,
                        SUM(total_amount) as revenue
                    FROM ml_orders
                    WHERE {$where}
                    GROUP BY HOUR(date_created)
                    ORDER BY hour";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
    }
    
    private function getSalesByCategory(string $accountId): array
    {
        try {
            $where = 'i.status = "active"';
            $params = [];

            if ($accountId !== 'all' && $accountId) {
                $where .= ' AND i.account_id = :account_id';
                $params['account_id'] = $accountId;
            }

            // Nota: tabela `categories` nunca existiu no schema (JOIN sempre falhava,
            // engolido pelo catch(\PDOException) abaixo, retornando lista vazia
            // silenciosamente). `items.category_name` já é denormalizado na própria
            // tabela (populado a partir da API ML), então dispensa o JOIN.
            $sql = "SELECT 
                        i.category_id,
                        MAX(i.category_name) as category_name,
                        COUNT(*) as count,
                        SUM(i.sold_quantity) as total_sales,
                        SUM(i.sold_quantity * i.price) as total_revenue
                    FROM items i
                    WHERE {$where}
                    GROUP BY i.category_id
                    ORDER BY total_sales DESC
                    LIMIT 10";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
    }

    private function exportCsv(array $data): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="dashboard_report_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');

        // BOM para UTF-8
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // KPIs
        fputcsv($output, ['=== KPIs ===']);
        fputcsv($output, ['Métrica', 'Valor']);
        fputcsv($output, ['Total de Vendas', $data['kpis']['total_sales'] ?? 0]);
        fputcsv($output, ['Receita Total', 'R$ ' . number_format($data['kpis']['total_revenue'] ?? 0, 2, ',', '.')]);
        fputcsv($output, ['Ticket Médio', 'R$ ' . number_format($data['kpis']['avg_ticket'] ?? 0, 2, ',', '.')]);
        fputcsv($output, ['Anúncios Ativos', $data['kpis']['active_listings'] ?? 0]);

        fputcsv($output, []);
        fputcsv($output, ['=== Timeline ===']);
        fputcsv($output, ['Data', 'Vendas', 'Receita']);
        foreach ($data['timeline'] as $row) {
            fputcsv($output, [$row['date'] ?? '', $row['sales'] ?? 0, $row['revenue'] ?? 0]);
        }

        fputcsv($output, []);
        fputcsv($output, ['Gerado em: ' . $data['generated_at']]);

        fclose($output);
    }
}

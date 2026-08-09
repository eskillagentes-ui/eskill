<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Helpers\RevenueHelper;
use App\Services\ItemService;
use App\Services\DashboardService;
use PDO;

use App\Services\UserService;

class FullController extends BaseController
{
    private ItemService $itemService;
    private DashboardService $dashboardService;
    private UserService $userService;
    private PDO $db;
    private ?int $accountId;

    // Constantes para cálculo de restock
    private const TARGET_DAYS_COVERAGE = 30;  // Objetivo: 30 dias de estoque
    private const CRITICAL_DAYS = 5;          // Crítico: menos de 5 dias
    private const WARNING_DAYS = 10;          // Alerta: menos de 10 dias

    public function __construct()
    {
        parent::__construct();
        $this->userService = new UserService();
        if (!$this->userService->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $this->accountId = $_SESSION['active_ml_account_id'] ?? null;
        $this->itemService = new ItemService($this->accountId);
        $this->dashboardService = new DashboardService();
        $this->db = Database::getInstance();
    }

    /**
     * Render Full Restock Dashboard
     */
    public function index(): void
    {
        $pageTitle = 'Full Restock';
        $activePage = 'full';

        ob_start();
        require __DIR__ . '/../Views/dashboard/logistics/full_restock.php';
        $content = ob_get_clean();
        require __DIR__ . '/../Views/layouts/modern/app.php';
    }

    /**
     * API: Get Restock Suggestions - dados reais do banco
     */
    public function getRestockSuggestions(): void
    {
        header('Content-Type: application/json');

        try {
            $sortBy = $this->request->get('sort', 'days_coverage');
            $sortDir = $this->request->getSortDir('dir', 'ASC');
            $status = $this->request->get('status'); // critical, warning, healthy
            $limit = $this->request->getIntClamped('limit', 10, 200, 100);

            // Buscar itens com fulfillment/Full e calcular velocidade de vendas
            $suggestions = $this->calculateRestockSuggestions($limit);

            // Filtrar por status se especificado
            if ($status) {
                $suggestions = array_filter($suggestions, fn($item) => $item['status'] === $status);
                $suggestions = array_values($suggestions);
            }

            // Ordenar
            usort($suggestions, function ($a, $b) use ($sortBy, $sortDir) {
                // days_coverage=null (sem vendas/urgência) deve ordenar como
                // "infinito" e não como 0, senão itens saudáveis aparecem
                // misturados com os críticos ao ordenar ASC (Onda 2 / T5).
                $fallback = $sortBy === 'days_coverage' ? INF : 0;
                $valA = $a[$sortBy] ?? $fallback;
                $valB = $b[$sortBy] ?? $fallback;

                if ($sortDir === 'ASC') {
                    return $valA <=> $valB;
                }
                return $valB <=> $valA;
            });

            // Resumo
            $summary = $this->calculateSummary($suggestions);

            echo json_encode([
                'success' => true,
                'items' => $suggestions,
                'summary' => $summary,
                'generated_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Calcula sugestões de restock baseado em dados reais
     */
    private function calculateRestockSuggestions(int $limit): array
    {
        $limitSql = max(1, min(200, (int)$limit));
        // Buscar itens ativos com estoque (fulfillment ou todos)
        // Assumindo que itens "Full" têm logistic_type = 'fulfillment' ou similar
        $itemsQuery = "
            SELECT
                i.ml_item_id as id,
                i.title,
                i.thumbnail,
                i.available_quantity as current_stock,
                i.price,
                i.category_id,
                COALESCE(JSON_UNQUOTE(JSON_EXTRACT(i.data, '$.shipping.logistic_type')), '') as logistic_type
            FROM items i
            WHERE i.account_id = :account_id
            AND i.status = 'active'
            AND i.available_quantity IS NOT NULL
            ORDER BY i.available_quantity ASC
            LIMIT {$limitSql}
        ";

        $stmt = $this->db->prepare($itemsQuery);
        $stmt->bindValue(':account_id', $this->accountId, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            return [];
        }

        // Buscar vendas dos últimos 30 dias para cada item
        $itemIds = array_column($items, 'id');
        $salesData = $this->getSalesVelocity($itemIds);

        $suggestions = [];

        foreach ($items as $item) {
            $itemId = $item['id'];
            $currentStock = (int)($item['current_stock'] ?? 0);
            $salesLast30d = (int)($salesData[$itemId] ?? 0);

            // Calcular velocidade diária
            $dailyVelocity = $salesLast30d / 30;

            // Cobertura em dias. Sem vendas nos últimos 30d = cobertura
            // indefinida ("infinita"): expõe null (front-end exibe "∞"/"—")
            // em vez de 999 cru ou NaN (Onda 2 / T5). Internamente, itens sem
            // venda são tratados como "healthy" (não há urgência de reposição
            // sem demanda observada).
            $daysCoverage = $dailyVelocity > 0 ? $currentStock / $dailyVelocity : null;

            // Calcular quantidade sugerida para envio
            $targetStock = ceil($dailyVelocity * self::TARGET_DAYS_COVERAGE);
            $suggestedSend = max(0, $targetStock - $currentStock);

            // Determinar status
            $status = 'healthy';
            if ($daysCoverage !== null) {
                if ($daysCoverage < self::CRITICAL_DAYS) {
                    $status = 'critical';
                } elseif ($daysCoverage < self::WARNING_DAYS) {
                    $status = 'warning';
                }
            }

            // Calcular valor do estoque sugerido
            $price = (float)($item['price'] ?? 0);
            $suggestedValue = $suggestedSend * $price;

            $suggestions[] = [
                'id' => $itemId,
                'title' => $item['title'],
                'thumbnail' => $item['thumbnail'],
                'current_stock' => $currentStock,
                'sales_last_30d' => $salesLast30d,
                'daily_velocity' => round($dailyVelocity, 2),
                'days_coverage' => $daysCoverage !== null ? round($daysCoverage, 1) : null,
                'suggested_send' => (int)$suggestedSend,
                'suggested_value' => round($suggestedValue, 2),
                'status' => $status,
                'logistic_type' => $item['logistic_type'],
                'price' => $price
            ];
        }

        return $suggestions;
    }

    /**
     * Busca velocidade de vendas dos últimos 30 dias.
     *
     * A tabela order_items está vazia (sem ETL que a popule); os itens
     * vendidos ficam apenas dentro de ml_orders.order_data (JSON retornado
     * pela API do ML). Por isso a agregação é feita em PHP a partir do JSON,
     * usando a mesma definição de "pago" do restante do sistema
     * (RevenueHelper — Onda 2 / T1/T5).
     */
    private function getSalesVelocity(array $itemIds): array
    {
        if (empty($itemIds)) {
            return [];
        }

        $wantedIds = array_flip(array_map('strval', $itemIds));

        $query = "
            SELECT order_data
            FROM ml_orders
            WHERE ml_account_id = :account_id
            AND date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND " . RevenueHelper::paidStatusesSql() . "
            AND order_data IS NOT NULL
        ";

        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':account_id', $this->accountId, PDO::PARAM_INT);
        $stmt->execute();

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $data = json_decode((string)$row['order_data'], true);
            $orderItems = $data['order_items'] ?? [];
            if (!is_array($orderItems)) {
                continue;
            }

            foreach ($orderItems as $orderItem) {
                $itemId = (string)($orderItem['item']['id'] ?? '');
                if ($itemId === '' || !isset($wantedIds[$itemId])) {
                    continue;
                }
                $quantity = (int)($orderItem['quantity'] ?? 0);
                $results[$itemId] = ($results[$itemId] ?? 0) + $quantity;
            }
        }

        return $results;
    }

    /**
     * Calcula resumo das sugestões
     */
    private function calculateSummary(array $suggestions): array
    {
        $critical = 0;
        $warning = 0;
        $healthy = 0;
        $totalSuggestedUnits = 0;
        $totalSuggestedValue = 0;

        foreach ($suggestions as $item) {
            switch ($item['status']) {
                case 'critical':
                    $critical++;
                    break;
                case 'warning':
                    $warning++;
                    break;
                case 'healthy':
                    $healthy++;
                    break;
            }

            $totalSuggestedUnits += $item['suggested_send'];
            $totalSuggestedValue += $item['suggested_value'];
        }

        return [
            'total_items' => count($suggestions),
            'critical_count' => $critical,
            'warning_count' => $warning,
            'healthy_count' => $healthy,
            'total_suggested_units' => $totalSuggestedUnits,
            'total_suggested_value' => round($totalSuggestedValue, 2),
            'needs_attention' => $critical + $warning
        ];
    }

    /**
     * API: Exportar lista de restock para CSV
     */
    public function exportRestock(): void
    {
        try {
            $suggestions = $this->calculateRestockSuggestions(500);

            // Filtrar apenas os que precisam de envio
            $toExport = array_filter($suggestions, fn($item) => $item['suggested_send'] > 0);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="restock_' . date('Y-m-d') . '.csv"');

            $output = fopen('php://output', 'w');

            // Cabeçalho
            fputcsv($output, [
                'ID',
                'Título',
                'Estoque Atual',
                'Vendas 30d',
                'Velocidade Diária',
                'Dias Cobertura',
                'Qtd Sugerida',
                'Valor Sugerido',
                'Status'
            ]);

            foreach ($toExport as $item) {
                fputcsv($output, [
                    $item['id'],
                    $item['title'],
                    $item['current_stock'],
                    $item['sales_last_30d'],
                    $item['daily_velocity'],
                    $item['days_coverage'],
                    $item['suggested_send'],
                    'R$ ' . number_format($item['suggested_value'], 2, ',', '.'),
                    strtoupper($item['status'])
                ]);
            }

            fclose($output);
            exit;
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Services\AI\ML\DeepDemandPredictor;
use PDO;

/**
 * Inventory Auto-Manager V9.0
 * 
 * Autonomous inventory management: scans items, predicts demand,
 * generates reorder alerts, and triggers restocking decisions.
 */
class InventoryAutoManager
{
    private PDO $db;
    private DeepDemandPredictor $demandPredictor;
    private DecisionEngineService $decisionEngine;
    private int $lowStockThreshold = 10;
    private int $criticalStockThreshold = 3;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->demandPredictor = new DeepDemandPredictor();
        $this->decisionEngine = new DecisionEngineService();
    }

    /**
     * Scan all items and return those needing attention
     */
    public function checkAllItems(?int $accountId = null): array
    {
        $sql = "SELECT ml_item_id, title, available_quantity, price, category_id 
                FROM items WHERE status = 'active'";
        $params = [];
        
        if ($accountId) {
            $sql .= " AND account_id = :account_id";
            $params['account_id'] = $accountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [
            'scanned' => count($items),
            'low_stock' => [],
            'critical_stock' => [],
            'healthy' => 0
        ];

        foreach ($items as $item) {
            $qty = (int)$item['available_quantity'];
            
            if ($qty <= $this->criticalStockThreshold) {
                $results['critical_stock'][] = $this->enrichWithForecast($item);
            } elseif ($qty <= $this->lowStockThreshold) {
                $results['low_stock'][] = $this->enrichWithForecast($item);
            } else {
                $results['healthy']++;
            }
        }

        return $results;
    }

    /**
     * Enrich item with demand forecast
     */
    private function enrichWithForecast(array $item): array
    {
        $forecast = $this->demandPredictor->forecastDemand($item['ml_item_id'], 14);
        $reorderPoint = $this->demandPredictor->calculateReorderPoint($item['ml_item_id']);
        
        return array_merge($item, [
            'forecast_14_days' => $forecast['predicted_sales'],
            'reorder_point' => $reorderPoint,
            'days_until_stockout' => $this->calculateDaysUntilStockout($item, $forecast)
        ]);
    }

    /**
     * Calculate days until item runs out of stock
     */
    private function calculateDaysUntilStockout(array $item, array $forecast): int
    {
        $dailyRate = $forecast['predicted_sales'] / 14;
        if ($dailyRate <= 0) return 999;
        return (int)floor($item['available_quantity'] / $dailyRate);
    }

    /**
     * Generate reorder recommendations
     */
    public function generateReorderAlerts(?int $accountId = null): array
    {
        $scan = $this->checkAllItems($accountId);
        $alerts = [];

        foreach (array_merge($scan['critical_stock'], $scan['low_stock']) as $item) {
            $decision = $this->decisionEngine->makeInventoryDecision($item['ml_item_id']);
            
            $alerts[] = [
                'item_id' => $item['ml_item_id'],
                'title' => $item['title'],
                'current_stock' => $item['available_quantity'],
                'urgency' => $decision['urgency'] ?? 'medium',
                'recommended_action' => $decision['recommended_action'] ?? 'restock',
                'recommended_qty' => $decision['recommended_quantity'] ?? 50,
                'confidence' => $decision['confidence'] ?? 0.75,
                'days_until_stockout' => $item['days_until_stockout']
            ];
        }

        // Save alerts
        $this->saveAlerts($alerts);

        return $alerts;
    }

    private function saveAlerts(array $alerts): void
    {
        foreach ($alerts as $alert) {
            $stmt = $this->db->prepare("
                INSERT INTO inventory_alerts (item_id, title, current_stock, urgency, recommended_qty, created_at)
                VALUES (:item_id, :title, :stock, :urgency, :qty, NOW())
                ON DUPLICATE KEY UPDATE urgency = VALUES(urgency), recommended_qty = VALUES(recommended_qty), created_at = NOW()
            ");
            $stmt->execute([
                'item_id' => $alert['item_id'],
                'title' => $alert['title'],
                'stock' => $alert['current_stock'],
                'urgency' => $alert['urgency'],
                'qty' => $alert['recommended_qty']
            ]);
        }
    }

    /**
     * Initialize alerts table
     */
    public function ensureAlertsTable(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS inventory_alerts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                item_id VARCHAR(50) NOT NULL UNIQUE,
                title VARCHAR(255),
                current_stock INT,
                urgency VARCHAR(20),
                recommended_qty INT,
                acknowledged BOOLEAN DEFAULT FALSE,
                created_at DATETIME,
                INDEX idx_urgency (urgency)
            )
        ");
    }
}

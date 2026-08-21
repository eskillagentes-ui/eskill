<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Services\CompetitorMonitorService;
use App\Database;

/**
 * Competitor Monitor Controller
 * Handles automated competitor tracking dashboard API endpoints
 */
class CompetitorMonitorController
{
    private \PDO $db;
    private ?int $accountId;
    private Request $request;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->request = new Request();
        $this->accountId = \App\Helpers\SessionHelper::getActiveAccountId();
    }

    /**
     * Check authentication
     */
    private function checkAuth(): bool
    {
        if (!isset($_SESSION['user_id']) || !$this->accountId) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return false;
        }
        return true;
    }

    /**
     * Get all tracked competitors
     * GET /api/competitor/tracked
     */
    public function getTracked(): void
    {
        header('Content-Type: application/json');

        if (!$this->checkAuth()) return;

        try {
            $stmt = $this->db->prepare("
                SELECT
                    ct.*,
                    i.title as my_title,
                    i.price as my_price,
                    i.thumbnail as my_thumbnail
                FROM competitor_tracking ct
                LEFT JOIN items i ON ct.my_item_id = i.ml_item_id AND i.account_id = ct.account_id
                WHERE ct.account_id = ?
                ORDER BY ct.last_checked DESC
            ");
            $stmt->execute([$this->accountId]);
            $competitors = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Enrich with current competitor data
            foreach ($competitors as &$comp) {
                $comp['price_diff'] = $comp['competitor_price'] - $comp['my_price'];
                $comp['price_diff_percent'] = $comp['my_price'] > 0
                    ? round(($comp['price_diff'] / $comp['my_price']) * 100, 2)
                    : 0;

                // Determine status based on price changes
                if ($comp['price_diff'] < -5) {
                    $comp['status'] = 'price_drop';
                } elseif ($comp['price_diff'] > 5) {
                    $comp['status'] = 'price_increase';
                } elseif ($comp['competitor_stock'] == 0) {
                    $comp['status'] = 'out_of_stock';
                } else {
                    $comp['status'] = 'normal';
                }
            }

            echo json_encode([
                'success' => true,
                'competitors' => $competitors,
                'total' => count($competitors)
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Get recent alerts
     * GET /api/competitor/alerts?limit=10
     */
    public function getAlerts(): void
    {
        header('Content-Type: application/json');

        if (!$this->checkAuth()) return;

        try {
            $limit = min($this->request->getInt('limit', 10), 50);
            $limitSql = max(1, (int)$limit);

            $stmt = $this->db->prepare("
                SELECT
                    ca.*,
                    ct.my_item_id,
                    ct.competitor_item_id,
                    i.title as my_title
                FROM competitor_alerts ca
                LEFT JOIN competitor_tracking ct ON ca.tracking_id = ct.id
                LEFT JOIN items i ON ct.my_item_id = i.ml_item_id AND i.account_id = ct.account_id
                WHERE ct.account_id = ?
                ORDER BY ca.created_at DESC
                LIMIT {$limitSql}
            ");
            $stmt->execute([$this->accountId]);
            $alerts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Format timestamps
            foreach ($alerts as &$alert) {
                $alert['time_ago'] = $this->getTimeAgo($alert['created_at']);
            }

            echo json_encode([
                'success' => true,
                'alerts' => $alerts,
                'unread_count' => $this->getUnreadAlertsCount()
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Get monitoring statistics
     * GET /api/competitor/stats
     */
    public function getStats(): void
    {
        header('Content-Type: application/json');

        if (!$this->checkAuth()) return;

        try {
            // Active competitors
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM competitor_tracking
                WHERE account_id = ? AND is_active = 1
            ");
            $stmt->execute([$this->accountId]);
            $activeCompetitors = $stmt->fetch(\PDO::FETCH_ASSOC)['count'];

            // Today's alerts
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM competitor_alerts ca
                LEFT JOIN competitor_tracking ct ON ca.tracking_id = ct.id
                WHERE ct.account_id = ?
                AND DATE(ca.created_at) = CURDATE()
            ");
            $stmt->execute([$this->accountId]);
            $todayAlerts = $stmt->fetch(\PDO::FETCH_ASSOC)['count'];

            // Price changes today
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM competitor_alerts ca
                LEFT JOIN competitor_tracking ct ON ca.tracking_id = ct.id
                WHERE ct.account_id = ?
                AND DATE(ca.created_at) = CURDATE()
                AND ca.type IN ('price_drop', 'price_increase')
            ");
            $stmt->execute([$this->accountId]);
            $priceChanges = $stmt->fetch(\PDO::FETCH_ASSOC)['count'];

            // Opportunities (out of stock or price drops)
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM competitor_tracking
                WHERE account_id = ?
                AND (competitor_stock = 0 OR competitor_price < my_price - 10)
            ");
            $stmt->execute([$this->accountId]);
            $opportunities = $stmt->fetch(\PDO::FETCH_ASSOC)['count'];

            echo json_encode([
                'success' => true,
                'stats' => [
                    'active_competitors' => (int)$activeCompetitors,
                    'today_alerts' => (int)$todayAlerts,
                    'price_changes' => (int)$priceChanges,
                    'opportunities' => (int)$opportunities
                ]
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Track new competitor
     * POST /api/competitor/track
     */
    public function track(): void
    {
        header('Content-Type: application/json');

        if (!$this->checkAuth()) return;

        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['my_item_id']) || !isset($data['competitor_item_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'my_item_id and competitor_item_id are required']);
            return;
        }

        try {
            $service = new CompetitorMonitorService($this->accountId);
            $result = $service->addToWatchlist([
                'item_id' => $data['competitor_item_id'],
                'keywords' => $data['keywords'] ?? null,
                'my_item_id' => $data['my_item_id'],
            ]);

            // Store alert preferences
            if (isset($data['alerts'])) {
                $stmt = $this->db->prepare("
                    UPDATE competitor_tracking
                    SET alert_price_drop = ?,
                        alert_price_increase = ?,
                        alert_stock_change = ?
                    WHERE my_item_id = ?
                    AND competitor_item_id = ?
                    AND account_id = ?
                ");
                $stmt->execute([
                    $data['alerts']['price_drop'] ?? 1,
                    $data['alerts']['price_increase'] ?? 1,
                    $data['alerts']['stock_change'] ?? 1,
                    $data['my_item_id'],
                    $data['competitor_item_id'],
                    $this->accountId
                ]);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Concorrente adicionado com sucesso',
                'data' => $result
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Start monitoring (enable auto-check)
     * POST /api/competitor/monitoring/start
     */
    public function startMonitoring(): void
    {
        header('Content-Type: application/json');

        if (!$this->checkAuth()) return;

        try {
            // Update user settings
            $stmt = $this->db->prepare("
                INSERT INTO user_settings (user_id, setting_key, setting_value)
                VALUES ((SELECT user_id FROM ml_accounts WHERE id = ?), 'competitor_monitoring_active', '1')
                ON DUPLICATE KEY UPDATE setting_value = '1'
            ");
            $stmt->execute([$this->accountId]);

            // Activate all tracked competitors
            $stmt = $this->db->prepare("
                UPDATE competitor_tracking
                SET is_active = 1
                WHERE account_id = ?
            ");
            $stmt->execute([$this->accountId]);

            echo json_encode([
                'success' => true,
                'message' => 'Monitoramento iniciado com sucesso'
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Pause monitoring
     * POST /api/competitor/monitoring/pause
     */
    public function pauseMonitoring(): void
    {
        header('Content-Type: application/json');

        if (!$this->checkAuth()) return;

        try {
            $stmt = $this->db->prepare("
                INSERT INTO user_settings (user_id, setting_key, setting_value)
                VALUES ((SELECT user_id FROM ml_accounts WHERE id = ?), 'competitor_monitoring_active', '0')
                ON DUPLICATE KEY UPDATE setting_value = '0'
            ");
            $stmt->execute([$this->accountId]);

            echo json_encode([
                'success' => true,
                'message' => 'Monitoramento pausado'
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Toggle individual competitor monitoring
     * POST /api/competitor/toggle/{id}
     */
    public function toggleMonitoring(string $id): void
    {
        header('Content-Type: application/json');

        if (!$this->checkAuth()) return;

        try {
            $stmt = $this->db->prepare("
                UPDATE competitor_tracking
                SET is_active = NOT is_active
                WHERE id = ? AND account_id = ?
            ");
            $stmt->execute([$id, $this->accountId]);

            echo json_encode([
                'success' => true,
                'message' => 'Status atualizado'
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Remove competitor from tracking
     * DELETE /api/competitor/{id}
     */
    public function remove(string $id): void
    {
        header('Content-Type: application/json');

        if (!$this->checkAuth()) return;

        try {
            $stmt = $this->db->prepare("
                DELETE FROM competitor_tracking
                WHERE id = ? AND account_id = ?
            ");
            $stmt->execute([$id, $this->accountId]);

            echo json_encode([
                'success' => true,
                'message' => 'Concorrente removido'
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Mark alert as read
     * POST /api/competitor/alert/{id}/read
     */
    public function markAlertRead(string $id): void
    {
        header('Content-Type: application/json');

        if (!$this->checkAuth()) return;

        try {
            $stmt = $this->db->prepare("
                UPDATE competitor_alerts ca
                LEFT JOIN competitor_tracking ct ON ca.tracking_id = ct.id
                SET ca.is_read = 1
                WHERE ca.id = ? AND ct.account_id = ?
            ");
            $stmt->execute([$id, $this->accountId]);

            echo json_encode([
                'success' => true,
                'message' => 'Alerta marcado como lido'
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Save monitoring settings
     * POST /api/competitor/settings
     */
    public function saveSettings(): void
    {
        header('Content-Type: application/json');

        if (!$this->checkAuth()) return;

        $data = json_decode(file_get_contents('php://input'), true);

        try {
            $stmtUser = $this->db->prepare("SELECT user_id FROM ml_accounts WHERE id = ?");
            $stmtUser->execute([$this->accountId]);
            $userId = $stmtUser->fetchColumn();

            $settings = [
                'competitor_check_frequency' => $data['check_frequency'] ?? '1h',
                'competitor_notify_email' => $data['notifications']['email'] ?? 0,
                'competitor_notify_push' => $data['notifications']['push'] ?? 0,
                'competitor_notify_whatsapp' => $data['notifications']['whatsapp'] ?? 0,
                'competitor_max_tracked' => $data['limits']['max_competitors'] ?? 50,
                'competitor_max_unread_alerts' => $data['limits']['max_unread_alerts'] ?? 100
            ];

            foreach ($settings as $key => $value) {
                $stmt = $this->db->prepare("
                    INSERT INTO user_settings (user_id, setting_key, setting_value)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                ");
                $stmt->execute([$userId, $key, $value]);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Configurações salvas com sucesso'
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Helper: Get unread alerts count
     */
    private function getUnreadAlertsCount(): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM competitor_alerts ca
            LEFT JOIN competitor_tracking ct ON ca.tracking_id = ct.id
            WHERE ct.account_id = ? AND ca.is_read = 0
        ");
        $stmt->execute([$this->accountId]);
        return (int)$stmt->fetch(\PDO::FETCH_ASSOC)['count'];
    }

    /**
     * Helper: Format time ago
     */
    private function getTimeAgo(string $datetime): string
    {
        $now = new \DateTime();
        $ago = new \DateTime($datetime);
        $diff = $now->diff($ago);

        if ($diff->d > 0) {
            return $diff->d . 'd atrás';
        } elseif ($diff->h > 0) {
            return $diff->h . 'h atrás';
        } elseif ($diff->i > 0) {
            return $diff->i . 'min atrás';
        } else {
            return 'agora';
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Services\MercadoLivreClient;
use PDO;

class CompetitorService
{
    private \PDO $db;
    private MercadoLivreClient $client;
    private ?int $accountId;

    public function __construct(?int $accountId = null)
    {
        $this->db = Database::getInstance();
        $this->accountId = $accountId;
        $this->client = new MercadoLivreClient($accountId);

        try {
            $this->ensureTables();
        } catch (\Exception $e) {
            // Log error but don't fail
            log_warning('Failed to ensure tables', ['service' => 'CompetitorService', 'error' => $e->getMessage()]);
        }
    }

    /**
     * Adiciona um vendedor para monitoramento
     */
    public function addSellerToWatch(int $mlSellerId, string $nickname): array
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO competitor_sellers (account_id, ml_seller_id, nickname, created_at)
                VALUES (:account_id, :ml_seller_id, :nickname, NOW())
                ON DUPLICATE KEY UPDATE nickname = VALUES(nickname)
            ");

            $stmt->execute([
                'account_id' => $this->accountId ?? 0,
                'ml_seller_id' => $mlSellerId,
                'nickname' => $nickname
            ]);

            return ['success' => true, 'message' => 'Vendedor adicionado ao monitoramento.'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Adiciona um item de concorrente para monitoramento
     */
    public function addItemToWatch(string $mlItemId): array
    {
        try {
            // Buscar detalhes do item primeiro para garantir que existe
            $item = $this->client->get("/items/{$mlItemId}");

            if (isset($item['error'])) {
                return ['success' => false, 'error' => 'Item não encontrado no Mercado Livre.'];
            }

            $sellerId = $item['seller_id'];
            $sellerNick = "Seller {$sellerId}"; // Pode ser atualizado depois

            // Adicionar vendedor se não existir
            $this->addSellerToWatch($sellerId, $sellerNick);

            $stmt = $this->db->prepare("
                INSERT INTO competitor_items (
                    account_id, ml_item_id, seller_id, title, price, permalink, status, created_at
                ) VALUES (
                    :account_id, :ml_item_id, :seller_id, :title, :price, :permalink, :status, NOW()
                )
                ON DUPLICATE KEY UPDATE
                    price = VALUES(price),
                    status = VALUES(status),
                    title = VALUES(title)
            ");

            $stmt->execute([
                'account_id' => $this->accountId ?? 0,
                'ml_item_id' => $item['id'],
                'seller_id' => $sellerId,
                'title' => $item['title'],
                'price' => $item['price'],
                'permalink' => $item['permalink'],
                'status' => $item['status']
            ]);

            return ['success' => true, 'message' => 'Item adicionado ao monitoramento.'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Registra snapshot diário e detecta mudanças
     */
    public function recordSnapshot(): array
    {
        $stats = [
            'analyzed' => 0,
            'changes' => 0,
            'errors' => 0
        ];

        // Buscar todos os itens monitorados
        $stmt = $this->db->query("SELECT * FROM competitor_items WHERE status != 'closed'"); // Ignorar fechados permanentemente?
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            return $stats;
        }

        // Processar em chunks para não sobrecarregar API
        $chunks = array_chunk($items, 20);

        foreach ($chunks as $chunk) {
            $ids = implode(',', array_column($chunk, 'ml_item_id'));

            try {
                $response = $this->client->get("/items", ['ids' => $ids]);

                // 403 / 404 Handling
                if (isset($response['error']) || (isset($response['status']) && $response['status'] >= 400)) {
                    $errorMsg = $response['message'] ?? 'Unknown Error';
                    // If 403, it might happen for ALL items or specific ones depending on endpoint
                    // /items with comma separated usually returns 200 with partial errors in body
                    // BUT if the App doesn't have scope, the whole request fails.
                    $stats['errors']++;
                    log_warning('API returned error', ['service' => 'CompetitorService', 'status' => $response['status'], 'error' => $errorMsg]);
                    continue; // Skip chunk
                }

                if (!is_array($response)) continue;

                foreach ($response as $apiItem) {
                    // Normalize response: /items multiget returns array of objects {code, body}
                    $body = $apiItem['body'] ?? null;
                    $code = $apiItem['code'] ?? 0;

                    if (!$body || $code != 200) {
                        // Singular item error (e.g. deleted item)
                        if ($code == 403) {
                            // Mark as "Data Restricted" internally?
                            // For now, just skip.
                        }
                        continue;
                    }

                    $newItem = $body;
                    $stats['analyzed']++;

                    // Buscar dados salvos localmente
                    $localItem = null;
                    foreach ($chunk as $c) {
                        if ($c['ml_item_id'] === $newItem['id']) {
                            $localItem = $c;
                            break;
                        }
                    }

                    if ($localItem) {
                        // Detectar mudanças
                        $changes = [];

                        if (abs((float)$localItem['price'] - (float)$newItem['price']) > 0.01) {
                            $changes[] = [
                                'type' => 'price_change',
                                'old_value' => $localItem['price'],
                                'new_value' => $newItem['price']
                            ];
                        }

                        // Treat 'paused' as a status change
                        if ($localItem['status'] !== $newItem['status']) {
                            $changes[] = [
                                'type' => 'status_change',
                                'old_value' => $localItem['status'],
                                'new_value' => $newItem['status']
                            ];
                        }

                        // Se houve mudança, registrar log e atualizar item
                        if (!empty($changes)) {
                            $this->logChanges($localItem, $changes);
                            $this->updateLocalItem($newItem);
                            $stats['changes']++;
                        }

                        // Always record history snapshot (daily) com estoque
                        $stock = $newItem['available_quantity'] ?? null;
                        $this->recordHistory($localItem['id'], $newItem['price'], $stock);
                    }
                }

                // Rate limiting: aguardar entre chunks para não sobrecarregar API
                usleep(100000); // 100ms entre chunks

            } catch (\Exception $e) {
                $stats['errors']++;
                log_error('Critical error during snapshot', ['service' => 'CompetitorService', 'error' => $e->getMessage()]);
            }
        }

        return $stats;
    }

    private function recordHistory(int $internalId, float $price, ?int $stock = null): void
    {
        // Verificar se já existe registro hoje para evitar duplicados
        $checkStmt = $this->db->prepare("
            SELECT id, price, min_price, max_price FROM competitor_price_history
            WHERE competitor_item_id = ? AND recorded_at = CURRENT_DATE()
        ");
        $checkStmt->execute([$internalId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Atualizar min/max se necessário
            $updateStmt = $this->db->prepare("
                UPDATE competitor_price_history
                SET min_price = LEAST(min_price, ?),
                    max_price = GREATEST(max_price, ?),
                    last_price = ?,
                    stock = COALESCE(?, stock),
                    snapshot_count = snapshot_count + 1
                WHERE id = ?
            ");
            $updateStmt->execute([$price, $price, $price, $stock, $existing['id']]);
        } else {
            // Inserir novo registro
            $sql = "
                INSERT INTO competitor_price_history
                (competitor_item_id, price, min_price, max_price, last_price, stock, snapshot_count, recorded_at)
                VALUES (?, ?, ?, ?, ?, ?, 1, CURRENT_DATE())
            ";
            $this->db->prepare($sql)->execute([$internalId, $price, $price, $price, $price, $stock]);
        }
    }

    /**
     * Get price history for chart
     */
    public function getPriceHistory(string $mlItemId, int $days = 30): array
    {
        // Get internal ID
        $s = $this->db->prepare("SELECT id FROM competitor_items WHERE ml_item_id = ?");
        $s->execute([$mlItemId]);
        $id = $s->fetchColumn();

        if (!$id) return [];

        $stmt = $this->db->prepare("
            SELECT price, recorded_at
            FROM competitor_price_history
            WHERE competitor_item_id = ?
            AND recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ORDER BY recorded_at ASC
        ");
        $stmt->execute([$id, $days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtém alertas recentes
     */
    public function getRecentAlerts(int $limit = 10): array
    {
        $limitSql = max(1, min((int)$limit, 200));

        $stmt = $this->db->prepare("
            SELECT l.*, i.title, i.permalink
            FROM competitor_logs l
            JOIN competitor_items i ON l.ml_item_id = i.ml_item_id
            WHERE l.account_id = :account_id
            ORDER BY l.created_at DESC
            LIMIT {$limitSql}
        ");

        $stmt->bindValue(':account_id', $this->accountId ?? 0);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function logChanges(array $item, array $changes): void
    {
        // Lazy load notification service
        $notificationService = new NotificationService();

        foreach ($changes as $change) {
            $stmt = $this->db->prepare("
                INSERT INTO competitor_logs (
                    account_id, ml_item_id, change_type, old_value, new_value, created_at
                ) VALUES (
                    :account_id, :ml_item_id, :change_type, :old_value, :new_value, NOW()
                )
            ");

            $stmt->execute([
                'account_id' => $this->accountId ?? 0,
                'ml_item_id' => $item['ml_item_id'],
                'change_type' => $change['type'],
                'old_value' => $change['old_value'],
                'new_value' => $change['new_value']
            ]);

            // Trigger Notification for aggressive price drops (>5%)
            if ($change['type'] === 'price_change') {
                $old = (float)$change['old_value'];
                $new = (float)$change['new_value'];
                if ($old > 0 && ($new < $old)) {
                    $dropPercent = (($old - $new) / $old) * 100;
                    if ($dropPercent > 1) { // Alert on any drop > 1%
                        $msg = "⚠️ Concorrente baixou o preço!\nItem: {$item['title']}\nDe: R$ $old Por: R$ $new (-" . number_format($dropPercent, 1) . "%)";
                        $notificationService->sendAlert('Alerta de Concorrente', $msg, 'MEDIUM');
                    }
                }
            }
        }
    }

    private function updateLocalItem(array $item): void
    {
        $stmt = $this->db->prepare("
            UPDATE competitor_items
            SET price = :price, status = :status, updated_at = NOW()
            WHERE ml_item_id = :ml_item_id
        ");

        $stmt->execute([
            'price' => $item['price'],
            'status' => $item['status'],
            'ml_item_id' => $item['id']
        ]);
    }

    private function ensureTables(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS competitor_sellers (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    account_id INT NOT NULL,
                    ml_seller_id BIGINT NOT NULL,
                    nickname VARCHAR(100),
                    reputation_level VARCHAR(50),
                    created_at DATETIME,
                    updated_at DATETIME,
                    UNIQUE KEY unique_seller (account_id, ml_seller_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\Exception $e) {
            log_warning('CompetitorService: failed to create competitor_sellers table', ['error' => $e->getMessage()]);
        }

        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS competitor_items (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    account_id INT NOT NULL,
                    ml_item_id VARCHAR(50) NOT NULL,
                    seller_id BIGINT NOT NULL,
                    title VARCHAR(255),
                    price DECIMAL(10,2),
                    original_price DECIMAL(10,2),
                    permalink TEXT,
                    status VARCHAR(50),
                    available_quantity INT DEFAULT 0,
                    sold_quantity INT DEFAULT 0,
                    category_id VARCHAR(50),
                    my_item_id VARCHAR(50),
                    created_at DATETIME,
                    updated_at DATETIME,
                    UNIQUE KEY unique_item (account_id, ml_item_id),
                    INDEX idx_seller (seller_id),
                    INDEX idx_my_item (my_item_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\Exception $e) {
            log_warning('CompetitorService: failed to create competitor_items table', ['error' => $e->getMessage()]);
        }

        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS competitor_logs (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    account_id INT NOT NULL,
                    ml_item_id VARCHAR(50) NOT NULL,
                    change_type VARCHAR(50),
                    old_value VARCHAR(255),
                    new_value VARCHAR(255),
                    change_percent DECIMAL(5,2),
                    created_at DATETIME,
                    INDEX idx_created (created_at),
                    INDEX idx_type (change_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\Exception $e) {
            log_warning('CompetitorService: failed to create competitor_logs table', ['error' => $e->getMessage()]);
        }

        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS competitor_price_history (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    competitor_item_id INT NOT NULL,
                    price DECIMAL(10,2) NOT NULL,
                    min_price DECIMAL(10,2),
                    max_price DECIMAL(10,2),
                    last_price DECIMAL(10,2),
                    stock INT,
                    snapshot_count INT DEFAULT 1,
                    recorded_at DATE NOT NULL,
                    UNIQUE KEY unique_history (competitor_item_id, recorded_at),
                    INDEX idx_recorded (recorded_at),
                    FOREIGN KEY (competitor_item_id) REFERENCES competitor_items(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\Exception $e) {
            log_warning('CompetitorService: failed to create competitor_price_history table', ['error' => $e->getMessage()]);
        }

        // Adicionar colunas novas para tabelas existentes
        try {
            $this->db->exec("ALTER TABLE competitor_price_history ADD COLUMN min_price DECIMAL(10,2) AFTER price");
        } catch (\Exception $e) { /* Column may already exist */
        }
        try {
            $this->db->exec("ALTER TABLE competitor_price_history ADD COLUMN max_price DECIMAL(10,2) AFTER min_price");
        } catch (\Exception $e) { /* Column may already exist */
        }
        try {
            $this->db->exec("ALTER TABLE competitor_price_history ADD COLUMN last_price DECIMAL(10,2) AFTER max_price");
        } catch (\Exception $e) { /* Column may already exist */
        }
        try {
            $this->db->exec("ALTER TABLE competitor_price_history ADD COLUMN stock INT AFTER last_price");
        } catch (\Exception $e) { /* Column may already exist */
        }
        try {
            $this->db->exec("ALTER TABLE competitor_price_history ADD COLUMN snapshot_count INT DEFAULT 1 AFTER stock");
        } catch (\Exception $e) { /* Column may already exist */
        }
        try {
            $this->db->exec("ALTER TABLE competitor_logs ADD COLUMN change_percent DECIMAL(5,2) AFTER new_value");
        } catch (\Exception $e) { /* Column may already exist */
        }
        try {
            $this->db->exec("ALTER TABLE competitor_items ADD COLUMN my_item_id VARCHAR(50) AFTER category_id");
        } catch (\Exception $e) { /* Column may already exist */
        }
    }

    /**
     * Vincula um item de concorrente a um item próprio para comparação direta
     */
    public function linkToMyItem(string $competitorItemId, string $myItemId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE competitor_items
            SET my_item_id = :my_item_id
            WHERE ml_item_id = :competitor_item_id AND account_id = :account_id
        ");
        return $stmt->execute([
            'my_item_id' => $myItemId,
            'competitor_item_id' => $competitorItemId,
            'account_id' => $this->accountId ?? 0,
        ]);
    }

    /**
     * Obtém comparativo de preços entre meus itens e concorrentes
     */
    public function getPriceComparison(): array
    {
        $stmt = $this->db->prepare("
            SELECT
                ci.ml_item_id as competitor_item_id,
                ci.title as competitor_title,
                ci.price as competitor_price,
                ci.seller_id,
                ci.my_item_id,
                i.title as my_title,
                i.price as my_price,
                (ci.price - i.price) as price_difference,
                ROUND(((ci.price - i.price) / i.price) * 100, 2) as difference_percent
            FROM competitor_items ci
            JOIN items i ON ci.my_item_id = i.ml_item_id
            WHERE ci.account_id = :account_id
            AND ci.my_item_id IS NOT NULL
            AND ci.status = 'active'
            ORDER BY difference_percent ASC
        ");
        $stmt->execute(['account_id' => $this->accountId ?? 0]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtém estatísticas agregadas de monitoramento
     */
    public function getMonitoringStats(): array
    {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(DISTINCT ci.id) as total_items,
                COUNT(DISTINCT ci.seller_id) as total_sellers,
                AVG(ci.price) as avg_price,
                MIN(ci.price) as min_price,
                MAX(ci.price) as max_price,
                SUM(CASE WHEN cl.change_type = 'price_change' AND cl.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as price_changes_7d
            FROM competitor_items ci
            LEFT JOIN competitor_logs cl ON ci.ml_item_id = cl.ml_item_id
            WHERE ci.account_id = :account_id
        ");
        $stmt->execute(['account_id' => $this->accountId ?? 0]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Obtém concorrentes que estão com preço menor que o meu
     */
    public function getCheaperCompetitors(): array
    {
        $stmt = $this->db->prepare("
            SELECT
                ci.*,
                i.price as my_price,
                i.title as my_title,
                (i.price - ci.price) as i_am_more_expensive_by,
                ROUND(((i.price - ci.price) / i.price) * 100, 2) as percent_more_expensive
            FROM competitor_items ci
            JOIN items i ON ci.my_item_id = i.ml_item_id
            WHERE ci.account_id = :account_id
            AND ci.my_item_id IS NOT NULL
            AND ci.price < i.price
            AND ci.status = 'active'
            ORDER BY percent_more_expensive DESC
        ");
        $stmt->execute(['account_id' => $this->accountId ?? 0]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

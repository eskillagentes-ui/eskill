<?php
declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;

/**
 * Serviço de detecção de duplicatas em clones de catálogo.
 *
 * Verifica se itens já foram clonados anteriormente, gerencia registros
 * de clones e fornece estatísticas de duplicação.
 */
class CloneDuplicateDetectionService
{
    private PDO $db;
    private const REGISTRY_TABLE = 'clone_duplicate_registry';

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->ensureRegistryTable();
    }

    /**
     * Verifica se um item já possui clones registrados.
     *
     * @param string $itemId   ID do item no Mercado Livre
     * @param int    $accountId ID da conta
     * @return array{is_duplicate: bool, existing_items: array, recommendation: string, severity?: string, options?: array}
     */
    public function checkDuplicate(string $itemId, int $accountId): array
    {
        $stmt = $this->db->prepare("
            SELECT target_item_id, created_at, status
            FROM " . self::REGISTRY_TABLE . "
            WHERE source_item_id = :item_id
              AND account_id = :account_id
              AND status = 'active'
            ORDER BY created_at DESC
        ");
        $stmt->execute(['item_id' => $itemId, 'account_id' => $accountId]);
        $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($existing)) {
            $existing = $this->fetchLegacyExistingItems($itemId, $accountId);
        }

        if (empty($existing)) {
            return [
                'is_duplicate' => false,
                'existing_items' => [],
                'clone_count' => 0,
                'recommendation' => 'proceed',
            ];
        }

        $severity = count($existing) >= 3 ? 'high' : 'low';
        $result = [
            'is_duplicate' => true,
            'existing_items' => $existing,
            'clone_count' => count($existing),
            'recommendation' => $severity === 'high' ? 'review' : 'proceed_with_caution',
            'severity' => $severity,
        ];

        if ($severity === 'high') {
            $result['options'] = [
                'skip' => 'Pular clonagem deste item',
                'update' => 'Atualizar clone existente',
                'create_new' => 'Criar novo clone mesmo assim',
            ];
        }

        return $result;
    }

    /**
     * Verifica duplicatas para múltiplos itens em lote.
     *
     * @param array $itemIds   Lista de IDs de itens
     * @param int   $accountId ID da conta
     * @return array Mapa itemId => resultado de duplicata
     */
    public function batchCheckDuplicates(array $itemIds, int $accountId): array
    {
        if (empty($itemIds)) {
            return [];
        }

        $results = [];
        foreach ($itemIds as $itemId) {
            $results[$itemId] = $this->checkDuplicate($itemId, $accountId);
        }

        return $results;
    }

    /**
     * Resolve uma situação de duplicata aplicando a ação escolhida.
     *
     * @param string $itemId    ID do item
     * @param int    $accountId ID da conta
     * @param string $action    Ação: 'skip', 'update' ou 'create_new'
     * @param array  $options   Opções adicionais para create_new
     * @return array Resultado da resolução
     */
    public function resolveDuplicate(
        string $itemId,
        int $accountId,
        string $action,
        array $options = []
    ): array {
        return match ($action) {
            'skip' => [
                'status' => 'skipped',
                'reason' => 'Item pulado pelo usuário devido a duplicata',
                'item_id' => $itemId,
            ],
            'update' => [
                'status' => 'update_required',
                'action' => 'update_existing_clone',
                'item_id' => $itemId,
            ],
            'create_new' => [
                'status' => 'proceed',
                'action' => 'create_new_clone',
                'item_id' => $itemId,
                'modifications' => [
                    'title_suffix' => $options['title_suffix'] ?? '',
                    'sku_suffix' => $options['sku_suffix'] ?? '',
                ],
            ],
            default => [
                'status' => 'error',
                'reason' => "Ação desconhecida: {$action}",
            ],
        };
    }

    /**
     * Verifica se um SKU já existe para a conta.
     *
     * @param string $sku       SKU a verificar
     * @param int    $accountId ID da conta
     * @return array Resultado com is_duplicate e opções
     */
    public function checkSkuDuplicate(string $sku, int $accountId): array
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as cnt
            FROM " . self::REGISTRY_TABLE . "
            WHERE JSON_EXTRACT(metadata, '$.sku') = :sku
              AND account_id = :account_id
              AND status = 'active'
        ");

        try {
            $stmt->execute(['sku' => $sku, 'account_id' => $accountId]);
            $count = (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            $count = 0;
        }

        $isDuplicate = $count > 0;

        $result = [
            'is_duplicate' => $isDuplicate,
            'recommendation' => $isDuplicate ? 'modify_sku' : 'proceed',
        ];

        if ($isDuplicate) {
            $result['options'] = [
                'skip' => 'Pular item com SKU duplicado',
                'modify_sku' => 'Adicionar sufixo ao SKU',
            ];
        }

        return $result;
    }

    /**
     * Retorna estatísticas de duplicatas para uma conta.
     *
     * @param int $accountId ID da conta
     * @param int $days      Período em dias
     * @return array Estatísticas
     */
    public function getDuplicateStats(int $accountId, int $days = 30): array
    {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(DISTINCT source_item_id) as total_source_items,
                COUNT(*) as total_clones
            FROM " . self::REGISTRY_TABLE . "
            WHERE account_id = :account_id
              AND status = 'active'
              AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
        ");
        $stmt->execute(['account_id' => $accountId, 'days' => $days]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);

        // Contar duplicatas (itens com mais de 1 clone)
        $stmt2 = $this->db->prepare("
            SELECT COUNT(*) as duplicate_clones
            FROM (
                SELECT source_item_id
                FROM " . self::REGISTRY_TABLE . "
                WHERE account_id = :account_id
                  AND status = 'active'
                  AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                GROUP BY source_item_id
                HAVING COUNT(*) > 1
            ) dup
        ");
        $stmt2->execute(['account_id' => $accountId, 'days' => $days]);
        $duplicateCount = (int) $stmt2->fetchColumn();

        // Top duplicatas
        $stmt3 = $this->db->prepare("
            SELECT source_item_id, COUNT(*) as clone_count
            FROM " . self::REGISTRY_TABLE . "
            WHERE account_id = :account_id
              AND status = 'active'
              AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            GROUP BY source_item_id
            HAVING COUNT(*) > 1
            ORDER BY clone_count DESC
            LIMIT 10
        ");
        $stmt3->execute(['account_id' => $accountId, 'days' => $days]);
        $topDuplicates = $stmt3->fetchAll(PDO::FETCH_ASSOC);

        return [
            'summary' => [
                'total_source_items' => (int) ($summary['total_source_items'] ?? 0),
                'total_clones' => (int) ($summary['total_clones'] ?? 0),
                'duplicate_clones' => $duplicateCount,
            ],
            'top_duplicates' => $topDuplicates,
            'period_days' => $days,
        ];
    }

    /**
     * Registra um novo clone no sistema.
     *
     * @param string      $sourceId  Item de origem
     * @param string      $targetId  Item clone criado
     * @param int         $accountId ID da conta
     * @param string|null $jobId     ID do job de clonagem
     * @return bool
     */
    public function registerClone(
        string $sourceId,
        string $targetId,
        int $accountId,
        ?string $jobId = null
    ): bool {
        $stmt = $this->db->prepare("
            INSERT INTO " . self::REGISTRY_TABLE . "
            (source_item_id, target_item_id, account_id, job_id, status, metadata, created_at, updated_at)
            VALUES (:source, :target, :account_id, :job_id, 'active', :metadata, NOW(), NOW())
        ");

        return $stmt->execute([
            'source' => $sourceId,
            'target' => $targetId,
            'account_id' => $accountId,
            'job_id' => $jobId,
            'metadata' => json_encode([], JSON_UNESCAPED_UNICODE)
        ]);
    }

    /**
     * Marca um clone como inativo.
     *
     * @param string $targetId ID do clone
     * @return bool
     */
    public function markCloneInactive(string $targetId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE " . self::REGISTRY_TABLE . "
            SET status = 'inactive', updated_at = NOW()
            WHERE target_item_id = :target_id
        ");

        return $stmt->execute(['target_id' => $targetId]);
    }

    /**
     * Remove registros inativos mais antigos que X dias.
     *
     * @param int $days Número de dias
     * @return int Número de registros removidos
     */
    public function cleanupOldInactiveClones(int $days = 90): int
    {
        $stmt = $this->db->prepare("
            DELETE FROM " . self::REGISTRY_TABLE . "
            WHERE status = 'inactive'
              AND updated_at < DATE_SUB(NOW(), INTERVAL :days DAY)
        ");
        $stmt->execute(['days' => $days]);

        return $stmt->rowCount();
    }

    private function ensureRegistryTable(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS " . self::REGISTRY_TABLE . " (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                source_item_id VARCHAR(50) NOT NULL,
                target_item_id VARCHAR(50) NOT NULL,
                account_id INT UNSIGNED NOT NULL,
                job_id VARCHAR(64) NULL,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                metadata JSON NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_source_account_status (source_item_id, account_id, status),
                INDEX idx_target_status (target_item_id, status),
                INDEX idx_account_created (account_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /**
     * @return array<int, array{target_item_id:string, created_at:string, status:string}>
     */
    private function fetchLegacyExistingItems(string $itemId, int $accountId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT i.target_item_id, i.created_at, i.status
                FROM catalog_clone_job_items i
                INNER JOIN catalog_clone_jobs j ON j.job_id = i.job_id
                WHERE i.source_item_id = :item_id
                  AND j.target_account_id = :account_id
                  AND i.target_item_id IS NOT NULL
                  AND i.status IN ('processing', 'completed')
                ORDER BY i.created_at DESC
            ");
            $stmt->execute([
                'item_id' => $itemId,
                'account_id' => $accountId,
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
    }
}

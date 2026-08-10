<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Database;
use PDO;
use Throwable;

/**
 * Cadastro e auditoria de CMV (custo da mercadoria) por anúncio.
 *
 * Fonte canônica de leitura do P&L: sku_custos.custo_produto.
 * Também espelha em items.cost_price para telas/repricing legados.
 * Histórico em item_cogs_history (effective_from) — nunca escreve no ML.
 */
final class CogsService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->ensureSchema();
    }

    /**
     * @return array{
     *   summary: array{total:int, with_real_cogs:int, missing:int, days:int},
     *   items: list<array<string, mixed>>
     * }
     */
    public function auditSoldItems(int $accountId, int $days = 90): array
    {
        $days = max(1, min(365, $days));
        $rows = $this->fetchSoldWithCogs($accountId, $days);

        $withReal = 0;
        $missing = 0;
        foreach ($rows as &$row) {
            $fonte = (string) ($row['fonte'] ?? 'none');
            $row['has_real_cogs'] = $fonte !== 'none';
            $row['cogs_badge'] = $fonte === 'none' ? 'estimado' : 'real';
            if ($row['has_real_cogs']) {
                $withReal++;
            } else {
                $missing++;
            }
            $row['title'] = $row['title'] !== '' ? $row['title'] : '—';
            $row['unit_cost'] = $fonte !== 'none' ? (float) $row['cmv'] : null;
            $row['vendas_90d'] = (int) $row['vendas_90d'];
            $row['receita_90d'] = round((float) $row['receita_90d'], 2);
        }
        unset($row);

        return [
            'summary' => [
                'total' => count($rows),
                'with_real_cogs' => $withReal,
                'missing' => $missing,
                'days' => $days,
            ],
            'items' => $rows,
        ];
    }

    /**
     * @return array{success:bool, mlb_id:string, unit_cost:float, fonte:string, message?:string}
     */
    public function upsertUnitCost(int $accountId, string $mlbId, float $unitCost, ?string $note = null): array
    {
        $mlbId = strtoupper(trim($mlbId));
        if (!preg_match('/^MLB\d+$/', $mlbId)) {
            return ['success' => false, 'mlb_id' => $mlbId, 'unit_cost' => $unitCost, 'fonte' => 'none', 'message' => 'MLB inválido'];
        }
        if ($unitCost < 0) {
            return ['success' => false, 'mlb_id' => $mlbId, 'unit_cost' => $unitCost, 'fonte' => 'none', 'message' => 'Custo não pode ser negativo'];
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare(
                'INSERT INTO sku_custos (account_id, mlb_id, custo_produto, preco_minimo, created_at, updated_at)
                 VALUES (?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE custo_produto = VALUES(custo_produto), updated_at = NOW()'
            );
            // preco_minimo NOT NULL no schema — usa o próprio custo como piso inicial se for insert
            $stmt->execute([$accountId, $mlbId, $unitCost, $unitCost]);

            // Espelho legado (items.cost_price) — best-effort
            try {
                $upd = $this->db->prepare(
                    'UPDATE items SET cost_price = ?, updated_at = NOW()
                     WHERE account_id = ? AND ml_item_id = ?'
                );
                $upd->execute([$unitCost, $accountId, $mlbId]);
            } catch (Throwable $e) {
                // tabela items pode não ter a linha — ok
            }

            $hist = $this->db->prepare(
                'INSERT INTO item_cogs_history (account_id, mlb_id, unit_cost, effective_from, note, created_at)
                 VALUES (?, ?, ?, CURDATE(), ?, NOW())'
            );
            $hist->execute([$accountId, $mlbId, $unitCost, $note]);

            $this->db->commit();

            return [
                'success' => true,
                'mlb_id' => $mlbId,
                'unit_cost' => $unitCost,
                'fonte' => 'sku_custos',
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            log_error('CogsService: falha ao salvar CMV', [
                'account_id' => $accountId,
                'mlb_id' => $mlbId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'mlb_id' => $mlbId,
                'unit_cost' => $unitCost,
                'fonte' => 'none',
                'message' => 'Falha ao salvar CMV',
            ];
        }
    }

    /**
     * Importa CSV com colunas MLB,custo (ou ml_item_id,unit_cost).
     *
     * @return array{success:bool, imported:int, failed:list<array{mlb:string,reason:string}>}
     */
    public function importCsv(int $accountId, string $csvContent): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csvContent)) ?: [];
        $imported = 0;
        $failed = [];

        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '' || ($i === 0 && preg_match('/mlb|custo|cost|unit/i', $line))) {
                continue;
            }
            $parts = str_getcsv($line);
            if (count($parts) < 2) {
                $failed[] = ['mlb' => $line, 'reason' => 'linha inválida'];
                continue;
            }
            $mlb = strtoupper(trim((string) $parts[0]));
            $costRaw = str_replace(['R$', ' ', ','], ['', '', '.'], trim((string) $parts[1]));
            if (!is_numeric($costRaw)) {
                $failed[] = ['mlb' => $mlb, 'reason' => 'custo inválido'];
                continue;
            }
            $result = $this->upsertUnitCost($accountId, $mlb, (float) $costRaw, 'csv_import');
            if ($result['success']) {
                $imported++;
            } else {
                $failed[] = ['mlb' => $mlb, 'reason' => $result['message'] ?? 'erro'];
            }
        }

        return [
            'success' => $failed === [],
            'imported' => $imported,
            'failed' => $failed,
        ];
    }

    /**
     * @return list<array{mlb_id:string,title:string,vendas_90d:int|string,receita_90d:float|string,cmv:float|string,fonte:string}>
     */
    private function fetchSoldWithCogs(int $accountId, int $days): array
    {
        $sql = "
            SELECT
              jt.item_id AS mlb_id,
              COALESCE(
                MAX(CONVERT(mi.title USING utf8mb4)),
                MAX(CONVERT(i.title USING utf8mb4)),
                ''
              ) COLLATE utf8mb4_unicode_ci AS title,
              SUM(jt.qty) AS vendas_90d,
              ROUND(SUM(jt.qty * jt.unit_price), 2) AS receita_90d,
              COALESCE(MAX(sc.custo_produto), MAX(i.cost_price), 0) AS cmv,
              CASE
                WHEN COALESCE(MAX(sc.custo_produto), 0) > 0 THEN 'sku_custos'
                WHEN COALESCE(MAX(i.cost_price), 0) > 0 THEN 'items.cost_price'
                ELSE 'none'
              END AS fonte
            FROM ml_orders o
            INNER JOIN JSON_TABLE(
              o.order_data, '$.order_items[*]'
              COLUMNS (
                item_id VARCHAR(32) PATH '$.item.id',
                qty INT PATH '$.quantity',
                unit_price DECIMAL(12,2) PATH '$.unit_price'
              )
            ) jt
            LEFT JOIN ml_items mi
              ON CONVERT(mi.ml_item_id USING utf8mb4) = CONVERT(jt.item_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
             AND mi.account_id = o.ml_account_id
            LEFT JOIN items i
              ON CONVERT(i.ml_item_id USING utf8mb4) = CONVERT(jt.item_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
             AND i.account_id = o.ml_account_id
            LEFT JOIN sku_custos sc
              ON CONVERT(sc.mlb_id USING utf8mb4) = CONVERT(jt.item_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
             AND sc.account_id = o.ml_account_id
             AND sc.custo_produto > 0
            WHERE o.ml_account_id = ?
              AND o.date_created >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND o.status IN ('paid', 'delivered', 'confirmed')
            GROUP BY jt.item_id
            ORDER BY vendas_90d DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$accountId, $days]);
        /** @var list<array{mlb_id:string,title:string,vendas_90d:int|string,receita_90d:float|string,cmv:float|string,fonte:string}> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function ensureSchema(): void
    {
        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS item_cogs_history (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    mlb_id VARCHAR(32) NOT NULL,
                    unit_cost DECIMAL(12,4) NOT NULL,
                    effective_from DATE NOT NULL,
                    note VARCHAR(255) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_cogs_hist_account_mlb (account_id, mlb_id, effective_from)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Throwable $e) {
            log_warning('CogsService: ensureSchema falhou', ['error' => $e->getMessage()]);
        }
    }
}

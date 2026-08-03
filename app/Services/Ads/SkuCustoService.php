<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\Database;
use PDO;
use Throwable;

/**
 * Cadastro e leitura de custos por SKU + trio de ROAS.
 * SKU sem custo → n/d (nunca assume margem).
 */
final class SkuCustoService
{
    private PDO $db;
    private RoasTrioCalculator $roas;

    public function __construct(?PDO $db = null, ?RoasTrioCalculator $roas = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->roas = $roas ?? new RoasTrioCalculator();
    }

    /**
     * @param array{
     *   mlb_id: string,
     *   custo_produto: float|int|string,
     *   comissao_pct?: float|int|string,
     *   frete_medio?: float|int|string,
     *   custos_operacionais_pct?: float|int|string,
     *   preco_minimo: float|int|string
     * } $row
     * @return array<string, mixed>
     */
    public function upsert(int $accountId, array $row): array
    {
        $mlbId = strtoupper(trim((string) ($row['mlb_id'] ?? '')));
        if ($mlbId === '' || !preg_match('/^MLB\d+$/', $mlbId)) {
            throw new \InvalidArgumentException('mlb_id inválido: ' . $mlbId);
        }

        $custo = (float) ($row['custo_produto'] ?? 0);
        $comissao = (float) ($row['comissao_pct'] ?? 0);
        $frete = (float) ($row['frete_medio'] ?? 0);
        $opPct = (float) ($row['custos_operacionais_pct'] ?? 0);
        $preco = (float) ($row['preco_minimo'] ?? 0);

        $margins = $this->roas->marginsFromCustos($custo, $comissao, $frete, $opPct, $preco);

        $this->db->prepare(
            'INSERT INTO sku_custos
               (account_id, mlb_id, custo_produto, comissao_pct, frete_medio,
                custos_operacionais_pct, preco_minimo, margem_bruta_pct, margem_liquida_pct)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               custo_produto = VALUES(custo_produto),
               comissao_pct = VALUES(comissao_pct),
               frete_medio = VALUES(frete_medio),
               custos_operacionais_pct = VALUES(custos_operacionais_pct),
               preco_minimo = VALUES(preco_minimo),
               margem_bruta_pct = VALUES(margem_bruta_pct),
               margem_liquida_pct = VALUES(margem_liquida_pct)'
        )->execute([
            $accountId,
            $mlbId,
            $custo,
            $comissao,
            $frete,
            $opPct,
            $preco,
            $margins['margem_bruta_pct'],
            $margins['margem_liquida_pct'],
        ]);

        return $this->getByMlb($accountId, $mlbId) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getByMlb(int $accountId, string $mlbId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM sku_custos WHERE account_id = ? AND mlb_id = ?'
        );
        $stmt->execute([$accountId, strtoupper(trim($mlbId))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return $this->enrich($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByAccount(int $accountId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM sku_custos WHERE account_id = ? ORDER BY mlb_id'
        );
        $stmt->execute([$accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn (array $r): array => $this->enrich($r), $rows);
    }

    public function countByAccount(int $accountId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM sku_custos WHERE account_id = ?');
        $stmt->execute([$accountId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Trio de ROAS para o SKU. Sem custo cadastrado → tudo n/d.
     *
     * @return array{
     *   mlb_id: string,
     *   has_custo: bool,
     *   margem_bruta_pct: float|null,
     *   margem_liquida_pct: float|null,
     *   roas_breakeven: float|null,
     *   roas_objetivo: float|null,
     *   roas_escala: float|null,
     *   reason: string|null
     * }
     */
    public function roasTrio(int $accountId, string $mlbId): array
    {
        $mlbId = strtoupper(trim($mlbId));
        $row = $this->getByMlb($accountId, $mlbId);
        if ($row === null) {
            return [
                'mlb_id' => $mlbId,
                'has_custo' => false,
                'margem_bruta_pct' => null,
                'margem_liquida_pct' => null,
                'roas_breakeven' => null,
                'roas_objetivo' => null,
                'roas_escala' => null,
                'reason' => 'custo_nao_cadastrado',
            ];
        }

        $liquida = isset($row['margem_liquida_pct']) ? (float) $row['margem_liquida_pct'] : null;
        $trio = $this->roas->fromMargemLiquida($liquida);

        return [
            'mlb_id' => $mlbId,
            'has_custo' => true,
            'margem_bruta_pct' => isset($row['margem_bruta_pct']) ? (float) $row['margem_bruta_pct'] : null,
            'margem_liquida_pct' => $liquida,
            'roas_breakeven' => $trio['roas_breakeven'],
            'roas_objetivo' => $trio['roas_objetivo'],
            'roas_escala' => $trio['roas_escala'],
            'reason' => $trio['reason'],
        ];
    }

    /**
     * Importa CSV: mlb_id,custo_produto,comissao_pct,frete_medio,custos_operacionais_pct,preco_minimo
     *
     * @return array{imported: int, errors: list<string>}
     */
    public function importCsv(int $accountId, string $path): array
    {
        if (!is_readable($path)) {
            throw new \InvalidArgumentException('CSV ilegível: ' . $path);
        }

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('Falha ao abrir CSV');
        }

        $header = fgetcsv($fh, 0, ',');
        if (!is_array($header) || $header === []) {
            fclose($fh);
            throw new \InvalidArgumentException('CSV sem cabeçalho');
        }
        $header = array_map(
            static fn ($h): string => strtolower(trim((string) $h)),
            $header
        );

        $imported = 0;
        $errors = [];
        $line = 1;
        while (($cols = fgetcsv($fh, 0, ',')) !== false) {
            $line++;
            if ($cols === [null] || $cols === false) {
                continue;
            }
            if (count(array_filter($cols, static fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            try {
                $assoc = [];
                foreach ($header as $i => $key) {
                    $assoc[$key] = $cols[$i] ?? null;
                }
                if (empty($assoc['mlb_id'])) {
                    throw new \InvalidArgumentException('mlb_id vazio');
                }
                $this->upsert($accountId, $assoc);
                $imported++;
            } catch (Throwable $e) {
                $errors[] = "linha {$line}: " . $e->getMessage();
            }
        }
        fclose($fh);

        return ['imported' => $imported, 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function enrich(array $row): array
    {
        $liquida = isset($row['margem_liquida_pct']) ? (float) $row['margem_liquida_pct'] : null;
        $trio = $this->roas->fromMargemLiquida($liquida);
        $row['roas_breakeven'] = $trio['roas_breakeven'];
        $row['roas_objetivo'] = $trio['roas_objetivo'];
        $row['roas_escala'] = $trio['roas_escala'];
        $row['roas_available'] = $trio['available'];
        return $row;
    }
}

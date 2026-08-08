<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;

/**
 * Sugestões de GTIN para Ficha Técnica via pool EanService ou EMPTY_GTIN_REASON.
 * Nunca inventa EAN algorítmico.
 */
class TechSheetGtinSuggestionService
{
    public const SOURCE_EAN_POOL = 'ean_pool';
    public const SOURCE_EMPTY_GTIN = 'empty_gtin_policy';

    public const MODE_POOL_FIRST = 'pool_first';
    public const MODE_EMPTY_PREFERRED = 'empty_reason_preferred';

    private PDO $db;
    private int $accountId;
    private EanService $eanService;
    private TechSheetService $techSheet;

    public function __construct(int $accountId, ?TechSheetService $techSheet = null, ?EanService $eanService = null)
    {
        $this->db = Database::getInstance();
        $this->accountId = $accountId;
        $this->eanService = $eanService ?? new EanService();
        $this->techSheet = $techSheet ?? new TechSheetService($accountId);
    }

    /**
     * @return array{success:bool,skipped?:bool,reason?:string,suggestion?:array,ean_balance?:array,dry_run?:bool}
     */
    public function suggestForItem(
        string $itemId,
        array $categoryAttributes,
        array $itemData,
        string $categoryId,
        string $title = '',
        bool $dryRun = false
    ): array {
        $itemId = trim($itemId);
        if ($itemId === '') {
            return ['success' => false, 'error' => 'item_id inválido'];
        }

        $balance = $this->eanService->getBalance($this->accountId);
        $available = (int)($balance['available'] ?? 0);
        $mode = $this->resolveMode();

        if ($this->itemHasGtin($itemData)) {
            return ['success' => true, 'skipped' => true, 'reason' => 'gtin_already_present', 'ean_balance' => $balance, 'dry_run' => $dryRun];
        }

        if (!$this->categoryHasAttribute($categoryAttributes, 'GTIN')
            && !$this->categoryHasAttribute($categoryAttributes, 'EMPTY_GTIN_REASON')) {
            return ['success' => true, 'skipped' => true, 'reason' => 'category_without_gtin_attrs', 'ean_balance' => $balance, 'dry_run' => $dryRun];
        }

        // Fase 1: não alocar GTIN de pool em itens com variações (GTIN variation_attribute).
        $hasVariations = !empty($itemData['variations']) && is_array($itemData['variations']);
        if ($hasVariations) {
            return $this->suggestEmptyReason($itemId, $categoryId, $categoryAttributes, $balance, $dryRun);
        }

        $existingPool = $this->findOpenSuggestion($itemId, 'GTIN', self::SOURCE_EAN_POOL);
        if ($existingPool !== null) {
            return [
                'success' => true,
                'skipped' => true,
                'reason' => 'idempotent_existing_pool',
                'suggestion' => $existingPool,
                'ean_balance' => $balance,
                'dry_run' => $dryRun,
            ];
        }

        $preferEmpty = ($mode === self::MODE_EMPTY_PREFERRED) || $available <= 0;

        if (!$preferEmpty && $this->categoryHasAttribute($categoryAttributes, 'GTIN')) {
            $pool = $this->suggestFromPool($itemId, $categoryId, $title, $categoryAttributes, $balance, $dryRun);
            if (($pool['success'] ?? false) && empty($pool['skipped'])) {
                return $pool;
            }
            // Sem EAN reservável → cair para EMPTY
        }

        return $this->suggestEmptyReason($itemId, $categoryId, $categoryAttributes, $balance, $dryRun);
    }

    /**
     * @return array{success:bool,skipped?:bool,reason?:string,suggestion?:array,ean_balance?:array}
     */
    private function suggestFromPool(
        string $itemId,
        string $categoryId,
        string $title,
        array $categoryAttributes,
        array $balance,
        bool $dryRun = false
    ): array {
        if ($dryRun) {
            $next = $this->eanService->getNextAvailableEan($this->accountId);
            if ($next === null) {
                return [
                    'success' => true,
                    'skipped' => true,
                    'reason' => 'no_ean_available',
                    'ean_balance' => $balance,
                    'dry_run' => true,
                ];
            }
            $ean = (string)($next['ean'] ?? '');
            if ($ean === '' || !$this->eanService->validateEan($ean)) {
                return [
                    'success' => true,
                    'skipped' => true,
                    'reason' => 'invalid_inventory_ean',
                    'ean_balance' => $balance,
                    'dry_run' => true,
                ];
            }
            return [
                'success' => true,
                'suggestion' => [
                    'attribute_id' => 'GTIN',
                    'suggested_value' => $ean,
                    'source' => self::SOURCE_EAN_POOL,
                    'confidence' => 100,
                ],
                'ean_balance' => $balance,
                'dry_run' => true,
            ];
        }

        $reserved = $this->eanService->reserveEanForItem($this->accountId, $itemId, $title !== '' ? $title : null);
        if ($reserved === null) {
            return [
                'success' => true,
                'skipped' => true,
                'reason' => 'no_ean_available',
                'ean_balance' => $balance,
            ];
        }

        $ean = (string)($reserved['ean'] ?? '');
        if ($ean === '' || !$this->eanService->validateEan($ean)) {
            $this->eanService->releaseEanReservation($this->accountId, $itemId, (int)($reserved['assignment_id'] ?? 0));
            log_warning('TechSheet GTIN: EAN inválido no inventário — reserva liberada', [
                'account_id' => $this->accountId,
                'item_id' => $itemId,
            ]);
            return [
                'success' => true,
                'skipped' => true,
                'reason' => 'invalid_inventory_ean',
                'ean_balance' => $balance,
            ];
        }

        $ok = $this->techSheet->persistSuggestion([
            'account_id' => $this->accountId,
            'item_id' => $itemId,
            'category_id' => $categoryId,
            'attribute_id' => 'GTIN',
            'attribute_name' => $this->attributeName($categoryAttributes, 'GTIN') ?? 'Código universal de produto',
            'suggested_value' => $ean,
            'source' => self::SOURCE_EAN_POOL,
            'confidence' => 100,
            'status' => 'pending',
            'meta' => [
                'ean_assignment_id' => (int)$reserved['assignment_id'],
                'ean_id' => (int)$reserved['ean_id'],
                'reservation' => 'soft',
                'ean' => $ean,
            ],
        ]);

        if (!$ok) {
            $this->eanService->releaseEanReservation($this->accountId, $itemId, (int)$reserved['assignment_id']);
            return ['success' => false, 'error' => 'Falha ao gravar sugestão GTIN', 'ean_balance' => $balance];
        }

        return [
            'success' => true,
            'suggestion' => [
                'attribute_id' => 'GTIN',
                'suggested_value' => $ean,
                'source' => self::SOURCE_EAN_POOL,
                'confidence' => 100,
            ],
            'ean_balance' => $this->eanService->getBalance($this->accountId),
        ];
    }

    /**
     * @return array{success:bool,skipped?:bool,reason?:string,suggestion?:array,ean_balance?:array}
     */
    private function suggestEmptyReason(
        string $itemId,
        string $categoryId,
        array $categoryAttributes,
        array $balance,
        bool $dryRun = false
    ): array {
        if (!$this->categoryHasAttribute($categoryAttributes, 'EMPTY_GTIN_REASON')) {
            return [
                'success' => true,
                'skipped' => true,
                'reason' => 'empty_gtin_reason_unavailable',
                'ean_balance' => $balance,
                'dry_run' => $dryRun,
            ];
        }

        if ($this->findOpenSuggestion($itemId, 'GTIN', self::SOURCE_EAN_POOL) !== null) {
            return [
                'success' => true,
                'skipped' => true,
                'reason' => 'pool_suggestion_exists',
                'ean_balance' => $balance,
                'dry_run' => $dryRun,
            ];
        }

        $existingEmpty = $this->findOpenSuggestion($itemId, 'EMPTY_GTIN_REASON', self::SOURCE_EMPTY_GTIN);
        if ($existingEmpty !== null) {
            return [
                'success' => true,
                'skipped' => true,
                'reason' => 'idempotent_existing_empty',
                'suggestion' => $existingEmpty,
                'ean_balance' => $balance,
                'dry_run' => $dryRun,
            ];
        }

        $reason = $this->resolveEmptyGtinReason($categoryAttributes);
        if ($reason === null) {
            return [
                'success' => true,
                'skipped' => true,
                'reason' => 'empty_reason_value_not_found',
                'ean_balance' => $balance,
                'dry_run' => $dryRun,
            ];
        }

        if ($dryRun) {
            return [
                'success' => true,
                'suggestion' => [
                    'attribute_id' => 'EMPTY_GTIN_REASON',
                    'suggested_value' => $reason['name'],
                    'source' => self::SOURCE_EMPTY_GTIN,
                    'confidence' => 90,
                    'meta' => ['reason_value_id' => $reason['id']],
                ],
                'ean_balance' => $balance,
                'dry_run' => true,
            ];
        }

        $ok = $this->techSheet->persistSuggestion([
            'account_id' => $this->accountId,
            'item_id' => $itemId,
            'category_id' => $categoryId,
            'attribute_id' => 'EMPTY_GTIN_REASON',
            'attribute_name' => $this->attributeName($categoryAttributes, 'EMPTY_GTIN_REASON') ?? 'Motivo de GTIN vazio',
            'suggested_value' => $reason['name'],
            'source' => self::SOURCE_EMPTY_GTIN,
            'confidence' => 90,
            'status' => 'pending',
            'meta' => [
                'reason_value_id' => $reason['id'],
                'reason_name' => $reason['name'],
            ],
        ]);

        if (!$ok) {
            return ['success' => false, 'error' => 'Falha ao gravar EMPTY_GTIN_REASON', 'ean_balance' => $balance];
        }

        return [
            'success' => true,
            'suggestion' => [
                'attribute_id' => 'EMPTY_GTIN_REASON',
                'suggested_value' => $reason['name'],
                'source' => self::SOURCE_EMPTY_GTIN,
                'confidence' => 90,
                'meta' => ['reason_value_id' => $reason['id']],
            ],
            'ean_balance' => $balance,
        ];
    }

    public function releaseReservationForRejectedSuggestion(string $itemId, string $attributeId, ?array $meta): void
    {
        if ($attributeId !== 'GTIN') {
            return;
        }
        $assignmentId = (int)($meta['ean_assignment_id'] ?? 0);
        if ($assignmentId <= 0) {
            return;
        }
        $this->eanService->releaseEanReservation($this->accountId, $itemId, $assignmentId);
    }

    /**
     * Após PUT ML OK: consome EAN do pool (sold + saldo).
     */
    public function confirmPoolAfterApply(string $itemId, array $appliedAttributes): void
    {
        $appliedGtin = false;
        foreach ($appliedAttributes as $attr) {
            if (($attr['id'] ?? '') === 'GTIN') {
                $appliedGtin = true;
                break;
            }
        }
        if (!$appliedGtin) {
            return;
        }

        $stmt = $this->db->prepare(
            "SELECT meta FROM tech_sheet_suggestions
             WHERE account_id = :account_id AND item_id = :item_id
               AND attribute_id = 'GTIN' AND source = :source
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([
            ':account_id' => $this->accountId,
            ':item_id' => $itemId,
            ':source' => self::SOURCE_EAN_POOL,
        ]);
        $metaRaw = $stmt->fetchColumn();
        $meta = is_string($metaRaw) ? (json_decode($metaRaw, true) ?: []) : [];
        $assignmentId = (int)($meta['ean_assignment_id'] ?? 0);
        if ($assignmentId <= 0) {
            return;
        }

        $this->eanService->confirmEanReservationAfterApply($this->accountId, $itemId, $assignmentId);
    }

    private function resolveMode(): string
    {
        $mode = (string)(getenv('TECH_SHEET_GTIN_MODE') ?: ($_ENV['TECH_SHEET_GTIN_MODE'] ?? self::MODE_POOL_FIRST));
        $mode = strtolower(trim($mode));
        if ($mode === self::MODE_EMPTY_PREFERRED) {
            return self::MODE_EMPTY_PREFERRED;
        }
        return self::MODE_POOL_FIRST;
    }

    private function itemHasGtin(array $itemData): bool
    {
        foreach (($itemData['attributes'] ?? []) as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            if (($attr['id'] ?? '') !== 'GTIN') {
                continue;
            }
            $name = trim((string)($attr['value_name'] ?? ''));
            if ($name !== '') {
                return true;
            }
        }
        return false;
    }

    private function categoryHasAttribute(array $categoryAttributes, string $attributeId): bool
    {
        foreach ($categoryAttributes as $attr) {
            if (($attr['id'] ?? '') === $attributeId) {
                return true;
            }
        }
        return false;
    }

    private function attributeName(array $categoryAttributes, string $attributeId): ?string
    {
        foreach ($categoryAttributes as $attr) {
            if (($attr['id'] ?? '') === $attributeId) {
                $name = $attr['name'] ?? null;
                return is_string($name) ? $name : null;
            }
        }
        return null;
    }

    /**
     * @return array{id:string,name:string}|null
     */
    private function resolveEmptyGtinReason(array $categoryAttributes): ?array
    {
        foreach ($categoryAttributes as $attr) {
            if (($attr['id'] ?? '') !== 'EMPTY_GTIN_REASON') {
                continue;
            }
            $values = $attr['values'] ?? [];
            if (!is_array($values)) {
                return null;
            }
            $preferred = [
                'no registrado',
                'não registrado',
                'nao registrado',
                'o produto não tem código cadastrado',
                'o produto nao tem codigo cadastrado',
                'el producto no tiene código registrado',
                'el producto no tiene codigo registrado',
            ];
            foreach ($values as $v) {
                $name = mb_strtolower(trim((string)($v['name'] ?? '')));
                if (in_array($name, $preferred, true)) {
                    return [
                        'id' => (string)($v['id'] ?? ''),
                        'name' => (string)($v['name'] ?? ''),
                    ];
                }
            }
            // Preferência por substring (PT/ES) quando o rótulo varia
            foreach ($values as $v) {
                $name = mb_strtolower(trim((string)($v['name'] ?? '')));
                if (
                    str_contains($name, 'não tem código')
                    || str_contains($name, 'nao tem codigo')
                    || str_contains($name, 'no tiene código')
                    || str_contains($name, 'no tiene codigo')
                    || str_contains($name, 'no registrado')
                ) {
                    return [
                        'id' => (string)($v['id'] ?? ''),
                        'name' => (string)($v['name'] ?? ''),
                    ];
                }
            }
            // fallback: primeiro valor da lista
            $first = $values[0] ?? null;
            if (is_array($first) && !empty($first['name'])) {
                return [
                    'id' => (string)($first['id'] ?? ''),
                    'name' => (string)$first['name'],
                ];
            }
        }
        return null;
    }

    private function findOpenSuggestion(string $itemId, string $attributeId, string $source): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, attribute_id, suggested_value, source, confidence, status, meta
             FROM tech_sheet_suggestions
             WHERE account_id = :account_id AND item_id = :item_id
               AND attribute_id = :attribute_id AND source = :source
               AND status IN ('pending', 'approved')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([
            ':account_id' => $this->accountId,
            ':item_id' => $itemId,
            ':attribute_id' => $attributeId,
            ':source' => $source,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        if (!empty($row['meta']) && is_string($row['meta'])) {
            $row['meta'] = json_decode($row['meta'], true) ?: [];
        }
        return $row;
    }
}

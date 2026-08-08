<?php

declare(strict_types=1);

namespace App\Services\HiddenSeo;

use App\Entity\HiddenSeo\HiddenSeoGap;
use App\Entity\HiddenSeo\PendingChange;
use App\Entity\HiddenSeo\Suggestion;
use App\Exception\UnsafeOperationException;
use App\Services\TechSheetService;
use App\ValueObjects\HiddenSeo\SuggestionSource;

/**
 * Orquestra generate (pending) e applyPending (ML) com SafetyGuard.
 *
 * @see https://developers.mercadolivre.com.br/pt_br/itens-e-buscas
 */
class HiddenSeoSuggester
{
    private int $accountId;
    private SafetyGuard $guard;
    private TechSheetService $techSheet;
    private TechSheetMapper $mapper;

    public function __construct(
        int $accountId,
        ?SafetyGuard $guard = null,
        ?TechSheetService $techSheet = null,
        ?TechSheetMapper $mapper = null
    ) {
        $this->accountId = $accountId;
        $this->guard = $guard ?? new SafetyGuard();
        $this->techSheet = $techSheet ?? new TechSheetService($accountId);
        $this->mapper = $mapper ?? new TechSheetMapper();
    }

    /**
     * Gera sugestões Hidden SEO. dryRun=true → não persiste.
     *
     * @param list<string> $itemIds
     * @return array{success:bool,created:int,skipped:int,suggestions:list<array>,dry_run:bool,error?:string}
     */
    public function generate(array $itemIds, bool $dryRun = true): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('strval', $itemIds))));
        $itemIds = array_slice($itemIds, 0, $this->guard->maxItemsPerRun());

        $created = 0;
        $skipped = 0;
        $suggestions = [];

        foreach ($itemIds as $itemId) {
            $batch = $this->techSheet->suggestHiddenForItem($itemId, $dryRun);
            $inner = $batch['result'] ?? [];
            $n = (int)($batch['created'] ?? 0);
            if ($n > 0) {
                $created += $n;
                foreach (($inner['suggestions'] ?? []) as $s) {
                    $suggestions[] = $s;
                }
            } else {
                $skipped++;
            }
        }

        return [
            'success' => true,
            'created' => $created,
            'skipped' => $skipped,
            'suggestions' => $suggestions,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * Aplica sugestões aprovadas no ML. Conta 1335 → UnsafeOperationException.
     *
     * @throws UnsafeOperationException
     * @return array{success:bool,applied:int,errors:int,dry_run:bool}
     */
    public function applyPending(array $itemIds, bool $dryRun = true, bool $allowApply = false): array
    {
        $this->guard->assertCanApply($this->accountId, $dryRun, $allowApply);

        if ($dryRun) {
            return [
                'success' => true,
                'applied' => 0,
                'errors' => 0,
                'dry_run' => true,
                'would_apply_items' => count($itemIds),
            ];
        }

        $applied = 0;
        $errors = 0;
        foreach ($itemIds as $itemId) {
            $result = $this->techSheet->applyApproved((string)$itemId);
            if ($result['success'] ?? false) {
                $applied++;
            } else {
                $errors++;
            }
        }

        return [
            'success' => $errors === 0,
            'applied' => $applied,
            'errors' => $errors,
            'dry_run' => false,
        ];
    }

    /**
     * Mapeia gap + ficha sem I/O (útil em testes / pré-visualização).
     *
     * @param array{line?:?string,mpn?:?string,sku?:?string,seller_sku?:?string} $ficha
     * @return list<Suggestion>
     */
    public function previewFromFicha(HiddenSeoGap $gap, array $ficha): array
    {
        return $this->mapper->map($gap, $ficha);
    }

    public function suggestionToPending(Suggestion $suggestion): PendingChange
    {
        return new PendingChange(
            $suggestion->mlItemId(),
            $this->accountId,
            $suggestion->attributeId(),
            $suggestion->newValue(),
            SuggestionSource::HIDDEN_SEO,
            $suggestion->evidence()->provenance(),
            $suggestion->evidence()->confidence(),
            $suggestion->oldValue()
        );
    }

    public function guard(): SafetyGuard
    {
        return $this->guard;
    }
}

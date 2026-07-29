<?php

declare(strict_types=1);

namespace App\Services\MercadoLivre;

use App\Security\AuthorizedAccountContext;
use App\Services\MercadoLivreClient;

/**
 * Sync read-only: scan ML moderations → SalesBlockerStore.
 * Nunca escreve no Mercado Livre.
 */
final class IrregularitySyncService
{
    public function __construct(
        private readonly ListingIrregularityScanService $scanService,
        private readonly SalesBlockerStore $store,
        private readonly ListingSearchVisibilityService $visibilityService,
    ) {}

    public static function forClient(MercadoLivreClient $client): self
    {
        $visibility = new ListingSearchVisibilityService($client);
        $scan = new ListingIrregularityScanService($client, $visibility);

        return new self($scan, new SalesBlockerStore(), $visibility);
    }

    /**
     * @return array<string, mixed>
     */
    public function syncAccount(AuthorizedAccountContext $context, int $limitPerBucket = 30): array
    {
        $scan = $this->scanService->scan($limitPerBucket);
        $blocked = is_array($scan['blocked'] ?? null) ? $scan['blocked'] : [];

        $scannedAt = (string) ($scan['scanned_at'] ?? gmdate('c'));
        foreach ($blocked as &$row) {
            if (is_array($row)) {
                $row['scanned_at'] = date('Y-m-d H:i:s');
            }
        }
        unset($row);

        $storeResult = $this->store->upsertBlocked($context, $blocked, 'urgent');

        // Fila de exposição (performance WARNING) — best-effort, rate-limited by scan size
        $exposureUpserted = 0;
        try {
            $queue = $this->visibilityService->buildSearchActivationQueue(null, min(20, $limitPerBucket));
            $items = is_array($queue['queue'] ?? null) ? $queue['queue'] : [];
            $exposureRows = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $warnings = (int) ($item['pending_warnings'] ?? 0);
                if ($warnings <= 0 && empty($item['has_moderation'])) {
                    continue;
                }
                $exposureRows[] = [
                    'listing_id' => $item['listing_id'] ?? $item['item_id'] ?? $item['id'] ?? null,
                    'source_status' => $warnings > 0 ? 'performance_warning' : 'moderation_flag',
                    'severity' => 'exposure_loss',
                    'reason' => 'pending_warnings=' . $warnings,
                    'remedy' => is_array($item['top_action'] ?? null)
                        ? json_encode($item['top_action'], JSON_UNESCAPED_UNICODE)
                        : ($item['top_action'] ?? null),
                    'performance' => $item,
                    'scanned_at' => date('Y-m-d H:i:s'),
                ];
            }
            if ($exposureRows !== []) {
                $exp = $this->store->upsertBlocked($context, $exposureRows, 'exposure');
                $exposureUpserted = (int) ($exp['upserted'] ?? 0);
            }
        } catch (\Throwable $e) {
            log_warning('IrregularitySync exposure queue skipped', [
                'account_id' => $context->accountId(),
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'account_id' => $context->accountId(),
            'scan' => [
                'totals' => $scan['totals'] ?? [],
                'scanned_at' => $scannedAt,
            ],
            'store' => [
                'urgent_upserted' => (int) ($storeResult['upserted'] ?? 0),
                'exposure_upserted' => $exposureUpserted,
                'counts' => $this->store->countsByQueue($context->accountId()),
            ],
            'write_enabled' => false,
        ];
    }
}

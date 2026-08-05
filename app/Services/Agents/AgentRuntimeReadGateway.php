<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Database;
use App\Services\Ads\AdsObservationService;
use App\Services\Ads\SkuCustoService;
use App\Services\FinancialService;
use App\Services\Sentinela\Sentinela;
use PDO;
use RuntimeException;

/** Implementação production final, estritamente read-only e account-bound por chamada. */
final class AgentRuntimeReadGateway implements AgentRuntimeReadGatewayInterface
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function sentinelaDashboard(int $accountId): array
    {
        return (new Sentinela($this->db))->getDashboard($accountId);
    }

    public function adsDashboard(int $accountId): array
    {
        return (new AdsObservationService($this->db))->dashboard($accountId);
    }

    public function financialDashboardSummary(int $accountId): array
    {
        return (new FinancialService($accountId))->getDashboardSummary();
    }

    public function financialMetrics(int $accountId, string $startDate, string $endDate): array
    {
        return (new FinancialService($accountId))->getMetrics($startDate, $endDate);
    }

    public function skuCostByMlb(int $accountId, string $mlbId): ?array
    {
        return (new SkuCustoService($this->db))->getByMlb($accountId, $mlbId);
    }

    public function item(int $accountId, string $mlbId): array
    {
        if ($accountId < 1 || preg_match('/^MLB[1-9][0-9]*$/D', $mlbId) !== 1) {
            throw new RuntimeException('invalid item provenance request');
        }

        $accountStatement = $this->db->prepare(
            "SELECT ml_user_id FROM ml_accounts WHERE id = :account_id AND status = 'active' LIMIT 1"
        );
        $accountStatement->execute(['account_id' => $accountId]);
        $account = $accountStatement->fetch(PDO::FETCH_ASSOC);
        $accountSellerId = is_array($account)
            ? self::canonicalPositiveDigits($account['ml_user_id'] ?? null)
            : null;
        if ($accountSellerId === null) {
            throw new RuntimeException('active account seller provenance unavailable');
        }

        $itemStatement = $this->db->prepare(
            'SELECT id, title, raw_data FROM ml_items '
            . 'WHERE account_id = :account_id AND id = :mlb_id LIMIT 1'
        );
        $itemStatement->execute(['account_id' => $accountId, 'mlb_id' => $mlbId]);
        $item = $itemStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($item) || ($item['id'] ?? null) !== $mlbId) {
            throw new RuntimeException('account-bound local item unavailable');
        }

        $rawData = $item['raw_data'] ?? null;
        $decoded = is_string($rawData) ? json_decode($rawData, true, 512, JSON_THROW_ON_ERROR) : null;
        $itemSellerId = is_array($decoded)
            ? self::canonicalPositiveDigits($decoded['seller_id'] ?? null)
            : null;
        if ($itemSellerId === null || $itemSellerId !== $accountSellerId) {
            throw new RuntimeException('item seller provenance mismatch');
        }
        $title = is_string($item['title'] ?? null) ? trim($item['title']) : '';
        if ($title === '') {
            throw new RuntimeException('item title unavailable');
        }

        $duplicateStatement = $this->db->prepare(
            "SELECT 1 FROM cloned_items "
            . "WHERE source_account_id = :account_id AND source_item_id = :mlb_id "
            . "AND (status IS NULL OR status NOT IN ('error', 'failed')) LIMIT 1"
        );
        $duplicateStatement->execute(['account_id' => $accountId, 'mlb_id' => $mlbId]);

        return [
            'account_id' => $accountId,
            'mlb_id' => $mlbId,
            'seller_id' => $accountSellerId,
            'title' => $title,
            'duplicate' => $duplicateStatement->fetchColumn() !== false,
        ];
    }

    private static function canonicalPositiveDigits(mixed $value): ?string
    {
        if (is_int($value)) {
            return $value > 0 ? (string) $value : null;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }
        return $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ListingApply;

use App\Services\HiddenSeo\SafetyGuard;
use App\Services\ListingInvestigation\ListingInvestigationService;
use App\Services\ListingInvestigation\ListingTitleDraftBuilder;
use PDO;
use Throwable;

/**
 * Official listing apply: dry-run by default. Live PUT only with --apply
 * and a single mlb allowlist. Never Premium/frete/MODEL stuffing/original_price.
 */
final class ListingApplyJobService
{
    public const STATUS_DRY_RUN = 'dry_run';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_APPLIED = 'applied';
    public const FACILYTY_ACCOUNT = 1335;
    public const ALLOWED_PUT_KEYS = ['title', 'catalog_listing'];

    private PDO $db;
    private SafetyGuard $guard;
    private ListingTitleDraftBuilder $titles;
    /** @var callable|null fn(int, string, array<string, mixed>): array<string, mixed> */
    private $putter;

    public function __construct(PDO $db, ?SafetyGuard $guard = null, $putter = null)
    {
        $this->db = $db;
        $this->guard = $guard ?? new SafetyGuard();
        $this->titles = new ListingTitleDraftBuilder();
        $this->putter = $putter;
    }

    /**
     * @return array<string, mixed>
     */
    public function run(int $accountId, string $mlb, bool $apply): array
    {
        $this->ensureTable();
        $mlb = strtoupper(trim($mlb));
        if ($accountId <= 0 || !$this->isValidMlb($mlb)) {
            return $this->persist($accountId, $mlb, self::STATUS_BLOCKED, false, [], null, 'mlb_or_account_invalid', false);
        }
        if (str_contains($mlb, ',') || str_contains($mlb, ' ')) {
            return $this->persist($accountId, $mlb, self::STATUS_BLOCKED, false, [], null, 'lote_sem_allowlist', false);
        }

        $item = $this->loadItem($accountId, $mlb);
        if ($item === null) {
            return $this->persist($accountId, $mlb, self::STATUS_BLOCKED, false, [], null, 'item_not_found_local', false);
        }

        $payload = $this->buildAllowedPayload($item);
        if ($payload === []) {
            return $this->persist($accountId, $mlb, self::STATUS_BLOCKED, false, [], $item, 'empty_allowed_payload', false);
        }

        if (!$apply) {
            return $this->persist($accountId, $mlb, self::STATUS_DRY_RUN, true, $payload, $item, null, false);
        }

        $block = $this->liveApplyBlockReason($accountId, $mlb);
        if ($block !== null) {
            return $this->persist($accountId, $mlb, self::STATUS_BLOCKED, true, $payload, $item, $block, false);
        }

        if ($this->putter === null) {
            return $this->persist($accountId, $mlb, self::STATUS_BLOCKED, true, $payload, $item, 'ml_write_automation_or_no_putter', false);
        }

        $api = ($this->putter)($accountId, $mlb, $payload);
        $called = !empty($api['api_called']);
        $ok = !empty($api['success']) && $called;
        $status = $ok ? self::STATUS_APPLIED : self::STATUS_BLOCKED;
        $reason = $ok ? null : (string) ($api['blocked_by'] ?? $api['error'] ?? 'put_blocked');

        return $this->persist($accountId, $mlb, $status, true, $payload, $item, $reason, $called);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function buildAllowedPayload(array $item): array
    {
        $realModel = (new ListingInvestigationService($this->db))->realModel($item);
        $draft = $this->titles->build($item, [], $realModel);
        $payload = [];
        $title = trim((string) ($draft['draft_title'] ?? ''));
        $current = trim((string) ($item['title'] ?? ''));
        if ($title !== '' && $title !== $current) {
            $payload['title'] = $title;
        }
        $catalogId = trim((string) ($item['catalog_product_id'] ?? ''));
        $already = $item['catalog_listing'] ?? false;
        $isCatalog = $already === true || $already === 1 || $already === 'true';
        if ($catalogId !== '' && !$isCatalog) {
            $payload['catalog_listing'] = true;
        }

        foreach (array_keys($payload) as $key) {
            if (!in_array($key, self::ALLOWED_PUT_KEYS, true)) {
                unset($payload[$key]);
            }
        }
        unset($payload['listing_type_id'], $payload['shipping'], $payload['original_price'], $payload['status'], $payload['attributes']);

        return $payload;
    }

    public function isValidMlb(string $mlb): bool
    {
        return preg_match('/^MLB[0-9]{6,}$/', strtoupper(trim($mlb))) === 1;
    }

    public function liveApplyBlockReason(int $accountId, string $mlb): ?string
    {
        if (str_contains((string) getcwd(), 'staging.eskill.com.br') && $accountId === self::FACILYTY_ACCOUNT) {
            return 'staging_must_not_apply_1335';
        }
        if ($this->guard->isForbidden($accountId) && $accountId !== self::FACILYTY_ACCOUNT) {
            return 'forbidden_account';
        }
        if ($accountId === self::FACILYTY_ACCOUNT && !$this->isValidMlb($mlb)) {
            return 'facilyty_requires_mlb_allowlist';
        }
        $automation = filter_var($_ENV['ML_WRITE_AUTOMATION'] ?? getenv('ML_WRITE_AUTOMATION') ?: 'false', FILTER_VALIDATE_BOOLEAN);
        if (!$automation) {
            return 'ml_write_automation_false';
        }
        if ($this->guard->isSafeMode()) {
            // --apply is the explicit flag; 1335 still needs the single mlb allowlist (the CLI --mlb).
            return null;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadItem(int $accountId, string $mlb): ?array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT ml_item_id, title, status, available_quantity, sold_quantity, catalog_product_id, data
                 FROM items WHERE account_id = ? AND ml_item_id = ? LIMIT 1"
            );
            $stmt->execute([$accountId, $mlb]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($row)) {
            return null;
        }
        $data = $row['data'] ?? null;
        if (is_string($data) && $data !== '') {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($data)) {
            $data = [];
        }
        $item = $data;
        $item['id'] = $mlb;
        $item['ml_item_id'] = $mlb;
        $item['title'] = (string) ($row['title'] ?? $data['title'] ?? '');
        $item['status'] = (string) ($row['status'] ?? $data['status'] ?? '');
        $catalog = trim((string) ($row['catalog_product_id'] ?? $data['catalog_product_id'] ?? ''));
        $item['catalog_product_id'] = $catalog !== '' ? $catalog : null;
        if (!array_key_exists('catalog_listing', $item)) {
            $item['catalog_listing'] = false;
        }

        return $item;
    }

    public function ensureTable(): void
    {
        $driver = '';
        try {
            $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable) {
            $driver = '';
        }
        if ($driver === 'sqlite') {
            $this->db->exec(
                'CREATE TABLE IF NOT EXISTS listing_apply_jobs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id INTEGER NOT NULL,
                    mlb_id TEXT NOT NULL,
                    status TEXT NOT NULL,
                    apply_requested INTEGER NOT NULL DEFAULT 0,
                    ml_write INTEGER NOT NULL DEFAULT 0,
                    api_called INTEGER NOT NULL DEFAULT 0,
                    payload TEXT NULL,
                    blocked_by TEXT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )'
            );
            return;
        }
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS listing_apply_jobs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                mlb_id VARCHAR(32) NOT NULL,
                status VARCHAR(32) NOT NULL,
                apply_requested TINYINT(1) NOT NULL DEFAULT 0,
                ml_write TINYINT(1) NOT NULL DEFAULT 0,
                api_called TINYINT(1) NOT NULL DEFAULT 0,
                payload JSON NULL,
                blocked_by VARCHAR(128) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_laj_account (account_id, created_at),
                KEY idx_laj_mlb (account_id, mlb_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $item
     * @return array<string, mixed>
     */
    private function persist(
        int $accountId,
        string $mlb,
        string $status,
        bool $applyRequested,
        array $payload,
        ?array $item,
        ?string $blockedBy,
        bool $apiCalled
    ): array {
        $mlWrite = $status === self::STATUS_APPLIED;
        $stmt = $this->db->prepare(
            'INSERT INTO listing_apply_jobs
                (account_id, mlb_id, status, apply_requested, ml_write, api_called, payload, blocked_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $accountId,
            $mlb,
            $status,
            $applyRequested ? 1 : 0,
            $mlWrite ? 1 : 0,
            $apiCalled ? 1 : 0,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $blockedBy,
        ]);
        $id = (int) $this->db->lastInsertId();

        return [
            'id' => $id,
            'account_id' => $accountId,
            'mlb_id' => $mlb,
            'status' => $status,
            'apply_requested' => $applyRequested,
            'ml_write' => $mlWrite,
            'api_called' => $apiCalled,
            'payload' => $payload,
            'blocked_by' => $blockedBy,
            'title_current' => is_array($item) ? (string) ($item['title'] ?? '') : '',
        ];
    }
}

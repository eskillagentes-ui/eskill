<?php

declare(strict_types=1);

namespace App\Services\AI\SEO\Strategies;

use App\Database;
use PDO;

/**
 * 🗄️ SEO Analysis Cache Service
 *
 * Provides caching layer for SEO strategy analyses to improve performance.
 * Caches are item-specific with configurable TTL.
 */
class SEOAnalysisCacheService
{
    private PDO $db;
    private int $accountId;
    private int $defaultTtl;
    private string $version = '1.0.0';

    public function __construct(int $accountId, int $ttlMinutes = 60)
    {
        $this->db = Database::getInstance();
        $this->accountId = $accountId;
        $this->defaultTtl = $ttlMinutes * 60; // Convert to seconds
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS seo_analysis_cache (
                    item_id VARCHAR(64) NOT NULL,
                    account_id INT NOT NULL,
                    category_id VARCHAR(32) NULL,
                    overall_score DECIMAL(6,2) DEFAULT 0,
                    strategies_json LONGTEXT NULL,
                    suggestions_json LONGTEXT NULL,
                    title_analysis_json LONGTEXT NULL,
                    description_analysis_json LONGTEXT NULL,
                    item_title VARCHAR(255) NULL,
                    item_price DECIMAL(10,2) NULL,
                    analysis_version VARCHAR(20) NULL,
                    expires_at DATETIME NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY (item_id, account_id),
                    INDEX idx_seo_cache_account (account_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (\Throwable $e) {
            // Fail silently in test environments without migrations
        }
    }

    /**
     * Get cached analysis for an item
     */
    public function get(string $itemId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                overall_score,
                strategies_json,
                suggestions_json,
                title_analysis_json,
                description_analysis_json,
                item_title,
                analysis_version,
                created_at,
                updated_at,
                expires_at
            FROM seo_analysis_cache
            WHERE item_id = :item_id
              AND account_id = :account_id
              AND (expires_at IS NULL OR expires_at > NOW())
        ");

        $stmt->execute([
            'item_id' => $itemId,
            'account_id' => $this->accountId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'overall_score' => (float) $row['overall_score'],
            'strategies' => json_decode($row['strategies_json'] ?? '{}', true),
            'suggestions' => json_decode($row['suggestions_json'] ?? '[]', true),
            'title_analysis' => json_decode($row['title_analysis_json'] ?? '{}', true),
            'description_analysis' => json_decode($row['description_analysis_json'] ?? '{}', true),
            'item_title' => $row['item_title'],
            'version' => $row['analysis_version'],
            'cached_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'expires_at' => $row['expires_at'],
            'from_cache' => true,
        ];
    }

    /**
     * Store analysis in cache
     */
    public function set(string $itemId, array $analysis, ?int $ttlSeconds = null): bool
    {
        $ttl = $ttlSeconds ?? $this->defaultTtl;
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

        $sql = "
            INSERT INTO seo_analysis_cache (
                item_id, account_id, category_id, overall_score,
                strategies_json, suggestions_json, title_analysis_json,
                description_analysis_json, item_title, item_price,
                analysis_version, expires_at, created_at, updated_at
            ) VALUES (
                :item_id, :account_id, :category_id, :overall_score,
                :strategies_json, :suggestions_json, :title_analysis_json,
                :description_analysis_json, :item_title, :item_price,
                :version, :expires_at, NOW(), NOW()
            )
            ON DUPLICATE KEY UPDATE
                category_id = VALUES(category_id),
                overall_score = VALUES(overall_score),
                strategies_json = VALUES(strategies_json),
                suggestions_json = VALUES(suggestions_json),
                title_analysis_json = VALUES(title_analysis_json),
                description_analysis_json = VALUES(description_analysis_json),
                item_title = VALUES(item_title),
                item_price = VALUES(item_price),
                analysis_version = VALUES(analysis_version),
                expires_at = VALUES(expires_at),
                updated_at = NOW()
        ";

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            'item_id' => $itemId,
            'account_id' => $this->accountId,
            'category_id' => $analysis['category_id'] ?? null,
            'overall_score' => $analysis['overall_score'] ?? 0,
            'strategies_json' => json_encode($analysis['strategies'] ?? []),
            'suggestions_json' => json_encode($analysis['suggestions'] ?? []),
            'title_analysis_json' => json_encode($analysis['title_analysis'] ?? []),
            'description_analysis_json' => json_encode($analysis['description_analysis'] ?? []),
            'item_title' => $analysis['item_title'] ?? null,
            'item_price' => $analysis['item_price'] ?? null,
            'version' => $this->version,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Invalidate cache for an item
     */
    public function invalidate(string $itemId): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM seo_analysis_cache
            WHERE item_id = :item_id AND account_id = :account_id
        ");

        return $stmt->execute([
            'item_id' => $itemId,
            'account_id' => $this->accountId,
        ]);
    }

    /**
     * Invalidate all cache for the account
     */
    public function invalidateAll(): int
    {
        $stmt = $this->db->prepare("
            DELETE FROM seo_analysis_cache
            WHERE account_id = :account_id
        ");

        $stmt->execute(['account_id' => $this->accountId]);

        return $stmt->rowCount();
    }

    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) as total_cached,
                SUM(CASE WHEN expires_at > NOW() THEN 1 ELSE 0 END) as valid_cache,
                SUM(CASE WHEN expires_at <= NOW() THEN 1 ELSE 0 END) as expired_cache,
                AVG(overall_score) as avg_score,
                MIN(created_at) as oldest_cache,
                MAX(updated_at) as newest_cache
            FROM seo_analysis_cache
            WHERE account_id = :account_id
        ");

        $stmt->execute(['account_id' => $this->accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'total_cached' => (int) ($row['total_cached'] ?? 0),
            'valid_cache' => (int) ($row['valid_cache'] ?? 0),
            'expired_cache' => (int) ($row['expired_cache'] ?? 0),
            'avg_score' => round((float) ($row['avg_score'] ?? 0), 1),
            'oldest_cache' => $row['oldest_cache'],
            'newest_cache' => $row['newest_cache'],
        ];
    }

    /**
     * Get items with low SEO scores (for optimization opportunities)
     */
    public function getLowScoreItems(int $limit = 20, float $maxScore = 50): array
    {
        $limitSql = max(1, min((int)$limit, 500));

        $stmt = $this->db->prepare("
            SELECT
                item_id,
                overall_score,
                item_title,
                category_id,
                strategies_json,
                updated_at
            FROM seo_analysis_cache
            WHERE account_id = :account_id
              AND overall_score <= :max_score
              AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY overall_score ASC
            LIMIT {$limitSql}
        ");

        $stmt->bindValue('account_id', $this->accountId, PDO::PARAM_INT);
        $stmt->bindValue('max_score', $maxScore, PDO::PARAM_STR);
        $stmt->execute();

        return array_map(function (array $row): array {
            return [
                'item_id' => $row['item_id'],
                'score' => (float) $row['overall_score'],
                'title' => $row['item_title'],
                'category_id' => $row['category_id'],
                'strategies' => json_decode($row['strategies_json'] ?? '{}', true),
                'updated_at' => $row['updated_at'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Get items needing re-analysis (expired or old cache)
     */
    public function getStaleItems(int $limit = 50): array
    {
        $limitSql = max(1, min((int)$limit, 500));

        $stmt = $this->db->prepare("
            SELECT item_id, updated_at, expires_at
            FROM seo_analysis_cache
            WHERE account_id = :account_id
              AND (expires_at <= NOW() OR updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY))
            ORDER BY updated_at ASC
            LIMIT {$limitSql}
        ");

        $stmt->bindValue('account_id', $this->accountId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Cleanup expired cache entries
     */
    public function cleanup(): int
    {
        $stmt = $this->db->prepare("
            DELETE FROM seo_analysis_cache
            WHERE expires_at IS NOT NULL
              AND expires_at < NOW()
        ");

        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Get or analyze with cache
     */
    public function getOrAnalyze(string $itemId, callable $analyzeCallback): array
    {
        // Try cache first
        $cached = $this->get($itemId);

        if ($cached !== null) {
            return $cached;
        }

        // Run analysis
        $analysis = $analyzeCallback($itemId);

        // Cache result
        if (!empty($analysis) && isset($analysis['overall_score'])) {
            $this->set($itemId, $analysis);
        }

        $analysis['from_cache'] = false;

        return $analysis;
    }

    /**
     * Batch get cached analyses
     */
    public function batchGet(array $itemIds): array
    {
        if (empty($itemIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));

        $sql = "
            SELECT
                item_id,
                overall_score,
                strategies_json,
                item_title,
                updated_at
            FROM seo_analysis_cache
            WHERE account_id = ?
              AND item_id IN ({$placeholders})
              AND (expires_at IS NULL OR expires_at > NOW())
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$this->accountId], $itemIds));

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['item_id']] = [
                'score' => (float) $row['overall_score'],
                'strategies' => json_decode($row['strategies_json'] ?? '{}', true),
                'title' => $row['item_title'],
                'updated_at' => $row['updated_at'],
                'from_cache' => true,
            ];
        }

        return $result;
    }

    /**
     * Get score distribution for dashboard
     */
    public function getScoreDistribution(): array
    {
        $stmt = $this->db->prepare("
            SELECT
                CASE
                    WHEN overall_score >= 80 THEN 'excellent'
                    WHEN overall_score >= 60 THEN 'good'
                    WHEN overall_score >= 40 THEN 'warning'
                    ELSE 'critical'
                END as category,
                COUNT(*) as count,
                AVG(overall_score) as avg_score
            FROM seo_analysis_cache
            WHERE account_id = :account_id
              AND (expires_at IS NULL OR expires_at > NOW())
            GROUP BY
                CASE
                    WHEN overall_score >= 80 THEN 'excellent'
                    WHEN overall_score >= 60 THEN 'good'
                    WHEN overall_score >= 40 THEN 'warning'
                    ELSE 'critical'
                END
        ");

        $stmt->execute(['account_id' => $this->accountId]);

        $distribution = [
            'excellent' => 0,
            'good' => 0,
            'warning' => 0,
            'critical' => 0,
        ];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $distribution[$row['category']] = (int) $row['count'];
        }

        return $distribution;
    }
}

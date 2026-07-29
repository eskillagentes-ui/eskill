<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;

/**
 * Service para registrar e consultar histórico de preços por categoria/marca.
 *
 * Grava snapshots na tabela `price_history` (migration 006) a partir dos dados
 * retornados por SearchService::analyzeListings().
 */
class PriceHistoryService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->ensureTableExists();
    }

    /**
     * Analisa listings via SearchService e grava o snapshot de preço.
     */
    public function recordPriceHistory(string $categoryId, string $brand): array
    {
        $searchService = new SearchService();
        $analysis = $searchService->analyzeListings($categoryId, $brand);

        $prices = $analysis['prices'] ?? [];

        if (empty($prices) || !isset($prices['avg'])) {
            return [
                'success' => false,
                'message' => 'Sem dados de preço para registro',
                'category_id' => $categoryId,
                'brand' => $brand,
            ];
        }

        $stmt = $this->db->prepare("
            INSERT INTO price_history (category_id, brand, avg_price, min_price, max_price, total_items, recorded_at)
            VALUES (:category_id, :brand, :avg_price, :min_price, :max_price, :total_items, NOW())
        ");

        $stmt->execute([
            'category_id' => $categoryId,
            'brand' => $brand,
            'avg_price' => $prices['avg'],
            'min_price' => $prices['min'] ?? 0,
            'max_price' => $prices['max'] ?? 0,
            'total_items' => $prices['count'] ?? 0,
        ]);

        return [
            'success' => true,
            'category_id' => $categoryId,
            'brand' => $brand,
            'avg_price' => $prices['avg'],
            'min_price' => $prices['min'] ?? 0,
            'max_price' => $prices['max'] ?? 0,
            'total_items' => $prices['count'] ?? 0,
        ];
    }

    /**
     * Retorna histórico de preços para uma categoria/marca.
     */
    public function getHistory(string $categoryId, string $brand, int $limit = 30): array
    {
        $limitSql = max(1, min(365, (int)$limit));
        $stmt = $this->db->prepare("
            SELECT id, category_id, brand, avg_price, min_price, max_price, total_items, recorded_at
            FROM price_history
            WHERE category_id = :category_id AND brand = :brand
            ORDER BY recorded_at DESC
            LIMIT {$limitSql}
        ");
        $stmt->bindValue('category_id', $categoryId);
        $stmt->bindValue('brand', $brand);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retorna variação de preço (último vs anterior) para uma categoria/marca.
     */
    public function getPriceVariation(string $categoryId, string $brand): array
    {
        $history = $this->getHistory($categoryId, $brand, 2);

        if (count($history) < 2) {
            return [
                'has_variation' => false,
                'current' => $history[0] ?? null,
                'previous' => null,
                'variation_pct' => 0,
            ];
        }

        $current = (float) $history[0]['avg_price'];
        $previous = (float) $history[1]['avg_price'];
        $variation = $previous > 0 ? round((($current - $previous) / $previous) * 100, 2) : 0;

        return [
            'has_variation' => true,
            'current' => $history[0],
            'previous' => $history[1],
            'variation_pct' => $variation,
        ];
    }

    /**
     * Garante que a tabela existe (idempotente).
     */
    private function ensureTableExists(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS price_history (
                id INT PRIMARY KEY AUTO_INCREMENT,
                category_id VARCHAR(50) NOT NULL,
                brand VARCHAR(100) NOT NULL,
                avg_price DECIMAL(10,2) NOT NULL,
                min_price DECIMAL(10,2) NOT NULL,
                max_price DECIMAL(10,2) NOT NULL,
                total_items INT DEFAULT 0,
                recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_category_brand (category_id, brand),
                INDEX idx_recorded_at (recorded_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

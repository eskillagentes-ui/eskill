<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\AdvancedRedisCacheService;
use App\Controllers\MonitoringController;

/**
 * Advanced Cache Middleware V8.1
 * Intelligent caching with performance optimization
 */
class CacheMiddleware
{
    private ?AdvancedRedisCacheService $cache;
    private array $config;

    public function __construct()
    {
        try {
            $this->cache = new AdvancedRedisCacheService();
        } catch (\Throwable $e) {
            // Fallback: cache desabilitado se Redis falhar
            log_warning('CacheMiddleware: Redis unavailable', ['error' => $e->getMessage()]);
            $this->cache = null;
        }
        $this->config = [
            'enabled' => $_ENV['CACHE_ENABLED'] ?? true,
            'default_ttl' => $_ENV['CACHE_DEFAULT_TTL'] ?? 300
        ];
    }

    /**
     * Process request through cache layer
     */
    public function handle(string $uri, string $method, callable $next): mixed
    {
        if (!$this->config['enabled'] || $method !== 'GET' || $this->cache === null) {
            return $next();
        }

        // Never cache authentication/security related pages.
        // These pages depend on per-session state (flash messages, CSRF tokens, redirects).
        if ($this->shouldSkipCaching($uri)) {
            if (!headers_sent()) {
                header('X-Cache: BYPASS');
            }
            return $next();
        }

        // Try to serve from cache
        $cached = $this->serveCached($uri, $method);
        if ($cached !== null) {
            return $cached['content'];
        }

        // Execute request and cache result
        $content = $next();
        $this->cacheResponse($uri, $content);

        return $content;
    }

    /**
     * Cache API responses
     */
    public function cacheApiResponse(string $endpoint, array $data, ?int $ttl = null): bool
    {
        if (!$this->config['enabled'] || $this->cache === null) {
            return false;
        }

        $cacheKey = $this->generateApiCacheKey($endpoint);
        $ttl = $ttl ?? $this->getApiCacheTtl($endpoint);

        // Tag the cache entry for invalidation
        $tag = $this->getApiCacheTag($endpoint);
        $this->cache->tag($tag, $cacheKey);

        return $this->cache->set($cacheKey, $data, $ttl);
    }

    /**
     * Get cached API response
     */
    public function getCachedApiResponse(string $endpoint): ?array
    {
        if (!$this->config['enabled'] || $this->cache === null) {
            return null;
        }

        $cacheKey = $this->generateApiCacheKey($endpoint);
        return $this->cache->get($cacheKey);
    }

    /**
     * Invalidate cache by tag
     */
    public function invalidateTag(string $tag): int
    {
        if ($this->cache === null) {
            return 0;
        }
        return $this->cache->invalidateTag($tag);
    }

    /**
     * Try to serve cached response
     */
    private function serveCached(string $uri, string $method): ?array
    {
        $cacheKey = $this->generateCacheKey($uri, $method);
        // Pass a fallback closure that returns null to match the improved Service signature if needed,
        // but here we just want to get it if it exists. Reverting to simple get.
        $cached = $this->cache->get($cacheKey);

        if ($cached && isset($cached['content'])) {
            // Add cache headers
            if (!headers_sent()) {
                header('X-Cache: HIT');
                header('X-Cache-TTL: ' . $this->cache->ttl($cacheKey));

                // Restore Content-Type if saved
                if (isset($cached['content_type'])) {
                    header('Content-Type: ' . $cached['content_type']);
                }
            }
            return $cached;
        }

        if (!headers_sent()) {
            header('X-Cache: MISS');
        }

        return null;
    }

    /**
     * Cache response content
     */
    private function cacheResponse(string $uri, string $content): bool
    {
        // Skip caching for certain patterns
        $skipPatterns = [
            '/api/monitoring/',
            '/api/real-time/',
            '/dashboard/notifications'
        ];

        foreach ($skipPatterns as $pattern) {
            if (str_contains($uri, $pattern)) {
                return false;
            }
        }

        // Centralized bypass rules
        if ($this->shouldSkipCaching($uri)) {
            return false;
        }

        $cacheKey = $this->generateCacheKey($uri, 'GET');
        $ttl = $this->getCacheTtl($uri);

        // Detect content type
        $headers = headers_list();
        $contentType = 'text/html';
        foreach ($headers as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                $contentType = trim(substr($header, 13));
                break;
            }
        }

        return $this->cache->set($cacheKey, [
            'content' => $content,
            'content_type' => $contentType,
            'timestamp' => time(),
            'uri' => $uri
        ], $ttl);
    }

    /**
     * True when caching must be bypassed for the URI.
     */
    private function shouldSkipCaching(string $uri): bool
    {
        // Auth flow pages and security dashboard should never be cached
        $neverCache = [
            '/login',
            '/logout',
            '/register',
            '/auth/',
            '/security',
        ];

        foreach ($neverCache as $pattern) {
            if (str_contains($uri, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate cache key
     */
    private function generateCacheKey(string $uri, string $method): string
    {
        $userId = $_SESSION['user_id'] ?? $_SERVER['API_USER_ID'] ?? 'anonymous';
        $queryString = $_SERVER['QUERY_STRING'] ?? '';

        // Public pages should share cache regardless of user (unless logged in specifics needed)
        // For /p/ (public product), we make it global
        if (str_contains($uri, '/p/')) {
            $userId = 'global';
        }

        return "page:" . md5("{$method}:{$uri}:{$queryString}:{$userId}");
    }

    /**
     * Generate API cache key
     */
    private function generateApiCacheKey(string $endpoint): string
    {
        $params = $_GET + $_POST;
        ksort($params);

        $userId = $_SESSION['user_id'] ?? $_SERVER['API_USER_ID'] ?? 'anonymous';
        $paramsHash = md5(json_encode($params));

        return "api:" . md5("{$endpoint}:{$paramsHash}:{$userId}");
    }

    /**
     * Get cache TTL based on URI
     */
    private function getCacheTtl(string $uri): int
    {
        if (str_contains($uri, '/p/')) return 3600; // 1 hour for Public Product Pages
        if (str_contains($uri, '/dashboard/static')) return 3600; // 1 hour
        if (str_contains($uri, '/products/')) return 1800; // 30 minutes
        if (str_contains($uri, '/dashboard/')) return 300; // 5 minutes
        if (str_contains($uri, '/api/')) return 120; // 2 minutes

        return $this->config['default_ttl'];
    }

    /**
     * Get API cache TTL
     */
    private function getApiCacheTtl(string $endpoint): int
    {
        if (str_contains($endpoint, 'products')) return 900; // 15 minutes
        if (str_contains($endpoint, 'orders')) return 120; // 2 minutes
        if (str_contains($endpoint, 'analytics')) return 300; // 5 minutes

        return 300;
    }

    /**
     * Get API cache tag for invalidation
     */
    private function getApiCacheTag(string $endpoint): string
    {
        if (str_contains($endpoint, 'orders')) return 'orders';
        if (str_contains($endpoint, 'products')) return 'products';
        if (str_contains($endpoint, 'analytics')) return 'analytics';

        return 'general';
    }

    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        if ($this->cache === null) {
            return ['status' => 'unavailable'];
        }
        return $this->cache->getStats();
    }
}

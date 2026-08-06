<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Middleware\CacheMiddleware;
use App\Services\AdvancedRedisCacheService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CacheMiddlewarePregaoTest extends TestCase
{
    public function testPregaoDashboardAlwaysBypassesAuthenticatedPageCache(): void
    {
        $reflection = new ReflectionClass(CacheMiddleware::class);
        /** @var CacheMiddleware $middleware */
        $middleware = $reflection->newInstanceWithoutConstructor();
        $cache = $this->createMock(AdvancedRedisCacheService::class);
        $cache->expects(self::never())->method('get');
        $cache->expects(self::never())->method('set');

        $cacheProperty = $reflection->getProperty('cache');
        $cacheProperty->setAccessible(true);
        $cacheProperty->setValue($middleware, $cache);
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);
        $configProperty->setValue($middleware, ['enabled' => true, 'default_ttl' => 300]);

        $uris = [
            '/dashboard/pregao?account_id=1335',
            '/api/pregao/snapshot',
            '/api/pregao/events?page=1',
            '/api/pregao/ticket',
            '/qa/live/123e4567-e89b-42d3-a456-426614174000',
            '/qa/frame/123e4567-e89b-42d3-a456-426614174000',
        ];
        $calls = 0;
        foreach ($uris as $uri) {
            $response = $middleware->handle(
                $uri,
                'GET',
                static function () use (&$calls): string {
                    $calls++;
                    return 'fresh-account-bound-response';
                }
            );
            self::assertSame('fresh-account-bound-response', $response, $uri);
        }

        self::assertSame(count($uris), $calls);
    }
}

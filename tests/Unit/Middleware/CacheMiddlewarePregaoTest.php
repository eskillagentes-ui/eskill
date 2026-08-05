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

        $calls = 0;
        $response = $middleware->handle(
            '/dashboard/pregao?account_id=1335',
            'GET',
            static function () use (&$calls): string {
                $calls++;
                return 'fresh-account-bound-html';
            }
        );

        self::assertSame('fresh-account-bound-html', $response);
        self::assertSame(1, $calls);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PHP84NullableCompatibilityTest extends TestCase
{
    /**
     * @dataProvider nullableParameterProvider
     */
    public function testNullableParametersAreDeclaredExplicitly(string $relativePath, string $signature): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $relativePath);

        self::assertIsString($source);
        self::assertStringContainsString($signature, $source);
    }

    public function nullableParameterProvider(): array
    {
        return [
            'job id' => [
                'app/Services/JobService.php',
                'private function executeJob(string $type, array $payload, ?int $jobId = null',
            ],
            'refresh token days' => [
                'app/Services/RefreshTokenService.php',
                'public function createToken(int $userId, ?string $deviceInfo = null, ?int $days = null',
            ],
            'market category' => [
                'app/Services/AI/SEO/MarketAnalytics.php',
                'public function analyzemarketSentiment(?string $categoryId = null',
            ],
        ];
    }
}

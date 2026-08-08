<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Helpers\TimezoneHelper;
use PHPUnit\Framework\TestCase;

final class TimezoneHelperTest extends TestCase
{
    public function test_mysql_offset_literal_for_sao_paulo(): void
    {
        $literal = TimezoneHelper::mysqlOffsetLiteral('America/Sao_Paulo');
        $this->assertMatchesRegularExpression('/^[+-]\d{2}:\d{2}$/', $literal);
        $this->assertSame('-03:00', $literal);
    }

    public function test_rewrite_wall_clock_preserves_instant(): void
    {
        $out = TimezoneHelper::rewriteWallClock(
            '2026-08-07 07:30:05',
            'Europe/Berlin',
            'America/Sao_Paulo'
        );

        $this->assertSame('2026-08-07 02:30:05', $out);
    }

    public function test_apply_from_env_sets_app_timezone(): void
    {
        $_ENV['APP_TIMEZONE'] = 'America/Sao_Paulo';
        $tz = TimezoneHelper::applyFromEnv();
        $this->assertSame('America/Sao_Paulo', $tz);
        $this->assertSame('America/Sao_Paulo', date_default_timezone_get());
    }
}

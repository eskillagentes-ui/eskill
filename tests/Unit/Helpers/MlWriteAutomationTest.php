<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Helpers\MlWriteAutomation;
use PHPUnit\Framework\TestCase;

final class MlWriteAutomationTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['ML_WRITE_AUTOMATION']);
        putenv('ML_WRITE_AUTOMATION');
        parent::tearDown();
    }

    public function testDisabledByDefault(): void
    {
        unset($_ENV['ML_WRITE_AUTOMATION']);
        putenv('ML_WRITE_AUTOMATION');
        $this->assertFalse(MlWriteAutomation::isEnabled());
        $guard = MlWriteAutomation::guard('test');
        $this->assertFalse($guard['allowed']);
    }

    public function testEnabledWhenTrue(): void
    {
        $_ENV['ML_WRITE_AUTOMATION'] = 'true';
        $this->assertTrue(MlWriteAutomation::isEnabled());
        $this->assertTrue(MlWriteAutomation::guard('test')['allowed']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ads;

use App\Services\Ads\AdsCollectCommand;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Ads\AdsCollectCommand
 */
final class AdsCollectCommandTest extends TestCase
{
    public function testOkFalseRetornaExitUmSemAlertasNemTick(): void
    {
        $calls = new \stdClass();
        $calls->collect = 0;
        $calls->alerts = 0;
        $calls->tick = 0;

        $command = new AdsCollectCommand(
            static function (int $accountId, bool $history) use ($calls): array {
                $calls->collect++;
                return ['ok' => false, 'available' => false, 'reason' => 'pads_http_503'];
            },
            static function (int $accountId) use ($calls): array {
                $calls->alerts++;
                return [['msg' => 'não deve executar']];
            },
            static function (int $accountId) use ($calls): array {
                $calls->tick++;
                return ['indice' => 1];
            }
        );

        $execution = $command->execute(5401, true, true);

        $this->assertSame(1, $execution['exit_code']);
        $this->assertFalse($execution['result']['ok']);
        $this->assertSame([], $execution['result']['alerts']);
        $this->assertArrayNotHasKey('tick', $execution['result']);
        $this->assertSame(1, $calls->collect);
        $this->assertSame(0, $calls->alerts);
        $this->assertSame(0, $calls->tick);
    }

    public function testOkTrueExecutaAlertasETickSolicitado(): void
    {
        $calls = new \stdClass();
        $calls->alerts = 0;
        $calls->tick = 0;
        $command = new AdsCollectCommand(
            static fn (int $accountId, bool $history): array => ['ok' => true, 'available' => true],
            static function (int $accountId) use ($calls): array {
                $calls->alerts++;
                return [['msg' => 'alerta']];
            },
            static function (int $accountId) use ($calls): array {
                $calls->tick++;
                return [
                    'indice' => 88.0,
                    'factors_active' => 4,
                    'label' => 'Bom',
                    'factors' => ['Ft' => 1],
                ];
            }
        );

        $execution = $command->execute(5402, false, true);

        $this->assertSame(0, $execution['exit_code']);
        $this->assertSame([['msg' => 'alerta']], $execution['result']['alerts']);
        $this->assertSame(88.0, $execution['result']['tick']['indice']);
        $this->assertSame(1, $calls->alerts);
        $this->assertSame(1, $calls->tick);
    }
}

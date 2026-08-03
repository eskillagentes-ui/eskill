<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pregao;

use App\Services\Pregao\PregaoEmitService;
use PDO;
use PHPUnit\Framework\TestCase;
use Redis;

/**
 * @covers \App\Services\Pregao\PregaoEmitService
 */
class PregaoEmitServiceTest extends TestCase
{
    public function testEmitRejeitaTipoInvalido(): void
    {
        $service = new PregaoEmitService($this->createMock(PDO::class), $this->createMock(Redis::class));
        $this->expectException(\InvalidArgumentException::class);
        $service->emit('foo.bar', []);
    }

    public function testEmitMontaEnvelopeCanonico(): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $pdo->method('prepare')->willReturn($stmt);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('publish')
            ->with(
                PregaoEmitService::CHANNEL,
                $this->callback(static function (string $json): bool {
                    $data = json_decode($json, true);
                    return is_array($data)
                        && ($data['v'] ?? null) === 1
                        && ($data['type'] ?? null) === 'op'
                        && isset($data['ts'], $data['payload'])
                        && ($data['account_id'] ?? null) === 1335
                        && ($data['payload']['robot'] ?? null) === 'R2';
                })
            );

        $service = new PregaoEmitService($pdo, $redis);
        $event = $service->emit('op', [
            'robot' => 'R2',
            'level' => 'info',
            'icon' => '🤖',
            'msg' => 'teste',
        ], 1335);

        $this->assertSame(1, $event['v']);
        $this->assertSame('op', $event['type']);
        $this->assertSame(1335, $event['account_id']);
        $this->assertSame('R2', $event['payload']['robot']);
    }

    public function testEmitSaleGeraCadeiaDeEventos(): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'vendas_hoje' => 3,
            'receita_hoje' => 600.0,
            'ticket_medio' => 200.0,
        ]);
        $pdo->method('prepare')->willReturn($stmt);

        $published = [];
        $redis = $this->createMock(Redis::class);
        $redis->method('publish')->willReturnCallback(static function (string $ch, string $json) use (&$published): int {
            $published[] = json_decode($json, true);
            return 1;
        });

        $service = new PregaoEmitService($pdo, $redis);
        $events = $service->emitSale([
            'order_id' => '2000084123',
            'valor' => 214.90,
            'titulo' => 'Portão 74-79cm Branco',
            'sku' => 'MLB6654685380',
        ], 10);

        $types = array_map(static fn (array $e): string => $e['type'], $events);
        $this->assertSame(
            ['sale', 'metric.update', 'metric.update', 'metric.update', 'op'],
            $types
        );
        $this->assertSame('VENDA', $events[4]['payload']['robot']);
        $this->assertCount(5, $published);
    }
}

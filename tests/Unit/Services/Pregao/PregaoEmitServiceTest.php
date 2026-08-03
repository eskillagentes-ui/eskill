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
                        && ($data['source'] ?? null) === 'live'
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
        $this->assertSame('live', $event['source']);
        $this->assertSame(1335, $event['account_id']);
        $this->assertSame('R2', $event['payload']['robot']);
    }

    public function testEmitSeedBloqueadoQuandoPregaoSeedFalse(): void
    {
        $prev = $_ENV['PREGAO_SEED'] ?? null;
        $_ENV['PREGAO_SEED'] = 'false';
        putenv('PREGAO_SEED=false');

        $service = new PregaoEmitService($this->createMock(PDO::class), $this->createMock(Redis::class));
        $this->expectException(\RuntimeException::class);
        try {
            $service->emit('op', ['msg' => 'seed'], 1335, 'seed');
        } finally {
            if ($prev === null) {
                unset($_ENV['PREGAO_SEED']);
                putenv('PREGAO_SEED');
            } else {
                $_ENV['PREGAO_SEED'] = $prev;
                putenv('PREGAO_SEED=' . $prev);
            }
        }
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

    public function testTresTicksMesmoSellerReputationEmitemUmOpApenas(): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $infoStmt = $this->createMock(\PDOStatement::class);
        $infoStmt->method('fetchColumn')->willReturn(1);
        $pdo->method('prepare')->willReturn($stmt);
        $pdo->method('query')->willReturn($infoStmt);

        $store = [];
        $published = [];
        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturnCallback(static function (string $key) use (&$store) {
            return $store[$key] ?? false;
        });
        $redis->method('set')->willReturnCallback(static function (string $key, $value) use (&$store): bool {
            $store[$key] = (string) $value;
            return true;
        });
        $redis->method('publish')->willReturnCallback(static function (string $ch, string $json) use (&$published): int {
            $published[] = json_decode($json, true);
            return 1;
        });
        $redis->method('lPush')->willReturn(1);
        $redis->method('lTrim')->willReturn(true);

        $service = new PregaoEmitService($pdo, $redis);
        $state = [
            'level_id' => '5_green',
            'cor' => 'verde-escuro',
            'semaforo' => 'verde',
            'reclamacoes_pct' => 0.74,
            'atrasos_pct' => 0.77,
            'cancelamentos_pct' => 0.0,
        ];
        $payload = [
            'robot' => 'REPUTAÇÃO',
            'level' => 'info',
            'icon' => '🛡️',
            'msg' => 'seller_reputation 5_green · semáforo verde',
        ];

        $ops = [];
        for ($i = 0; $i < 3; $i++) {
            $ops[] = $service->emitOpOnTransition('REPUTACAO', $payload, $state, 1335, 'live');
        }

        $emitted = array_values(array_filter($ops));
        $this->assertCount(1, $emitted, '3 ticks com mesmo seller_reputation devem emitir 1 op');
        $this->assertSame('op', $emitted[0]['type']);
        $this->assertSame('REPUTAÇÃO', $emitted[0]['payload']['robot']);
        $opPublished = array_values(array_filter(
            $published,
            static fn (array $e): bool => ($e['type'] ?? '') === 'op'
        ));
        $this->assertCount(1, $opPublished);
    }

    public function testOpEmitidoQuandoSellerReputationMuda(): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $infoStmt = $this->createMock(\PDOStatement::class);
        $infoStmt->method('fetchColumn')->willReturn(1);
        $pdo->method('prepare')->willReturn($stmt);
        $pdo->method('query')->willReturn($infoStmt);

        $store = [];
        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturnCallback(static function (string $key) use (&$store) {
            return $store[$key] ?? false;
        });
        $redis->method('set')->willReturnCallback(static function (string $key, $value) use (&$store): bool {
            $store[$key] = (string) $value;
            return true;
        });
        $redis->method('publish')->willReturn(1);
        $redis->method('lPush')->willReturn(1);
        $redis->method('lTrim')->willReturn(true);

        $service = new PregaoEmitService($pdo, $redis);
        $base = [
            'robot' => 'REPUTAÇÃO',
            'level' => 'info',
            'icon' => '🛡️',
            'msg' => 'seller_reputation',
        ];

        $a = $service->emitOpOnTransition('REPUTACAO', $base, ['semaforo' => 'verde'], 10, 'live');
        $b = $service->emitOpOnTransition('REPUTACAO', $base, ['semaforo' => 'amarelo'], 10, 'live');

        $this->assertNotNull($a);
        $this->assertNotNull($b);
    }
}

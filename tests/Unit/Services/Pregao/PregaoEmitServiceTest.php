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

    public function testAgentStatusFazParteDoContratoCanonico(): void
    {
        $this->assertContains('agent.status', PregaoEmitService::VALID_TYPES);
    }

    public function testTodoEventoTenantExigeContaPositiva(): void
    {
        $service = new PregaoEmitService($this->createMock(PDO::class), $this->createMock(Redis::class));

        $this->expectException(\InvalidArgumentException::class);
        $service->emit('op', ['msg' => 'sem conta']);
    }

    public function testKeywordRankRejeitaPayloadNaoTipadoAntesDeIo(): void
    {
        $service = new PregaoEmitService($this->createMock(PDO::class), $this->createMock(Redis::class));
        $invalidPayloads = [
            ['kw' => 'bagageiro', 'pos' => '7', 'delta' => null],
            ['kw' => 'bagageiro', 'pos' => 7, 'delta' => '<svg onload=alert(1)>'],
            ['kw' => '', 'pos' => 7, 'delta' => 1],
            ['kw' => 'bagageiro', 'pos' => 0, 'delta' => 1],
            ['kw' => 'bagageiro', 'pos' => 7, 'delta' => null, 'extra' => true],
        ];

        foreach ($invalidPayloads as $payload) {
            try {
                $service->emit('keyword.rank', $payload, 1335);
                self::fail('keyword.rank inválido deveria ser rejeitado');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testQaStatusRejeitaPayloadSemEvidenciaOuComUrlHostilAntesDeIo(): void
    {
        $service = new PregaoEmitService($this->createMock(PDO::class), $this->createMock(Redis::class));
        $invalidPayloads = [
            [],
            ['running' => false, 'suite' => 'smoke', 'test' => 'login', 'result' => 'passed', 'extra' => true],
            [
                'running' => true,
                'suite' => 'smoke',
                'test' => 'login',
                'result' => 'running',
                'stream_url' => 'javascript:alert(1)',
            ],
        ];

        foreach ($invalidPayloads as $payload) {
            try {
                $service->emit('qa.status', $payload, 1335);
                self::fail('qa.status inválido deveria ser rejeitado');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testQaStatusValidatorAceitaSomenteContratoSeguro(): void
    {
        $payload = [
            'running' => false,
            'suite' => 'smoke',
            'test' => 'login',
            'result' => 'passed',
            'video_url' => '/storage/qa/run-1.mp4',
        ];

        self::assertSame(
            $payload + ['stream_url' => null],
            PregaoEmitService::validateQaStatusPayload($payload)
        );
    }

    public function testFalhaDeConexaoRedisNaoExpoeMensagemBrutaNoLog(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/app/Services/Pregao/PregaoEmitService.php'
        );
        self::assertIsString($source);
        self::assertStringNotContainsString('getMessage()', $source);
        self::assertStringContainsString("['reason' => 'redis_connection_failed']", $source);
    }

    public function testAgentStatusExigeContaPositiva(): void
    {
        $service = new PregaoEmitService($this->createMock(PDO::class), $this->createMock(Redis::class));

        $this->expectException(\InvalidArgumentException::class);
        $service->emit('agent.status', $this->validAgentStatusPayload());
    }

    public function testAgentStatusRejeitaCampoExtra(): void
    {
        $service = new PregaoEmitService($this->createMock(PDO::class), $this->createMock(Redis::class));
        $payload = $this->validAgentStatusPayload();
        $payload['data'] = ['raw' => 'redacted'];

        $this->expectException(\InvalidArgumentException::class);
        $service->emit('agent.status', $payload, 1335);
    }

    public function testAgentStatusRejeitaReasonArbitrario(): void
    {
        $service = new PregaoEmitService($this->createMock(PDO::class), $this->createMock(Redis::class));
        $payload = $this->validAgentStatusPayload();
        $payload['reason'] = 'token_secret_value';

        $this->expectException(\InvalidArgumentException::class);
        $service->emit('agent.status', $payload, 1335);
    }

    public function testAgentStatusRejeitaStatusSuccessComReasonDeFalha(): void
    {
        $service = new PregaoEmitService($this->createMock(PDO::class), $this->createMock(Redis::class));
        $payload = $this->validAgentStatusPayload();
        $payload['status'] = 'success';
        $payload['reason'] = 'read_only_violation';

        $this->expectException(\InvalidArgumentException::class);
        $service->emit('agent.status', $payload, 1335);
    }

    public function testAgentStatusRejeitaCorrelacaoDeOutraConta(): void
    {
        $service = new PregaoEmitService($this->createMock(PDO::class), $this->createMock(Redis::class));
        $payload = $this->validAgentStatusPayload();
        $payload['correlation_id'] = 'agent24x7-20260804T120000Z-0123abcd:9999';

        $this->expectException(\InvalidArgumentException::class);
        $service->emit('agent.status', $payload, 1335);
    }

    public function testAgentStatusFalhaQuandoDbERedisNaoEntregam(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willThrowException(new \RuntimeException('db-secret'));
        $pdo->method('prepare')->willThrowException(new \RuntimeException('db-secret'));

        $redis = $this->createMock(Redis::class);
        $redis->method('publish')->willThrowException(new \RuntimeException('redis-secret'));

        $service = new PregaoEmitService($pdo, $redis);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Falha ao entregar agent.status');
        $service->emit('agent.status', $this->validAgentStatusPayload(), 1335);
    }

    public function testAgentStatusAceitaReasonsHttpNao2xxDoProdutor(): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $infoStmt = $this->createMock(\PDOStatement::class);
        $infoStmt->method('fetchColumn')->willReturn(1);
        $pdo->method('prepare')->willReturn($stmt);
        $pdo->method('query')->willReturn($infoStmt);
        $redis = $this->createMock(Redis::class);
        $redis->method('lPush')->willReturn(1);

        $service = new PregaoEmitService($pdo, $redis);
        foreach (['legacy_http_101', 'legacy_http_302'] as $reason) {
            $payload = $this->validAgentStatusPayload();
            $payload['status'] = 'failed';
            $payload['reason'] = $reason;
            self::assertSame('agent.status', $service->emit('agent.status', $payload, 1335)['type']);
        }
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
                        && ($data['v'] ?? null) === 2
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

        $this->assertSame(2, $event['v']);
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

    public function testHeartbeatMsgNeutraNaoReaproveitaAlerta(): void
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
        $payload = [
            'robot' => 'ADS',
            'level' => 'alert',
            'icon' => '🛑',
            'msg' => 'MLB1 · abaixo do breakeven — candidato a pausa (ROAS 0.00x < 3.84x)',
        ];
        $fp = ['on' => true, 'roas' => 0];

        $first = $service->emitOpOnTransition('ADS_ABAIXO_BREAKEVEN:MLB1', $payload, $fp, 1335, 'live');
        $this->assertNotNull($first);
        $this->assertArrayNotHasKey('heartbeat', $first['payload']);

        // força janela de heartbeat expirada (simula >1h)
        $store['pregao:heartbeat:1335:robot:ADS'] = (string) (time() - PregaoEmitService::OP_HEARTBEAT_TTL_SECONDS - 1);

        $hb = $service->emitOpOnTransition('ADS_ABAIXO_BREAKEVEN:MLB1', $payload, $fp, 1335, 'live');
        $this->assertNotNull($hb);
        $this->assertTrue($hb['payload']['heartbeat'] ?? false);
        $this->assertSame('info', $hb['payload']['level']);
        $this->assertSame('ADS · heartbeat (coletor vivo)', $hb['payload']['msg']);
        $this->assertStringNotContainsString('candidato a pausa', (string) $hb['payload']['msg']);
    }

    public function testHeartbeatNoMaximoUmaVezPorColetorNaMesmaHora(): void
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
        $payload = [
            'robot' => 'ADS',
            'level' => 'alert',
            'icon' => '🛑',
            'msg' => 'alerta',
        ];

        $service->emitOpOnTransition('ADS_A:MLB1', $payload, ['on' => true], 1335, 'live');
        $service->emitOpOnTransition('ADS_B:MLB2', $payload, ['on' => true], 1335, 'live');

        $store['pregao:heartbeat:1335:robot:ADS'] = (string) (time() - PregaoEmitService::OP_HEARTBEAT_TTL_SECONDS - 1);

        $hb1 = $service->emitOpOnTransition('ADS_A:MLB1', $payload, ['on' => true], 1335, 'live');
        $hb2 = $service->emitOpOnTransition('ADS_B:MLB2', $payload, ['on' => true], 1335, 'live');

        $this->assertNotNull($hb1);
        $this->assertTrue($hb1['payload']['heartbeat'] ?? false);
        $this->assertNull($hb2, 'segundo stateKey do mesmo robô na mesma hora não deve emitir outro heartbeat');
    }

    /**
     * @return array{agent:string,status:string,reason:string,correlation_id:string,attempts:int,state_changed:bool,ml_write_automation:bool}
     */
    private function validAgentStatusPayload(): array
    {
        return [
            'agent' => 'sentinela',
            'status' => 'success',
            'reason' => 'legacy_read_complete',
            'correlation_id' => 'agent24x7-20260804T120000Z-0123abcd:1335',
            'attempts' => 1,
            'state_changed' => false,
            'ml_write_automation' => false,
        ];
    }
}

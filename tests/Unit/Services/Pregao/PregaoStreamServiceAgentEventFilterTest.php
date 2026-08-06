<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pregao;

use App\Services\Pregao\PregaoStreamService;
use PHPUnit\Framework\TestCase;

final class PregaoStreamServiceAgentEventFilterTest extends TestCase
{
    public function testAgentStatusExigeEnvelopeCanonicoDaConta(): void
    {
        $event = $this->validEvent();
        self::assertTrue(PregaoStreamService::isEventAllowedForAccount($event, 1335));

        $withoutAccount = $event;
        unset($withoutAccount['account_id']);
        self::assertFalse(PregaoStreamService::isEventAllowedForAccount($withoutAccount, 1335));

        self::assertFalse(PregaoStreamService::isEventAllowedForAccount($event, 9999));

        $mutated = $event;
        $mutated['payload']['state_changed'] = true;
        self::assertFalse(PregaoStreamService::isEventAllowedForAccount($mutated, 1335));

        $spoofed = $event;
        $spoofed['payload']['reason'] = 'token_secret_value';
        self::assertFalse(PregaoStreamService::isEventAllowedForAccount($spoofed, 1335));

        $wrongVersion = $event;
        $wrongVersion['v'] = 1;
        self::assertFalse(PregaoStreamService::isEventAllowedForAccount($wrongVersion, 1335));

        $future = $event;
        $future['ts'] = (new \DateTimeImmutable('+5 minutes'))->format(DATE_ATOM);
        self::assertFalse(PregaoStreamService::isEventAllowedForAccount($future, 1335));

        $nonCanonical = $event;
        $nonCanonical['ts'] = 'next Thursday';
        self::assertFalse(PregaoStreamService::isEventAllowedForAccount($nonCanonical, 1335));

        $invalidCalendar = $event;
        $invalidCalendar['ts'] = '2026-02-30T12:00:00Z';
        self::assertFalse(PregaoStreamService::isEventAllowedForAccount($invalidCalendar, 1335));

        $old = $event;
        $old['ts'] = (new \DateTimeImmutable('-11 minutes'))->format(DATE_ATOM);
        self::assertTrue(PregaoStreamService::isEventAllowedForAccount($old, 1335));
    }

    public function testEventoTenantSemContaEhBloqueado(): void
    {
        $event = [
            'v' => 2,
            'type' => 'metric.update',
            'ts' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'payload' => [],
            'source' => 'live',
        ];
        self::assertFalse(PregaoStreamService::isEventAllowedForAccount($event, 1335));
        $event['account_id'] = 1335;
        self::assertTrue(PregaoStreamService::isEventAllowedForAccount($event, 1335));
        self::assertFalse(PregaoStreamService::isEventAllowedForAccount($event, 9999));
    }

    public function testKeywordRankExigePayloadCanonicoAntesDoFanout(): void
    {
        $event = [
            'v' => 2,
            'type' => 'keyword.rank',
            'ts' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'payload' => ['kw' => 'bagageiro', 'pos' => 7, 'delta' => null],
            'source' => 'live',
            'account_id' => 1335,
        ];
        self::assertTrue(PregaoStreamService::isEventAllowedForAccount($event, 1335));

        $stringPosition = $event;
        $stringPosition['payload']['pos'] = '<img src=x onerror=alert(1)>';
        self::assertFalse(PregaoStreamService::isEventAllowedForAccount($stringPosition, 1335));

        $extraKey = $event;
        $extraKey['payload']['extra'] = true;
        self::assertFalse(PregaoStreamService::isEventAllowedForAccount($extraKey, 1335));

        $invalidDelta = $event;
        $invalidDelta['payload']['delta'] = '1';
        self::assertFalse(PregaoStreamService::isEventAllowedForAccount($invalidDelta, 1335));
    }

    public function testQaStatusNaoFazFanoutSemProdutorConfiavel(): void
    {
        $event = [
            'v' => 2,
            'type' => 'qa.status',
            'ts' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'payload' => [
                'running' => false,
                'suite' => 'smoke',
                'test' => 'login',
                'result' => 'passed',
            ],
            'source' => 'live',
            'account_id' => 1335,
        ];

        self::assertFalse(PregaoStreamService::isEventAllowedForAccount($event, 1335));
    }

    public function testGatewayWebSocketUsaOFiltroCanonico(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/bin/pregao-ws-gateway.php');
        self::assertIsString($source);
        self::assertStringContainsString(
            'PregaoStreamService::isEventAllowedForAccount($event, $client[\'account_id\'], $qaProof, $qaRuns)',
            $source
        );
        self::assertStringContainsString('$fan->rPop(\'pregao:fanout\')', $source);
        self::assertStringNotContainsString('$fan->lPop(\'pregao:fanout\')', $source);
    }

    public function testTicketWebSocketEhConsumidoAtomicamenteESemVazarExcecao(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/app/Services/Pregao/PregaoStreamService.php'
        );
        self::assertIsString($source);
        self::assertStringContainsString('$redis->eval(self::CONSUME_TICKET_LUA', $source);
        self::assertStringNotContainsString('$redis->get($key)', $source);
        self::assertStringNotContainsString('$redis->del($key)', $source);
        self::assertStringContainsString("preg_match('/\\A[a-f0-9]{48}\\z/D'", $source);
        self::assertStringContainsString("['account_id', 'exp', 'user_id']", $source);
        self::assertStringContainsString('$data[\'exp\'] < time()', $source);
        self::assertStringNotContainsString('getMessage()', $source);
    }

    /**
     * @return array{v:int,type:string,ts:string,payload:array{agent:string,status:string,reason:string,correlation_id:string,attempts:int,state_changed:bool,ml_write_automation:bool},source:string,account_id:int}
     */
    private function validEvent(): array
    {
        return [
            'v' => 2,
            'type' => 'agent.status',
            'ts' => '2026-08-04T12:00:00+00:00',
            'payload' => [
                'agent' => 'sentinela',
                'status' => 'success',
                'reason' => 'legacy_read_complete',
                'correlation_id' => 'agent24x7-20260804T120000Z-0123abcd:1335',
                'attempts' => 1,
                'state_changed' => false,
                'ml_write_automation' => false,
            ],
            'source' => 'live',
            'account_id' => 1335,
        ];
    }
}

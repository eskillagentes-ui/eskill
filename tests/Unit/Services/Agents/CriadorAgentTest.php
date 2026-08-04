<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\CriadorAgent;
use PHPUnit\Framework\TestCase;

/** @covers \App\Services\Agents\CriadorAgent */
final class CriadorAgentTest extends TestCase
{
    public function testGeraRascunhoDeterministicoESanitizadoDeSnapshots(): void
    {
        $agent = new CriadorAgent();
        $context = $this->context([
            'creator_request' => ['source_mlb_id' => 'MLB700'],
            'creator_source_snapshot' => [
                'valid' => true,
                'duplicate' => false,
                'item' => [
                    'id' => 'MLB700', 'title' => '  Kit corrente  ',
                    'published' => true, 'permalink' => 'https://example.invalid/item',
                ],
            ],
        ], 52);

        $first = $agent->run($context);
        $second = (new CriadorAgent())->run($context);
        $expectedKey = hash('sha256', '52:MLB700');

        $this->assertSame('success', $first->status());
        $this->assertSame('draft-' . substr($expectedKey, 0, 24), $first->data()['draft']['id']);
        $this->assertSame('Kit corrente', $first->data()['draft']['title']);
        $this->assertSame('draft', $first->data()['draft']['status']);
        $this->assertTrue($first->data()['draft']['start_paused']);
        $this->assertFalse($first->data()['publish_allowed']);
        $this->assertArrayNotHasKey('published', $first->data()['draft']);
        $this->assertArrayNotHasKey('permalink', $first->data()['draft']);
        $this->assertSame($first->data(), $second->data());
        $this->assertFalse($first->stateChanged());
        $this->assertSame([], $first->emittedOps());
    }

    /** @dataProvider invalidRequests */
    public function testRequestInvalidoBloqueia(array $metadata): void
    {
        $result = (new CriadorAgent())->run($this->context($metadata));
        $this->assertSame('blocked', $result->status());
        $this->assertSame('creator_request_blocked', $result->reason());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public function invalidRequests(): iterable
    {
        yield 'ausente' => [[]];
        yield 'id invalido' => [['creator_request' => ['source_mlb_id' => 'item-1']]];
        yield 'campo operacional extra' => [['creator_request' => [
            'source_mlb_id' => 'MLB1', 'publish' => true,
        ]]];
    }

    /** @dataProvider invalidSources */
    public function testSnapshotDeOrigemInvalidoFalhaFechado(mixed $source): void
    {
        $result = (new CriadorAgent())->run($this->context([
            'creator_request' => ['source_mlb_id' => 'MLB810'],
            'creator_source_snapshot' => $source,
        ]));
        $this->assertSame('failed', $result->status());
        $this->assertSame('invalid_creator_source_snapshot', $result->reason());
        $this->assertSame([], $result->data());
    }

    /** @return iterable<string, array{mixed}> */
    public function invalidSources(): iterable
    {
        yield 'ausente' => [null];
        yield 'campo top-level extra' => [[
            'valid' => true, 'duplicate' => false, 'item' => ['id' => 'MLB810'], 'command' => 'publish',
        ]];
        yield 'campo de item extra' => [[
            'valid' => true, 'duplicate' => false, 'item' => ['id' => 'MLB810', 'stock' => 100],
        ]];
        yield 'tipo invalido' => [[
            'valid' => 'yes', 'duplicate' => false, 'item' => ['id' => 'MLB810'],
        ]];
    }

    /** @dataProvider rejectedSources */
    public function testOrigemRejeitadaBloqueia(array $source): void
    {
        $result = (new CriadorAgent())->run($this->context([
            'creator_request' => ['source_mlb_id' => 'MLB810'],
            'creator_source_snapshot' => $source,
        ]));
        $this->assertSame('blocked', $result->status());
        $this->assertSame('creator_request_blocked', $result->reason());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public function rejectedSources(): iterable
    {
        yield 'duplicata' => [['valid' => true, 'duplicate' => true, 'item' => ['id' => 'MLB810']]];
        yield 'item divergente' => [['valid' => true, 'duplicate' => false, 'item' => ['id' => 'MLB999']]];
        yield 'fonte invalida' => [['valid' => false, 'duplicate' => false, 'item' => ['id' => 'MLB810']]];
    }

    private function context(array $metadata, int $accountId = 81): AgentContext
    {
        return new AgentContext($accountId, 'local', 'corr-creator-snapshot', false, $metadata);
    }
}

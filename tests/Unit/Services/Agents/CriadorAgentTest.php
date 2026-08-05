<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\CriadorAgent;
use PHPUnit\Framework\TestCase;

/** @covers \App\Services\Agents\CriadorAgent */
final class CriadorAgentTest extends TestCase
{
    public function testGeraRascunhoDeterministicoEmMemoriaSemPortaDeEscrita(): void
    {
        $calls = 0;
        $requests = [];
        $agent = new CriadorAgent(
            static function (int $accountId, array $request) use (&$calls, &$requests): array {
                $calls++;
                $requests[] = [$accountId, $request];
                return [
                    'valid' => true,
                    'duplicate' => false,
                    'item' => [
                        'id' => 'MLB700',
                        'title' => 'Kit corrente',
                        'published' => true,
                        'permalink' => 'https://example.invalid/item',
                    ],
                ];
            }
        );

        $context = new AgentContext(52, 'production', 'corr-creator-pure', false, [
            'creator_request' => [
                'source_mlb_id' => 'MLB700',
                'human_approved' => true,
                'publish' => true,
                'action' => 'publish',
            ],
        ]);
        $result = $agent->run($context);
        $expectedKey = hash('sha256', '52:MLB700');

        $this->assertSame(1, $calls);
        $this->assertSame(52, $requests[0][0]);
        $this->assertSame($expectedKey, $requests[0][1]['idempotency_key']);
        $this->assertTrue($requests[0][1]['start_paused']);
        $this->assertFalse($requests[0][1]['include_description']);
        $this->assertFalse($requests[0][1]['include_pictures']);
        $this->assertArrayNotHasKey('publish', $requests[0][1]);
        $this->assertSame('success', $result->status());
        $this->assertSame('draft-' . substr($expectedKey, 0, 24), $result->data()['draft']['id']);
        $this->assertSame('Kit corrente', $result->data()['draft']['title']);
        $this->assertSame('draft', $result->data()['draft']['status']);
        $this->assertTrue($result->data()['draft']['start_paused']);
        $this->assertFalse($result->data()['publish_allowed']);
        $this->assertSame(['required' => true, 'status' => 'pending'], $result->data()['human_gate']);
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    /**
     * @dataProvider invalidCreatorMetadata
     * @param array<string, mixed> $metadata
     */
    public function testBloqueiaRequestInvalidoSemChamarOrigem(array $metadata): void
    {
        $calls = 0;
        $agent = new CriadorAgent(static function (int $accountId, array $request) use (&$calls): array {
            $calls++;
            return [];
        });

        $result = $agent->run(new AgentContext(10, 'local', 'corr-invalid', false, $metadata));

        $this->assertSame('blocked', $result->status());
        $this->assertSame('creator_request_blocked', $result->reason());
        $this->assertSame(0, $calls);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public function invalidCreatorMetadata(): array
    {
        return [
            'ausente' => [[]],
            'tipo invalido' => [['creator_request' => 'MLB1']],
            'vazio' => [['creator_request' => []]],
            'id invalido' => [['creator_request' => ['source_mlb_id' => 'item-123']]],
        ];
    }

    /**
     * @dataProvider rejectedSourcePayloads
     * @param array<string, mixed> $payload
     */
    public function testFalhaFechadaParaOrigemRejeitadaOuIndisponivel(array $payload, string $status): void
    {
        $agent = new CriadorAgent(static fn (int $accountId, array $request): array => $payload);
        $result = $agent->run(new AgentContext(81, 'local', 'corr-source', false, [
            'creator_request' => ['source_mlb_id' => 'MLB810'],
        ]));

        $this->assertSame($status, $result->status());
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public function rejectedSourcePayloads(): array
    {
        return [
            'duplicata' => [[
                'valid' => true,
                'duplicate' => true,
                'item' => ['id' => 'MLB810'],
            ], 'blocked'],
            'item divergente' => [[
                'valid' => true,
                'duplicate' => false,
                'item' => ['id' => 'MLB999'],
            ], 'blocked'],
            '401' => [['http_status' => 401], 'failed'],
            '403 string' => [['http_status' => '403'], 'failed'],
            '503' => [['http_status' => 503], 'failed'],
        ];
    }

    public function testExcecaoOuRetornoNaoArrayDaOrigemFalhaSemVazamento(): void
    {
        $ports = [
            static function (int $accountId, array $request): array {
                throw new \RuntimeException('segredo-na-origem');
            },
            static function (int $accountId, array $request) {
                return 'payload-invalido';
            },
        ];

        foreach ($ports as $index => $port) {
            $result = (new CriadorAgent($port))->run(new AgentContext(91, 'local', 'corr-error-' . $index, false, [
                'creator_request' => ['source_mlb_id' => 'MLB910'],
            ]));
            $this->assertSame('failed', $result->status());
            $this->assertSame('creator_unavailable', $result->reason());
            $this->assertStringNotContainsString('segredo', $result->reason());
        }
    }

    public function testIdempotenciaEhEstavelNaInstanciaEEntreInstancias(): void
    {
        $calls = 0;
        $source = static function (int $accountId, array $request) use (&$calls): array {
            $calls++;
            return [
                'valid' => true,
                'duplicate' => false,
                'item' => ['id' => $request['source_mlb_id'], 'title' => 'Produto'],
            ];
        };
        $context = new AgentContext(99, 'local', 'corr-idempotent', false, [
            'creator_request' => ['source_mlb_id' => 'MLB990'],
        ]);
        $firstAgent = new CriadorAgent($source);

        $first = $firstAgent->run($context);
        $sameInstance = $firstAgent->run($context);
        $otherInstance = (new CriadorAgent($source))->run($context);

        $this->assertSame(2, $calls);
        $this->assertSame($first, $sameInstance);
        $this->assertSame($first->data()['draft']['id'], $otherInstance->data()['draft']['id']);
        $this->assertSame($first->data()['idempotency_key'], $otherInstance->data()['idempotency_key']);
    }
}

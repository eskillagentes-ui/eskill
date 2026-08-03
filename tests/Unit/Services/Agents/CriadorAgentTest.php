<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\CriadorAgent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Agents\CriadorAgent
 */
final class CriadorAgentTest extends TestCase
{
    public function testCriaSomenteRascunhoPausadoComGateHumanoMesmoAprovado(): void
    {
        $seenAccountIds = [];
        $seenRequests = [];
        $sourcePort = function (int $accountId, array $request) use (&$seenAccountIds, &$seenRequests): array {
            $seenAccountIds[] = $accountId;
            $seenRequests[] = $request;

            return [
                'valid' => true,
                'duplicate' => false,
                'item' => ['id' => 'MLB700', 'title' => 'Kit corrente'],
            ];
        };
        $draftPort = function (int $accountId, array $request) use (&$seenAccountIds, &$seenRequests): array {
            $seenAccountIds[] = $accountId;
            $seenRequests[] = $request;

            return [
                'draft' => [
                    'id' => 'draft-700',
                    'title' => 'Kit corrente',
                    'status' => 'active',
                    'published' => true,
                    'publish_allowed' => true,
                    'item_id' => 'MLB700',
                    'permalink' => 'https://example.invalid/item',
                    'description' => 'Descricao de origem',
                    'pictures' => [['id' => 'picture-source']],
                    'start_paused' => false,
                    'include_description' => true,
                    'include_pictures' => true,
                ],
            ];
        };
        $request = [
            'source_mlb_id' => 'MLB700',
            'human_approved' => true,
            'publish' => true,
            'publish_allowed' => true,
            'action' => 'publish',
            'start_paused' => false,
            'include_description' => true,
            'include_pictures' => true,
        ];

        $agent = new CriadorAgent($sourcePort, $draftPort);
        $result = $agent->run(new AgentContext(
            52,
            'staging',
            'corr-creator-1',
            true,
            ['creator_request' => $request]
        ));

        $this->assertSame('criador', $agent->name());
        $this->assertSame([52, 52], $seenAccountIds);
        $this->assertCount(2, $seenRequests);
        foreach ($seenRequests as $seenRequest) {
            $this->assertTrue($seenRequest['start_paused']);
            $this->assertFalse($seenRequest['include_description']);
            $this->assertFalse($seenRequest['include_pictures']);
            $this->assertArrayNotHasKey('human_approved', $seenRequest);
            $this->assertArrayNotHasKey('publish', $seenRequest);
            $this->assertArrayNotHasKey('publish_allowed', $seenRequest);
            $this->assertArrayNotHasKey('action', $seenRequest);
        }
        $this->assertSame('success', $result->status());
        $this->assertSame('draft-700', $result->data()['draft']['id']);
        $this->assertTrue($result->data()['draft']['start_paused']);
        $this->assertFalse($result->data()['draft']['include_description']);
        $this->assertFalse($result->data()['draft']['include_pictures']);
        $this->assertSame('draft', $result->data()['draft']['status']);
        $this->assertArrayNotHasKey('published', $result->data()['draft']);
        $this->assertArrayNotHasKey('publish_allowed', $result->data()['draft']);
        $this->assertArrayNotHasKey('item_id', $result->data()['draft']);
        $this->assertArrayNotHasKey('permalink', $result->data()['draft']);
        $this->assertArrayNotHasKey('description', $result->data()['draft']);
        $this->assertArrayNotHasKey('pictures', $result->data()['draft']);
        $this->assertTrue($result->data()['read_only']);
        $this->assertSame(['required' => true, 'status' => 'pending'], $result->data()['human_gate']);
        $this->assertFalse($result->data()['publish_allowed']);
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    /**
     * @dataProvider invalidCreatorMetadata
     * @param array<string, mixed> $metadata
     */
    public function testBloqueiaCreatorRequestAusenteOuItemInvalidoSemChamarPortas(array $metadata): void
    {
        $sourceCalls = 0;
        $draftCalls = 0;
        $agent = new CriadorAgent(
            static function (int $accountId, array $request) use (&$sourceCalls): array {
                $sourceCalls++;
                return [];
            },
            static function (int $accountId, array $request) use (&$draftCalls): array {
                $draftCalls++;
                return [];
            }
        );

        $result = $agent->run(new AgentContext(10, 'local', 'corr-invalid-request', false, $metadata));

        $this->assertSame('blocked', $result->status());
        $this->assertSame('creator_request_blocked', $result->reason());
        $this->assertSame(0, $sourceCalls);
        $this->assertSame(0, $draftCalls);
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public function invalidCreatorMetadata(): array
    {
        return [
            'ausente' => [[]],
            'tipo invalido' => [['creator_request' => 'MLB1']],
            'vazio' => [['creator_request' => []]],
            'id invalido' => [['creator_request' => ['source_mlb_id' => 'item-123']]],
            'metadata alternativo ignorado' => [['request' => ['source_mlb_id' => 'MLB123']]],
        ];
    }

    /**
     * @dataProvider blockedSourcePayloads
     * @param array<string, mixed> $sourcePayload
     */
    public function testDuplicataOuItemFonteInvalidoBloqueiaAntesDoRascunho(array $sourcePayload): void
    {
        $sourceCalls = 0;
        $draftCalls = 0;
        $agent = new CriadorAgent(
            static function (int $accountId, array $request) use (&$sourceCalls, $sourcePayload): array {
                $sourceCalls++;
                return $sourcePayload;
            },
            static function (int $accountId, array $request) use (&$draftCalls): array {
                $draftCalls++;
                return ['draft' => ['id' => 'nao-deveria-existir']];
            }
        );

        $result = $agent->run(new AgentContext(
            81,
            'local',
            'corr-source-blocked',
            false,
            ['creator_request' => ['source_mlb_id' => 'MLB810']]
        ));

        $this->assertSame('blocked', $result->status());
        $this->assertSame('creator_request_blocked', $result->reason());
        $this->assertSame(1, $sourceCalls);
        $this->assertSame(0, $draftCalls);
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public function blockedSourcePayloads(): array
    {
        return [
            'duplicata' => [[
                'valid' => true,
                'duplicate' => true,
                'item' => ['id' => 'MLB810'],
            ]],
            'marcado invalido' => [[
                'valid' => false,
                'duplicate' => false,
                'item' => ['id' => 'MLB810'],
            ]],
            'item ausente' => [[
                'valid' => true,
                'duplicate' => false,
            ]],
            'item divergente' => [[
                'valid' => true,
                'duplicate' => false,
                'item' => ['id' => 'MLB999'],
            ]],
        ];
    }

    /**
     * @dataProvider unavailableSourcePayloads
     * @param array<string, mixed> $sourcePayload
     */
    public function testFonteIndisponivelFalhaAntesDoRascunho(array $sourcePayload): void
    {
        $draftCalls = 0;
        $agent = new CriadorAgent(
            static function (int $accountId, array $request) use ($sourcePayload): array {
                return $sourcePayload;
            },
            static function (int $accountId, array $request) use (&$draftCalls): array {
                $draftCalls++;
                return ['draft' => ['id' => 'nao-deveria-existir']];
            }
        );

        $result = $agent->run(new AgentContext(
            82,
            'local',
            'corr-source-unavailable',
            false,
            ['creator_request' => ['source_mlb_id' => 'MLB820']]
        ));

        $this->assertSame('failed', $result->status());
        $this->assertSame('creator_unavailable', $result->reason());
        $this->assertSame(0, $draftCalls);
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    /** @return array<string, array{array<string, int|string>}> */
    public function unavailableSourcePayloads(): array
    {
        return [
            'nao autorizado' => [['http_status' => 401]],
            'rate limit inteiro' => [['http_status' => 429]],
            'erro remoto inteiro' => [['http_status' => 503]],
            'rate limit string' => [['http_status' => '429']],
            'erro remoto string' => [['http_status' => '503']],
        ];
    }

    public function testExecucaoRepetidaDaMesmaSolicitacaoEhIdempotente(): void
    {
        $sourceCalls = 0;
        $draftCalls = 0;
        $agent = new CriadorAgent(
            static function (int $accountId, array $request) use (&$sourceCalls): array {
                $sourceCalls++;
                return [
                    'valid' => true,
                    'duplicate' => false,
                    'item' => ['id' => $request['source_mlb_id']],
                ];
            },
            static function (int $accountId, array $request) use (&$draftCalls): array {
                $draftCalls++;
                return ['draft' => ['id' => 'draft-idempotente']];
            }
        );
        $context = new AgentContext(
            99,
            'local',
            'corr-idempotent',
            false,
            ['creator_request' => ['source_mlb_id' => 'MLB990']]
        );

        $first = $agent->run($context);
        $second = $agent->run($context);

        $this->assertSame(1, $sourceCalls);
        $this->assertSame(1, $draftCalls);
        $this->assertSame($first, $second);
        $this->assertFalse($second->stateChanged());
        $this->assertSame([], $second->emittedOps());
    }

    public function testChaveDeIdempotenciaEhDeterministicaEntreInstanciasEEnviadaAsPortas(): void
    {
        $seenKeys = [];
        $source = static function (int $accountId, array $request) use (&$seenKeys): array {
            $seenKeys[] = $request['idempotency_key'] ?? null;
            return [
                'valid' => true,
                'duplicate' => false,
                'item' => ['id' => $request['source_mlb_id']],
            ];
        };
        $draft = static function (int $accountId, array $request) use (&$seenKeys): array {
            $seenKeys[] = $request['idempotency_key'] ?? null;
            return ['draft' => ['id' => 'draft-shared-key']];
        };
        $context = new AgentContext(
            99,
            'local',
            'corr-cross-instance',
            false,
            ['creator_request' => ['source_mlb_id' => 'MLB990']]
        );

        $first = (new CriadorAgent($source, $draft))->run($context);
        $second = (new CriadorAgent($source, $draft))->run($context);

        $expected = hash('sha256', '99:MLB990');
        $this->assertSame([$expected, $expected, $expected, $expected], $seenKeys);
        $this->assertSame($expected, $first->data()['idempotency_key']);
        $this->assertSame($expected, $second->data()['idempotency_key']);
    }

    public function testExcecoesDasPortasFalhamFechadoSemVazarDetalhes(): void
    {
        $validSource = static function (int $accountId, array $request): array {
            return [
                'valid' => true,
                'duplicate' => false,
                'item' => ['id' => $request['source_mlb_id']],
            ];
        };
        $validDraft = static function (int $accountId, array $request): array {
            return ['draft' => ['id' => 'draft-ok']];
        };
        $cases = [
            [
                static function (int $accountId, array $request): array {
                    throw new \RuntimeException('segredo-na-fonte');
                },
                $validDraft,
            ],
            [
                $validSource,
                static function (int $accountId, array $request): array {
                    throw new \RuntimeException('segredo-no-draft');
                },
            ],
        ];

        foreach ($cases as $index => $ports) {
            $agent = new CriadorAgent($ports[0], $ports[1]);
            $result = $agent->run(new AgentContext(
                101,
                'local',
                'corr-creator-error-' . $index,
                false,
                ['creator_request' => ['source_mlb_id' => 'MLB1010']]
            ));

            $this->assertSame('failed', $result->status());
            $this->assertSame('creator_unavailable', $result->reason());
            $this->assertStringNotContainsString('segredo', $result->reason());
            $this->assertFalse($result->stateChanged());
            $this->assertSame([], $result->emittedOps());
        }
    }

    public function testRetornoNaoArrayDasPortasFalhaFechado(): void
    {
        $validSource = static function (int $accountId, array $request): array {
            return [
                'valid' => true,
                'duplicate' => false,
                'item' => ['id' => $request['source_mlb_id']],
            ];
        };
        $validDraft = static function (int $accountId, array $request): array {
            return ['draft' => ['id' => 'draft-valid']];
        };
        $cases = [
            [static function (int $accountId, array $request) {
                return 'invalid-source';
            }, $validDraft],
            [$validSource, static function (int $accountId, array $request) {
                return new \stdClass();
            }],
        ];

        foreach ($cases as $index => $ports) {
            $agent = new CriadorAgent($ports[0], $ports[1]);
            $result = $agent->run(new AgentContext(
                103,
                'local',
                'corr-invalid-port-' . $index,
                false,
                ['creator_request' => ['source_mlb_id' => 'MLB1030']]
            ));

            $this->assertSame('failed', $result->status());
            $this->assertSame('creator_unavailable', $result->reason());
            $this->assertFalse($result->stateChanged());
            $this->assertSame([], $result->emittedOps());
        }
    }

    /**
     * @dataProvider invalidDraftPayloads
     * @param array<string, mixed> $draftPayload
     */
    public function testRascunhoInvalidoOuIndisponivelFalhaFechado(array $draftPayload): void
    {
        $agent = new CriadorAgent(
            static function (int $accountId, array $request): array {
                return [
                    'valid' => true,
                    'duplicate' => false,
                    'item' => ['id' => $request['source_mlb_id']],
                ];
            },
            static function (int $accountId, array $request) use ($draftPayload): array {
                return $draftPayload;
            }
        );

        $result = $agent->run(new AgentContext(
            102,
            'local',
            'corr-invalid-draft',
            false,
            ['creator_request' => ['source_mlb_id' => 'MLB1020']]
        ));

        $this->assertSame('failed', $result->status());
        $this->assertSame('creator_unavailable', $result->reason());
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public function invalidDraftPayloads(): array
    {
        return [
            'vazio' => [[]],
            'draft nao array' => [['draft' => 'invalid']],
            'draft sem id' => [['draft' => ['title' => 'incompleto']]],
            'draft id vazio' => [['draft' => ['id' => '']]],
            'draft com id publicado' => [['draft' => ['id' => 'MLB1020']]],
            'nao autorizado' => [[
                'http_status' => 403,
                'draft' => ['id' => 'draft-nao-autorizado'],
            ]],
            'rate limit' => [['http_status' => 429]],
            'erro remoto' => [['http_status' => 503]],
            'rate limit string' => [['http_status' => '429']],
            'erro remoto string' => [['http_status' => '503']],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\OtimizadorAgent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Agents\OtimizadorAgent
 */
final class OtimizadorAgentTest extends TestCase
{
    public function testRetornaSomenteRecomendacoesValidadasSemEmitirOperacoes(): void
    {
        $seenAccountIds = [];
        $seenMlbIds = [];
        $observePort = function (int $accountId) use (&$seenAccountIds): array {
            $seenAccountIds[] = $accountId;

            return [
                'recommendations' => [
                    [
                        'mlb_id' => 'MLB123',
                        'kind' => 'ads_roas',
                        'recommended_roas' => 3.25,
                    ],
                ],
            ];
        };
        $costValidationPort = function (int $accountId, array $mlbIds) use (&$seenAccountIds, &$seenMlbIds): array {
            $seenAccountIds[] = $accountId;
            $seenMlbIds = $mlbIds;

            return [
                'items' => [
                    'MLB123' => [
                        'validated' => true,
                        'suspicious' => false,
                        'cost' => 100.0,
                    ],
                ],
            ];
        };

        $agent = new OtimizadorAgent($observePort, $costValidationPort);
        $result = $agent->run(new AgentContext(77, 'staging', 'corr-opt-1', true));

        $this->assertSame('otimizador', $agent->name());
        $this->assertSame([77, 77], $seenAccountIds);
        $this->assertSame(['MLB123'], $seenMlbIds);
        $this->assertSame('success', $result->status());
        $this->assertSame('otimizador', $result->agent());
        $this->assertSame(3.25, $result->data()['recommendations'][0]['recommended_roas']);
        $this->assertTrue($result->data()['recommendations'][0]['actionable']);
        $this->assertFalse($result->data()['recommendations'][0]['blocked']);
        $this->assertTrue($result->data()['read_only']);
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    /**
     * @dataProvider invalidCostPayloads
     * @param array<string, mixed> $costPayload
     */
    public function testCustoAusenteSuspeitoOuNaoValidadoBloqueiaRecomendacao(array $costPayload): void
    {
        $agent = new OtimizadorAgent(
            static function (int $accountId): array {
                return [
                    'recommendations' => [
                        ['mlb_id' => 'MLB999', 'kind' => 'ads_roas', 'recommended_roas' => 9.99],
                    ],
                ];
            },
            static function (int $accountId, array $mlbIds) use ($costPayload): array {
                return $costPayload;
            }
        );

        $result = $agent->run(new AgentContext(91, 'local', 'corr-opt-cost', false));

        $this->assertSame('blocked', $result->status());
        $this->assertFalse($result->data()['recommendations'][0]['actionable']);
        $this->assertTrue($result->data()['recommendations'][0]['blocked']);
        $this->assertArrayNotHasKey('recommended_roas', $result->data()['recommendations'][0]);
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public function invalidCostPayloads(): array
    {
        return [
            'custo ausente' => [['items' => []]],
            'custo suspeito' => [[
                'items' => [
                    'MLB999' => ['validated' => true, 'suspicious' => true, 'cost' => 90.0],
                ],
            ]],
            'custo nao validado' => [[
                'items' => [
                    'MLB999' => ['validated' => false, 'suspicious' => false, 'cost' => 90.0],
                ],
            ]],
        ];
    }

    public function testPayloadIncompletoRateLimitErroRemotoOuExcecaoFalhaFechadaSemVazamento(): void
    {
        $cases = [
            static function (int $accountId) {
                return 'invalid-observe-payload';
            },
            static function (int $accountId): array {
                return [];
            },
            static function (int $accountId): array {
                return ['http_status' => 429];
            },
            static function (int $accountId): array {
                return [
                    'http_status' => 401,
                    'recommendations' => [[
                        'mlb_id' => 'MLB401',
                        'kind' => 'ads_roas',
                        'recommended_roas' => 3.0,
                    ]],
                ];
            },
            static function (int $accountId): array {
                return ['http_status' => 503];
            },
            static function (int $accountId): array {
                throw new \RuntimeException('token-secreto-resposta-ML');
            },
        ];

        foreach ($cases as $index => $observePort) {
            $costCalls = 0;
            $agent = new OtimizadorAgent(
                $observePort,
                static function (int $accountId, array $mlbIds) use (&$costCalls): array {
                    $costCalls++;
                    return [];
                }
            );

            $result = $agent->run(new AgentContext(33, 'local', 'corr-opt-fail-' . $index, false));

            $this->assertSame('failed', $result->status());
            $this->assertSame('optimizer_unavailable', $result->reason());
            $this->assertStringNotContainsString('token-secreto', $result->reason());
            $this->assertSame(0, $costCalls);
            $this->assertFalse($result->stateChanged());
            $this->assertSame([], $result->emittedOps());
        }
    }

    public function testFalhaDaValidacaoDeCustosTambemFalhaFechadaSemVazamento(): void
    {
        $costCases = [
            static function (int $accountId, array $mlbIds) {
                return 'invalid-cost-payload';
            },
            static function (int $accountId, array $mlbIds): array {
                return [];
            },
            static function (int $accountId, array $mlbIds): array {
                return ['http_status' => 429];
            },
            static function (int $accountId, array $mlbIds): array {
                return [
                    'http_status' => 403,
                    'items' => [
                        'MLB456' => ['validated' => true, 'suspicious' => false, 'cost' => 100.0],
                    ],
                ];
            },
            static function (int $accountId, array $mlbIds): array {
                return ['http_status' => 500];
            },
            static function (int $accountId, array $mlbIds): array {
                throw new \RuntimeException('detalhe-interno-custo');
            },
        ];

        foreach ($costCases as $index => $costPort) {
            $agent = new OtimizadorAgent(
                static function (int $accountId): array {
                    return ['recommendations' => [[
                        'mlb_id' => 'MLB456',
                        'kind' => 'ads_roas',
                        'recommended_roas' => 3.0,
                    ]]];
                },
                $costPort
            );

            $result = $agent->run(new AgentContext(44, 'local', 'corr-cost-fail-' . $index, true));

            $this->assertSame('failed', $result->status());
            $this->assertSame('optimizer_unavailable', $result->reason());
            $this->assertStringNotContainsString('detalhe-interno', $result->reason());
            $this->assertFalse($result->stateChanged());
            $this->assertSame([], $result->emittedOps());
        }
    }

    /**
     * @dataProvider unsafeRecommendationPayloads
     * @param array<string, mixed> $recommendation
     */
    public function testRejeitaRecomendacaoMalformadaOuComCampoOperacional(array $recommendation): void
    {
        $costCalls = 0;
        $agent = new OtimizadorAgent(
            static fn (int $accountId): array => ['recommendations' => [$recommendation]],
            static function (int $accountId, array $mlbIds) use (&$costCalls): array {
                $costCalls++;
                return ['items' => []];
            }
        );

        $result = $agent->run(new AgentContext(45, 'local', 'corr-unsafe-recommendation', false));

        $this->assertSame('failed', $result->status());
        $this->assertSame('optimizer_unavailable', $result->reason());
        $this->assertSame(0, $costCalls);
        $this->assertSame([], $result->data());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public function unsafeRecommendationPayloads(): array
    {
        $valid = [
            'mlb_id' => 'MLB123',
            'kind' => 'ads_roas',
            'recommended_roas' => 3.0,
        ];

        return [
            'id invalido' => [array_merge($valid, ['mlb_id' => 'not-an-mlb;DROP'])],
            'kind operacional' => [array_merge($valid, ['kind' => 'publish'])],
            'roas zero' => [array_merge($valid, ['recommended_roas' => 0])],
            'acao extra' => [array_merge($valid, ['action' => 'publish'])],
            'preco extra' => [array_merge($valid, ['price' => 0])],
            'estoque extra' => [array_merge($valid, ['stock' => 999])],
            'orcamento extra' => [array_merge($valid, ['budget' => 999999])],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\AgentResult;
use App\Services\Agents\QaAgent;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \App\Services\Agents\QaAgent
 */
class QaAgentTest extends TestCase
{
    public function testExecutaChecksUmaVezNaOrdemERetornaSuccessSeguro(): void
    {
        $calls = [];
        $qa = new QaAgent([
            'php-lint' => function (AgentContext $context) use (&$calls): AgentResult {
                $calls[] = 'php-lint';
                return AgentResult::success('php-lint', 'ok');
            },
            'phpunit-agents' => function (AgentContext $context) use (&$calls): AgentResult {
                $calls[] = 'phpunit-agents';
                return AgentResult::success('phpunit-agents', 'ok');
            },
        ]);

        $result = $qa->run($this->context());

        $this->assertSame(['php-lint', 'phpunit-agents'], $calls);
        $this->assertSame('qa', $qa->name());
        $this->assertSame('qa', $result->agent());
        $this->assertSame('success', $result->status());
        $this->assertSame('all_checks_passed', $result->reason());
        $this->assertSame(
            [
                'checks' => [
                    'php-lint' => ['approved' => true, 'reason' => 'approved'],
                    'phpunit-agents' => ['approved' => true, 'reason' => 'approved'],
                ],
                'order' => ['php-lint', 'phpunit-agents'],
            ],
            $result->data()
        );
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    /**
     * @dataProvider invalidConfigurations
     * @param array<mixed, mixed> $checks
     */
    public function testRejeitaConfiguracaoInvalida(array $checks): void
    {
        $this->expectException(InvalidArgumentException::class);
        new QaAgent($checks);
    }

    /** @return array<string, array{0: array<mixed, mixed>}> */
    public function invalidConfigurations(): array
    {
        $valid = static function (AgentContext $context): AgentResult {
            return AgentResult::success('valid', 'ok');
        };

        return [
            'lista vazia' => [[]],
            'id vazio' => [['' => $valid]],
            'id em branco' => [['   ' => $valid]],
            'id nao string' => [[0 => $valid]],
            'check nao callable' => [['valid' => 'not-callable']],
        ];
    }

    /**
     * @dataProvider invalidAgentResults
     */
    public function testReprovaResultadoQueViolaContrato(AgentResult $checkResult, string $expectedReason): void
    {
        $qa = new QaAgent([
            'contract' => static function (AgentContext $context) use ($checkResult): AgentResult {
                return $checkResult;
            },
        ]);

        $result = $qa->run($this->context());

        $this->assertSame('failed', $result->status());
        $this->assertSame('checks_failed', $result->reason());
        $this->assertSame(
            ['approved' => false, 'reason' => $expectedReason],
            $result->data()['checks']['contract']
        );
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    /** @return array<string, array{0: AgentResult, 1: string}> */
    public function invalidAgentResults(): array
    {
        return [
            'skipped' => [AgentResult::skipped('contract', 'skip detail'), 'status_not_success'],
            'blocked' => [AgentResult::blocked('contract', 'block detail'), 'status_not_success'],
            'failed' => [AgentResult::failed('contract', 'failure detail'), 'status_not_success'],
            'nome divergente' => [AgentResult::success('other', 'ok'), 'agent_mismatch'],
            'mudou estado' => [AgentResult::success('contract', 'ok', [], true), 'state_changed'],
            'emitiu ops' => [AgentResult::success('contract', 'ok', [], false, ['op:unsafe']), 'emitted_ops'],
        ];
    }

    public function testFalhaGenericamenteEmThrowableERetornoInvalidoMasContinua(): void
    {
        $calls = [];
        $qa = new QaAgent([
            'throws' => function (AgentContext $context) use (&$calls): AgentResult {
                $calls[] = 'throws';
                throw new RuntimeException('segredo-interno-que-nao-pode-vazar');
            },
            'invalid' => function (AgentContext $context) use (&$calls) {
                $calls[] = 'invalid';
                return ['status' => 'success'];
            },
            'after' => function (AgentContext $context) use (&$calls): AgentResult {
                $calls[] = 'after';
                return AgentResult::success('after', 'ok');
            },
        ]);

        $result = $qa->run($this->context());

        $this->assertSame(['throws', 'invalid', 'after'], $calls);
        $this->assertSame('failed', $result->status());
        $this->assertSame(['approved' => false, 'reason' => 'check_exception'], $result->data()['checks']['throws']);
        $this->assertSame(['approved' => false, 'reason' => 'invalid_result'], $result->data()['checks']['invalid']);
        $this->assertSame(['approved' => true, 'reason' => 'approved'], $result->data()['checks']['after']);
        $this->assertStringNotContainsString('segredo-interno', serialize($result->data()));
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    public function testIgnoraComandosDaMetadataEUsaSomenteIdsDeCheckCapturados(): void
    {
        $qa = new QaAgent([
            'php-lint' => static function (AgentContext $context): AgentResult {
                return AgentResult::success('php-lint', 'captured');
            },
            'phpunit-agents' => static function (AgentContext $context): AgentResult {
                return AgentResult::success('phpunit-agents', 'captured');
            },
        ]);
        $context = $this->context([
            'commands' => ['php-lint', 'phpunit-agents', 'e2e-readonly'],
        ]);

        $result = $qa->run($context);

        $this->assertSame('success', $result->status());
        $this->assertSame(['php-lint', 'phpunit-agents'], $result->data()['order']);
        $this->assertArrayNotHasKey('commands', $result->data());
    }

    public function testImplementacaoNaoPossuiCapacidadesDeShellOuIntegracoesExternas(): void
    {
        $sources = [
            file_get_contents(__DIR__ . '/../../../../app/Services/Agents/QaAgent.php'),
            file_get_contents(__DIR__ . '/../../../../app/Services/Agents/QaMergeGate.php'),
        ];

        foreach ($sources as $source) {
            $this->assertIsString($source);
            foreach (['exec', 'system', 'shell_exec', 'passthru', 'proc_open'] as $function) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\\b' . preg_quote($function, '/') . '\\s*\\(/',
                    $source
                );
            }
            foreach (['git', 'deploy', 'MercadoLivre', 'PDO', 'Database'] as $capability) {
                $this->assertStringNotContainsString($capability, $source);
            }
        }
    }

    private function context(array $metadata = []): AgentContext
    {
        return new AgentContext(10, 'local', 'corr-qa', false, $metadata);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pregao;

use App\Services\Pregao\PregaoQaWorkerProtocol;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Pregao\PregaoQaWorkerProtocol
 */
final class PregaoQaWorkerProtocolTest extends TestCase
{
    private function runId(): string
    {
        return '22222222-2222-4222-8222-222222222222';
    }

    private function line(array $overrides = []): string
    {
        $payload = array_merge([
            'cursor' => null,
            'observed_at' => gmdate('c'),
            'result' => 'running',
            'run_id' => $this->runId(),
            'screenshot' => null,
            'sequence' => 1,
            'step' => 'dashboard',
        ], $overrides);
        ksort($payload);
        // decode exige chaves exatas (ordem alfabética via sort no decode)
        $ordered = [];
        foreach (['cursor', 'observed_at', 'result', 'run_id', 'screenshot', 'sequence', 'step'] as $key) {
            $ordered[$key] = $payload[$key];
        }
        return json_encode($ordered, JSON_THROW_ON_ERROR);
    }

    public function testDecodeAcceptsFirstRunningStep(): void
    {
        $decoded = PregaoQaWorkerProtocol::decode($this->line(), $this->runId(), 0);
        $this->assertNotNull($decoded);
        $this->assertSame(1, $decoded['sequence']);
        $this->assertSame('dashboard', $decoded['step']);
    }

    public function testDecodeRejectsWrongSequence(): void
    {
        $this->assertNull(
            PregaoQaWorkerProtocol::decode($this->line(['sequence' => 2, 'step' => 'snapshot']), $this->runId(), 0)
        );
    }

    public function testDecodeRejectsPassedBeforeLastStep(): void
    {
        $this->assertNull(
            PregaoQaWorkerProtocol::decode(
                $this->line(['result' => 'passed', 'sequence' => 1, 'step' => 'dashboard']),
                $this->runId(),
                0
            )
        );
    }

    public function testDecodeAcceptsFinalPassed(): void
    {
        $last = count(PregaoQaWorkerProtocol::STEPS);
        $step = PregaoQaWorkerProtocol::STEPS[$last - 1];
        $decoded = PregaoQaWorkerProtocol::decode(
            $this->line([
                'result' => 'passed',
                'sequence' => $last,
                'step' => $step,
                'screenshot' => 'latest.png',
            ]),
            $this->runId(),
            $last - 1,
            'running'
        );
        $this->assertNotNull($decoded);
        $this->assertSame('passed', $decoded['result']);
    }

    public function testDecodeRejectsAfterTerminalPrevious(): void
    {
        $this->assertNull(
            PregaoQaWorkerProtocol::decode(
                $this->line(['sequence' => 2, 'step' => 'snapshot']),
                $this->runId(),
                1,
                'passed'
            )
        );
    }

    public function testDecodeRejectsInvalidJson(): void
    {
        $this->assertNull(PregaoQaWorkerProtocol::decode('{broken', $this->runId(), 0));
    }
}

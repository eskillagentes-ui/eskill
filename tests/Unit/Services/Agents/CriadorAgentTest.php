<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\CriadorAgent;
use PHPUnit\Framework\TestCase;

/** @covers \App\Services\Agents\CriadorAgent */
final class CriadorAgentTest extends TestCase
{
    use AgentSnapshotFixtures;

    public function testGeraDraftDeterministico(): void
    {
        $ctx = $this->context([
            'creator_request' => ['source_mlb_id' => 'MLB123'],
            'creator_source_snapshot' => $this->envelope([
                'valid' => true,
                'duplicate' => false,
                'item' => ['id' => 'MLB123', 'title' => 'Peça'],
            ]),
        ]);
        $result = (new CriadorAgent())->run($ctx);
        $this->assertSame('success', $result->status());
        $this->assertSame('MLB123', $result->data()['draft']['source_mlb_id']);
        $this->assertFalse($result->data()['publish_allowed']);
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    public function testSourceComMlbDiferenteBloqueia(): void
    {
        $result = (new CriadorAgent())->run($this->context([
            'creator_request' => ['source_mlb_id' => 'MLB123'],
            'creator_source_snapshot' => $this->envelope([
                'valid' => true,
                'duplicate' => false,
                'item' => ['id' => 'MLB999'],
            ]),
        ]));
        $this->assertSame('failed', $result->status());
        $this->assertSame('invalid_creator_source_snapshot', $result->reason());
    }

    public function testEnvelopeDeOutraContaFalha(): void
    {
        $result = (new CriadorAgent())->run($this->context([
            'creator_request' => ['source_mlb_id' => 'MLB123'],
            'creator_source_snapshot' => $this->envelope([
                'valid' => true,
                'duplicate' => false,
                'item' => ['id' => 'MLB123'],
            ], 55),
        ], 10));
        $this->assertSame('failed', $result->status());
        $this->assertSame('invalid_creator_source_snapshot', $result->reason());
    }

    public function testRequestInvalidoBloqueia(): void
    {
        $result = (new CriadorAgent())->run($this->context([
            'creator_request' => ['source_mlb_id' => 'X'],
        ]));
        $this->assertSame('blocked', $result->status());
    }

    public function testDuplicateBloqueia(): void
    {
        $result = (new CriadorAgent())->run($this->context([
            'creator_request' => ['source_mlb_id' => 'MLB123'],
            'creator_source_snapshot' => $this->envelope([
                'valid' => true,
                'duplicate' => true,
                'item' => ['id' => 'MLB123'],
            ]),
        ]));
        $this->assertSame('blocked', $result->status());
        $this->assertSame('creator_request_blocked', $result->reason());
    }
}

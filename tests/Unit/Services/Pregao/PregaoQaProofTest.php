<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pregao;

use App\Services\Pregao\PregaoQaProof;
use App\Services\Pregao\PregaoQaWorkerProtocol;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Pregao\PregaoQaProof
 */
final class PregaoQaProofTest extends TestCase
{
    private const SECRET = 'unit-test-pregao-qa-signing-key-32b';

    private function proof(): PregaoQaProof
    {
        return new PregaoQaProof(self::SECRET);
    }

    private function runId(): string
    {
        return '11111111-1111-4111-8111-111111111111';
    }

    public function testConstructorRejectsShortSecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PregaoQaProof('short');
    }

    public function testSignAndVerifyManifestRoundTrip(): void
    {
        $base = [
            'account_id' => 1335,
            'created_at' => '2026-08-06T12:00:00-03:00',
            'expires_at' => '2026-08-06T13:00:00-03:00',
            'run_id' => $this->runId(),
            'user_id' => 7,
        ];
        $signed = $this->proof()->signManifest($base);

        $this->assertArrayHasKey('manifest_hash', $signed);
        $this->assertArrayHasKey('signature', $signed);
        $this->assertTrue($this->proof()->verifyManifest($signed));
    }

    public function testVerifyManifestRejectsTamperedSignature(): void
    {
        $signed = $this->proof()->signManifest([
            'account_id' => 1,
            'created_at' => '2026-08-06T12:00:00Z',
            'expires_at' => '2026-08-06T13:00:00Z',
            'run_id' => $this->runId(),
            'user_id' => 1,
        ]);
        $signed['signature'] = str_repeat('ab', 32);

        $this->assertFalse($this->proof()->verifyManifest($signed));
    }

    public function testSignAndProjectStatus(): void
    {
        $started = gmdate('c', time() - 30);
        $observed = gmdate('c', time() - 5);
        $status = [
            'cursor' => null,
            'manifest_hash' => str_repeat('a', 64),
            'observed_at' => $observed,
            'result' => 'running',
            'run_id' => $this->runId(),
            'running' => true,
            'screenshot_url' => null,
            'sequence' => 1,
            'started_at' => $started,
            'step' => 'dashboard',
            'stream_url' => '/qa/live/' . $this->runId(),
            'suite' => 'pregao-live',
            'test' => 'dashboard',
            'video_url' => null,
        ];

        $signed = $this->proof()->signStatus($status, 42);
        $this->assertTrue($this->proof()->verifyStatus($signed, 42));
        $this->assertFalse($this->proof()->verifyStatus($signed, 99));

        $projected = $this->proof()->projectStatus($signed, 42);
        $this->assertNotNull($projected);
        $this->assertTrue($projected['trusted']);
        $this->assertTrue($projected['running']);
        $this->assertSame('running', $projected['status']);
        $this->assertSame('/qa/live/' . $this->runId(), $projected['stream_url']);
        $this->assertSame(PregaoQaProof::STATUS_PROJECTION_KEYS, array_keys($projected));
    }

    public function testProjectStatusRejectsJavascriptStreamUrl(): void
    {
        $started = gmdate('c', time() - 30);
        $observed = gmdate('c', time() - 5);
        $status = [
            'cursor' => null,
            'manifest_hash' => str_repeat('b', 64),
            'observed_at' => $observed,
            'result' => 'running',
            'run_id' => $this->runId(),
            'running' => true,
            'screenshot_url' => null,
            'sequence' => 1,
            'started_at' => $started,
            'step' => 'dashboard',
            'stream_url' => 'javascript:alert(1)',
            'suite' => 'pregao-live',
            'test' => 'dashboard',
            'video_url' => null,
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->proof()->signStatus($status, 1);
    }
}

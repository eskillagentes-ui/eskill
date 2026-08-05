<?php

declare(strict_types=1);

namespace Tests\Unit\PregaoQa;

use App\Services\Pregao\PregaoQaProof;
use PHPUnit\Framework\TestCase;

final class PregaoQaProofFreshnessTest extends TestCase
{
    public function testRejectsTerminalEvidenceOlderThanTwentyFourHours(): void
    {
        $proof = new PregaoQaProof(str_repeat('f', 32));
        $status = $proof->signStatus($this->terminalStatus(new \DateTimeImmutable('-24 hours -5 minutes')), 1335);

        self::assertFalse($proof->verifyStatus($status, 1335));
        self::assertNull($proof->projectStatus($status, 1335));
    }

    public function testKeepsFreshTerminalHistoryVisibleWithoutActiveManifestLookup(): void
    {
        $proof = new PregaoQaProof(str_repeat('f', 32));
        $status = $proof->signStatus($this->terminalStatus(new \DateTimeImmutable('-23 hours')), 1335);

        self::assertTrue($proof->verifyStatus($status, 1335));
        self::assertSame('passed', $proof->projectStatus($status, 1335)['result']);
    }

    public function testRejectsEvidenceBeyondSixtySecondFutureSkew(): void
    {
        $proof = new PregaoQaProof(str_repeat('f', 32));
        $status = $proof->signStatus($this->terminalStatus(new \DateTimeImmutable('+2 minutes')), 1335);

        self::assertFalse($proof->verifyStatus($status, 1335));
        self::assertNull($proof->projectStatus($status, 1335));
    }

    /** @return array<string,mixed> */
    private function terminalStatus(\DateTimeImmutable $observedAt): array
    {
        return [
            'running' => false,
            'suite' => 'pregao-live',
            'test' => 'console_http',
            'result' => 'passed',
            'video_url' => null,
            'stream_url' => null,
            'run_id' => '123e4567-e89b-42d3-a456-426614174000',
            'sequence' => 5,
            'step' => 'console_http',
            'screenshot_url' => null,
            'observed_at' => $observedAt->format(DATE_ATOM),
            'started_at' => $observedAt->modify('-1 minute')->format(DATE_ATOM),
            'manifest_hash' => str_repeat('a', 64),
        ];
    }
}

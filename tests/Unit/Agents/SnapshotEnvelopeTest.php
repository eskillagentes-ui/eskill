<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use App\Services\Agents\SnapshotEnvelope;
use PHPUnit\Framework\TestCase;

/**
 * Cobertura do envelope SnapshotEnvelope (wrap/extract).
 *
 * Invariantes sob teste:
 *  - wrap() rejeita account_id invalido e correlation_id vazio
 *  - extract() verifica estrutura exata (chaves account_id/correlation_id/payload)
 *  - extract() valida tipos e exige match com o contexto (accountId/correlationId)
 *  - qualquer violacao retorna null (fail-closed)
 */
final class SnapshotEnvelopeTest extends TestCase
{
    public function testWrapBuildsCanonicalEnvelope(): void
    {
        $envelope = SnapshotEnvelope::wrap(1335, 'corr-xyz', ['x' => 1, 'y' => 'two']);
        $this->assertSame(1335, $envelope['account_id']);
        $this->assertSame('corr-xyz', $envelope['correlation_id']);
        $this->assertSame(['x' => 1, 'y' => 'two'], $envelope['payload']);
    }

    public function testWrapRejectsNonPositiveAccountId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SnapshotEnvelope::wrap(0, 'corr-xyz', []);
    }

    public function testWrapRejectsEmptyCorrelationId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SnapshotEnvelope::wrap(1335, '   ', []);
    }

    public function testExtractRoundTrip(): void
    {
        $envelope = SnapshotEnvelope::wrap(7, 'corr-aa', ['status' => 'ok']);
        $this->assertSame(
            ['status' => 'ok'],
            SnapshotEnvelope::extract($envelope, 7, 'corr-aa')
        );
    }

    public function testExtractRejectsAccountMismatch(): void
    {
        $envelope = SnapshotEnvelope::wrap(7, 'corr-aa', ['status' => 'ok']);
        $this->assertNull(SnapshotEnvelope::extract($envelope, 8, 'corr-aa'));
    }

    public function testExtractRejectsCorrelationMismatch(): void
    {
        $envelope = SnapshotEnvelope::wrap(7, 'corr-aa', ['status' => 'ok']);
        $this->assertNull(SnapshotEnvelope::extract($envelope, 7, 'corr-bb'));
    }

    public function testExtractRejectsExtraKeys(): void
    {
        $envelope = SnapshotEnvelope::wrap(7, 'corr-aa', ['status' => 'ok']);
        $tampered = $envelope + ['intruder' => 'value'];
        $this->assertNull(SnapshotEnvelope::extract($tampered, 7, 'corr-aa'));
    }

    public function testExtractRejectsNonArray(): void
    {
        $this->assertNull(SnapshotEnvelope::extract('not an envelope', 7, 'corr-aa'));
        $this->assertNull(SnapshotEnvelope::extract(null, 7, 'corr-aa'));
        $this->assertNull(SnapshotEnvelope::extract(123, 7, 'corr-aa'));
    }

    public function testExtractRejectsNonPositiveAccountIdInEnvelope(): void
    {
        $bad = ['account_id' => 0, 'correlation_id' => 'corr-aa', 'payload' => []];
        $this->assertNull(SnapshotEnvelope::extract($bad, 0, 'corr-aa'));
    }

    public function testExtractRejectsBlankCorrelationIdInEnvelope(): void
    {
        $bad = ['account_id' => 7, 'correlation_id' => '', 'payload' => []];
        $this->assertNull(SnapshotEnvelope::extract($bad, 7, ''));
    }

    public function testExtractRejectsNonArrayPayload(): void
    {
        $bad = ['account_id' => 7, 'correlation_id' => 'corr-aa', 'payload' => 'string-not-array'];
        $this->assertNull(SnapshotEnvelope::extract($bad, 7, 'corr-aa'));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Pregao;

final class PregaoQaStatusProducer
{
    private PregaoEmitService $emitter;
    private PregaoQaProof $proof;
    private PregaoQaRunService $runs;

    public function __construct(
        PregaoEmitService $emitter,
        PregaoQaProof $proof,
        PregaoQaRunService $runs
    ) {
        $this->emitter = $emitter;
        $this->proof = $proof;
        $this->runs = $runs;
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $protocol
     * @return array<string,mixed>
     */
    public function emit(array $manifest, array $protocol): array
    {
        $expiresAt = strtotime((string) ($manifest['expires_at'] ?? ''));
        $observedAt = strtotime((string) ($protocol['observed_at'] ?? ''));
        if (!$this->proof->verifyManifest($manifest)
            || $expiresAt === false
            || $observedAt === false
            || $observedAt > $expiresAt
            || ($protocol['run_id'] ?? null) !== $manifest['run_id']
        ) {
            throw new \InvalidArgumentException('Manifesto QA inválido ou expirado');
        }
        if (!$this->runs->protocolMatchesPersistedState($manifest, $protocol)) {
            throw new \InvalidArgumentException('Protocolo QA sem progressão durável correspondente');
        }
        $validated = $protocol;
        $frameUrl = $validated['screenshot'] === 'latest.png'
            ? '/qa/frame/' . $manifest['run_id']
            : null;
        $payload = $this->proof->signStatus([
            'running' => $validated['result'] === 'running',
            'suite' => 'pregao-live',
            'test' => $validated['step'],
            'result' => $validated['result'],
            'video_url' => null,
            'stream_url' => $frameUrl === null ? null : '/qa/live/' . $manifest['run_id'],
            'run_id' => $manifest['run_id'],
            'sequence' => $validated['sequence'],
            'step' => $validated['step'],
            'screenshot_url' => $frameUrl,
            'observed_at' => $validated['observed_at'],
            'started_at' => $manifest['created_at'],
            'manifest_hash' => $manifest['manifest_hash'],
        ], $manifest['account_id']);
        $receipt = $this->runs->receiptForStatus($payload, (int) $manifest['account_id']);
        if ($receipt !== null) {
            return $this->eventFromReceipt($payload, (int) $manifest['account_id'], $receipt);
        }
        $emitted = $this->emitter->emitTrustedQaStatusWithReceipt(
            $payload,
            (int) $manifest['account_id'],
            $this->proof
        );
        if (!$this->runs->confirmEvidence($manifest, $payload, $emitted['event_id'], $emitted['event']['ts'])) {
            throw new \RuntimeException('Falha ao confirmar evidência QA');
        }
        return $emitted['event'];
    }

    /** @param array<string,mixed> $manifest @param array<string,mixed> $state */
    public function repairEvidence(array $manifest, array $state): bool
    {
        if (!in_array($state['status'] ?? null, ['passed', 'failed', 'blocked'], true)) {
            return false;
        }
        $screenshotUrl = $state['screenshot_url'] ?? null;
        if ($screenshotUrl !== null && $screenshotUrl !== '/qa/frame/' . ($manifest['run_id'] ?? '')) {
            return false;
        }
        $protocol = [
            'run_id' => $state['run_id'] ?? null,
            'sequence' => $state['sequence'] ?? null,
            'step' => $state['step'] ?? null,
            'result' => $state['status'],
            'screenshot' => $screenshotUrl === null ? null : 'latest.png',
            'cursor' => $state['cursor'] ?? null,
            'observed_at' => $state['observed_at'] ?? null,
        ];
        try {
            $this->emit($manifest, $protocol);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $receipt
     * @return array<string,mixed>
     */
    private function eventFromReceipt(array $payload, int $accountId, array $receipt): array
    {
        return [
            'v' => PregaoEmitService::VERSION,
            'type' => 'qa.status',
            'ts' => $receipt['event_ts'],
            'payload' => $payload,
            'source' => 'live',
            'account_id' => $accountId,
        ];
    }
}

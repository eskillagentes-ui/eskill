<?php

declare(strict_types=1);

namespace App\Services\Pregao;

final class PregaoQaStatusProducer
{
    private PregaoEmitService $emitter;
    private PregaoQaProof $proof;

    public function __construct(PregaoEmitService $emitter, PregaoQaProof $proof)
    {
        $this->emitter = $emitter;
        $this->proof = $proof;
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $protocol
     * @return array<string,mixed>
     */
    public function emit(array $manifest, array $protocol): array
    {
        if (!$this->proof->verifyManifest($manifest)
            || strtotime((string) $manifest['expires_at']) < time()
            || ($protocol['run_id'] ?? null) !== $manifest['run_id']
        ) {
            throw new \InvalidArgumentException('Manifesto QA inválido ou expirado');
        }
        $encoded = json_encode($protocol, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $validated = PregaoQaWorkerProtocol::decode(
            $encoded,
            $manifest['run_id'],
            is_int($protocol['sequence'] ?? null) ? $protocol['sequence'] - 1 : -2
        );
        if ($validated === null) {
            throw new \InvalidArgumentException('Protocolo QA inválido');
        }
        $frameUrl = $validated['screenshot'] === 'latest.png'
            ? '/qa/frame/' . $manifest['run_id']
            : null;
        $payload = $this->proof->signStatus([
            'running' => $validated['result'] === 'running',
            'suite' => 'pregao-live',
            'test' => $validated['step'],
            'result' => $validated['result'],
            'video_url' => null,
            'stream_url' => $frameUrl,
            'run_id' => $manifest['run_id'],
            'sequence' => $validated['sequence'],
            'step' => $validated['step'],
            'screenshot_url' => $frameUrl,
            'observed_at' => $validated['observed_at'],
            'manifest_hash' => $manifest['manifest_hash'],
        ], $manifest['account_id']);
        return $this->emitter->emitTrustedQaStatus($payload, $manifest['account_id'], $this->proof);
    }
}

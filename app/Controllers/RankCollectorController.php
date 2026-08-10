<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Rank\RankTrackerService;
use Throwable;

/**
 * Endpoints do coletor local residencial (T1b).
 * Autenticação: chave dedicada RANK_COLLECTOR_KEY + HMAC no ingest.
 * Nunca recebe nem transmite tokens ML.
 */
final class RankCollectorController extends BaseController
{
    public function assignments(): void
    {
        $svc = new RankTrackerService();
        if (!$svc->isLocalCollectorEnabled()) {
            $this->json(['error' => 'rank_collector_disabled', 'message' => 'RANK_COLLECTOR_LOCAL=false'], 503);
            return;
        }
        if (!$this->authorizeCollectorKey()) {
            $this->json(['error' => 'unauthorized', 'message' => 'chave inválida'], 401);
            return;
        }

        $accountId = (int) ($this->request->get('account_id')
            ?? ($_ENV['PREGAO_ACCOUNT_ID'] ?? getenv('PREGAO_ACCOUNT_ID') ?: 1335));
        $max = (int) ($this->request->get('max') ?? 30);
        $max = max(1, min(30, $max));

        try {
            $assignments = $svc->listAssignments($accountId, $max);
            $this->json([
                'data' => [
                    'account_id' => $accountId,
                    'day' => (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d'),
                    'max' => $max,
                    'assignments' => $assignments,
                    'rate_hint' => '1 req / 2s no api.mercadolibre.com (sem token)',
                ],
                'error' => null,
                'message' => 'ok',
            ]);
        } catch (Throwable $e) {
            $this->json(['error' => 'server_error', 'message' => 'falha ao listar assignments'], 500);
        }
    }

    public function ingest(): void
    {
        $svc = new RankTrackerService();
        if (!$svc->isLocalCollectorEnabled()) {
            $this->json(['error' => 'rank_collector_disabled', 'message' => 'RANK_COLLECTOR_LOCAL=false'], 503);
            return;
        }
        if (!$this->authorizeCollectorKey()) {
            $this->json(['error' => 'unauthorized', 'message' => 'chave inválida'], 401);
            return;
        }

        $raw = file_get_contents('php://input') ?: '';
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $this->json(['error' => 'invalid_json', 'message' => 'body JSON inválido'], 400);
            return;
        }

        $sig = (string) ($this->request->header('X-Rank-Signature')
            ?? ($_SERVER['HTTP_X_RANK_SIGNATURE'] ?? ''));
        if (!$this->verifyHmac($raw, $sig)) {
            $this->json(['error' => 'invalid_signature', 'message' => 'HMAC inválido'], 401);
            return;
        }

        try {
            $result = $svc->ingestFromCollector($payload);
            $code = ($result['ok'] ?? false) ? 200 : 422;
            $this->json([
                'data' => $result,
                'error' => ($result['ok'] ?? false) ? null : (string) ($result['error'] ?? 'ingest_failed'),
                'message' => (string) ($result['message'] ?? ''),
            ], $code);
        } catch (Throwable $e) {
            $this->json(['error' => 'server_error', 'message' => 'falha no ingest'], 500);
        }
    }

    private function authorizeCollectorKey(): bool
    {
        $expected = (string) ($_ENV['RANK_COLLECTOR_KEY'] ?? getenv('RANK_COLLECTOR_KEY') ?: '');
        if ($expected === '' || strlen($expected) < 16) {
            return false;
        }
        $provided = (string) ($this->request->get('key')
            ?? $this->request->header('X-Rank-Key')
            ?? ($_SERVER['HTTP_X_RANK_KEY'] ?? ''));
        return $provided !== '' && hash_equals($expected, $provided);
    }

    private function verifyHmac(string $rawBody, string $signature): bool
    {
        $secret = (string) ($_ENV['RANK_COLLECTOR_HMAC_SECRET'] ?? getenv('RANK_COLLECTOR_HMAC_SECRET')
            ?: ($_ENV['RANK_COLLECTOR_KEY'] ?? getenv('RANK_COLLECTOR_KEY') ?: ''));
        if ($secret === '' || $signature === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        $sig = preg_replace('#^sha256=#i', '', trim($signature)) ?? $signature;
        return hash_equals($expected, $sig);
    }
}

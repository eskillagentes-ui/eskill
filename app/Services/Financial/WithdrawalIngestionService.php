<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Database;
use App\Helpers\Log;
use App\Services\MercadoLivreClient;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Ingestão de saques (withdrawals) da carteira MP — PATCH 3.
 *
 * Fontes tentadas (nessa ordem), todas somente leitura:
 *  1) GET /v1/account/bank_report/list (api.mercadopago.com)
 *  2) GET /v1/account/movements/search (api.mercadopago.com)
 *  3) GET /users/{id}/mercadopago_account/movements (legacy ML)
 *
 * Se TODAS falharem por 403/404 (escopo OAuth insuficiente), o serviço
 * NÃO fabrica saques — retorna api_blocked=true com motivo rastreável.
 * A conciliação de caixa (released vs withdrawn no ledger) continua disponível.
 */
final class WithdrawalIngestionService
{
    private PDO $db;
    private FinancialEventNormalizer $normalizer;
    private FinancialLedgerService $ledger;
    private ?MercadoLivreClient $client = null;

    public function __construct(
        private readonly int $accountId,
        ?PDO $db = null,
        ?FinancialEventNormalizer $normalizer = null,
        ?FinancialLedgerService $ledger = null,
        ?MercadoLivreClient $client = null,
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->normalizer = $normalizer ?? new FinancialEventNormalizer();
        $this->ledger = $ledger ?? new FinancialLedgerService($this->db);
        $this->client = $client;
    }

    /**
     * @param array{dry_run?: bool, limit?: int} $options
     * @return array<string, mixed>
     */
    public function backfillWithdrawals(string $fromDate, string $toDate, array $options = []): array
    {
        $dryRun = (bool)($options['dry_run'] ?? false);
        $limit = max(0, (int)($options['limit'] ?? 100));

        $stats = [
            'account_id' => $this->accountId,
            'from' => $fromDate,
            'to' => $toDate,
            'api_blocked' => false,
            'api_block_reason' => null,
            'movements_scanned' => 0,
            'entries_created' => 0,
            'entries_updated' => 0,
            'entries_unchanged' => 0,
            'skipped_no_amount' => 0,
            'errors' => [],
            'dry_run' => $dryRun,
            'cash_reconciliation' => null,
        ];

        $fetch = $this->fetchWithdrawalMovements($limit);
        if ($fetch['blocked']) {
            $stats['api_blocked'] = true;
            $stats['api_block_reason'] = $fetch['reason'];
            $stats['cash_reconciliation'] = $this->reconcileCash($fromDate, $toDate);
            return $stats;
        }

        $fromTs = strtotime($fromDate . ' 00:00:00') ?: 0;
        $toTs = strtotime($toDate . ' 23:59:59') ?: PHP_INT_MAX;
        $entries = [];

        foreach ($fetch['movements'] as $movement) {
            if (!is_array($movement)) {
                continue;
            }
            $stats['movements_scanned']++;
            $occurredAt = $this->parseDate($movement['date_created'] ?? $movement['date'] ?? null)
                ?? new DateTimeImmutable('now');
            $ts = $occurredAt->getTimestamp();
            if ($ts < $fromTs || $ts > $toTs) {
                continue;
            }

            $entry = $this->normalizer->fromWithdrawal($this->accountId, $movement, $occurredAt);
            if ($entry === null) {
                $stats['skipped_no_amount']++;
                continue;
            }
            $entries[] = $entry;
        }

        if ($dryRun) {
            $stats['entries_unchanged'] = count($entries);
        } else {
            $upsert = $this->ledger->upsertMany($entries);
            $stats['entries_created'] = $upsert['created'];
            $stats['entries_updated'] = $upsert['updated'];
            $stats['entries_unchanged'] = $upsert['unchanged'];
        }

        $stats['cash_reconciliation'] = $this->reconcileCash($fromDate, $toDate);
        return $stats;
    }

    /**
     * Compara liberado vs sacado no ledger (independente da API de saques).
     *
     * @return array<string, mixed>
     */
    public function reconcileCash(string $fromDate, string $toDate): array
    {
        $summary = $this->ledger->summarizePeriod($this->accountId, $fromDate, $toDate);
        $released = (float)$summary['released_amount'];
        $pending = (float)$summary['pending_release_amount'];
        $withdrawn = (float)$summary['withdrawn_amount'];
        $hold = (float)$summary['hold_amount'];

        return [
            'released_amount' => $released,
            'pending_release_amount' => $pending,
            'withdrawn_amount' => $withdrawn,
            'hold_amount' => $hold,
            // Liberado ainda não sacado (aproximação de saldo disponível interno ao ledger)
            'released_not_withdrawn' => round(max(0.0, $released - $withdrawn), 2),
            'entries_count' => (int)$summary['entries_count'],
            'note' => $withdrawn <= 0.0
                ? 'Sem saques no ledger no período (API pode estar bloqueada ou não houve saque).'
                : null,
        ];
    }

    /**
     * @return array{blocked: bool, reason: ?string, movements: list<array<string, mixed>>}
     */
    private function fetchWithdrawalMovements(int $limit): array
    {
        $mlClient = $this->getClient();
        $sellerId = $mlClient->getSellerId();
        $attempts = [];
        $params = ['limit' => $limit, 'offset' => 0];
        if ($sellerId) {
            $params['user_id'] = $sellerId;
        }

        // 1) MP bank_report/list (host correto: api.mercadopago.com)
        try {
            $data = $this->mpGet('/v1/account/bank_report/list', $params);
            if (!isset($data['error']) && is_array($data)) {
                $results = $data['results'] ?? (array_is_list($data) ? $data : []);
                if (is_array($results) && $results !== []) {
                    return ['blocked' => false, 'reason' => null, 'movements' => array_values(array_filter($results, 'is_array'))];
                }
            }
            $attempts[] = 'mp:bank_report/list: ' . (string)($data['message'] ?? $data['error'] ?? 'vazio/erro');
        } catch (Throwable $e) {
            $attempts[] = 'mp:bank_report/list: ' . $e->getMessage();
        }

        // 2) MP movements/search (documentado; pode 404 conforme escopo)
        try {
            $data = $this->mpGet('/v1/account/movements/search', $params);
            if (!isset($data['error']) && is_array($data)) {
                $results = $data['results'] ?? (array_is_list($data) ? $data : []);
                if (is_array($results) && $results !== []) {
                    return ['blocked' => false, 'reason' => null, 'movements' => array_values(array_filter($results, 'is_array'))];
                }
            }
            $attempts[] = 'mp:movements/search: ' . (string)($data['message'] ?? $data['error'] ?? 'vazio/erro');
        } catch (Throwable $e) {
            $attempts[] = 'mp:movements/search: ' . $e->getMessage();
        }

        // 3) Legacy ML mercadopago_account/movements
        if ($sellerId) {
            try {
                $query = http_build_query(['limit' => $limit, 'offset' => 0]);
                $data = $mlClient->get("/users/{$sellerId}/mercadopago_account/movements?{$query}");
                if (isset($data['body']) && is_array($data['body'])) {
                    $data = $data['body'];
                }
                if (!isset($data['error']) && is_array($data)) {
                    $results = $data['results'] ?? (isset($data[0]) ? $data : []);
                    if (is_array($results) && $results !== []) {
                        return ['blocked' => false, 'reason' => null, 'movements' => array_values($results)];
                    }
                }
                $attempts[] = 'ml:mercadopago_account/movements: ' . (string)($data['message'] ?? $data['error'] ?? 'vazio/erro');
            } catch (Throwable $e) {
                $attempts[] = 'ml:mercadopago_account/movements: ' . $e->getMessage();
            }
        }

        $reason = 'Endpoints de saque/extrato MP indisponíveis com o OAuth atual. '
            . 'Tentativas: ' . implode(' | ', $attempts)
            . '. Configure token MP com escopo de carteira (ean_settings.mp_access_token) para desbloquear.';

        Log::warning('WithdrawalIngestionService: API bloqueada', [
            'account_id' => $this->accountId,
            'attempts' => $attempts,
        ]);

        return ['blocked' => true, 'reason' => $reason, 'movements' => []];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function mpGet(string $path, array $params = []): array
    {
        $token = $this->getClient()->getAccessToken();
        $http = new \GuzzleHttp\Client([
            'base_uri' => 'https://api.mercadopago.com',
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . ($token ?? ''),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);

        try {
            $response = $http->request('GET', $path, ['query' => $params]);
            $data = json_decode($response->getBody()->getContents(), true);
            return is_array($data) ? $data : [];
        } catch (Throwable $e) {
            $status = null;
            $decoded = null;
            if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                $resp = $e->getResponse();
                $status = $resp->getStatusCode();
                $decoded = json_decode((string) $resp->getBody(), true);
            }
            if (is_array($decoded)) {
                return array_merge($decoded, [
                    'error' => (string) ($decoded['error'] ?? 'http_error'),
                    'message' => (string) ($decoded['message'] ?? $e->getMessage()),
                    'status' => $status,
                ]);
            }
            return ['error' => 'http_error', 'message' => $e->getMessage(), 'status' => $status];
        }
    }


    private function getClient(): MercadoLivreClient
    {
        return $this->client ??= new MercadoLivreClient($this->accountId);
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable(is_string($value) ? $value : (string)$value);
        } catch (Throwable) {
            return null;
        }
    }
}

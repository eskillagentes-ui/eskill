<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\MercadoLivreClient;
use App\Database;
use PDO;

class ClaimsService
{
    private MercadoLivreClient $client;
    private ?PDO $db;
    private ?int $accountId;

    public function __construct(
        ?int $accountId = null,
        ?MercadoLivreClient $client = null,
        ?PDO $db = null,
        bool $skipDbAutoConnect = false
    ) {
        $this->accountId = $accountId;
        $this->client = $client ?? new MercadoLivreClient($accountId);

        if ($db !== null) {
            $this->db = $db;
        } elseif (!$skipDbAutoConnect) {
            $this->db = Database::getInstance();
        } else {
            $this->db = null;
        }
    }

    /**
     * Get claims with filters
     */
    public function getClaims(string $type = 'to_seller', int $limit = 50, int $offset = 0): array
    {
        try {
            $params = [
                'limit' => $limit,
                'offset' => $offset,
                'status' => 'opened',
            ];

            // Doc ML: listagem é /claims/search (sem /search → 404 resource not found).
            $sellerId = $this->resolveSellerId();
            if ($sellerId !== null) {
                $params['players.user_id'] = $sellerId;
                $params['players.role'] = 'respondent';
            }

            $endpoint = '/post-purchase/v1/claims/search';
            $response = $this->client->get($endpoint, $params);

            if (isset($response['error'])) {
                return ['error' => $response['message'] ?? 'Failed to fetch claims'];
            }

            return $response;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function resolveSellerId(): ?string
    {
        try {
            $me = $this->client->get('/users/me');
            if (isset($me['id']) && $me['id'] !== '' && $me['id'] !== null) {
                return (string) $me['id'];
            }
        } catch (\Throwable) {
            // segue sem filtro de player
        }
        return null;
    }


    /**
     * Get single claim details
     */
    public function getClaim(string $claimId): array
    {
        try {
            // Doc ML: detalhe é /post-purchase/v1/claims/{id} (/v1/claims/{id} → 404).
            $endpoint = '/post-purchase/v1/claims/' . rawurlencode($claimId);
            $response = $this->client->get($endpoint);

            if (isset($response['error'])) {
                return ['error' => $response['message'] ?? $response['error'] ?? 'Failed to fetch claim'];
            }

            if (isset($response['id'])) {
                $this->syncClaimToDatabase($response);
            }

            return $response;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }


    /**
     * Sync single claim to local database
     */
    public function syncClaim(string $claimId): bool
    {
        $claim = $this->getClaim($claimId);
        if (isset($claim['id'])) {
            return true; // Saved in getClaim
        }
        return false;
    }

    /**
     * Send message to claim
     */
    public function sendMessage(string $claimId, string $message, array $attachments = []): array
    {
        try {
            $payload = [
                'receiver_role' => 'complainant',
                'message' => $message,
                'attachments' => $attachments
            ];

            $response = $this->client->post(
                '/post-purchase/v1/claims/' . rawurlencode($claimId) . '/messages',
                $payload
            );

            if (isset($response['error'])) {
                return ['error' => $response['message'] ?? $response['error'] ?? 'Failed to send message'];
            }

            return $response;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function syncClaimToDatabase(array $claim): void
    {
        if ($this->db === null) {
            return;
        }

        $sql = "
            INSERT INTO ml_claims (
                id, order_id, account_id, type, status, stage, reason,
                amount, currency_id, date_created, last_updated, raw_data
            ) VALUES (
                :id, :order_id, :account_id, :type, :status, :stage, :reason,
                :amount, :currency_id, :date_created, :last_updated, :raw_data
            )
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                stage = VALUES(stage),
                amount = VALUES(amount),
                last_updated = VALUES(last_updated),
                raw_data = VALUES(raw_data),
                updated_at = CURRENT_TIMESTAMP
        ";

        try {
            $stmt = $this->db->prepare($sql);

            $stmt->execute([
                ':id' => $claim['id'],
                ':order_id' => $claim['order_id'],
                ':account_id' => $this->accountId,
                ':type' => $claim['type'],
                ':status' => $claim['status'],
                ':stage' => $claim['stage'],
                ':reason' => $claim['reason'],
                ':amount' => $claim['amount_claimed']['amount'],
                ':currency_id' => $claim['amount_claimed']['currency_id'],
                ':date_created' => date('Y-m-d H:i:s', strtotime($claim['date_created'])),
                ':last_updated' => date('Y-m-d H:i:s', strtotime($claim['last_updated'])),
                ':raw_data' => json_encode($claim)
            ]);
        } catch (\Exception $e) {
            log_error('Falha ao sincronizar reclamação no banco', [
                'service' => 'ClaimsService',
                'error' => $e->getMessage(),
            ]);
        }
    }
}

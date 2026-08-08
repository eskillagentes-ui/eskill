<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;
use Exception;
use GuzzleHttp\Client;

use App\Services\QueueService;

class WhatsAppService
{
    private PDO $db;
    private ?array $settings = null;
    private int $userId;
    private ?QueueService $queueService = null;

    public function __construct(int $userId)
    {
        $this->db = Database::getInstance();
        $this->userId = $userId;
        $this->loadSettings();
    }

    private function getQueue(): QueueService
    {
        if (!$this->queueService) {
            $this->queueService = new QueueService();
        }
        return $this->queueService;
    }

    /**
     * Carrega configurações do usuário
     */
    private function loadSettings(): void
    {
        $stmt = $this->db->prepare("SELECT * FROM whatsapp_settings WHERE user_id = ?");
        $stmt->execute([$this->userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $extra = json_decode($row['settings'] ?? '{}', true) ?: [];
            $this->settings = [
                'provider' => $extra['provider'] ?? 'simulator',
                'api_url' => $extra['api_url'] ?? null,
                'api_key' => $row['api_key'],
                'instance_id' => $row['instance_id'],
                'from_number' => $row['phone_number'],
                'is_active' => $row['status'] === 'active',
                'api_secret' => $extra['api_secret'] ?? null,
            ];
        } else {
            $this->settings = null;
        }
    }

    /**
     * Salva ou atualiza configurações
     */
    public function saveSettings(array $data): bool
    {
        // Preparar JSON de configurações extras
        $extraSettings = [
            'provider' => $data['provider'],
            'api_url' => $data['api_url'] ?? null,
            'api_secret' => $data['api_secret'] ?? null,
        ];

        $status = ($data['is_active'] ?? false) ? 'active' : 'inactive';

        // Check if exists
        $stmt = $this->db->prepare("SELECT id FROM whatsapp_settings WHERE user_id = ?");
        $stmt->execute([$this->userId]);
        $exists = $stmt->fetch();

        if ($exists) {
            $stmt = $this->db->prepare("
                UPDATE whatsapp_settings
                SET api_key = ?, instance_id = ?, phone_number = ?, status = ?, settings = ?, updated_at = NOW()
                WHERE user_id = ?
            ");
            return $stmt->execute([
                $data['api_key'] ?? null,
                $data['instance_id'] ?? null,
                $data['from_number'] ?? null,
                $status,
                json_encode($extraSettings),
                $this->userId
            ]);
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO whatsapp_settings
                (user_id, api_key, instance_id, phone_number, status, settings, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            return $stmt->execute([
                $this->userId,
                $data['api_key'] ?? null,
                $data['instance_id'] ?? null,
                $data['from_number'] ?? null,
                $status,
                json_encode($extraSettings)
            ]);
        }
    }

    /**
     * Alias compatível com o contrato esperado pela suíte de testes.
     */
    public function sendMessage(string $to, string $message, bool $shouldQueue = false): array
    {
        return $this->send($to, $message, $shouldQueue);
    }

    public function isConfigured(): bool
    {
        return $this->settings !== null
            && ($this->settings['is_active'] ?? false) === true;
    }

    /**
     * Envia mensagem WhatsApp
     *
     * @param bool $shouldQueue Se true, envia para fila Redis
     */
    public function send(string $to, string $message, bool $shouldQueue = false): array
    {
        if ($shouldQueue) {
            try {
                $jobId = $this->getQueue()->push('whatsapp_message', [
                    'user_id' => $this->userId,
                    'to' => $to,
                    'message' => $message
                ]);

                // Log as 'queued'
                $this->logMessage($to, $message, 'queued');

                return ['success' => true, 'queued' => true, 'job_id' => $jobId];
            } catch (Exception $e) {
                // Fallback to sync if queue fails
                log_warning('Falha na fila do WhatsApp, usando fallback síncrono', [
                    'service' => 'WhatsAppService',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!$this->settings || !$this->settings['is_active']) {
            return ['success' => false, 'error' => 'WhatsApp não configurado ou inativo'];
        }

        // Limpar número (apenas dígitos)
        $to = preg_replace('/[^0-9]/', '', $to);

        // Log inicial
        $logId = $this->logMessage($to, $message, 'pending');

        try {
            $response = match ($this->settings['provider']) {
                'twilio' => $this->sendViaTwilio($to, $message),
                'wppconnect' => $this->sendViaWppConnect($to, $message),
                'simulator' => ['success' => true, 'id' => 'sim_' . uniqid()],
                default => throw new Exception('Provedor desconhecido')
            };

            $status = $response['success'] ? 'sent' : 'failed';
            $providerResponse = json_encode($response);

            $this->updateLog($logId, $status, $providerResponse);

            return $response;
        } catch (Exception $e) {
            $this->updateLog($logId, 'failed', '', $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Envia via Twilio
     */
    private function sendViaTwilio(string $to, string $message): array
    {
        $sid = $this->settings['api_key'];
        $token = $this->settings['api_secret'];
        $from = $this->settings['from_number'];

        if (!$sid || !$token || !$from) {
            throw new Exception('Configurações do Twilio incompletas');
        }

        $client = new Client(['timeout' => 15.0]);
        $url = "https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json";

        // Adicionar + se não tiver
        if (!str_starts_with($to, '+')) {
            $to = '+' . $to;
        }

        try {
            $response = $client->post($url, [
                'auth' => [$sid, $token],
                'form_params' => [
                    'To' => $to,
                    'From' => $from,
                    'Body' => $message
                ]
            ]);

            $body = json_decode((string)$response->getBody(), true);
            return ['success' => true, 'id' => $body['sid']];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Envia via WppConnect (Exemplo genérico)
     */
    private function sendViaWppConnect(string $to, string $message): array
    {
        $url = $this->settings['api_url'];
        $token = $this->settings['api_secret']; // Bearer token se necessário
        $session = $this->settings['api_key']; // Session name se necessário

        if (!$url) {
            throw new Exception('URL da API não configurada');
        }

        $client = new Client(['timeout' => 15.0]);

        try {
            $response = $client->post($url . '/api/' . $session . '/send-message', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'phone' => $to,
                    'message' => $message
                ]
            ]);

            $body = json_decode((string)$response->getBody(), true);
            return ['success' => true, 'response' => $body];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function logMessage(string $to, string $message, string $status): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO whatsapp_logs
            (user_id, phone_to, message, status, message_type, created_at)
            VALUES (?, ?, ?, ?, 'sent', NOW())
        ");
        $stmt->execute([$this->userId, $to, $message, $status]);
        return (int)$this->db->lastInsertId();
    }

    private function updateLog(int $id, string $status, string $response, ?string $errorMessage = null): void
    {
        $metadata = ['response' => $response];
        $stmt = $this->db->prepare("
            UPDATE whatsapp_logs
            SET status = ?, metadata = ?, error_message = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $status,
            json_encode($metadata),
            $errorMessage,
            $id
        ]);
    }

    public function getSettings(): ?array
    {
        return $this->settings;
    }

    public function getLogs(int $limit = 50): array
    {
        $limitSql = max(1, min(200, (int)$limit));
        $stmt = $this->db->prepare("
            SELECT * FROM whatsapp_logs
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT {$limitSql}
        ");
        $stmt->execute([$this->userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

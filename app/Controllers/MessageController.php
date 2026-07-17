<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use PDO;
use App\Helpers\SessionHelper;

class MessageController extends BaseController
{
    private $db;

    public function __construct()
    {
        parent::__construct();
        $this->db = Database::getInstance();
    }

    /**
     * List Templates
     * GET /api/messages/templates
     */
    public function index(): void
    {
        $this->requireUserId();
        $context = $this->requireAuthorizedAccount('messages.read');
        if ($context === null) {
            return;
        }
        $accountId = $context->accountId();

        $stmt = $this->db->prepare("SELECT * FROM message_templates WHERE account_id = :aid ORDER BY event_trigger");
        $stmt->execute(['aid' => $accountId]);
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'templates' => $templates]);
    }

    /**
     * Create Template
     * POST /api/messages/templates
     */
    public function store(): void
    {
        $this->requireUserId();
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = [];
        }
        $explicit = isset($data['account_id']) ? (int) $data['account_id'] : 0;
        try {
            $context = (new \App\Security\AccountContextResolver())->authorizeForCurrentActor(
                'messages.write',
                $explicit > 0 ? $explicit : null
            );
            $accountId = $context->accountId();
        } catch (\App\Security\AccountAccessException $e) {
            http_response_code($e->httpStatus());
            echo json_encode(['error' => $e->getMessage(), 'error_code' => $e->errorCode()]);
            return;
        }

        if (empty($data['name']) || empty($data['event_trigger']) || empty($data['content'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields']);
            return;
        }

        // Validate trigger uniqueness per account? Maybe allow multiple but only one active?
        // For simplicity, enforce 1 trigger per type for now in UI logic, but here assume data is valid.

        try {
            $stmt = $this->db->prepare("
                INSERT INTO message_templates (account_id, name, event_trigger, content, is_active, created_at)
                VALUES (:aid, :name, :trigger, :content, :active, NOW())
            ");
            $stmt->execute([
                'aid' => $accountId,
                'name' => $data['name'],
                'trigger' => $data['event_trigger'],
                'content' => $data['content'],
                'active' => $data['is_active'] ?? 1
            ]);

            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'id' => $this->db->lastInsertId()]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Update Template
     * PUT /api/messages/templates/{id}
     */
    public function update(string $id): void
    {
        $this->requireUserId();
        $data = json_decode(file_get_contents('php://input'), true);

        $fields = [];
        $params = [];

        if (isset($data['name'])) {
            $fields[] = "name = ?";
            $params[] = $data['name'];
        }
        if (isset($data['content'])) {
            $fields[] = "content = ?";
            $params[] = $data['content'];
        }
        if (isset($data['is_active'])) {
            $fields[] = "is_active = ?";
            $params[] = $data['is_active'];
        }
        if (isset($data['event_trigger'])) {
            $fields[] = "event_trigger = ?";
            $params[] = $data['event_trigger'];
        }

        if (empty($fields)) {
            echo json_encode(['success' => true]); // No update
            return;
        }

        $params[] = $id;

        try {
            $stmt = $this->db->prepare("UPDATE message_templates SET " . implode(', ', $fields) . " WHERE id = ?");
            $stmt->execute($params);

            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Delete Template
     * DELETE /api/messages/templates/{id}
     */
    public function delete(string $id): void
    {
        $this->requireUserId();
        try {
            $stmt = $this->db->prepare("DELETE FROM message_templates WHERE id = ?");
            $stmt->execute([$id]);

            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}

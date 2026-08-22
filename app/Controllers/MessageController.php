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
     * Conta da sessão apenas. Query/body account_id (ex.: 1336 na sessão 1335) é ignorado.
     */
    protected function scopedAccountId(): ?int
    {
        $accountId = SessionHelper::getActiveAccountId();
        return ($accountId !== null && $accountId > 0) ? $accountId : null;
    }

    /**
     * List Templates
     * GET /api/messages/templates
     */
    public function index(): void
    {
        $this->requireUserId();
        $accountId = $this->scopedAccountId();

        if (!$accountId) {
            http_response_code(400);
            echo json_encode(['error' => 'Account ID required']);
            return;
        }

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
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $accountId = $this->scopedAccountId();

        if (empty($accountId) || empty($data['name']) || empty($data['event_trigger']) || empty($data['content'])) {
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
        $accountId = $this->scopedAccountId();
        if (!$accountId) {
            http_response_code(400);
            echo json_encode(['error' => 'Account ID required']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true) ?: [];

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
        $params[] = $accountId;

        try {
            $stmt = $this->db->prepare(
                "UPDATE message_templates SET " . implode(', ', $fields) . " WHERE id = ? AND account_id = ?"
            );
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
        $accountId = $this->scopedAccountId();
        if (!$accountId) {
            http_response_code(400);
            echo json_encode(['error' => 'Account ID required']);
            return;
        }

        try {
            $stmt = $this->db->prepare("DELETE FROM message_templates WHERE id = ? AND account_id = ?");
            $stmt->execute([$id, $accountId]);

            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}

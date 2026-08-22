<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Helpers\SessionHelper;
use App\Services\RealTimeNotificationService;
use App\Services\UserService;

/**
 * Controller para Notificações em Tempo Real
 * Gerencia polling, configurações e áudio de notificações
 */
class RealTimeNotificationController
{
    private RealTimeNotificationService $notificationService;
    private UserService $userService;
    private Request $request;
    
    public function __construct()
    {
        $this->notificationService = new RealTimeNotificationService();
        $this->userService = new UserService();
        $this->request = new Request();
    }
    
    /**
     * Polling de notificações pendentes
     * GET /api/notifications/poll
     */
    public function poll(): void
    {
        $user = $this->userService->getCurrentUser();
        
        if (!$user) {
            $this->jsonResponse(['error' => 'Não autenticado'], 401);
            return;
        }
        
        $accountId = $this->requireActiveAccountId();
        if ($accountId === null) {
            return;
        }
        
        // Verificar horário silencioso
        $isQuiet = $this->notificationService->isQuietHours($accountId);
        
        // Buscar notificações pendentes
        $notifications = $this->notificationService->getPendingNotifications($accountId);
        
        // Obter configurações
        $settings = $this->notificationService->getSettings($accountId);
        
        // Marcar como enviadas
        if (!empty($notifications)) {
            $ids = array_column($notifications, 'id');
            $this->notificationService->markAsPushed($ids);
        }
        
        // Contar não lidas
        $unreadCount = $this->notificationService->countUnread($accountId);
        $unreadOrders = $this->notificationService->countUnread($accountId, 'order');
        $unreadQuestions = $this->notificationService->countUnread($accountId, 'question');
        
        $this->jsonResponse([
            'success' => true,
            'notifications' => $notifications,
            'counts' => [
                'total' => $unreadCount,
                'orders' => $unreadOrders,
                'questions' => $unreadQuestions
            ],
            'settings' => [
                'sound_enabled' => !$isQuiet && $settings['sound_enabled'],
                'sound_volume' => $settings['sound_volume'],
                'desktop_enabled' => $settings['desktop_enabled'],
                'polling_interval' => $settings['polling_interval']
            ],
            'quiet_hours' => $isQuiet,
            'timestamp' => time()
        ]);
    }
    
    /**
     * Lista todas as notificações não lidas
     * GET /api/notifications/unread
     */
    public function unread(): void
    {
        $user = $this->userService->getCurrentUser();
        
        if (!$user) {
            $this->jsonResponse(['error' => 'Não autenticado'], 401);
            return;
        }
        
        $accountId = $this->requireActiveAccountId();
        if ($accountId === null) {
            return;
        }
        $type = $this->request->get('type');
        $limit = min(100, max(1, $this->request->getInt('limit', 50)));
        
        if ($type) {
            $notifications = $this->notificationService->getUnreadNotifications($accountId, $limit);
            $notifications = array_filter($notifications, fn($n) => $n['type'] === $type);
        } else {
            $notifications = $this->notificationService->getUnreadNotifications($accountId, $limit);
        }
        
        $this->jsonResponse([
            'success' => true,
            'notifications' => array_values($notifications),
            'total' => count($notifications)
        ]);
    }
    
    /**
     * Marca notificação como lida
     * POST /api/notifications/{id}/read
     */
    public function markRead(int $id): void
    {
        $user = $this->userService->getCurrentUser();
        
        if (!$user) {
            $this->jsonResponse(['error' => 'Não autenticado'], 401);
            return;
        }
        
        $this->notificationService->markAsRead($id);
        
        $this->jsonResponse([
            'success' => true,
            'message' => 'Notificação marcada como lida'
        ]);
    }
    
    /**
     * Marca todas as notificações como lidas
     * POST /api/notifications/read-all
     */
    public function markAllRead(): void
    {
        $user = $this->userService->getCurrentUser();
        
        if (!$user) {
            $this->jsonResponse(['error' => 'Não autenticado'], 401);
            return;
        }
        
        $accountId = $this->requireActiveAccountId();
        if ($accountId === null) {
            return;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $type = $input['type'] ?? null;
        
        $count = $this->notificationService->markAllAsRead($accountId, $type);
        
        $this->jsonResponse([
            'success' => true,
            'marked' => $count,
            'message' => "{$count} notificações marcadas como lidas"
        ]);
    }
    
    /**
     * Obtém configurações de notificação
     * GET /api/notifications/settings
     */
    public function getSettings(): void
    {
        $user = $this->userService->getCurrentUser();
        
        if (!$user) {
            $this->jsonResponse(['error' => 'Não autenticado'], 401);
            return;
        }
        
        $accountId = $this->requireActiveAccountId();
        if ($accountId === null) {
            return;
        }
        $settings = $this->notificationService->getSettings($accountId);
        $availableSounds = RealTimeNotificationService::getAvailableSounds();
        
        $this->jsonResponse([
            'success' => true,
            'settings' => $settings,
            'available_sounds' => $availableSounds
        ]);
    }
    
    /**
     * Salva configurações de notificação
     * POST /api/notifications/settings
     */
    public function saveSettings(): void
    {
        $user = $this->userService->getCurrentUser();
        
        if (!$user) {
            $this->jsonResponse(['error' => 'Não autenticado'], 401);
            return;
        }
        
        $accountId = $this->requireActiveAccountId();
        if ($accountId === null) {
            return;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        
        $settings = [
            'sound_enabled' => $input['sound_enabled'] ?? true,
            'sound_volume' => min(100, max(0, (int)($input['sound_volume'] ?? 80))),
            'sound_order' => $input['sound_order'] ?? 'order_notification',
            'sound_question' => $input['sound_question'] ?? 'question_notification',
            'sound_message' => $input['sound_message'] ?? 'message_notification',
            'desktop_enabled' => $input['desktop_enabled'] ?? true,
            'polling_interval' => min(120, max(10, (int)($input['polling_interval'] ?? 30))),
            'quiet_hours_start' => $input['quiet_hours_start'] ?? null,
            'quiet_hours_end' => $input['quiet_hours_end'] ?? null
        ];
        
        $success = $this->notificationService->saveSettings($accountId, $settings);
        
        $this->jsonResponse([
            'success' => $success,
            'message' => $success ? 'Configurações salvas' : 'Erro ao salvar configurações',
            'settings' => $settings
        ]);
    }
    
    /**
     * Obtém estatísticas de notificações
     * GET /api/notifications/stats
     */
    public function stats(): void
    {
        $user = $this->userService->getCurrentUser();
        
        if (!$user) {
            $this->jsonResponse(['error' => 'Não autenticado'], 401);
            return;
        }
        
        $accountId = $this->requireActiveAccountId();
        if ($accountId === null) {
            return;
        }
        $stats = $this->notificationService->getStats($accountId);
        
        $this->jsonResponse([
            'success' => true,
            'stats' => $stats
        ]);
    }
    
    /**
     * Testa som de notificação
     * POST /api/notifications/test-sound
     */
    public function testSound(): void
    {
        $user = $this->userService->getCurrentUser();
        
        if (!$user) {
            $this->jsonResponse(['error' => 'Não autenticado'], 401);
            return;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $soundType = $input['type'] ?? 'order';
        
        $this->jsonResponse([
            'success' => true,
            'play_sound' => true,
            'sound_type' => $soundType,
            'message' => 'Execute o som no cliente'
        ]);
    }
    
    /**
     * Sessão ativa apenas. current_account_id / user.id / request account_id não entram.
     */
    private function requireActiveAccountId(): ?int
    {
        $accountId = SessionHelper::getActiveAccountId();
        if ($accountId === null || $accountId <= 0) {
            $this->jsonResponse(['error' => 'Conta ML ativa obrigatória'], 400);
            return null;
        }

        return $accountId;
    }

    /**
     * Resposta JSON
     */
    private function jsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}

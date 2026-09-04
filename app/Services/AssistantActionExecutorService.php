<?php

declare(strict_types=1);

namespace App\Services;

use App\Exception\UnsafeOperationException;
use App\Services\HiddenSeo\SafetyGuard;
use RuntimeException;
use Throwable;

/**
 * AssistantActionExecutorService
 *
 * Executa um action_run criado pelo AssistantConnectorController.
 * Essa execução acontece via JobService (tipo: assistant_action).
 */
class AssistantActionExecutorService
{
    private AssistantConnectorService $connector;

    public function __construct(?AssistantConnectorService $connector = null)
    {
        $this->connector = $connector ?? new AssistantConnectorService();
    }

    /**
     * @param array{action_run_id?: int|string} $payload
     * @return array<string, mixed>
     */
    public function execute(array $payload, int $jobId): array
    {
        $actionRunId = isset($payload['action_run_id']) ? (int)$payload['action_run_id'] : 0;
        if ($actionRunId <= 0) {
            throw new RuntimeException('assistant_action: action_run_id ausente/ inválido');
        }

        if (!$this->connector->isDbAvailable()) {
            throw new RuntimeException('assistant_action: DB indisponível para executar action_run');
        }

        $run = $this->connector->getActionRunById($actionRunId);
        if ($run === null) {
            throw new RuntimeException('assistant_action: action_run não encontrado');
        }

        $status = (string)($run['status'] ?? 'queued');
        if ($status === 'completed') {
            return [
                'success' => true,
                'skipped' => true,
                'reason' => 'already_completed',
                'action_run_id' => $actionRunId,
                'result' => $run['result'] ?? null,
            ];
        }

        $this->connector->markActionRunProcessing($actionRunId, $jobId);

        $action = (string)($run['action'] ?? '');
        $accountId = (int)($run['account_id'] ?? 0);
        $parameters = $run['parameters'] ?? [];
        if (!is_array($parameters)) {
            $parameters = [];
        }

        try {
            $result = $this->executeAction($action, $accountId, $parameters);
            $this->connector->markActionRunCompleted($actionRunId, $result);

            return [
                'success' => true,
                'action_run_id' => $actionRunId,
                'action' => $action,
                'account_id' => $accountId,
                'result' => $result,
            ];
        } catch (Throwable $e) {
            // Mantém action_run como queued para permitir retry pelo JobService.
            $this->connector->markActionRunRetry($actionRunId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function executeAction(string $action, int $accountId, array $parameters): array
    {
        $normalized = AssistantConnectorService::normalizeAction($action);
        if ($normalized === null) {
            throw new RuntimeException('Ação não permitida: ' . $action);
        }

        return match ($normalized) {
            AssistantConnectorService::ACTION_ANSWER_QUESTION => $this->executeAnswerQuestion($accountId, $parameters),
            AssistantConnectorService::ACTION_UPDATE_STOCK => $this->executeUpdateStock($accountId, $parameters),
            AssistantConnectorService::ACTION_RECONCILE_ORDER => $this->executeReconcileOrder($accountId, $parameters),
            AssistantConnectorService::ACTION_REFRESH_ACCOUNT_TOKEN => $this->executeRefreshAccountToken($accountId),
            default => throw new RuntimeException('Ação não implementada: ' . $normalized),
        };
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function executeAnswerQuestion(int $accountId, array $parameters): array
    {
        $questionId = isset($parameters['question_id']) ? (string)$parameters['question_id'] : '';
        $text = isset($parameters['text']) ? (string)$parameters['text'] : '';

        if (trim($questionId) === '' || trim($text) === '') {
            throw new RuntimeException('Parâmetros obrigatórios: question_id, text');
        }

        try {
            (new SafetyGuard())->assertCanApply($accountId, false, true);
        } catch (UnsafeOperationException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        $service = new QuestionService($accountId);
        $result = $service->answerQuestion($questionId, $text);

        $success = (bool)($result['success'] ?? false);
        if (!$success || isset($result['error'])) {
            $message = (string)($result['message'] ?? ($result['error'] ?? 'Falha ao responder pergunta'));
            throw new RuntimeException($message);
        }

        return [
            'provider' => 'mercadolivre',
            'operation' => 'answer_question',
            'question_id' => $questionId,
            'data' => $result,
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function executeUpdateStock(int $accountId, array $parameters): array
    {
        $itemId = isset($parameters['item_id']) ? (string)$parameters['item_id'] : '';
        $quantity = isset($parameters['quantity']) ? (int)$parameters['quantity'] : null;

        if (trim($itemId) === '' || $quantity === null) {
            throw new RuntimeException('Parâmetros obrigatórios: item_id, quantity');
        }

        if ($quantity < 0) {
            throw new RuntimeException('quantity deve ser >= 0');
        }

        $service = new ItemService($accountId);
        $result = $service->updateStock($itemId, $quantity);

        if (isset($result['error'])) {
            $message = (string)($result['message'] ?? 'Falha ao atualizar estoque');
            throw new RuntimeException($message);
        }

        return [
            'provider' => 'mercadolivre',
            'operation' => 'update_stock',
            'item_id' => $itemId,
            'quantity' => $quantity,
            'data' => $result,
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function executeReconcileOrder(int $accountId, array $parameters): array
    {
        $orderId = isset($parameters['order_id']) ? (string)$parameters['order_id'] : '';
        if (trim($orderId) === '') {
            throw new RuntimeException('Parâmetro obrigatório: order_id');
        }

        $service = new OrderService($accountId);
        $result = $service->getOrder($orderId, ['allow_local_cache' => true]);
        $success = (bool)($result['success'] ?? false);
        if (!$success) {
            $message = (string)($result['message'] ?? ($result['error'] ?? 'Falha ao reconciliar pedido'));
            throw new RuntimeException($message);
        }

        return [
            'provider' => 'mercadolivre',
            'operation' => 'reconcile_order',
            'order_id' => $orderId,
            'data' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function executeRefreshAccountToken(int $accountId): array
    {
        if ($accountId <= 0) {
            throw new RuntimeException('account_id inválido para refresh_account_token');
        }

        $service = new UnifiedTokenRefreshService();
        $result = $service->refreshAccount($accountId);
        $success = (bool)($result['success'] ?? false);
        if (!$success) {
            $message = (string)($result['message'] ?? 'Falha ao renovar token');
            throw new RuntimeException($message);
        }

        return [
            'provider' => 'mercadolivre',
            'operation' => 'refresh_account_token',
            'account_id' => $accountId,
            'data' => $result,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exception\UnsafeOperationException;
use App\Services\HiddenSeo\SafetyGuard;
use App\Helpers\AccountScopeHelper;
use App\Helpers\SessionHelper;
use App\Services\Pregao\PregaoQuestionsService;
use App\Services\QuestionService;

class QuestionController extends BaseController
{
    private QuestionService $service;
    private int $accountId;

    public function __construct()
    {
        parent::__construct();
        $active = AccountScopeHelper::activeAccountId();
        $this->accountId = $active ?? 0;

        if ($this->accountId > 0) {
            $ctx = $this->authorizeAccount($this->accountId, 'questions');
            $this->accountId = $ctx !== null ? $ctx->accountId() : 0;
        }

        $this->service = new QuestionService($this->accountId ?: null);
    }

    /**
     * GET /api/questions
     * Lista perguntas
     */
    public function index()
    {
        header('Content-Type: application/json');

        if (!$this->accountId) {
            http_response_code(400);
            echo json_encode(['error' => 'Conta ML não selecionada']);
            return;
        }

        $filters = [
            'status' => $this->request->get('status'),
            'item_id' => $this->request->get('item_id'),
            'limit' => $this->request->getInt('limit', 50),
            'offset' => $this->request->getInt('offset', 0),
            'account_id' => $this->accountId,
            'allow_local_cache' => $this->request->get('allow_local_cache'),
            'source' => $this->request->get('source')
        ];

        // A listagem da tabela usa SEMPRE o cache local (ml_questions) — mesma
        // fonte de stats() — para nunca divergir dos contadores exibidos no
        // topo da tela de Perguntas (Onda 2.1 / F1). "item_id" continua sendo
        // um filtro válido do cache local; apenas o roteamento para a API
        // live do ML (antes condicionado a account_id === 'all') foi
        // removido desta rota de listagem.
        $result = $this->service->getQuestionsLocal($filters);

        if (isset($result['error'])) {
            $error = (string)$result['error'];
            if (in_array($error, ['missing_seller_id', 'local_cache_required'], true)) {
                http_response_code(422);
            } elseif (in_array($error, ['db_unavailable', 'network_disabled', 'circuit_breaker_open'], true)) {
                http_response_code(503);
            } elseif ($error === 'missing_token') {
                http_response_code(401);
            } else {
                http_response_code(502);
            }
        }

        echo json_encode($result);
    }

    /**
     * GET /api/questions/stats
     * Retorna métricas resumidas das perguntas.
     */
    public function stats(): void
    {
        header('Content-Type: application/json');

        try {
            // Usa cache local (mesma fonte da tabela em index(), Onda 2.1 / F1)
            // para não depender da API ML em tela inicial do dashboard.
            $stats = $this->service->getLocalStats();
            $avgSeconds = $this->service->getAverageResponseTimeSeconds();

            echo json_encode([
                'success' => true,
                'source' => 'local',
                'total' => (int) ($stats['total'] ?? 0),
                'pending' => (int) ($stats['pending'] ?? 0),
                'answered' => (int) ($stats['answered'] ?? 0),
                'unanswered_ge_1h' => (int) ($stats['unanswered_ge_1h'] ?? 0),
                'sla_seconds' => QuestionService::SLA_UNANSWERED_SECONDS,
                'avg_response_time' => $avgSeconds !== null
                    ? PregaoQuestionsService::formatDurationHuman((int) round($avgSeconds))
                    : '—',
            ]);
        } catch (\Throwable $e) {
            log_warning('Erro ao calcular stats de perguntas', [
                'controller' => 'QuestionController',
                'error' => $e->getMessage(),
            ]);

            // Falhar em modo degradado (sem 5xx na UI).
            echo json_encode([
                'success' => true,
                'source' => 'local',
                'total' => 0,
                'pending' => 0,
                'answered' => 0,
                'unanswered_ge_1h' => 0,
                'sla_seconds' => QuestionService::SLA_UNANSWERED_SECONDS,
                'avg_response_time' => '—',
            ]);
        }
    }

    /**
     * GET /api/questions/{id}
     * Detalhes da pergunta
     */
    public function show(string $id)
    {
        header('Content-Type: application/json');
        $options = [];
        $allowLocalCache = $this->request->get('allow_local_cache');
        if ($allowLocalCache !== null) {
            $options['allow_local_cache'] = $allowLocalCache;
        }

        $source = $this->request->get('source');
        if ($source !== null) {
            $options['source'] = $source;
        }

        $result = $this->service->getQuestion($id, $options);

        if (isset($result['error'])) {
            $error = (string)$result['error'];
            if (in_array($error, ['not_found', 'question_not_found'], true)) {
                http_response_code(404);
            } elseif (in_array($error, ['db_unavailable', 'network_disabled', 'circuit_breaker_open'], true)) {
                http_response_code(503);
            } elseif ($error === 'missing_token') {
                http_response_code(401);
            } else {
                http_response_code(502);
            }
        }

        echo json_encode($result);
    }

    /**
     * POST /api/questions/{id}/answer
     * Responder pergunta
     */
    public function answer(string $id)
    {
        header('Content-Type: application/json');

        if (!$this->accountId) {
            http_response_code(400);
            echo json_encode(['error' => 'Conta ML não selecionada']);
            return;
        }

        $data = $this->request->json();
        $text = trim((string) ($data['text'] ?? $data['answer'] ?? ''));

        if (empty($text)) {
            http_response_code(400);
            echo json_encode(['error' => 'Texto da resposta é obrigatório']);
            return;
        }

        try {
            (new SafetyGuard())->assertCanApply($this->accountId, false, true);
        } catch (UnsafeOperationException $e) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'apply_blocked' => true,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $result = $this->service->answerQuestion($id, $text);
        if (!empty($result['apply_blocked'])) {
            http_response_code(403);
        }
        echo json_encode($result);
    }

    /**
     * DELETE /api/questions/{id}
     * Excluir pergunta
     */
    public function delete(string $id)
    {
        header('Content-Type: application/json');

        if (!$this->accountId) {
            http_response_code(400);
            echo json_encode(['error' => 'Conta ML não selecionada']);
            return;
        }

        try {
            (new SafetyGuard())->assertCanApply($this->accountId, false, true);
        } catch (UnsafeOperationException $e) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'apply_blocked' => true,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $result = $this->service->deleteQuestion($id);
        echo json_encode($result);
    }

    /**
     * GET /api/questions/unanswered/count
     * Contagem de não respondidas
     */
    public function countUnanswered()
    {
        header('Content-Type: application/json');

        if (!$this->accountId) {
            http_response_code(400);
            echo json_encode(['error' => 'Conta ML não selecionada']);
            return;
        }

        $count = $this->service->getUnansweredCount();
        echo json_encode(['count' => $count]);
    }

    /**
     * POST /api/questions/sync
     * Sincroniza perguntas da API do ML para o banco local.
     * Body: { limit?: int }
     */
    public function sync(): void
    {
        header('Content-Type: application/json');

        if (!$this->accountId) {
            http_response_code(400);
            echo json_encode(['error' => 'Conta ML não selecionada']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $limit = isset($input['limit']) ? max(1, min(200, (int) $input['limit'])) : 50;

        try {
            $result = $this->service->syncQuestions($limit);
            echo json_encode([
                'success' => true,
                'synced'  => $result['synced'] ?? 0,
                'errors'  => $result['errors'] ?? 0,
                'message' => "Sincronização concluída: {$result['synced']} perguntas importadas.",
            ] + $result);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * POST /api/questions/{id}/analyze
     * Analisa sentimento e intenção com IA
     */
    public function analyze(string $id)
    {
        header('Content-Type: application/json');

        if (!$this->accountId) {
            http_response_code(400);
            echo json_encode(['error' => 'Conta ML não selecionada']);
            return;
        }

        $result = $this->service->analyzeQuestion($id);
        echo json_encode($result);
    }

    /**
     * POST /api/questions/{id}/draft
     * Gera rascunho de resposta com IA
     */
    public function draft(string $id)
    {
        header('Content-Type: application/json');

        if (!$this->accountId) {
            http_response_code(400);
            echo json_encode(['error' => 'Conta ML não selecionada']);
            return;
        }

        $result = $this->service->generateDraftAnswer($id);
        echo json_encode($result);
    }

    /**
     * GET /api/questions/auto-answer/settings
     * Retorna configurações do job de auto-resposta
     */
    public function getAutoAnswerSettings(): void
    {
        header('Content-Type: application/json');

        if (!$this->userService->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['error' => 'Autenticação necessária']);
            return;
        }

        try {
            $db = \App\Database::getInstance();
            $stmt = $db->query(
                "SELECT setting_key, setting_value FROM system_settings
                 WHERE setting_key IN ('auto_answer_enabled','auto_answer_confidence','auto_answer_count_today')"
            );
            $rows = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

            echo json_encode([
                'success'        => true,
                'enabled'        => filter_var($rows['auto_answer_enabled'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
                'min_confidence' => (int) ($rows['auto_answer_confidence'] ?? 90),
                'answered_today' => (int) ($rows['auto_answer_count_today'] ?? 0),
            ]);
        } catch (\Exception $e) {
            echo json_encode([
                'success'        => true,
                'enabled'        => false,
                'min_confidence' => 90,
                'answered_today' => 0,
            ]);
        }
    }

    /**
     * POST /api/questions/auto-answer/settings
     * Salva configurações do job de auto-resposta
     */
    public function saveAutoAnswerSettings(): void
    {
        header('Content-Type: application/json');

        if (!$this->userService->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['error' => 'Autenticação necessária']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['error' => 'JSON inválido']);
            return;
        }

        $enabled    = isset($input['enabled']) ? (bool) $input['enabled'] : false;
        $confidence = isset($input['min_confidence'])
            ? max(50, min(99, (int) $input['min_confidence']))
            : 90;

        try {
            $db = \App\Database::getInstance();
            $upsert = "INSERT INTO system_settings (setting_key, setting_value, updated_at)
                       VALUES (:key, :val, NOW())
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()";

            $stmt = $db->prepare($upsert);
            $stmt->execute([':key' => 'auto_answer_enabled',    ':val' => $enabled ? 'true' : 'false']);
            $stmt->execute([':key' => 'auto_answer_confidence', ':val' => (string) $confidence]);

            echo json_encode(['success' => true, 'enabled' => $enabled, 'min_confidence' => $confidence]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao salvar configurações', 'details' => $e->getMessage()]);
        }
    }
}

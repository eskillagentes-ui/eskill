<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Helpers\Log;
use App\Services\AI\Answers\AnswerGeneratorService;
use App\Services\AI\Answers\QuestionAnalyzerService;
use PDO;
use Throwable;

/**
 * Serviço de Gestão de Perguntas e Respostas (Q&A)
 */
class QuestionService
{
    private MercadoLivreClient $client;
    private CacheService $cache;
    private ?int $accountId;
    private ?AnswerGeneratorService $answerGenerator;
    private ?QuestionAnalyzerService $questionAnalyzer;
    private ?ItemService $itemService;
    private ?PDO $db;

    public function __construct(
        ?int $accountId = null,
        ?MercadoLivreClient $client = null,
        ?CacheService $cache = null,
        ?AnswerGeneratorService $answerGenerator = null,
        ?QuestionAnalyzerService $questionAnalyzer = null,
        ?ItemService $itemService = null,
        ?PDO $db = null,
        bool $skipDbAutoConnect = false
    ) {
        $this->accountId = $accountId;
        $this->client = $client ?? new MercadoLivreClient($accountId);
        $this->cache = $cache ?? new CacheService();

        if ($answerGenerator !== null) {
            $this->answerGenerator = $answerGenerator;
        } elseif ($skipDbAutoConnect) {
            $this->answerGenerator = null;
        } else {
            $this->answerGenerator = null;
            try {
                $this->answerGenerator = new AnswerGeneratorService($accountId);
            } catch (Throwable $e) {
                log_warning('QuestionService: AnswerGenerator indisponível (dependências não inicializadas)', [
                    'service' => 'QuestionService',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($questionAnalyzer !== null) {
            $this->questionAnalyzer = $questionAnalyzer;
        } elseif ($skipDbAutoConnect) {
            $this->questionAnalyzer = null;
        } else {
            $this->questionAnalyzer = null;
            try {
                $this->questionAnalyzer = new QuestionAnalyzerService();
            } catch (Throwable $e) {
                log_warning('QuestionService: QuestionAnalyzer indisponível (dependências não inicializadas)', [
                    'service' => 'QuestionService',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->itemService = $itemService;

        if ($db !== null) {
            $this->db = $db;
        } elseif ($skipDbAutoConnect) {
            $this->db = null;
        } else {
            try {
                $this->db = Database::getInstance();
            } catch (Throwable $e) {
                $this->db = null;
                log_warning('QuestionService: DB indisponível, operando em modo API-only', [
                    'service' => 'QuestionService',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Lista perguntas com filtros e dados enriquecidos
     */
    public function syncQuestions(int $limit = 50): array
    {
        $stats = ['synced' => 0, 'errors' => 0];
        $limit = max(1, min(200, $limit));

        try {
            $sellerId = $this->getSellerIdForQuestions();

            if (!$sellerId) {
                throw new \RuntimeException('Seller ID não encontrado para sincronizar perguntas');
            }

            $apiResult = $this->unwrapMlResponse($this->client->get('/questions/search', [
                'seller_id' => $sellerId,
                'sort' => 'date_created_desc',
                'limit' => $limit
            ]));

            if (isset($apiResult['error'])) {
                throw new \RuntimeException($this->formatMlApiErrorMessage(
                    $apiResult,
                    'Falha ao sincronizar perguntas na API do Mercado Livre'
                ));
            }

            $questions = $apiResult['questions'] ?? [];
            if (!is_array($questions)) {
                return $stats;
            }

            foreach ($questions as $q) {
                if (!is_array($q)) {
                    continue;
                }

                $q['account_id'] = $this->accountId;
                $q['seller_id'] = $sellerId;

                try {
                    $this->saveQuestionToDatabase($q);
                    $stats['synced']++;
                } catch (Throwable $e) {
                    $stats['errors']++;
                }
            }
        } catch (Throwable $e) {
            $stats['errors']++;
            $stats['last_error'] = $e->getMessage();
        }

        return $stats;
    }

    /**
     * Lista perguntas SEMPRE a partir do cache local (ml_questions), nunca da
     * API live do ML. Esta é a fonte única usada tanto pela tabela de
     * /dashboard/questions quanto pelos contadores de stats() (Onda 2.1 / F1).
     * Antes desta extração, a tabela (index()) e os contadores (stats())
     * chegavam à mesma query por caminhos de código diferentes e dependiam de
     * um valor exato de account_id vindo do front-end ('all'); qualquer outro
     * valor (ex.: "" para "Conta Atual") desviava a tabela para a API live do
     * ML, que pode retornar vazio/erro e nunca cair no fallback local
     * (allow_local_cache não é enviado pelo front-end), causando divergência
     * entre tabela vazia e contadores populados.
     */
    public function getQuestionsLocal(array $filters = []): array
    {
        $limit = max(1, min(200, (int)($filters['limit'] ?? 50)));
        $offset = max(0, (int)($filters['offset'] ?? 0));

        $local = $this->getQuestionsFromDatabase([
            'status' => $filters['status'] ?? null,
            'item_id' => $filters['item_id'] ?? null,
            'limit' => $limit,
            'offset' => $offset,
            'account_id' => $filters['account_id'] ?? 'all',
        ]);

        if (!isset($local['error'])) {
            $local['success'] = true;
            $local['source'] = 'local';
        }

        return $local;
    }

    public function getQuestions(array $filters = []): array
    {
        $limit = max(1, min(200, (int)($filters['limit'] ?? 50)));
        $offset = max(0, (int)($filters['offset'] ?? 0));

        if (isset($filters['account_id']) && $filters['account_id'] === 'all') {
            return $this->getQuestionsLocal($filters);
        }

        $params = [
            'sort' => 'date_created_desc',
            'limit' => $limit,
            'offset' => $offset,
        ];

        if (!empty($filters['status'])) {
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['item_id'])) {
            $params['item'] = $filters['item_id'];
        }

        if (!empty($filters['seller_id'])) {
            $params['seller_id'] = (string)$filters['seller_id'];
        }

        if (!isset($params['item'])) {
            $sellerId = $this->getSellerIdForQuestions($filters);
            if ($sellerId !== null && $sellerId !== '') {
                $params['seller_id'] = $sellerId;
            }
        }

        $apiResult = $this->unwrapMlResponse($this->client->get('/questions/search', $params));

        if (isset($apiResult['error'])) {
            if ($this->shouldAllowLocalFallback($filters)) {
                $fallback = $this->getQuestionsFromDatabase([
                    'status' => $filters['status'] ?? null,
                    'limit' => $limit,
                    'offset' => $offset,
                    'account_id' => $this->accountId,
                ]);

                if (!isset($fallback['error'])) {
                    $fallback['success'] = true;
                    $fallback['source'] = 'local';
                    $fallback['fallback_from'] = 'ml_api';
                    $fallback['warning'] = $this->formatMlApiErrorMessage(
                        $apiResult,
                        'API indisponível, exibindo cache local'
                    );
                }

                return $fallback;
            }

            return $this->emptyQuestionsPayload($limit, $offset, [
                'error' => 'ml_api_error',
                'message' => $this->formatMlApiErrorMessage(
                    $apiResult,
                    'Falha ao buscar perguntas na API do Mercado Livre'
                ),
                'api_error' => $apiResult,
                'source' => 'ml_api',
            ]);
        }

        $questions = $apiResult['questions'] ?? [];
        if (!is_array($questions)) {
            $questions = [];
        }

        if (!empty($questions)) {
            $ids = array_column($questions, 'id');
            $localData = $this->fetchLocalData($ids);

            foreach ($questions as &$q) {
                if (!is_array($q)) {
                    continue;
                }

                $qid = $q['id'] ?? null;
                if (!is_string($qid) && !is_int($qid)) {
                    continue;
                }

                $qid = (string)$qid;
                if (isset($localData[$qid])) {
                    $q['sentiment'] = $localData[$qid]['sentiment'];
                    $q['intent'] = $localData[$qid]['intent'];
                    $q['urgency'] = $localData[$qid]['urgency'];
                    $q['ai_draft'] = $localData[$qid]['ai_draft'];
                }
            }
            unset($q);
        }

        return [
            'success' => true,
            'source' => 'ml_api',
            'questions' => $questions,
            'paging' => $apiResult['paging'] ?? [
                'total' => count($questions),
                'limit' => $limit,
                'offset' => $offset,
            ],
        ];
    }

    /**
     * Gera um rascunho de resposta (Delegado para AnswerGenerator)
     */
    public function generateDraftAnswer(string $questionId): array
    {
        if ($this->answerGenerator === null) {
            return [
                'success' => false,
                'error' => 'service_unavailable',
                'message' => 'Gerador de rascunho indisponível no momento.',
            ];
        }

        $question = $this->getQuestion($questionId);
        if (isset($question['error'])) {
            return $question;
        }

        $result = $this->answerGenerator->generateDraft($question);

        if (!empty($result['success']) && isset($result['draft'])) {
            $this->updateQuestionDraft($questionId, $result['draft']);
        }

        return $result;
    }

    /**
     * Analisa uma pergunta (Delegado para QuestionAnalyzer)
     */
    public function analyzeQuestion(string $questionId): array
    {
        if ($this->questionAnalyzer === null) {
            return [
                'success' => false,
                'error' => 'service_unavailable',
                'message' => 'Analisador de perguntas indisponível no momento.',
            ];
        }

        $question = $this->getQuestion($questionId);
        if (isset($question['error'])) {
            return $question;
        }

        $itemService = $this->itemService ?? new ItemService($this->accountId);
        $itemId = (string)($question['item_id'] ?? '');
        $item = $itemId !== '' ? $itemService->getItem($itemId) : [];
        $context = $item['title'] ?? '';

        $analysis = $this->questionAnalyzer->analyze((string)($question['text'] ?? ''), (string)$context);
        $this->updateQuestionAnalysis($questionId, $analysis);

        return $analysis;
    }

    public function getQuestion(string $questionId, array $options = []): array
    {
        $apiResult = $this->unwrapMlResponse($this->client->get("/questions/{$questionId}"));

        if (!isset($apiResult['error'])) {
            if (isset($apiResult['id']) && is_array($apiResult)) {
                try {
                    $toPersist = $apiResult;
                    $toPersist['account_id'] = $toPersist['account_id'] ?? $this->accountId;
                    $this->saveQuestionToDatabase($toPersist);
                } catch (Throwable $e) {
                    Log::warning('QuestionService: falha ao salvar pergunta no cache local', [
                        'question_id' => $questionId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $apiResult['success'] = true;
            $apiResult['source'] = 'ml_api';
            return $apiResult;
        }

        if ($this->shouldAllowLocalFallback($options)) {
            $local = $this->getQuestionFromDatabase($questionId);
            if ($local !== null) {
                $local['success'] = true;
                $local['source'] = 'local';
                $local['fallback_from'] = 'ml_api';
                $local['warning'] = $this->formatMlApiErrorMessage(
                    $apiResult,
                    'Falha ao consultar pergunta na API, retornando cache local'
                );
                return $local;
            }
        }

        $apiResult['success'] = false;
        $apiResult['source'] = 'ml_api';
        return $apiResult;
    }

    public function getQuestionFromDatabase(string $questionId): ?array
    {
        if ($this->db === null) {
            return null;
        }

        try {
            $sql = "SELECT * FROM ml_questions WHERE question_id = ?";
            $params = [$questionId];
            if ($this->accountId !== null && $this->accountId > 0) {
                $sql .= " AND account_id = ?";
                $params[] = $this->accountId;
            }
            $sql .= " LIMIT 1";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                return null;
            }

            return $this->normalizeLocalQuestionRow($row);
        } catch (Throwable $e) {
            Log::warning('QuestionService: falha ao buscar pergunta no banco', [
                'question_id' => $questionId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function answerQuestion(string $questionId, string $text): array
    {
        if (trim($text) === '') {
            return [
                'success' => false,
                'error' => 'validation_error',
                'message' => 'Texto obrigatório',
            ];
        }

        $result = $this->unwrapMlResponse($this->client->post('/answers', [
            'question_id' => (int)$questionId,
            'text' => $text
        ]));

        if (isset($result['error'])) {
            $result['success'] = false;
            return $result;
        }

        if (isset($result['id'])) {
            $this->syncSingleQuestion($questionId);
        }

        $result['success'] = true;
        return $result;
    }

    public function syncSingleQuestion(string $questionId): array
    {
        $q = $this->getQuestion($questionId, ['allow_local_cache' => true]);
        if (isset($q['error'])) {
            return $q;
        }

        if (isset($q['id'])) {
            try {
                $q['account_id'] = $q['account_id'] ?? $this->accountId;
                $this->saveQuestionToDatabase($q);
            } catch (Throwable $e) {
                Log::warning('QuestionService: falha ao sincronizar pergunta no banco', [
                    'question_id' => $questionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $q;
    }

    public function deleteQuestion(string $questionId): array
    {
        if ($this->db === null) {
            return [
                'success' => false,
                'error' => 'db_unavailable',
                'message' => 'Banco indisponível para remover cache local da pergunta.',
            ];
        }

        $stmt = $this->db->prepare("DELETE FROM ml_questions WHERE question_id = ?");
        $stmt->execute([$questionId]);

        $deleted = $stmt->rowCount() > 0;

        return [
            'success' => true,
            'deleted' => $deleted,
            'source' => 'local_cache',
            'message' => $deleted
                ? 'Pergunta removida do cache local.'
                : 'Pergunta não encontrada no cache local.',
            'note' => 'A API do Mercado Livre não suporta exclusão de perguntas.',
        ];
    }

    public function getUnansweredCount(): int
    {
        $params = [
            'status' => 'UNANSWERED',
            'limit' => 1,
        ];

        $sellerId = $this->getSellerIdForQuestions();
        if ($sellerId) {
            $params['seller_id'] = $sellerId;
        }

        $response = $this->unwrapMlResponse($this->client->get('/questions/search', $params));

        if (!isset($response['error'])) {
            $total = $response['paging']['total'] ?? null;
            if (is_numeric($total)) {
                return (int)$total;
            }

            $questions = $response['questions'] ?? [];
            return is_array($questions) ? count($questions) : 0;
        }

        if ($this->shouldAllowLocalFallback([])) {
            return $this->getLocalUnansweredCount();
        }

        return 0;
    }

    // --- Private / Helpers ---

    private function fetchLocalData(array $ids): array
    {
        if ($this->db === null || empty($ids)) {
            return [];
        }

        $normalizedIds = [];
        foreach ($ids as $id) {
            if (is_string($id) || is_int($id)) {
                $normalizedIds[] = (string)$id;
            }
        }

        if (empty($normalizedIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($normalizedIds), '?'));
        $stmt = $this->db->prepare("SELECT question_id, sentiment, intent, urgency, ai_draft FROM ml_questions WHERE question_id IN ($placeholders)");
        $stmt->execute($normalizedIds);

        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[$row['question_id']] = $row;
        }
        return $map;
    }

    private function updateQuestionDraft(string $questionId, string $draft): void
    {
        if ($this->db === null) {
            return;
        }

        $stmt = $this->db->prepare("UPDATE ml_questions SET ai_draft = ? WHERE question_id = ?");
        $stmt->execute([$draft, $questionId]);
    }

    private function updateQuestionAnalysis(string $questionId, array $analysis): void
    {
        if ($this->db === null) {
            return;
        }

        $stmt = $this->db->prepare("UPDATE ml_questions SET sentiment = ?, intent = ?, urgency = ? WHERE question_id = ?");
        $stmt->execute([
            $analysis['sentiment'] ?? 'neutral',
            $analysis['intent'] ?? 'unknown',
            $analysis['urgency'] ?? 'normal',
            $questionId
        ]);
    }

    private function getQuestionsFromDatabase(array $filters): array
    {
        if ($this->db === null) {
            return $this->emptyQuestionsPayload(
                max(1, min(200, (int)($filters['limit'] ?? 50))),
                max(0, (int)($filters['offset'] ?? 0)),
                [
                    'error' => 'db_unavailable',
                    'message' => 'Banco indisponível para consultar cache local de perguntas.',
                    'source' => 'local',
                ]
            );
        }

        $where = ["1=1"];
        $params = [];

        $statusFilter = $filters['status'] ?? null;
        if (!empty($statusFilter) && strtolower((string)$statusFilter) !== 'all') {
            $where[] = "status = ?";
            $params[] = $statusFilter;
        }

        if (!empty($filters['account_id']) && $filters['account_id'] !== 'all') {
            $where[] = "account_id = ?";
            $params[] = (int)$filters['account_id'];
        } elseif ($this->accountId !== null && $this->accountId > 0) {
            $where[] = "account_id = ?";
            $params[] = $this->accountId;
        }

        if (!empty($filters['item_id'])) {
            $where[] = "item_id = ?";
            $params[] = (string)$filters['item_id'];
        }

        $offset = max(0, (int)($filters['offset'] ?? 0));
        $limit = max(1, min(200, (int)($filters['limit'] ?? 50)));

        $sql = "SELECT * FROM ml_questions WHERE " . implode(" AND ", $where) . " ORDER BY date_created DESC LIMIT $limit OFFSET $offset";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $questions = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $questions[] = $this->normalizeLocalQuestionRow($row);
            }
        }

        $countSql = "SELECT COUNT(*) FROM ml_questions WHERE " . implode(" AND ", $where);
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        return [
            'questions' => $questions,
            'paging' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ]
        ];
    }

    /**
     * Tempo médio de resposta (segundos) das perguntas respondidas, no mesmo
     * escopo de conta usado por getQuestionsFromDatabase()/stats() (Onda 2 / T8).
     * Retorna null quando não há amostra suficiente (nunca gera "-" silencioso
     * sem diferenciar "sem dado" de "zero").
     */
    public function getAverageResponseTimeSeconds(): ?float
    {
        if ($this->db === null) {
            return null;
        }

        $where = ["status = 'ANSWERED'", "answer_date IS NOT NULL"];
        $params = [];
        if ($this->accountId !== null && $this->accountId > 0) {
            $where[] = "account_id = ?";
            $params[] = $this->accountId;
        }

        $sql = "SELECT AVG(TIMESTAMPDIFF(SECOND, date_created, answer_date)) AS avg_s
                FROM ml_questions WHERE " . implode(' AND ', $where);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $avg = $stmt->fetchColumn();

        if ($avg === false || $avg === null) {
            return null;
        }

        return (float) $avg;
    }

    public function saveQuestionToDatabase(array $q): void
    {
        if ($this->db === null) {
            throw new \RuntimeException('DB indisponível para salvar perguntas');
        }

        if (!isset($q['id']) || !isset($q['item_id']) || !isset($q['status']) || !isset($q['text'])) {
            throw new \InvalidArgumentException('Payload de pergunta inválido para persistência');
        }

        $fromId = $q['from']['id'] ?? null;
        if (!is_numeric($fromId)) {
            $fromId = 0;
        }

        $stmt = $this->db->prepare("
            INSERT INTO ml_questions (
                question_id, account_id, item_id, status, question_text,
                answer_text, from_user_id, date_created, answer_date,
                updated_at, seller_id
            ) VALUES (
                :question_id, :account_id, :item_id, :status, :text,
                :answer, :from_user_id, :date_created, :date_answered,
                NOW(), :seller_id
            )
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                answer_text = VALUES(answer_text),
                answer_date = VALUES(answer_date),
                updated_at = NOW()
        ");

        $stmt->execute([
            ':question_id' => (string)$q['id'],
            ':account_id' => $q['account_id'] ?? $this->accountId,
            ':item_id' => (string)$q['item_id'],
            ':status' => (string)$q['status'],
            ':text' => (string)$q['text'],
            ':answer' => $q['answer']['text'] ?? null,
            ':from_user_id' => (int)$fromId,
            ':date_created' => $q['date_created'] ?? date('Y-m-d H:i:s'),
            ':date_answered' => $q['answer']['date_created'] ?? null,
            ':seller_id' => isset($q['seller_id']) ? (int)$q['seller_id'] : 0
        ]);
    }

    private function normalizeLocalQuestionRow(array $row): array
    {
        $payload = [
            'id' => (string)($row['question_id'] ?? ''),
            'text' => (string)($row['question_text'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'item_id' => (string)($row['item_id'] ?? ''),
            'from' => ['id' => (int)($row['from_user_id'] ?? 0)],
            'date_created' => $row['date_created'] ?? null,
            'seller_id' => isset($row['seller_id']) ? (int)$row['seller_id'] : null,
            'account_id' => isset($row['account_id']) ? (int)$row['account_id'] : $this->accountId,
            'sentiment' => $row['sentiment'] ?? null,
            'intent' => $row['intent'] ?? null,
            'urgency' => $row['urgency'] ?? null,
            'ai_draft' => $row['ai_draft'] ?? null,
        ];

        if (!empty($row['answer_text'])) {
            $payload['answer'] = [
                'text' => $row['answer_text'],
                'date_created' => $row['answer_date'] ?? null,
            ];
        }

        return $payload;
    }

    private function getLocalUnansweredCount(): int
    {
        if ($this->db === null) {
            return 0;
        }

        $sql = "SELECT COUNT(*) FROM ml_questions WHERE status = :status";
        $params = ['status' => 'UNANSWERED'];

        if ($this->accountId !== null && $this->accountId > 0) {
            $sql .= " AND account_id = :account_id";
            $params['account_id'] = $this->accountId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    private function getSellerIdForQuestions(array $filters = []): ?string
    {
        if (!empty($filters['seller_id'])) {
            return (string)$filters['seller_id'];
        }

        $sellerId = $this->client->getSellerId();
        if ($sellerId) {
            return (string)$sellerId;
        }

        $userInfo = $this->unwrapMlResponse($this->client->getMe());
        if (isset($userInfo['error'])) {
            return null;
        }

        $userId = $userInfo['id'] ?? null;
        if (is_string($userId) || is_int($userId)) {
            return (string)$userId;
        }

        return null;
    }

    private function unwrapMlResponse(array $response): array
    {
        if (isset($response['error'])) {
            return $response;
        }

        if (isset($response['body']) && is_array($response['body'])) {
            return $response['body'];
        }

        return $response;
    }

    private function shouldAllowLocalFallback(array $filters): bool
    {
        if (!empty($filters['allow_local_cache']) && filter_var($filters['allow_local_cache'], FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        if (!empty($filters['source']) && $filters['source'] === 'local') {
            return true;
        }

        $envAllow = $_ENV['ML_ALLOW_LOCAL_CACHE_FALLBACK'] ?? getenv('ML_ALLOW_LOCAL_CACHE_FALLBACK') ?? null;
        if (!filter_var($envAllow, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $appEnv = strtolower((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'production'));
        if (in_array($appEnv, ['production', 'prod', 'staging'], true)) {
            $prodAllow = $_ENV['ML_ALLOW_LOCAL_CACHE_FALLBACK_PRODUCTION']
                ?? getenv('ML_ALLOW_LOCAL_CACHE_FALLBACK_PRODUCTION')
                ?? null;

            return filter_var($prodAllow, FILTER_VALIDATE_BOOLEAN);
        }

        return true;
    }

    private function formatMlApiErrorMessage(array $error, string $prefix): string
    {
        $message = $prefix;

        $detail = $error['message'] ?? ($error['error'] ?? null);
        if (is_string($detail) && $detail !== '') {
            $message .= ': ' . $detail;
        }

        $status = $error['status'] ?? null;
        if (is_int($status) && $status > 0) {
            $message .= ' (HTTP ' . $status . ')';
        }

        $endpoint = $error['endpoint'] ?? null;
        if (is_string($endpoint) && $endpoint !== '') {
            $message .= ' [' . $endpoint . ']';
        }

        return $message;
    }

    private function emptyQuestionsPayload(int $limit, int $offset, array $extra = []): array
    {
        return array_merge([
            'success' => false,
            'questions' => [],
            'paging' => [
                'total' => 0,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ], $extra);
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\HiddenSeo\SafetyGuard;
use App\Services\QuestionService;
use PDO;
use Throwable;

/**
 * Auto-resposta de perguntas. Fail-closed: exige account_id explícito.
 * Conta 1335 (FACILYTY) nunca envia POST /answers.
 */
class AutoAnswerJob
{
    private int $accountId;
    private ?QuestionService $questionService;
    /** @var array{enabled?: bool, min_confidence?: int}|null */
    private ?array $settingsOverride;
    private ?PDO $db;

    /**
     * @param array{enabled?: bool, min_confidence?: int}|null $settings
     */
    public function __construct(
        int $accountId = 0,
        ?QuestionService $questionService = null,
        ?array $settings = null,
        ?PDO $db = null
    ) {
        $this->accountId = $accountId;
        $this->questionService = $questionService;
        $this->settingsOverride = $settings;
        $this->db = $db;
    }

    /**
     * @return array{sent: int, posts: int, apply_blocked: bool, account_id: int}
     */
    public function run(): array
    {
        logger()->info('Starting AutoAnswerJob', [
            'job' => 'AutoAnswerJob',
            'account_id' => $this->accountId,
        ]);

        $blocked = $this->blockedStart();
        if ($blocked !== null) {
            return $blocked;
        }

        $settings = $this->getSettings();
        $autoEnabled = $settings['enabled'] ?? false;
        $minConfidence = $settings['min_confidence'] ?? 90;

        if (!$autoEnabled) {
            logger()->info('Auto-Answer disabled', [
                'settings' => $settings,
                'account_id' => $this->accountId,
            ]);
            return $this->result(0, 0, false);
        }

        $service = $this->questionService ?? new QuestionService($this->accountId);
        $fetched = $service->getQuestions(['status' => 'UNANSWERED', 'limit' => 50]);
        $questions = $fetched['questions'] ?? [];
        if (!is_array($questions)) {
            $questions = [];
        }

        $sent = 0;
        $posts = 0;
        $applyBlocked = false;

        foreach ($questions as $q) {
            if (!is_array($q) || empty($q['draft_answer'])) {
                continue;
            }

            $confidence = (int) ($q['confidence_score'] ?? 0);
            if ($confidence < $minConfidence) {
                logger()->debug('Skipping low confidence answer', [
                    'question_id' => $q['id'] ?? null,
                    'confidence' => $confidence,
                    'min_required' => $minConfidence,
                    'account_id' => $this->accountId,
                ]);
                continue;
            }

            $questionId = (string) ($q['id'] ?? '');
            if ($questionId === '') {
                continue;
            }

            logger()->info('Auto-sending answer', [
                'question_id' => $questionId,
                'confidence' => $confidence,
                'account_id' => $this->accountId,
            ]);

            try {
                $posts++;
                $answer = $service->answerQuestion($questionId, (string) $q['draft_answer']);
                if (!empty($answer['apply_blocked'])) {
                    $applyBlocked = true;
                    logger()->warning('AutoAnswerJob apply blocked', [
                        'question_id' => $questionId,
                        'account_id' => $this->accountId,
                        'error' => $answer['error'] ?? 'apply_blocked',
                    ]);
                    continue;
                }
                if (!empty($answer['success'])) {
                    $sent++;
                }
            } catch (Throwable $e) {
                logger()->error('Failed to send answer', [
                    'question_id' => $questionId,
                    'account_id' => $this->accountId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        logger()->info('AutoAnswerJob finished', [
            'sent' => $sent,
            'posts' => $posts,
            'total_questions' => count($questions),
            'account_id' => $this->accountId,
        ]);

        return $this->result($sent, $posts, $applyBlocked);
    }

    /**
     * @return array{sent: int, posts: int, apply_blocked: bool, account_id: int}|null
     */
    private function blockedStart(): ?array
    {
        if ($this->accountId <= 0) {
            logger()->warning('AutoAnswerJob skipped: account_id required', [
                'job' => 'AutoAnswerJob',
            ]);

            return $this->result(0, 0, true);
        }

        if ((new SafetyGuard())->isForbidden($this->accountId)) {
            logger()->warning('AutoAnswerJob apply blocked', [
                'job' => 'AutoAnswerJob',
                'account_id' => $this->accountId,
            ]);

            return $this->result(0, 0, true);
        }

        return null;
    }

    /**
     * @return array{sent: int, posts: int, apply_blocked: bool, account_id: int}
     */
    private function result(int $sent, int $posts, bool $applyBlocked): array
    {
        return [
            'sent' => $sent,
            'posts' => $posts,
            'apply_blocked' => $applyBlocked,
            'account_id' => $this->accountId,
        ];
    }

    /**
     * @return array{enabled: bool, min_confidence: int}
     */
    private function getSettings(): array
    {
        if ($this->settingsOverride !== null) {
            return [
                'enabled' => (bool) ($this->settingsOverride['enabled'] ?? false),
                'min_confidence' => (int) ($this->settingsOverride['min_confidence'] ?? 90),
            ];
        }

        try {
            $db = $this->db ?? \App\Database::getInstance();
            $stmt = $db->query(
                "SELECT setting_key, setting_value FROM system_settings
                 WHERE setting_key IN ('auto_answer_enabled', 'auto_answer_confidence')"
            );
            $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

            $config = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $key = (string) ($row['setting_key'] ?? '');
                if ($key !== '') {
                    $config[$key] = $row['setting_value'] ?? null;
                }
            }

            return [
                'enabled' => filter_var($config['auto_answer_enabled'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
                'min_confidence' => (int) ($config['auto_answer_confidence'] ?? 90),
            ];
        } catch (Throwable $e) {
            logger()->warning('AutoAnswerJob settings unavailable', [
                'job' => 'AutoAnswerJob',
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);

            return [
                'enabled' => false,
                'min_confidence' => 90,
            ];
        }
    }
}

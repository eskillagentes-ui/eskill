<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

final class QuestionControllerWriteGuardTest extends TestCase
{
    public function testAnswerAndDeleteSurfaceApplyBlocked(): void
    {
        $path = dirname(__DIR__, 3) . '/app/Controllers/QuestionController.php';
        $source = (string) file_get_contents($path);

        $this->assertStringContainsString('use App\\Services\\HiddenSeo\\SafetyGuard;', $source);
        $this->assertStringContainsString('assertCanApply', $source);
        $this->assertStringContainsString("'apply_blocked' => true", $source);
        $this->assertStringContainsString("\$data['text'] ?? \$data['answer']", $source);

        foreach (['answer(string $id)', 'delete(string $id)'] as $method) {
            $start = strpos($source, 'public function ' . $method);
            $this->assertNotFalse($start, $method . ' ausente');
            $next = strpos($source, "\n    public function ", $start + 10);
            $chunk = $next === false ? substr($source, $start) : substr($source, $start, $next - $start);
            $this->assertStringContainsString('UnsafeOperationException', $chunk);
            $this->assertStringContainsString('http_response_code(403)', $chunk);
        }

        $this->assertStringContainsString("!empty(\$result['apply_blocked'])", $source);
    }

    public function testQuestionsViewChecksApplyBlockedAndSendsText(): void
    {
        $path = dirname(__DIR__, 3) . '/app/Views/dashboard/questions.php';
        $source = (string) file_get_contents($path);

        $this->assertStringContainsString('apply_blocked', $source);
        $this->assertStringContainsString('Conta 1335 exige GO item a item', $source);
        $this->assertStringContainsString('{ text: answer, answer }', $source);
        $this->assertLessThan(
            strpos($source, 'Resposta enviada com sucesso!') ?: PHP_INT_MAX,
            strpos($source, 'apply_blocked') ?: 0
        );
    }

    public function testQuestionWritePathsGuardForbiddenAccount(): void
    {
        $root = dirname(__DIR__, 3);

        $service = (string) file_get_contents($root . '/app/Services/QuestionService.php');
        $this->assertStringContainsString('blockedAnswerWrite', $service);
        $this->assertStringContainsString('isForbidden', $service);

        $job = (string) file_get_contents($root . '/app/Jobs/AutoAnswerJob.php');
        $this->assertStringContainsString('apply_blocked', $job);
        $this->assertStringContainsString('isForbidden', $job);
        $this->assertStringContainsString('new QuestionService($this->accountId)', $job);
        $this->assertStringNotContainsString('new QuestionService()', $job);

        $assistant = (string) file_get_contents($root . '/app/Services/AssistantActionExecutorService.php');
        $this->assertStringContainsString('assertCanApply', $assistant);

        $smart = (string) file_get_contents($root . '/app/Services/MercadoLivre/SmartQAService.php');
        $this->assertStringContainsString('assertCanApply', $smart);
        $this->assertStringContainsString('sendAutoAnswer apply blocked', $smart);
    }
}

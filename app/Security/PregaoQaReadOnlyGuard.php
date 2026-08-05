<?php

declare(strict_types=1);

namespace App\Security;

final class PregaoQaReadOnlyGuard
{
    /** @var list<string> */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public static function isAllowed(string $method, string $path): bool
    {
        $method = strtoupper($method);
        $path = '/' . ltrim((string) (parse_url($path, PHP_URL_PATH) ?? ''), '/');
        if (!in_array($method, self::SAFE_METHODS, true)) {
            return false;
        }
        return $path === '/dashboard/pregao'
            || $path === '/api/pregao/snapshot'
            || $path === '/api/pregao/events'
            || $path === '/api/pregao/stream'
            || $path === '/api/pregao/ticket'
            || preg_match('#\A/qa/(?:live|frame)/[0-9a-f-]{36}\z#D', $path) === 1;
    }

    public static function enforce(string $method, string $path): void
    {
        $expiresAt = $_SESSION['qa_expires_at'] ?? null;
        if (!is_int($expiresAt) || $expiresAt < time() || !self::isAllowed($method, $path)) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION = [];
                session_destroy();
            }
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['success' => false, 'error' => 'Sessão QA somente leitura bloqueada']);
            exit;
        }
    }
}

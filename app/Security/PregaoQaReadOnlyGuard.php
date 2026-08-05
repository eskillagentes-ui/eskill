<?php

declare(strict_types=1);

namespace App\Security;

final class PregaoQaReadOnlyGuard
{
    /** @var list<string> */
    private const SAFE_METHODS = ['GET', 'HEAD'];

    /** @var list<string> */
    private const DASHBOARD_READ_ROUTES = [
        '/dashboard/pregao',
        '/api/pregao/snapshot',
        '/api/pregao/events',
        '/api/pregao/stream',
        '/api/pregao/ticket',
        '/api/menu-items',
        '/api/dashboard/recent-activity',
        '/api/dashboard/recent-documents',
        '/api/dashboard/notifications',
        '/css/dashboard-modern.css',
        '/css/theme.css',
        '/css/components.css',
        '/css/pregao.css',
        '/js/csrf-helper.js',
        '/js/api-client.js',
        '/js/ml-integration-preflight.js',
        '/js/layout-modern-init.js',
        '/js/dashboard-modern.js',
        '/js/pregao-chart-layout.js',
        '/js/pregao-qa.js',
        '/js/pregao.js',
        '/js/pregao-events.js',
    ];

    public static function isAllowed(string $method, string $path): bool
    {
        $method = strtoupper($method);
        $parsedPath = parse_url($path, PHP_URL_PATH);
        if (!is_string($parsedPath) || str_contains($parsedPath, "\0")) {
            return false;
        }
        $path = '/' . ltrim($parsedPath, '/');
        if (!in_array($method, self::SAFE_METHODS, true)) {
            return false;
        }
        return in_array($path, self::DASHBOARD_READ_ROUTES, true)
            || preg_match('#\A/qa/(?:live|frame)/[0-9a-f-]{36}\z#D', $path) === 1;
    }

    public static function enforce(string $method, string $path): void
    {
        $expiresAt = $_SESSION['qa_expires_at'] ?? null;
        $expired = !is_int($expiresAt) || $expiresAt < time();
        if (!$expired && self::isAllowed($method, $path)) {
            return;
        }
        if ($expired && session_status() === PHP_SESSION_ACTIVE) {
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

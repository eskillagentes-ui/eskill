<?php

declare(strict_types=1);

/**
 * Redirect canônico do Precificador legado → shell moderno.
 * Evita flash do layouts/app.php enquanto ViewController/web.php forem root:root.
 */
(static function (): void {
    if (PHP_SAPI === 'cli') {
        return;
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        return;
    }

    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return;
    }

    $legacyPaths = [
        '/dashboard/pricing',
        '/dashboard/precificador',
        '/pricing/dashboard',
    ];

    if (!in_array($path, $legacyPaths, true)) {
        return;
    }

    $query = parse_url($uri, PHP_URL_QUERY);
    $target = '/dashboard/pricing-v2';
    if (is_string($query) && $query !== '') {
        $target .= '?' . $query;
    }

    header('Location: ' . $target, true, 302);
    exit;
})();

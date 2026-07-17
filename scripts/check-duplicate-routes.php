#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * CI helper: exit 1 se houver METHOD+PATH com handlers distintos.
 */

$routesDir = dirname(__DIR__) . '/app/Routes';
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($routesDir, FilesystemIterator::SKIP_DOTS)
);

/** @var array<string, list<string>> $map */
$map = [];

$normalize = static function (string $controllerExpr, string $action): string {
    $controller = trim($controllerExpr);
    $controller = trim($controller, "'\"");
    $controller = str_replace(['::class', '\\\\'], ['', '\\'], $controller);
    $controller = ltrim($controller, '\\');
    if (str_contains($controller, '\\')) {
        $parts = explode('\\', $controller);
        $controller = (string) end($parts);
    }
    return $controller . '::' . $action;
};

foreach ($files as $file) {
    if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
        continue;
    }
    $contents = (string) file_get_contents($file->getPathname());
    if (!preg_match_all(
        '/\$router->(get|post|put|patch|delete)\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*([^,]+)\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/i',
        $contents,
        $matches,
        PREG_SET_ORDER
    )) {
        continue;
    }

    foreach ($matches as $m) {
        $method = strtoupper($m[1]);
        $path = trim($m[2], '/');
        $handler = $normalize($m[3], $m[4]);
        $key = $method . ' ' . $path;
        $map[$key] = $map[$key] ?? [];
        if (!in_array($handler, $map[$key], true)) {
            $map[$key][] = $handler;
        }
    }
}

$conflicts = array_filter($map, static fn(array $h): bool => count($h) > 1);
if ($conflicts === []) {
    fwrite(STDOUT, "OK: nenhuma rota duplicada com handlers distintos\n");
    exit(0);
}

fwrite(STDERR, "FAIL: rotas conflitantes\n");
foreach ($conflicts as $key => $handlers) {
    fwrite(STDERR, $key . ' => ' . implode(' | ', $handlers) . "\n");
}
exit(1);

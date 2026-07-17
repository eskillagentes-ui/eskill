<?php

declare(strict_types=1);

namespace Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;

/**
 * Falha se a mesma combinação METHOD+PATH apontar para handlers distintos (após normalização).
 */
final class DuplicateRoutesTest extends TestCase
{
    public function testNoConflictingDuplicateRoutes(): void
    {
        $conflicts = $this->findConflicts(dirname(__DIR__, 3) . '/app/Routes');
        $messages = [];
        foreach ($conflicts as $key => $handlers) {
            $messages[] = $key . ' => ' . implode(' | ', $handlers);
        }

        $this->assertSame(
            [],
            $conflicts,
            "Rotas duplicadas com handlers distintos:\n" . implode("\n", $messages)
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function findConflicts(string $routesDir): array
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($routesDir, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var array<string, list<string>> $map */
        $map = [];

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
                $handler = $this->normalizeHandler($m[3], $m[4]);
                $key = $method . ' ' . $path;
                $map[$key] = $map[$key] ?? [];
                if (!in_array($handler, $map[$key], true)) {
                    $map[$key][] = $handler;
                }
            }
        }

        $conflicts = [];
        foreach ($map as $key => $handlers) {
            if (count($handlers) > 1) {
                $conflicts[$key] = $handlers;
            }
        }

        return $conflicts;
    }

    private function normalizeHandler(string $controllerExpr, string $action): string
    {
        $controller = trim($controllerExpr);
        $controller = trim($controller, "'\"");
        $controller = str_replace(['::class', '\\\\'], ['', '\\'], $controller);
        $controller = ltrim($controller, '\\');
        if (str_contains($controller, '\\')) {
            $parts = explode('\\', $controller);
            $controller = (string) end($parts);
        }

        return $controller . '::' . $action;
    }
}

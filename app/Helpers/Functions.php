<?php

declare(strict_types=1);

/**
 * Funções auxiliares globais — disponíveis em qualquer arquivo do projeto.
 *
 * Autoloaded via composer.json "autoload.files".
 *
 * Funções definidas aqui:
 *   env(string $key, mixed $default = null): mixed
 *   config(string $key, mixed $default = null): mixed
 *   app_path(string ...$segments): string
 *   storage_path(string ...$segments): string
 *   base_path(string ...$segments): string
 */

// ─── env() ───────────────────────────────────────────────────────────────────

if (!function_exists('env')) {
    /**
     * Lê uma variável de ambiente com coerção de tipo automática.
     *
     * Regras de coerção:
     *   'true' / 'false'  → bool
     *   'null'            → null
     *   '(empty)'         → ''  (string vazia mantida)
     *   '"quoted"'        → string sem aspas
     *   numeric string    → int ou float
     *   demais            → string
     *
     * Uso:
     *   $debug = env('APP_DEBUG', false);       // bool
     *   $port  = env('REDIS_PORT', 6379);       // int
     *   $dsn   = env('DB_DSN');                 // string|null
     */
    function env(string $key, mixed $default = null): mixed
    {
        // $_ENV carregado pelo vlucas/phpdotenv (preferência)
        // Fallback para getenv() para compatibilidade com $_SERVER / runtime vars
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        // Cast conforme conteúdo
        return match (true) {
            strtolower((string) $value) === 'true'    => true,
            strtolower((string) $value) === 'false'   => false,
            strtolower((string) $value) === 'null'    => null,
            strtolower((string) $value) === '(null)'  => null,
            strtolower((string) $value) === '(empty)' => '',

            // Quoted string: "value" → value
            str_starts_with((string) $value, '"') && str_ends_with((string) $value, '"')
                => substr((string) $value, 1, -1),

            // Integer: pure digits (optional leading minus)
            preg_match('/^-?\d+$/', (string) $value) === 1 => (int) $value,

            // Float
            is_numeric($value) => (float) $value,

            default => (string) $value,
        };
    }
}

// ─── config() ────────────────────────────────────────────────────────────────

if (!function_exists('config')) {
    /**
     * Lê um valor de configuração de config/app.php usando dot notation.
     *
     * Uso:
     *   $id = config('mercadolivre.client_id');
     *   $env = config('app_env', 'production');
     */
    function config(string $key, mixed $default = null): mixed
    {
        return \App\Core\Config::getInstance()->get($key, $default);
    }
}

// ─── base_path() ─────────────────────────────────────────────────────────────

if (!function_exists('base_path')) {
    /**
     * Retorna o caminho absoluto para a raiz do projeto.
     *
     * Uso: base_path('config', 'app.php')  →  /var/www/config/app.php
     */
    function base_path(string ...$segments): string
    {
        $base = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2);
        if (empty($segments)) {
            return $base;
        }
        return $base . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
    }
}

// ─── app_path() ──────────────────────────────────────────────────────────────

if (!function_exists('app_path')) {
    /**
     * Retorna o caminho absoluto para a pasta app/.
     *
     * Uso: app_path('Services', 'UserService.php')
     */
    function app_path(string ...$segments): string
    {
        $base = defined('APP_PATH') ? APP_PATH : (base_path() . DIRECTORY_SEPARATOR . 'app');
        if (empty($segments)) {
            return $base;
        }
        return $base . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
    }
}

// ─── storage_path() ──────────────────────────────────────────────────────────

if (!function_exists('storage_path')) {
    /**
     * Retorna o caminho absoluto para a pasta storage/.
     *
     * Uso: storage_path('logs', 'app.log')
     */
    function storage_path(string ...$segments): string
    {
        $base = base_path('storage');
        if (empty($segments)) {
            return $base;
        }
        return $base . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
    }
}

// ─── db() ────────────────────────────────────────────────────────────────────

if (!function_exists('db')) {
    /**
     * Retorna um QueryBuilder configurado para a tabela informada.
     *
     * Atalho para: (new \App\Core\QueryBuilder(\App\Database::getInstance()))->table($table)
     *
     * Uso:
     *   $rows  = db('notifications')->where('user_id', $id)->get();
     *   $count = db('items')->where('active', 1)->count();
     *   $id    = db('users')->insert(['name' => 'Alice', 'age' => 30]);
     */
    function db(string $table): \App\Core\QueryBuilder
    {
        return (new \App\Core\QueryBuilder(\App\Database::getInstance()))->table($table);
    }
}

// ─── collect() ───────────────────────────────────────────────────────────────

if (!function_exists('collect')) {
    /**
     * Cria uma Collection a partir de um array.
     * Atalho para new \App\Core\Collection($items).
     *
     * Uso:
     *   $names = collect($users)->pluck('name')->sort()->values()->all();
     *   $total = collect($items)->sum('price');
     *
     * @param array<int|string, mixed> $items
     * @return \App\Core\Collection<mixed>
     */
    function collect(array $items = []): \App\Core\Collection
    {
        return \App\Core\Collection::make($items);
    }
}

// ─── deduplicate_items() ─────────────────────────────────────────────────

if (!function_exists('deduplicate_items')) {
    /**
     * Deduplicates a list of items while preserving the first-seen order by default.
     *
     * Each item is normalized via a callback when provided, otherwise the raw value
     * is converted to string, trimmed, and lower-cased for comparison.
     *
     * @template T
     * @param array<int, T> $items
     * @param (callable(T): string)|null $normalizer
     * @param bool $preserveFirstOccurrence
     * @return array<int, T>
     */
    function deduplicate_items(array $items, ?callable $normalizer = null, bool $preserveFirstOccurrence = true): array
    {
        if ($items === []) {
            return [];
        }

        $seen = [];
        $result = [];

        foreach ($items as $item) {
            if ($item === null) {
                continue;
            }

            $candidate = $normalizer !== null ? $normalizer($item) : $item;
            $normalized = is_string($candidate) ? $candidate : (string) $candidate;
            $normalized = trim($normalized);
            $normalized = strtolower($normalized);

            if ($normalized === '') {
                continue;
            }

            if (array_key_exists($normalized, $seen)) {
                if ($preserveFirstOccurrence) {
                    continue;
                }

                $index = $seen[$normalized];
                if ($index !== null && isset($result[$index])) {
                    unset($result[$index]);
                    $result = array_values($result);

                    foreach ($seen as $key => $value) {
                        if ($key !== $normalized && $value > $index) {
                            $seen[$key] = $value - 1;
                        }
                    }
                }

                $seen[$normalized] = count($result);
                $result[] = $item;
                continue;
            }

            $seen[$normalized] = count($result);
            $result[] = $item;
        }

        return $result;
    }
}

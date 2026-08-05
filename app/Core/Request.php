<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Request - Centraliza acesso, validação e sanitização de inputs HTTP.
 *
 * Uso:
 *   $request = new Request();
 *   $page = $request->getInt('page', 1);
 *   $search = $request->getString('search');
 *   $data = $request->json();
 *   $file = $request->file('image');
 */
class Request
{
    private array $query;
    private array $post;
    private array $server;
    private array $files;
    private array $cookies;
    private ?array $jsonBody = null;

    public function __construct()
    {
        $this->query = $_GET;
        $this->post = $_POST;
        $this->server = $_SERVER;
        $this->files = $_FILES;
        $this->cookies = $_COOKIE;
    }

    // ── Query (GET) Parameters ───────────────────────────────────────

    /**
     * Obtém parâmetro GET sanitizado como string.
     */
    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? $default;
        if ($value !== null && !is_string($value)) {
            throw new \InvalidArgumentException("Query parameter '{$key}' must be scalar");
        }

        return $value !== null ? $this->sanitizeString($value) : null;
    }

    /**
     * Obtém parâmetro GET como inteiro.
     */
    public function getInt(string $key, int $default = 0): int
    {
        return (int) $this->assertScalar($this->query[$key] ?? $default, $key, 'query');
    }

    /**
     * Obtém parâmetro GET como float.
     */
    public function getFloat(string $key, float $default = 0.0): float
    {
        return (float) $this->assertScalar($this->query[$key] ?? $default, $key, 'query');
    }

    /**
     * Obtém parâmetro GET como boolean.
     */
    public function getBool(string $key, bool $default = false): bool
    {
        if (!isset($this->query[$key])) {
            return $default;
        }
        return filter_var($this->assertScalar($this->query[$key], $key, 'query'), FILTER_VALIDATE_BOOLEAN);
    }

    // ── POST Parameters ──────────────────────────────────────────────

    /**
     * Obtém parâmetro POST sanitizado como string.
     */
    public function post(string $key, ?string $default = null): ?string
    {
        $value = $this->post[$key] ?? $default;
        if ($value !== null && !is_string($value)) {
            throw new \InvalidArgumentException("Post parameter '{$key}' must be scalar");
        }
        return $value !== null ? $this->sanitizeString($value) : null;
    }

    /**
     * Obtém parâmetro POST como inteiro.
     */
    public function postInt(string $key, int $default = 0): int
    {
        return (int) $this->assertScalar($this->post[$key] ?? $default, $key, 'post');
    }

    /**
     * Obtém parâmetro POST como array.
     */
    public function postArray(string $key, array $default = []): array
    {
        $value = $this->post[$key] ?? $default;
        return is_array($value) ? $value : $default;
    }

    // ── JSON Body ────────────────────────────────────────────────────

    /**
     * Decodifica o body JSON da requisição.
     */
    public function json(): ?array
    {
        if ($this->jsonBody === null) {
            $raw = file_get_contents('php://input');
            $this->jsonBody = json_decode($raw, true) ?? [];
        }
        return $this->jsonBody;
    }

    /**
     * Obtém campo específico do JSON body.
     */
    public function jsonField(string $key, mixed $default = null): mixed
    {
        $data = $this->json();
        return $data[$key] ?? $default;
    }

    // ── Input (GET + POST + JSON) ────────────────────────────────────

    /**
     * Busca input de qualquer fonte (GET > POST > JSON), sanitizado.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        if (isset($this->query[$key])) {
            $value = $this->assertScalar($this->query[$key], $key, 'query');
            return $this->sanitizeString((string) $value);
        }
        if (isset($this->post[$key])) {
            $value = $this->assertScalar($this->post[$key], $key, 'post');
            return $this->sanitizeString((string) $value);
        }
        $json = $this->json();
        return $json[$key] ?? $default;
    }

    /**
     * Obtém input como inteiro de qualquer fonte.
     */
    public function inputInt(string $key, int $default = 0): int
    {
        $value = $this->query[$key] ?? $this->post[$key] ?? $this->json()[$key] ?? $default;
        return (int) $this->assertScalar($value, $key, 'input');
    }

    // ── Files ────────────────────────────────────────────────────────

    /**
     * Obtém arquivo enviado com validação básica.
     *
     * @param string $key Nome do campo
     * @param array  $allowedMimes Tipos MIME permitidos (ex: ['image/jpeg', 'image/png'])
     * @param int    $maxSize Tamanho máximo em bytes (default: 10MB)
     * @return array{name: string, tmp_name: string, type: string, size: int, error: int}|null
     */
    public function file(string $key, array $allowedMimes = [], int $maxSize = 10485760): ?array
    {
        if (!isset($this->files[$key]) || $this->files[$key]['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $file = $this->files[$key];

        // Validar tamanho
        if ($file['size'] > $maxSize) {
            return null;
        }

        // Validar MIME real (não confiar no tipo informado pelo cliente)
        if (!empty($allowedMimes)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $realMime = $finfo->file($file['tmp_name']);
            if (!in_array($realMime, $allowedMimes, true)) {
                return null;
            }
            $file['validated_mime'] = $realMime;
        }

        return $file;
    }

    // ── Server / Headers ─────────────────────────────────────────────

    /**
     * Obtém método HTTP da requisição.
     */
    public function method(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * Obtém header HTTP.
     */
    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $this->server[$key] ?? $default;
    }

    /**
     * Obtém IP do cliente.
     */
    public function ip(): string
    {
        return $this->server['HTTP_X_FORWARDED_FOR']
            ?? $this->server['HTTP_X_REAL_IP']
            ?? $this->server['REMOTE_ADDR']
            ?? '0.0.0.0';
    }

    /**
     * Verifica se a requisição é AJAX/XHR.
     */
    public function isAjax(): bool
    {
        return ($this->server['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
            || $this->header('Accept') === 'application/json';
    }

    /**
     * Obtém o URI da requisição.
     */
    public function uri(): string
    {
        return $this->server['REQUEST_URI'] ?? '/';
    }

    // ── Validation ───────────────────────────────────────────────────

    /**
     * Retorna todos os inputs (GET + POST + JSON body) como array único.
     * Prioridade: GET sobrescreve POST, POST sobrescreve JSON.
     * Não sanitiza — use os accessors tipados para leitura final.
     */
    public function all(): array
    {
        $json = $this->json() ?? [];
        return array_merge($json, $this->post, $this->query);
    }

    /**
     * Valida que os campos obrigatórios estão presentes no JSON/POST.
     *
     * @return array Campos ausentes (vazio se todos presentes)
     */
    public function validateRequired(array $fields, ?array $data = null): array
    {
        $data = $data ?? ($this->method() === 'GET' ? $this->query : ($this->json() ?: $this->post));
        $missing = [];

        foreach ($fields as $field) {
            if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * Obtém parâmetro GET validado contra lista de valores permitidos.
     */
    public function getEnum(string $key, array $allowed, string $default = ''): string
    {
        $value = $this->assertScalar($this->query[$key] ?? $default, $key, 'query');
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * Obtém parâmetro GET como inteiro dentro de um range.
     */
    public function getIntClamped(string $key, int $min, int $max, int $default = 0): int
    {
        $value = (int) $this->assertScalar($this->query[$key] ?? $default, $key, 'query');
        return max($min, min($max, $value));
    }

    /**
     * Obtém parâmetro GET como sort direction (ASC/DESC).
     */
    public function getSortDir(string $key = 'dir', string $default = 'DESC'): string
    {
        $value = strtoupper((string) $this->assertScalar($this->query[$key] ?? $default, $key, 'query'));
        return $value === 'ASC' ? 'ASC' : 'DESC';
    }

    // ── Sanitization (private) ───────────────────────────────────────

    /**
     * Sanitiza string contra XSS.
     */
    private function sanitizeString(string $value): string
    {
        return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
    }

    private function assertScalar(mixed $value, string $key, string $source): string|int|float|bool|null
    {
        if ($value !== null && !is_scalar($value)) {
            throw new \InvalidArgumentException(ucfirst($source) . " parameter '{$key}' must be scalar");
        }

        return $value;
    }
}

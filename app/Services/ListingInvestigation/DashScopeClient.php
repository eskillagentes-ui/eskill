<?php

declare(strict_types=1);

namespace App\Services\ListingInvestigation;

use Throwable;

/**
 * OpenAI-compatible DashScope client (Qwen). Never logs the API key.
 * Read-only caller: drafts only. No Mercado Livre writes.
 */
final class DashScopeClient
{
    public const MODEL_PLUS = 'qwen3.7-plus';
    public const MODEL_MAX = 'qwen3.8-max';
    public const MODEL_PLUS_FALLBACK = 'qwen-plus';
    public const MODEL_MAX_FALLBACK = 'qwen-max';

    public const DEFAULT_BASE = 'https://dashscope.aliyuncs.com/compatible-mode/v1';
    public const INTL_BASE = 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1';

    private string $apiKey;
    private string $baseUrl;

    /** @var callable|null fn(string $url, array $headers, array $payload): ?array */
    private $transport;

    public function __construct(?string $apiKey = null, ?string $baseUrl = null, ?callable $transport = null)
    {
        $this->apiKey = trim((string) ($apiKey ?? $this->env('DASHSCOPE_API_KEY') ?? $this->env('QWEN_API_KEY') ?? ''));
        $configured = trim((string) ($baseUrl ?? $this->env('DASHSCOPE_BASE_URL') ?? ''));
        $this->baseUrl = rtrim($configured !== '' ? $configured : self::DEFAULT_BASE, '/');
        $this->transport = $transport;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return array{content:?string, model:string, raw?:array<string,mixed>}|null
     */
    public function complete(string $model, array $messages, int $timeoutSeconds = 25): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $tried = [];
        foreach ($this->modelCandidates($model) as $candidate) {
            foreach ($this->baseCandidates() as $base) {
                $key = $base . '|' . $candidate;
                if (isset($tried[$key])) {
                    continue;
                }
                $tried[$key] = true;
                $result = $this->postChat($base, $candidate, $messages, $timeoutSeconds);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function modelCandidates(string $model): array
    {
        $model = trim($model);
        if ($model === self::MODEL_MAX || $model === self::MODEL_MAX_FALLBACK) {
            return [self::MODEL_MAX, self::MODEL_MAX_FALLBACK];
        }

        return [self::MODEL_PLUS, self::MODEL_PLUS_FALLBACK];
    }

    /**
     * @return list<string>
     */
    private function baseCandidates(): array
    {
        $bases = [$this->baseUrl];
        if ($this->baseUrl !== self::INTL_BASE) {
            $bases[] = self::INTL_BASE;
        }

        return $bases;
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return array{content:?string, model:string, raw?:array<string,mixed>}|null
     */
    private function postChat(string $base, string $model, array $messages, int $timeoutSeconds): ?array
    {
        $url = rtrim($base, '/') . '/chat/completions';
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.2,
            'max_tokens' => 800,
        ];
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];

        try {
            $decoded = $this->transport !== null
                ? ($this->transport)($url, $headers, $payload)
                : $this->curlJson($url, $headers, $payload, $timeoutSeconds);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }
        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            return null;
        }

        return [
            'content' => $content,
            'model' => (string) ($decoded['model'] ?? $model),
            'raw' => $decoded,
        ];
    }

    /**
     * @param list<string> $headers
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function curlJson(string $url, array $headers, array $payload, int $timeoutSeconds): ?array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            return null;
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function env(string $name): ?string
    {
        $v = $_ENV[$name] ?? getenv($name);
        if ($v === false || $v === null) {
            return null;
        }
        $v = trim((string) $v);

        return $v !== '' ? $v : null;
    }
}

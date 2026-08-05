<?php

declare(strict_types=1);

namespace App\Services\Pregao;

final class PregaoQaWorkerEnvironment
{
    /** @var list<string> */
    private const REQUIRED = [
        'PREGAO_QA_RUN_ID',
        'PREGAO_QA_BASE_URL',
        'PREGAO_QA_OUTPUT_DIR',
        'PREGAO_QA_SESSION_COOKIE',
        'PREGAO_QA_ACCOUNT_ID',
    ];

    /**
     * Chromium is a descendant of Node, so this exact array is the complete
     * environment inherited by both processes.
     *
     * @param array<string,mixed> $host
     * @param array<string,string> $required
     * @return array<string,string>
     */
    public static function build(array $host, array $required): array
    {
        foreach (self::REQUIRED as $key) {
            if (!isset($required[$key]) || $required[$key] === '' || str_contains($required[$key], "\0")) {
                throw new \InvalidArgumentException('Ambiente QA obrigatório ausente');
            }
        }
        if (preg_match('/\A[1-9][0-9]*\z/D', $required['PREGAO_QA_ACCOUNT_ID']) !== 1) {
            throw new \InvalidArgumentException('Conta QA obrigatória inválida');
        }
        $path = self::safeValue($host['PATH'] ?? null, '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');
        $home = self::safeAbsolutePath($host['HOME'] ?? null, '/home/eskill');
        $lang = self::safeLocale($host['LANG'] ?? null, 'C.UTF-8');
        $tmp = self::safeAbsolutePath($host['TMPDIR'] ?? null, '/tmp');
        $browser = self::safeAbsolutePath(
            $host['PREGAO_QA_BROWSER_EXECUTABLE'] ?? null,
            '/usr/bin/google-chrome-stable'
        );

        return [
            'PATH' => $path,
            'HOME' => $home,
            'LANG' => $lang,
            'TMPDIR' => $tmp,
            'PREGAO_QA_BROWSER_EXECUTABLE' => $browser,
            'PREGAO_QA_RUN_ID' => $required['PREGAO_QA_RUN_ID'],
            'PREGAO_QA_BASE_URL' => $required['PREGAO_QA_BASE_URL'],
            'PREGAO_QA_OUTPUT_DIR' => $required['PREGAO_QA_OUTPUT_DIR'],
            'PREGAO_QA_SESSION_COOKIE' => $required['PREGAO_QA_SESSION_COOKIE'],
            'PREGAO_QA_ACCOUNT_ID' => $required['PREGAO_QA_ACCOUNT_ID'],
        ];
    }

    private static function safeValue(mixed $value, string $fallback): string
    {
        return is_string($value) && $value !== '' && !str_contains($value, "\0") ? $value : $fallback;
    }

    private static function safeAbsolutePath(mixed $value, string $fallback): string
    {
        return is_string($value) && str_starts_with($value, '/') && !str_contains($value, "\0") ? $value : $fallback;
    }

    private static function safeLocale(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9_.@-]{1,64}\z/D', $value) === 1 ? $value : $fallback;
    }
}

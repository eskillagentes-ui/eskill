<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Gate global para escrita automática no Mercado Livre / decisões autônomas.
 * Padrão: DESLIGADO (ADR-003). Só liga com ML_WRITE_AUTOMATION=true explícito.
 */
final class MlWriteAutomation
{
    public static function isEnabled(): bool
    {
        $raw = $_ENV['ML_WRITE_AUTOMATION'] ?? getenv('ML_WRITE_AUTOMATION') ?? 'false';
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN) === true;
    }

    /**
     * @return array{allowed: bool, reason: string}
     */
    public static function guard(string $action = 'write'): array
    {
        if (self::isEnabled()) {
            return ['allowed' => true, 'reason' => 'ML_WRITE_AUTOMATION enabled'];
        }

        if (function_exists('log_warning')) {
            log_warning('ML write automation blocked', [
                'action' => $action,
                'flag' => 'ML_WRITE_AUTOMATION',
            ]);
        }

        return [
            'allowed' => false,
            'reason' => 'ML_WRITE_AUTOMATION is disabled (recommend-only mode)',
        ];
    }

    /**
     * Workers de escrita/automação: saem com código 0 se a flag estiver off (ADR-003).
     */
    public static function exitIfDisabledForCli(string $workerName): void
    {
        if (self::isEnabled()) {
            return;
        }

        $msg = sprintf(
            "[%s] ML_WRITE_AUTOMATION=false — worker em quarentena (recommend-only). Saindo.\n",
            $workerName
        );
        fwrite(STDERR, $msg);
        if (function_exists('log_info')) {
            log_info('Worker automation quarantined', [
                'worker' => $workerName,
                'flag' => 'ML_WRITE_AUTOMATION',
            ]);
        }
        exit(0);
    }
}

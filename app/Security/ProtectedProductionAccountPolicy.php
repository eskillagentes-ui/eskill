<?php

declare(strict_types=1);

namespace App\Security;

use App\Exception\UnsafeOperationException;

/**
 * Impede que staging vincule a identidade de produção FACILYTY.
 *
 * Produção continua podendo reconectar 1335 / ml_user_id 3058804121.
 * Staging nunca liga essa identidade (OAuth novo ou reconexão).
 */
final class ProtectedProductionAccountPolicy
{
    public const FACILYTY_INTERNAL_ID = 1335;
    public const FACILYTY_ML_USER_ID = '3058804121';
    public const FACILYTY_NICKNAME = 'FACILYTY';

    public static function isStaging(?string $appEnv = null, ?string $cwd = null, ?string $appUrl = null): bool
    {
        $env = strtolower(trim((string) ($appEnv ?? ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: ''))));
        if ($env === 'staging') {
            return true;
        }

        $path = (string) ($cwd ?? (getcwd() !== false ? getcwd() : ''));
        if (str_contains($path, 'staging.eskill.com.br')) {
            return true;
        }

        $url = strtolower((string) ($appUrl ?? ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: '')));

        return str_contains($url, 'staging.eskill.com.br');
    }

    public function isFacilytyIdentity(string|int $mlUserId, ?string $nickname = null, ?int $internalAccountId = null): bool
    {
        if ($internalAccountId === self::FACILYTY_INTERNAL_ID) {
            return true;
        }
        if ((string) $mlUserId === self::FACILYTY_ML_USER_ID) {
            return true;
        }
        $nick = strtoupper(trim((string) $nickname));

        return $nick === self::FACILYTY_NICKNAME;
    }

    public function canLinkIdentity(
        string|int $mlUserId,
        ?string $nickname = null,
        ?int $internalAccountId = null,
        ?bool $staging = null
    ): bool {
        $isStaging = $staging ?? self::isStaging();
        if (!$isStaging) {
            return true;
        }

        return !$this->isFacilytyIdentity($mlUserId, $nickname, $internalAccountId);
    }

    /**
     * @throws UnsafeOperationException
     */
    public function assertCanLinkIdentity(
        string|int $mlUserId,
        ?string $nickname = null,
        ?int $internalAccountId = null,
        ?bool $staging = null
    ): void {
        if ($this->canLinkIdentity($mlUserId, $nickname, $internalAccountId, $staging)) {
            return;
        }

        throw new UnsafeOperationException(
            'Staging não pode vincular a identidade FACILYTY de produção '
            . '(account 1335 / ml_user_id ' . self::FACILYTY_ML_USER_ID . ').'
        );
    }
}

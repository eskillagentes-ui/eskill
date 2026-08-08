<?php

declare(strict_types=1);

namespace App\Services\HiddenSeo;

use App\Exception\UnsafeOperationException;

/**
 * Guarda de segurança para Hidden SEO / Ficha Técnica.
 *
 * SAFE_MODE=true (default): apply ML exige conta fora da blacklist.
 * FORBIDDEN_ACCOUNTS (default 1335): apply sempre bloqueado.
 *
 * @see https://developers.mercadolivre.com.br/pt_br/api-docs-pt-br/atributos
 */
class SafetyGuard
{
    public const DEFAULT_FORBIDDEN = [1335];
    public const DEFAULT_MAX_ITEMS = 500;

    private bool $safeMode;
    /** @var list<int> */
    private array $forbiddenAccounts;
    private int $maxItemsPerRun;

    public function __construct(
        ?bool $safeMode = null,
        ?array $forbiddenAccounts = null,
        ?int $maxItemsPerRun = null
    ) {
        $this->safeMode = $safeMode ?? $this->envBool('SAFE_MODE', true);
        $this->forbiddenAccounts = $forbiddenAccounts ?? $this->envForbiddenAccounts();
        $this->maxItemsPerRun = $maxItemsPerRun ?? max(
            1,
            (int)(getenv('MAX_ITEMS_PER_RUN') ?: ($_ENV['MAX_ITEMS_PER_RUN'] ?? self::DEFAULT_MAX_ITEMS))
        );
    }

    public function isSafeMode(): bool
    {
        return $this->safeMode;
    }

    public function isForbidden(int $accountId): bool
    {
        return in_array($accountId, $this->forbiddenAccounts, true);
    }

    /**
     * @return list<int>
     */
    public function forbiddenAccounts(): array
    {
        return $this->forbiddenAccounts;
    }

    public function maxItemsPerRun(): int
    {
        return $this->maxItemsPerRun;
    }

    public function clampLimit(int $limit): int
    {
        if ($limit <= 0) {
            return min(100, $this->maxItemsPerRun);
        }
        return min($limit, $this->maxItemsPerRun);
    }

    /**
     * Dry-run sempre permitido. Apply real: bloqueia blacklist; em SAFE_MODE
     * também exige $allowApply explícito (flag --apply / confirmação).
     *
     * @throws UnsafeOperationException
     */
    public function assertCanApply(int $accountId, bool $dryRun, bool $allowApply = false): void
    {
        if ($dryRun) {
            return;
        }

        if ($this->isForbidden($accountId)) {
            throw new UnsafeOperationException(
                "Apply bloqueado: conta {$accountId} está na blacklist (FORBIDDEN_ACCOUNTS). "
                . 'Hidden SEO / Ficha Técnica não aplica automaticamente na FACILYTY (1335).'
            );
        }

        if ($this->safeMode && !$allowApply) {
            throw new UnsafeOperationException(
                'Apply bloqueado: SAFE_MODE=true exige flag explícita --apply (e conta fora da blacklist).'
            );
        }
    }

    private function envBool(string $key, bool $default): bool
    {
        $raw = getenv($key);
        if ($raw === false) {
            $raw = $_ENV[$key] ?? null;
        }
        if ($raw === null || $raw === '') {
            return $default;
        }
        $v = strtolower(trim((string)$raw));
        if (in_array($v, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($v, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return $default;
    }

    /**
     * @return list<int>
     */
    private function envForbiddenAccounts(): array
    {
        $raw = getenv('FORBIDDEN_ACCOUNTS');
        if ($raw === false || $raw === '') {
            $raw = $_ENV['FORBIDDEN_ACCOUNTS'] ?? '1335';
        }
        $parts = preg_split('/[,\s]+/', (string)$raw) ?: [];
        $ids = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '' && ctype_digit($p)) {
                $ids[] = (int)$p;
            }
        }
        return $ids !== [] ? array_values(array_unique($ids)) : self::DEFAULT_FORBIDDEN;
    }
}

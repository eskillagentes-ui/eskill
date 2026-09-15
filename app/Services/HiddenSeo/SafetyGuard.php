<?php

declare(strict_types=1);

namespace App\Services\HiddenSeo;

use App\Exception\UnsafeOperationException;
use Throwable;

/**
 * Guarda de segurança para Hidden SEO / Ficha Técnica / apply ML.
 *
 * SAFE_MODE=true (default): apply real exige $allowApply explícito.
 * FACILYTY (1335): apply/mutate SEMPRE exige ItemGoGrant ativa para aquele MLB,
 * mesmo se 1335 NÃO estiver em FORBIDDEN_ACCOUNTS.
 * FORBIDDEN_ACCOUNTS: blacklist opcional extra para outras contas.
 * Sentinels none / - / empty (e 0 ignorado) → lista vazia; env vazio NÃO cai em 1335.
 * ML_WRITE_AUTOMATION não é lido aqui (permanece false por padrão noutros gates).
 */
class SafetyGuard
{
    public const FACILYTY_ACCOUNT = 1335;
    /** @var list<int> env vazio / sentinel → nenhuma conta extra na blacklist */
    public const DEFAULT_FORBIDDEN = [];
    public const DEFAULT_MAX_ITEMS = 500;
    /** @var list<string> */
    public const EMPTY_FORBIDDEN_SENTINELS = ['none', '-', 'empty'];

    private bool $safeMode;
    /** @var list<int> */
    private array $forbiddenAccounts;
    private int $maxItemsPerRun;
    private ?ItemGoGrantLookup $grants;

    /**
     * @param list<int>|null $forbiddenAccounts
     */
    public function __construct(
        ?bool $safeMode = null,
        ?array $forbiddenAccounts = null,
        ?int $maxItemsPerRun = null,
        ?ItemGoGrantLookup $grants = null
    ) {
        $this->safeMode = $safeMode ?? $this->envBool('SAFE_MODE', true);
        $this->forbiddenAccounts = $forbiddenAccounts ?? $this->envForbiddenAccounts();
        $this->maxItemsPerRun = $maxItemsPerRun ?? max(
            1,
            (int)(getenv('MAX_ITEMS_PER_RUN') ?: ($_ENV['MAX_ITEMS_PER_RUN'] ?? self::DEFAULT_MAX_ITEMS))
        );
        $this->grants = $grants;
    }

    public function isSafeMode(): bool
    {
        return $this->safeMode;
    }

    public function isFacilyty(int $accountId): bool
    {
        return $accountId === self::FACILYTY_ACCOUNT;
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
     * Dry-run sempre permitido. Apply real:
     *  - FACILYTY: ItemGoGrant ativa daquele MLB + (SAFE_MODE ⇒ allowApply)
     *  - outras contas em FORBIDDEN_ACCOUNTS: sempre bloqueado
     *  - SAFE_MODE: exige $allowApply
     *
     * @throws UnsafeOperationException
     */
    public function assertCanApply(int $accountId, bool $dryRun, bool $allowApply = false, ?string $mlbId = null): void
    {
        if ($dryRun) {
            return;
        }

        if ($this->isFacilyty($accountId)) {
            $mlb = strtoupper(trim((string) $mlbId));
            if ($mlb === '' || !$this->hasActiveGrant($accountId, $mlb)) {
                throw new UnsafeOperationException(
                    "Apply bloqueado: FACILYTY (conta {$accountId}) exige ItemGoGrant ativa para o MLB. "
                    . 'Um anúncio por vez; lote/massa continua proibido.'
                );
            }

            if ($this->safeMode && !$allowApply) {
                throw new UnsafeOperationException(
                    'Apply bloqueado: SAFE_MODE=true exige flag explícita --apply (e ItemGoGrant do MLB).'
                );
            }

            return;
        }

        if ($this->isForbidden($accountId)) {
            throw new UnsafeOperationException(
                "Apply bloqueado: conta {$accountId} está na blacklist (FORBIDDEN_ACCOUNTS). "
                . 'Hidden SEO / Ficha Técnica não aplica automaticamente nesta conta.'
            );
        }

        if ($this->safeMode && !$allowApply) {
            throw new UnsafeOperationException(
                'Apply bloqueado: SAFE_MODE=true exige flag explícita --apply (e conta fora da blacklist).'
            );
        }
    }

    public function hasActiveGrant(int $accountId, string $mlbId): bool
    {
        $lookup = $this->resolveGrants();
        if ($lookup === null) {
            return false;
        }

        try {
            return $lookup->hasActive($accountId, $mlbId);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Consome a grant FACILYTY no apply real. No-op para outras contas.
     *
     * @throws UnsafeOperationException
     */
    public function consumeOnApply(int $accountId, string $mlbId): void
    {
        if (!$this->isFacilyty($accountId)) {
            return;
        }
        $mlb = strtoupper(trim($mlbId));
        if ($mlb === '' || !$this->consumeGrant($accountId, $mlb)) {
            throw new UnsafeOperationException(
                "Apply bloqueado: ItemGoGrant do MLB {$mlb} não pôde ser consumida (já usada, expirada ou corrida)."
            );
        }
    }

    /**
     * Interpreta FORBIDDEN_ACCOUNTS. Sentinels none / - / empty → [].
     * Token 0 é ignorado. Env vazio NÃO faz fallback para 1335.
     *
     * @return list<int>
     */
    public static function parseForbiddenAccounts(string $raw): array
    {
        $trimmed = strtolower(trim($raw));
        if ($trimmed === '' || in_array($trimmed, self::EMPTY_FORBIDDEN_SENTINELS, true)) {
            return [];
        }

        $parts = preg_split('/[,\s]+/', $raw) ?: [];
        $ids = [];
        foreach ($parts as $p) {
            $p = trim($p);
            $lower = strtolower($p);
            if ($p === '' || in_array($lower, self::EMPTY_FORBIDDEN_SENTINELS, true)) {
                continue;
            }
            if (!ctype_digit($p)) {
                continue;
            }
            $id = (int) $p;
            if ($id <= 0) {
                continue;
            }
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
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
        if ($raw === false) {
            $raw = $_ENV['FORBIDDEN_ACCOUNTS'] ?? '';
        }

        return self::parseForbiddenAccounts((string) $raw);
    }

    private function resolveGrants(): ?ItemGoGrantLookup
    {
        if ($this->grants instanceof ItemGoGrantLookup) {
            return $this->grants;
        }

        try {
            $this->grants = new ItemGoGrant();
            return $this->grants;
        } catch (Throwable) {
            return null;
        }
    }

    private function consumeGrant(int $accountId, string $mlbId): bool
    {
        $lookup = $this->resolveGrants();
        if ($lookup === null) {
            return false;
        }

        try {
            return $lookup->consume($accountId, $mlbId);
        } catch (Throwable) {
            return false;
        }
    }
}

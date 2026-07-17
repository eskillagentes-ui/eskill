<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Contexto de conta ML já autorizado para um ator.
 *
 * organizationId segue ADR-001: igual a ownerUserId até existir modelo real de organizações.
 */
final class AuthorizedAccountContext
{
    public function __construct(
        private readonly int $accountId,
        private readonly int $ownerUserId,
        private readonly int $organizationId,
        private readonly int $actorUserId,
        private readonly string $capability,
        private readonly string $status,
        private readonly ?string $mlUserId = null,
        private readonly ?string $nickname = null,
    ) {
        if ($this->accountId <= 0) {
            throw new \InvalidArgumentException('accountId inválido');
        }
        if ($this->ownerUserId <= 0) {
            throw new \InvalidArgumentException('ownerUserId inválido');
        }
        if ($this->actorUserId <= 0) {
            throw new \InvalidArgumentException('actorUserId inválido');
        }
        if ($this->capability === '') {
            throw new \InvalidArgumentException('capability obrigatória');
        }
    }

    public function accountId(): int
    {
        return $this->accountId;
    }

    public function ownerUserId(): int
    {
        return $this->ownerUserId;
    }

    public function organizationId(): int
    {
        return $this->organizationId;
    }

    public function actorUserId(): int
    {
        return $this->actorUserId;
    }

    public function capability(): string
    {
        return $this->capability;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function mlUserId(): ?string
    {
        return $this->mlUserId;
    }

    public function nickname(): ?string
    {
        return $this->nickname;
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toAuditArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'owner_user_id' => $this->ownerUserId,
            'organization_id' => $this->organizationId,
            'actor_user_id' => $this->actorUserId,
            'capability' => $this->capability,
            'status' => $this->status,
            'ml_user_id' => $this->mlUserId,
            'nickname' => $this->nickname,
        ];
    }
}

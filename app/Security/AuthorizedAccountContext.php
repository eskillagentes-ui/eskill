<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Value object imutável produzido após autorização bem-sucedida de acesso a uma
 * conta Mercado Livre.
 *
 * Regras:
 * - Não contém access_token, refresh_token nem client_secret.
 * - Imutável após criação.
 * - Contém apenas metadados autorizados.
 */
final class AuthorizedAccountContext
{
    private function __construct(
        private readonly int     $accountId,
        private readonly int     $ownerUserId,
        private readonly int     $organizationId,
        private readonly string  $marketplace,
        private readonly ?string $siteId,
        private readonly string  $status,
        private readonly array   $capabilities,
    ) {}

    public static function create(
        int     $accountId,
        int     $ownerUserId,
        int     $organizationId,
        string  $marketplace,
        ?string $siteId,
        string  $status,
        array   $capabilities = []
    ): self {
        return new self(
            accountId:      $accountId,
            ownerUserId:    $ownerUserId,
            organizationId: $organizationId,
            marketplace:    $marketplace,
            siteId:         $siteId,
            status:         $status,
            capabilities:   $capabilities,
        );
    }

    public function getAccountId(): int
    {
        return $this->accountId;
    }

    public function getOwnerUserId(): int
    {
        return $this->ownerUserId;
    }

    /** Limite transitório: equivale a owner_user_id (ADR-001 / SEC-001). */
    public function getOrganizationId(): int
    {
        return $this->organizationId;
    }

    public function getMarketplace(): string
    {
        return $this->marketplace;
    }

    public function getSiteId(): ?string
    {
        return $this->siteId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /** @return string[] */
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
}

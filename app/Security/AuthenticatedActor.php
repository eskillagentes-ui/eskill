<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Value object imutável que representa o ator autenticado que realiza uma ação.
 *
 * Regras:
 * - userId deve ser positivo.
 * - Não armazena tokens, senhas ou segredos.
 * - organizationId usa owner_user_id como limite transitório (ADR-001 / SEC-001).
 * - Não obtém identidade de $_GET, $_POST ou headers arbitrários.
 */
final class AuthenticatedActor
{
    public const TYPE_HUMAN   = 'human';
    public const TYPE_API     = 'api';
    public const TYPE_SERVICE = 'service';

    private function __construct(
        private readonly int    $userId,
        private readonly int    $organizationId,
        private readonly string $actorType,
        private readonly ?int   $apiTokenId,
        private readonly array  $scopes,
    ) {}

    /**
     * Cria ator humano (sessão web autenticada).
     *
     * @param int      $userId         ID do usuário autenticado.
     * @param string[] $scopes         Capacidades autorizadas.
     */
    public static function fromHumanSession(
        int   $userId,
        array $scopes = []
    ): self {
        self::assertValidUserId($userId);
        return new self(
            userId:         $userId,
            organizationId: $userId,   // ADR-001: owner_user_id == organizationId
            actorType:      self::TYPE_HUMAN,
            apiTokenId:     null,
            scopes:         $scopes,
        );
    }

    /**
     * Cria ator via token de API.
     *
     * @param int      $userId     ID do usuário dono do token.
     * @param int      $apiTokenId ID do token de API.
     * @param string[] $scopes     Escopos do token.
     */
    public static function fromApiToken(
        int   $userId,
        int   $apiTokenId,
        array $scopes = []
    ): self {
        self::assertValidUserId($userId);
        return new self(
            userId:         $userId,
            organizationId: $userId,   // ADR-001
            actorType:      self::TYPE_API,
            apiTokenId:     $apiTokenId,
            scopes:         $scopes,
        );
    }

    /**
     * Cria ator de serviço interno (workers, crons).
     *
     * @param int      $userId     ID de serviço/usuário responsável.
     * @param string[] $scopes     Escopos permitidos.
     */
    public static function fromService(
        int   $userId,
        array $scopes = []
    ): self {
        self::assertValidUserId($userId);
        return new self(
            userId:         $userId,
            organizationId: $userId,   // ADR-001
            actorType:      self::TYPE_SERVICE,
            apiTokenId:     null,
            scopes:         $scopes,
        );
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    /** Limite transitório: equivale a owner_user_id (ADR-001 / SEC-001). */
    public function getOrganizationId(): int
    {
        return $this->organizationId;
    }

    public function getActorType(): string
    {
        return $this->actorType;
    }

    public function getApiTokenId(): ?int
    {
        return $this->apiTokenId;
    }

    /** @return string[] */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function isHuman(): bool
    {
        return $this->actorType === self::TYPE_HUMAN;
    }

    public function isService(): bool
    {
        return $this->actorType === self::TYPE_SERVICE;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    private static function assertValidUserId(int $userId): void
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException(
                'AuthenticatedActor requer userId positivo.'
            );
        }
    }
}

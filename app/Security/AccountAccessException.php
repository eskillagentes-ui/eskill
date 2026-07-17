<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Falha de autorização de conta ML.
 * Mensagens genéricas — não revelam existência de conta alheia.
 */
class AccountAccessException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 403,
        private readonly string $errorCode = 'account_access_denied',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public static function denied(): self
    {
        return new self('Conta não autorizada ou não selecionada', 403, 'account_access_denied');
    }

    public static function notFound(): self
    {
        return new self('Conta não encontrada', 404, 'account_not_found');
    }

    public static function inactive(): self
    {
        return new self('Conta indisponível', 403, 'account_inactive');
    }

    public static function missingActor(): self
    {
        return new self('Autenticação necessária', 401, 'missing_actor');
    }

    public static function missingAccountForWorker(): self
    {
        return new self('account_id explícito obrigatório para worker/CLI', 400, 'missing_account_id');
    }
}

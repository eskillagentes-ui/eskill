<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Lançada quando o acesso a uma conta é negado pela AccountAccessPolicy.
 *
 * Regras de segurança:
 * - Mensagem externa genérica: não revela se a conta existe.
 * - Não revela o proprietário da conta.
 * - Não revela organização.
 * - Não contém IDs ou tokens na mensagem pública.
 * - Código interno (auditCode) para rastreabilidade interna.
 */
final class AccountAccessDeniedException extends \RuntimeException
{
    private const PUBLIC_MESSAGE = 'Acesso não autorizado.';

    private function __construct(
        private readonly string $auditCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(self::PUBLIC_MESSAGE, 403, $previous);
    }

    /**
     * Cria exceção com código interno de auditoria.
     * O código nunca é exposto na mensagem pública.
     */
    public static function withAuditCode(string $auditCode): self
    {
        return new self($auditCode);
    }

    /**
     * Código interno para logs de segurança.
     * Nunca deve ser exposto em respostas HTTP ao cliente.
     */
    public function getAuditCode(): string
    {
        return $this->auditCode;
    }

    /**
     * Mensagem pública segura (genérica, sem vazamento de informação).
     */
    public function getPublicMessage(): string
    {
        return self::PUBLIC_MESSAGE;
    }
}

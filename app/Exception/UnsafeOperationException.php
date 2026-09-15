<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Operação insegura bloqueada (ex.: apply FACILYTY sem ItemGoGrant, ou conta em FORBIDDEN_ACCOUNTS).
 */
class UnsafeOperationException extends \RuntimeException
{
}

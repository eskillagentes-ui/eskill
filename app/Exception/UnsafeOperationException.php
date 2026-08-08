<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Operação insegura bloqueada (ex.: apply Hidden SEO na conta blacklist 1335).
 */
class UnsafeOperationException extends \RuntimeException
{
}

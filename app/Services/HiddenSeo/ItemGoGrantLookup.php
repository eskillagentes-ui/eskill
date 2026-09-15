<?php

declare(strict_types=1);

namespace App\Services\HiddenSeo;

/**
 * Consulta de autorização GO por MLB (uma grant ativa por conta).
 */
interface ItemGoGrantLookup
{
    public function hasActive(int $accountId, string $mlbId): bool;

    /**
     * Consome a grant ativa deste MLB. Retorna false se não havia grant válida.
     */
    public function consume(int $accountId, string $mlbId): bool;
}

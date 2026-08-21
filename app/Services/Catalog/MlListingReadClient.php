<?php

declare(strict_types=1);

namespace App\Services\Catalog;

/**
 * Superfície read-only usada pelo scanner de irregularidades.
 */
interface MlListingReadClient
{
    /**
     * @param array<string, scalar|null> $params
     * @return array<string, mixed>
     */
    public function getMyItems(array $params = []): array;

    /**
     * @param array<string, scalar|null> $params
     * @return array<string, mixed>
     */
    public function get(string $endpoint, array $params = []): array;

    public function getMlUserId(): ?string;

    /**
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function getLastModeration(string $itemId): array;

    /**
     * @param array<string, scalar|null> $params
     * @return array<string, mixed>
     */
    public function getSellerInfractions(array $params = []): array;
}

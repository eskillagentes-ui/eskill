<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Stub mínimo de ProxyService.
 *
 * AlternativeSearchService / scraping do ML exigem proxy residencial.
 * Sem proxies cadastrados (ml_proxies) ou ML_PROXY_*, retorna null.
 */
class ProxyService
{
    /**
     * @return array{host:string,port:int,user?:string,pass?:string,type?:string}|null
     */
    public function getBestProxy(): ?array
    {
        $enabledRaw = $_ENV['ML_PROXY_ENABLED'] ?? getenv('ML_PROXY_ENABLED') ?? 'false';
        $enabled = filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) {
            return null;
        }

        $host = trim((string) ($_ENV['ML_PROXY_HOST'] ?? getenv('ML_PROXY_HOST') ?? ''));
        $port = (int) ($_ENV['ML_PROXY_PORT'] ?? getenv('ML_PROXY_PORT') ?? 0);
        if ($host === '' || $port <= 0) {
            return null;
        }

        $proxy = [
            'host' => $host,
            'port' => $port,
            'type' => (string) ($_ENV['ML_PROXY_TYPE'] ?? getenv('ML_PROXY_TYPE') ?? 'http'),
        ];

        $user = trim((string) ($_ENV['ML_PROXY_USER'] ?? getenv('ML_PROXY_USER') ?? ''));
        $pass = (string) ($_ENV['ML_PROXY_PASS'] ?? getenv('ML_PROXY_PASS') ?? '');
        if ($user !== '') {
            $proxy['user'] = $user;
            $proxy['pass'] = $pass;
        }

        return $proxy;
    }
}

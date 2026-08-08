<?php

declare(strict_types=1);

namespace App\Services;

/**
 * CacheService - Serviço de Cache com suporte a Redis e File
 *
 * @property-read string $driver
 */
class CacheService
{
    private string $driver;
    private string $cacheDir;
    /** @var \Redis|null */
    private $redis = null;

    public function __construct()
    {
        $config = \App\Core\Config::getInstance();
        $this->driver = $config->get('cache.driver', 'file');
        $this->cacheDir = __DIR__ . '/../../storage/cache';

        if ($this->driver === 'redis') {
            $this->initRedis();
        } else {
            // Garantir que diretório existe
            if (!is_dir($this->cacheDir)) {
                mkdir($this->cacheDir, 0755, true);
            }
        }
    }

    /**
     * Inicializa conexão Redis
     */
    private function initRedis(): void
    {
        if (extension_loaded('redis')) {
            try {
                $redisClass = 'Redis';
                $this->redis = new $redisClass();
                $host = $_ENV['REDIS_HOST'] ?? '127.0.0.1';
                $port = (int)($_ENV['REDIS_PORT'] ?? 6379);
                $this->redis->connect($host, $port);

                $redisPass = $_ENV['REDIS_PASSWORD'] ?? '';
                if ($redisPass && $redisPass !== 'null') {
                    $this->redis->auth($redisPass);
                }
            } catch (\Exception $e) {
                log_warning('Erro ao conectar Redis', ['service' => 'CacheService', 'error' => $e->getMessage()]);
                $this->driver = 'file'; // Fallback para file
            }
        } else {
            $this->driver = 'file'; // Fallback se Redis não disponível
        }
    }

    /**
     * Obtém valor do cache
     */
    public function get(string $key): mixed
    {
        if ($this->driver === 'redis' && $this->redis) {
            $value = $this->redis->get($key);
            return $value ? json_decode($value, true) : null;
        } else {
            return $this->getFromFile($key);
        }
    }

    /**
     * Define valor no cache
     * Suporta assinatura: set(key, value, ttl) ou set(key, value, tag, ttl)
     */
    public function set(string $key, mixed $value, mixed $ttlOrTag = 3600, int $extraTtl = 3600): bool
    {
        $ttl = $ttlOrTag;

        // Suporte a tags (ignoradas por enquanto)
        if (is_string($ttlOrTag)) {
            $ttl = $extraTtl;
        }

        $ttl = (int)$ttl;

        if ($this->driver === 'redis' && $this->redis) {
            return $this->redis->setex($key, $ttl, json_encode($value));
        } else {
            return $this->saveToFile($key, $value, $ttl);
        }
    }

    /**
     * Remove do cache
     */
    public function forget(string $key): bool
    {
        if ($this->driver === 'redis' && $this->redis) {
            return $this->redis->del($key) > 0;
        } else {
            return $this->deleteFromFile($key);
        }
    }

    /**
     * Alias para forget() - Remove do cache
     */
    public function delete(string $key): bool
    {
        return $this->forget($key);
    }

    /**
     * Limpa todo o cache
     */
    public function flush(): bool
    {
        if ($this->driver === 'redis' && $this->redis) {
            return $this->redis->flushDB();
        } else {
            return $this->flushFiles();
        }
    }

    /**
     * Alias para flush() - Limpa todo o cache
     */
    public function clear(): bool
    {
        return $this->flush();
    }

    /**
     * Verifica se existe no cache
     */
    public function has(string $key): bool
    {
        if ($this->driver === 'redis' && $this->redis) {
            return $this->redis->exists($key) > 0;
        } else {
            return $this->hasInFile($key);
        }
    }

    /**
     * Obtém ou calcula e armazena (cache pattern)
     */
    public function remember(string $key, callable $callback, int $ttl = 3600): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * Cache em arquivo - GET
     */
    private function getFromFile(string $key): mixed
    {
        $file = $this->getCacheFile($key);

        if (!file_exists($file)) {
            return null;
        }

        $data = json_decode(file_get_contents($file), true);

        if (!$data || !isset($data['expires_at'])) {
            return null;
        }

        if (time() > $data['expires_at']) {
            @unlink($file); // Suppress permission errors
            return null;
        }

        return $data['value'] ?? null;
    }

    /**
     * Cache em arquivo - SET
     */
    private function saveToFile(string $key, mixed $value, int $ttl): bool
    {
        $file = $this->getCacheFile($key);
        $dir = dirname($file);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $data = [
            'key' => $key,
            'value' => $value,
            'expires_at' => time() + $ttl,
            'created_at' => time(),
        ];

        $result = @file_put_contents($file, json_encode($data), LOCK_EX);
        return $result !== false;
    }

    /**
     * Cache em arquivo - DELETE
     */
    private function deleteFromFile(string $key): bool
    {
        $file = $this->getCacheFile($key);

        if (file_exists($file)) {
            return @unlink($file); // Suppress permission errors
        }

        return true;
    }

    /**
     * Cache em arquivo - HAS
     */
    private function hasInFile(string $key): bool
    {
        $file = $this->getCacheFile($key);

        if (!file_exists($file)) {
            return false;
        }

        $data = json_decode(file_get_contents($file), true);

        if (!$data || !isset($data['expires_at'])) {
            return false;
        }

        if (time() > $data['expires_at']) {
            @unlink($file); // Suppress permission errors
            return false;
        }

        return true;
    }

    /**
     * Cache em arquivo - FLUSH
     */
    private function flushFiles(): bool
    {
        // Limpar arquivos em subdiretórios também
        $files = array_merge(
            glob($this->cacheDir . '/*.json'),
            glob($this->cacheDir . '/*/*.json'),
            glob($this->cacheDir . '/*/*/*.json')
        );

        $deleted = 0;
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
                $deleted++;
            }
        }

        // Remover diretórios vazios
        $dirs = glob($this->cacheDir . '/*', GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            if (is_dir($dir) && count(glob($dir . '/*')) === 0) {
                @rmdir($dir);
            }
        }

        return true;
    }

    /**
     * Obtém caminho do arquivo de cache
     */
    private function getCacheFile(string $key): string
    {
        $hash = md5($key);
        return $this->cacheDir . '/' . substr($hash, 0, 2) . '/' . $hash . '.json';
    }

    /**
     * Limpa cache expirado (manutenção)
     */
    public function cleanExpired(): int
    {
        $cleaned = 0;

        if ($this->driver === 'file') {
            $files = glob($this->cacheDir . '/**/*.json', GLOB_BRACE);

            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);

                if ($data && isset($data['expires_at']) && time() > $data['expires_at']) {
                    @unlink($file); // Suppress permission errors
                    $cleaned++;
                }
            }
        }

        return $cleaned;
    }

    /**
     * Obtém estatísticas do cache
     */
    public function getStats(): array
    {
        $stats = [
            'driver' => $this->driver,
            'cache_dir' => $this->cacheDir
        ];

        if ($this->driver === 'redis' && $this->redis) {
            try {
                $info = $this->redis->info();
                $stats['redis'] = [
                    'used_memory' => $info['used_memory_human'] ?? 'N/A',
                    'connected_clients' => $info['connected_clients'] ?? 0,
                    'total_commands' => $info['total_commands_processed'] ?? 0,
                    'keyspace_hits' => $info['keyspace_hits'] ?? 0,
                    'keyspace_misses' => $info['keyspace_misses'] ?? 0,
                    'hit_ratio' => isset($info['keyspace_hits'], $info['keyspace_misses']) && ($info['keyspace_hits'] + $info['keyspace_misses']) > 0
                        ? round($info['keyspace_hits'] / ($info['keyspace_hits'] + $info['keyspace_misses']) * 100, 2)
                        : 0
                ];
            } catch (\Exception $e) {
                $stats['redis_error'] = $e->getMessage();
            }
        } else {
            // Estatísticas do cache em arquivo
            $files = array_merge(
                glob($this->cacheDir . '/*.json') ?: [],
                glob($this->cacheDir . '/*/*.json') ?: [],
                glob($this->cacheDir . '/*/*/*.json') ?: []
            );

            $totalSize = 0;
            $validFiles = 0;
            $expiredFiles = 0;

            foreach ($files as $file) {
                if (is_file($file)) {
                    $totalSize += filesize($file);
                    $data = json_decode(file_get_contents($file), true);

                    if ($data && isset($data['expires_at'])) {
                        if (time() <= $data['expires_at']) {
                            $validFiles++;
                        } else {
                            $expiredFiles++;
                        }
                    }
                }
            }

            $stats['file'] = [
                'total_files' => count($files),
                'valid_files' => $validFiles,
                'expired_files' => $expiredFiles,
                'total_size' => $this->formatBytes($totalSize)
            ];
        }

        return $stats;
    }

    /**
     * Invalida cache por tag (prefixo)
     */
    public function invalidateTag(string $tag): int
    {
        $count = 0;
        $pattern = $tag . ':*';

        if ($this->driver === 'redis' && $this->redis) {
            $keys = $this->redis->keys($pattern);

            foreach ($keys as $key) {
                if ($this->redis->del($key)) {
                    $count++;
                }
            }
        } else {
            // Para cache em arquivo, buscar por prefixo no nome
            $files = array_merge(
                glob($this->cacheDir . '/*.json') ?: [],
                glob($this->cacheDir . '/*/*.json') ?: [],
                glob($this->cacheDir . '/*/*/*.json') ?: []
            );

            foreach ($files as $file) {
                // Verificar conteúdo para encontrar chaves com a tag
                $data = json_decode(file_get_contents($file), true);
                if ($data && isset($data['key']) && strpos($data['key'], $tag) === 0) {
                    if (@unlink($file)) { // Suppress permission errors
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Obtém driver atual
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * Remove chaves que correspondem a um padrão glob (ex: "sales_data_123_*")
     */
    public function deletePattern(string $pattern): bool
    {
        if ($this->driver === 'redis' && $this->redis) {
            $keys = $this->redis->keys($pattern);
            if (!empty($keys)) {
                $this->redis->del(...$keys);
            }
            return true;
        }

        // Para file cache, escanear todos os arquivos e verificar a chave armazenada
        $files = array_merge(
            glob($this->cacheDir . '/*/*.json') ?: [],
            glob($this->cacheDir . '/*/*/*.json') ?: []
        );

        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $data = json_decode($raw, true);
            if (isset($data['key']) && preg_match($regex, $data['key'])) {
                @unlink($file);
            }
        }

        return true;
    }

    /**
     * Formata bytes para exibição
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}

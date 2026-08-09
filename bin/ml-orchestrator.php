#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Services\MercadoLivreOrchestratorService;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$rootDir = dirname(__DIR__);
if (file_exists($rootDir . '/.env')) {
    Dotenv::createImmutable($rootDir)->safeLoad();
}

$command = $argv[1] ?? 'help';
$options = parseCliOptions(array_slice($argv, 2));
$service = new MercadoLivreOrchestratorService($rootDir);

if ($command === '--help' || $command === '-h') {
    $command = 'help';
}

try {
    $result = match ($command) {
        'queue' => $service->runQueue(
            (int)($options['batch-size'] ?? 10),
            (int)($options['max-batches'] ?? 1),
            array_key_exists('cleanup-jobs', $options),
            (int)($options['cleanup-jobs'] ?? 30),
            !array_key_exists('no-lock', $options),
            !array_key_exists('skip-webhook-replay', $options),
            (int)($options['webhook-replay-limit'] ?? 25)
        ),
        'refresh-tokens' => $service->runTokenRefresh(
            array_key_exists('all', $options) || array_key_exists('force-all', $options),
            isset($options['account']) ? (int)$options['account'] : null,
            !array_key_exists('no-lock', $options)
        ),
        'poll-orders' => $service->runPolling(
            ['orders'],
            ['orders' => (int)($options['limit'] ?? 100)],
            array_key_exists('with-queue', $options),
            (int)($options['queue-batch-size'] ?? 25),
            (int)($options['queue-max-batches'] ?? 2),
            array_key_exists('cleanup-jobs', $options),
            (int)($options['cleanup-jobs'] ?? 30),
            !array_key_exists('no-lock', $options)
        ),
        'poll-items' => $service->runPolling(
            ['items'],
            ['items' => (int)($options['limit'] ?? 100)],
            array_key_exists('with-queue', $options),
            (int)($options['queue-batch-size'] ?? 25),
            (int)($options['queue-max-batches'] ?? 2),
            false,
            30,
            !array_key_exists('no-lock', $options)
        ),
        'poll-questions' => $service->runPolling(
            ['questions'],
            ['questions' => (int)($options['limit'] ?? 50)],
            array_key_exists('with-queue', $options),
            (int)($options['queue-batch-size'] ?? 25),
            (int)($options['queue-max-batches'] ?? 2),
            false,
            30,
            !array_key_exists('no-lock', $options)
        ),
        'poll' => $service->runPolling(
            parseResourcesOption((string)($options['resources'] ?? 'orders,items,questions')),
            [
                'orders' => (int)($options['orders-limit'] ?? 100),
                'items' => (int)($options['items-limit'] ?? 100),
                'questions' => (int)($options['questions-limit'] ?? 50),
            ],
            array_key_exists('with-queue', $options),
            (int)($options['queue-batch-size'] ?? 25),
            (int)($options['queue-max-batches'] ?? 2),
            array_key_exists('cleanup-jobs', $options),
            (int)($options['cleanup-jobs'] ?? 30),
            !array_key_exists('no-lock', $options)
        ),
        'cycle' => $service->runCycle([
            'force_all' => array_key_exists('all', $options) || array_key_exists('force-all', $options),
            'account_id' => isset($options['account']) ? (int)$options['account'] : null,
            'orders_limit' => (int)($options['orders-limit'] ?? 100),
            'items_limit' => (int)($options['items-limit'] ?? 100),
            'questions_limit' => (int)($options['questions-limit'] ?? 50),
            'queue_batch_size' => (int)($options['queue-batch-size'] ?? 25),
            'queue_max_batches' => (int)($options['queue-max-batches'] ?? 4),
            'cleanup_old_jobs' => array_key_exists('cleanup-jobs', $options),
            'cleanup_days' => (int)($options['cleanup-jobs'] ?? 30),
            'replay_failed_webhooks' => !array_key_exists('skip-webhook-replay', $options),
            'webhook_replay_limit' => (int)($options['webhook-replay-limit'] ?? 25),
        ], !array_key_exists('no-lock', $options)),
        'help' => ['success' => true, 'help' => helpText()],
        default => ['success' => false, 'error' => 'unknown_command', 'help' => helpText()],
    };

    $pretty = !array_key_exists('compact', $options);
    echo json_encode($result, $pretty ? (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 0) . PHP_EOL;

    $ok = (bool)($result['success'] ?? false);
    $skipped = (bool)($result['skipped'] ?? false);
    exit($ok || $skipped ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode([
        'success' => false,
        'error' => 'exception',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

/**
 * @param array<int, string> $args
 * @return array<string, string>
 */
function parseCliOptions(array $args): array
{
    $options = [];
    foreach ($args as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        $raw = substr($arg, 2);
        if ($raw === '') {
            continue;
        }

        if (!str_contains($raw, '=')) {
            $options[$raw] = '1';
            continue;
        }

        [$key, $value] = explode('=', $raw, 2);
        if ($key !== '') {
            $options[$key] = $value;
        }
    }

    return $options;
}

/**
 * @return array<int, string>
 */
function parseResourcesOption(string $resources): array
{
    $items = array_map('trim', explode(',', $resources));
    $items = array_values(array_filter($items, static fn (string $value): bool => $value !== ''));
    return $items === [] ? ['orders', 'items', 'questions'] : $items;
}

function helpText(): string
{
    return implode(PHP_EOL, [
        'Uso:',
        '  php bin/ml-orchestrator.php cycle [--queue-batch-size=25] [--queue-max-batches=4] [--cleanup-jobs=30]',
        '  php bin/ml-orchestrator.php poll [--resources=orders,items,questions] [--with-queue]',
        '  php bin/ml-orchestrator.php poll-orders [--limit=100] [--with-queue]',
        '  php bin/ml-orchestrator.php poll-items [--limit=100] [--with-queue]',
        '  php bin/ml-orchestrator.php poll-questions [--limit=50] [--with-queue]',
        '  php bin/ml-orchestrator.php queue [--batch-size=10] [--max-batches=1] [--cleanup-jobs=30]',
        '  php bin/ml-orchestrator.php refresh-tokens [--all|--account=ID]',
        'Opções gerais:',
        '  --no-lock      Desabilita lock de escopo (uso manual/teste)',
        '  --skip-webhook-replay    Não reprocessa automaticamente inbox.failed na execução',
        '  --webhook-replay-limit=25  Limite de eventos failed para replay por execução',
        '  --compact      Saída JSON compacta',
    ]);
}

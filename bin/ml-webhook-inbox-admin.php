#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Services\MercadoLivreWebhookReplayService;
use App\Services\WebhookInboxService;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$rootDir = dirname(__DIR__);
if (file_exists($rootDir . '/.env')) {
    Dotenv::createImmutable($rootDir)->safeLoad();
}

/**
 * CLI admin para inbox de webhooks do Mercado Livre.
 *
 * Exemplos:
 *   php bin/ml-webhook-inbox-admin.php list-failed --limit=20
 *   php bin/ml-webhook-inbox-admin.php replay-failed --limit=20
 *   php bin/ml-webhook-inbox-admin.php replay-failed --event-key=abc123 --dry-run
 *   php bin/ml-webhook-inbox-admin.php cleanup --days=30 --statuses=processed,failed
 */

$command = $argv[1] ?? 'help';
$options = parseOptions(array_slice($argv, 2));
$provider = 'mercadolivre';

try {
    $inbox = new WebhookInboxService();

    switch ($command) {
        case 'list-failed':
            $limit = (int)($options['limit'] ?? 20);
            $eventKey = isset($options['event-key']) ? (string)$options['event-key'] : null;
            $events = filterFailedEvents($inbox->getFailedEvents($provider, max(1, min(1000, $limit))), $eventKey);
            echo "failed_events=" . count($events) . PHP_EOL;
            foreach ($events as $row) {
                $id = (int)($row['id'] ?? 0);
                $key = (string)($row['event_key'] ?? '');
                $receivedAt = (string)($row['received_at'] ?? '');
                $processedAt = (string)($row['processed_at'] ?? '');
                $jobId = isset($row['job_id']) ? (string)$row['job_id'] : '';
                $error = trim((string)($row['error_message'] ?? ''));
                echo sprintf(
                    "- id=%d event_key=%s received_at=%s processed_at=%s job_id=%s error=%s\n",
                    $id,
                    $key,
                    $receivedAt,
                    $processedAt,
                    $jobId !== '' ? $jobId : 'null',
                    $error !== '' ? $error : 'n/a'
                );
            }
            exit(0);

        case 'replay-failed':
            $limit = (int)($options['limit'] ?? 20);
            $eventKey = isset($options['event-key']) ? (string)$options['event-key'] : null;
            $dryRun = array_key_exists('dry-run', $options);
            $minAgeSeconds = isset($options['min-age-seconds']) ? (int)$options['min-age-seconds'] : 0;

            $replayService = new MercadoLivreWebhookReplayService($inbox);
            $replayResult = $replayService->replayFailedEvents(
                max(1, min(1000, $limit)),
                $eventKey,
                $dryRun,
                'ml-webhook-inbox-admin',
                $minAgeSeconds
            );

            foreach (($replayResult['details'] ?? []) as $detail) {
                $detailEventKey = (string)($detail['event_key'] ?? 'n/a');
                $detailStatus = (string)($detail['status'] ?? 'unknown');
                $jobId = isset($detail['job_id']) ? (int)$detail['job_id'] : null;
                $error = isset($detail['error']) ? (string)$detail['error'] : null;

                echo sprintf(
                    "- event_key=%s status=%s%s%s\n",
                    $detailEventKey,
                    $detailStatus,
                    $jobId !== null ? " job_id={$jobId}" : '',
                    $error !== null && $error !== '' ? " error={$error}" : ''
                );
            }

            echo sprintf(
                "Resumo: attempted=%d replayed=%d skipped=%d failed=%d dry_run=%s\n",
                (int)($replayResult['attempted'] ?? 0),
                (int)($replayResult['replayed'] ?? 0),
                (int)($replayResult['skipped'] ?? 0),
                (int)($replayResult['failed'] ?? 0),
                $dryRun ? 'true' : 'false'
            );
            exit(0);

        case 'cleanup':
            $days = (int)($options['days'] ?? 30);
            $statusesRaw = isset($options['statuses']) ? (string)$options['statuses'] : 'processed,failed';
            $statuses = array_values(array_filter(array_map('trim', explode(',', $statusesRaw))));
            $deleted = $inbox->cleanupOldEvents($provider, $days, $statuses);
            echo sprintf(
                "Cleanup concluído: provider=%s days=%d statuses=%s deleted=%d\n",
                $provider,
                $days,
                implode(',', $statuses),
                $deleted
            );
            exit(0);

        default:
            echo <<<TXT
Uso:
  php bin/ml-webhook-inbox-admin.php list-failed [--limit=20] [--event-key=...]
  php bin/ml-webhook-inbox-admin.php replay-failed [--limit=20] [--event-key=...] [--min-age-seconds=0] [--dry-run]
  php bin/ml-webhook-inbox-admin.php cleanup [--days=30] [--statuses=processed,failed]

TXT;
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Erro: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

/**
 * @param array<int, string> $args
 * @return array<string, string>
 */
function parseOptions(array $args): array
{
    $options = [];
    foreach ($args as $arg) {
        if (!is_string($arg) || !str_starts_with($arg, '--')) {
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
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function filterFailedEvents(array $rows, ?string $eventKey): array
{
    $eventKey = $eventKey !== null ? trim($eventKey) : null;
    if ($eventKey === null || $eventKey === '') {
        return $rows;
    }

    return array_values(array_filter($rows, static function (array $row) use ($eventKey): bool {
        return (string)($row['event_key'] ?? '') === $eventKey;
    }));
}

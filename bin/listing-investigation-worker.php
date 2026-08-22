#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Listing Investigation Worker (read-only).
 *
 * Diagnoses official blockers for FACILYTY items and stores local drafts.
 * Never PUT/POST Mercado Livre items, answers, ads, or pauses.
 *
 *   php bin/listing-investigation-worker.php --once --account=1335 --limit=50
 *   php bin/listing-investigation-worker.php --once --no-llm
 *   php bin/listing-investigation-worker.php --once --llm   # only if DASHSCOPE_MODEL_OK=1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/autoload.php';

if (class_exists(Dotenv\Dotenv::class)) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

use App\Database;
use App\Services\ListingInvestigation\DashScopeClient;
use App\Services\ListingInvestigation\ListingInvestigationService;

$options = getopt('', ['account:', 'limit:', 'once', 'no-llm', 'llm', 'help']);

if (isset($options['help'])) {
    echo "Listing Investigation Worker (apply_blocked, no ML write)\n";
    echo "  --once            one shot (default behaviour)\n";
    echo "  --account=ID      default 1335 (FACILYTY). Staging refuses 1335.\n";
    echo "  --limit=N         max listings per run (default 50, covers ficha universe)\n";
    echo "  --no-llm          rules-only (default)\n";
    echo "  --llm             opt-in Qwen only if DASHSCOPE_MODEL_OK=1 (a model already returned 200)\n";
    exit(0);
}

$accountId = isset($options['account']) ? (int) $options['account'] : ListingInvestigationService::FACILYTY_ACCOUNT;
$limit = isset($options['limit']) ? (int) $options['limit'] : ListingInvestigationService::DEFAULT_LIMIT;
$wantLlm = isset($options['llm']) && !isset($options['no-llm']);

if (str_contains($root, 'staging.eskill.com.br') && $accountId === ListingInvestigationService::FACILYTY_ACCOUNT) {
    fwrite(STDERR, "staging workers must not point at FACILYTY 1335\n");
    exit(2);
}

echo "Listing Investigation Worker\n";
echo str_repeat('=', 60) . "\n";
echo 'account=' . $accountId . " apply_blocked=true ml_write=false\n";

try {
    $db = Database::getInstance();
    $llm = null;
    $llmNote = 'rules (Alibaba not required)';
    if ($wantLlm) {
        $candidate = new DashScopeClient();
        if ($candidate->isConfigured() && $candidate->hasKnownWorkingModel()) {
            $llm = $candidate;
            $llmNote = 'opt-in DashScope (known 200)';
        } else {
            $llmNote = 'rules (llm requested but no known-working model; skipped DashScope)';
        }
    }
    $forceRules = $llm === null;
    echo 'model_used_default=rules dashscope=' . $llmNote . "\n";

    $service = new ListingInvestigationService($db, $llm, $forceRules);
    $result = $service->run($accountId, $limit);
    $snap = $service->pregaoSnapshot($accountId);

    echo 'closed_sold=' . (int) $result['closed_sold'] . "\n";
    echo 'investigated=' . count($result['investigated']) . "\n";
    echo 'refreshed=' . (int) ($result['refreshed_count'] ?? 0) . "\n";
    echo 'pregao.investigacao.count=' . (int) $snap['count'] . " published=no\n";

    $printed = array_merge($result['refreshed'] ?? [], $result['investigated']);
    foreach ($printed as $row) {
        $codes = [];
        foreach ($row['blockers'] as $b) {
            $codes[] = is_array($b) ? (string) ($b['code'] ?? '') : (string) $b;
        }
        echo sprintf(
            "mlb=%s title=%s blockers=%s draft_title=%s model_used=%s published=no\n",
            $row['mlb_id'],
            mb_substr((string) ($row['title'] ?? ''), 0, 80),
            implode(',', array_filter($codes)),
            $row['draft_title'],
            $row['model_used']
        );
    }
    echo "zero ML writes\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'error=' . $e->getMessage() . "\n");
    exit(1);
}

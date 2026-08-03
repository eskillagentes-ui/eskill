<?php

declare(strict_types=1);

use App\Services\Agents\AgentContext;
use App\Services\Agents\AgentResult;
use App\Services\Agents\QaAgent;
use App\Services\Agents\QaMergeGate;

require dirname(__DIR__) . '/vendor/autoload.php';

$evidenceVariables = [
    'php-lint' => 'QA_GATE_PHP_LINT',
    'phpunit-agents' => 'QA_GATE_PHPUNIT_AGENTS',
    'phpunit-unit' => 'QA_GATE_PHPUNIT_UNIT',
    'playwright-readonly' => 'QA_GATE_PLAYWRIGHT_READONLY',
];
$checks = [];
foreach ($evidenceVariables as $id => $variable) {
    $checks[$id] = static function (AgentContext $context) use ($id, $variable): AgentResult {
        return getenv($variable) === 'passed'
            ? AgentResult::success($id, 'evidence_passed')
            : AgentResult::failed($id, 'evidence_missing');
    };
}

try {
    (new QaMergeGate())->assertPasses(
        new QaAgent($checks),
        new AgentContext(1, 'local', 'ci-qa-merge-gate', false)
    );
} catch (\Throwable) {
    fwrite(STDERR, "qa_merge_gate_rejected\n");
    exit(1);
}

fwrite(STDOUT, "qa_merge_gate_passed\n");

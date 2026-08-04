#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Services\Agents\AgentResult;
use App\Services\Agents\AgentRuntimeFactory;

const AGENT_RUNTIME_USAGE = 'usage: php bin/agent-runtime.php --account-id=ID --correlation=ID --environment=local|staging|production [--creator=MLB123]';

/** @return never */
function agentRuntimeInvalidArguments(): void
{
    fwrite(STDERR, "invalid arguments\n" . AGENT_RUNTIME_USAGE . "\n");
    exit(64);
}

/** @return array<string, string> */
function agentRuntimeParseArguments(array $arguments): array
{
    $options = [];
    foreach ($arguments as $argument) {
        if ($argument === '--help') {
            fwrite(STDOUT, AGENT_RUNTIME_USAGE . "\n");
            exit(0);
        }
        if (!is_string($argument)
            || preg_match('/^--([a-z-]+)=(.*)$/D', $argument, $matches) !== 1
            || !in_array($matches[1], ['account-id', 'correlation', 'environment', 'creator'], true)
            || array_key_exists($matches[1], $options)
        ) {
            agentRuntimeInvalidArguments();
        }
        $options[$matches[1]] = $matches[2];
    }

    if (array_keys(array_intersect_key($options, array_flip(['account-id', 'correlation', 'environment'])))
        === []
    ) {
        agentRuntimeInvalidArguments();
    }
    foreach (['account-id', 'correlation', 'environment'] as $required) {
        if (!array_key_exists($required, $options)) {
            agentRuntimeInvalidArguments();
        }
    }
    if (preg_match('/^[1-9][0-9]*$/D', $options['account-id']) !== 1
        || (string) (int) $options['account-id'] !== $options['account-id']
        || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $options['correlation']) !== 1
        || !in_array($options['environment'], ['local', 'staging', 'production'], true)
        || (isset($options['creator'])
            && preg_match('/^MLB[1-9][0-9]*$/D', $options['creator']) !== 1)
    ) {
        agentRuntimeInvalidArguments();
    }

    return $options;
}

/** @return array{agent: string, status: string, reason: string, stateChanged: bool, emittedOps: list<string>} */
function agentRuntimeSummary(AgentResult $result): array
{
    return [
        'agent' => $result->agent(),
        'status' => $result->status(),
        'reason' => $result->reason(),
        'stateChanged' => $result->stateChanged(),
        'emittedOps' => $result->emittedOps(),
    ];
}

$options = agentRuntimeParseArguments(array_slice($argv, 1));

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../autoload.php';

try {
    $factory = new AgentRuntimeFactory();
    $contextOptions = ['environment' => $options['environment']];
    if (isset($options['creator'])) {
        $contextOptions['creator_request'] = ['source_mlb_id' => $options['creator']];
    }
    $context = $factory->buildContext(
        (int) $options['account-id'],
        $options['correlation'],
        $contextOptions
    );
    $aggregate = $factory->createOrchestrator()->run($context);

    foreach ($aggregate->data()['results'] as $agentResult) {
        $line = json_encode(agentRuntimeSummary($agentResult), JSON_UNESCAPED_SLASHES);
        fwrite(STDOUT, ($line === false ? '{"status":"failed"}' : $line) . "\n");
    }
    $line = json_encode(agentRuntimeSummary($aggregate), JSON_UNESCAPED_SLASHES);
    fwrite(STDOUT, ($line === false ? '{"status":"failed"}' : $line) . "\n");
    exit($aggregate->status() === 'success' ? 0 : 1);
} catch (Throwable) {
    $failed = AgentResult::failed('agent-runtime', 'runtime_exception');
    $line = json_encode(agentRuntimeSummary($failed), JSON_UNESCAPED_SLASHES);
    fwrite(STDERR, ($line === false ? '{"status":"failed"}' : $line) . "\n");
    exit(1);
}

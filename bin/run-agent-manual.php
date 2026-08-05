#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Services\AutonomousAgentService;

const RUN_AGENT_MANUAL_USAGE = <<<'HELP'
Uso: php bin/run-agent-manual.php --agent=guardian|sniper

Opções:
  --agent=CODE   Agent a executar (guardian ou sniper)
  --help         Exibir esta ajuda
HELP;

/** @param array<string, mixed> $options */
function runAgentManualAgentFromOptions(array $options): string
{
    $agentCode = $options['agent'] ?? null;

    if (!is_string($agentCode) || !in_array($agentCode, ['guardian', 'sniper'], true)) {
        throw new InvalidArgumentException('Informe --agent=guardian ou --agent=sniper');
    }

    return $agentCode;
}

/**
 * @param null|callable(): AutonomousAgentService $serviceFactory
 * @return array<string, mixed>
 */
function runAgentManualExecute(string $agentCode, ?callable $serviceFactory = null): array
{
    $serviceFactory ??= static fn (): AutonomousAgentService => new AutonomousAgentService();
    $service = $serviceFactory();

    if (!$service instanceof AutonomousAgentService) {
        throw new RuntimeException('Factory não retornou AutonomousAgentService');
    }

    return $service->runAgent($agentCode);
}

/** @param array<string, mixed> $payload */
function runAgentManualJson(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === false ? '{"success":false,"error":"json_encode_failed"}' : $json;
}

if (!defined('RUN_AGENT_MANUAL_TESTING')) {
    $options = getopt('', ['agent:', 'help'], $restIndex);

    if (is_array($options) && isset($options['help'])) {
        fwrite(STDOUT, RUN_AGENT_MANUAL_USAGE . "\n");
        exit(0);
    }

    try {
        if (!is_array($options) || $restIndex !== $argc) {
            throw new InvalidArgumentException('Argumentos inválidos');
        }

        $agentCode = runAgentManualAgentFromOptions($options);
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, $e->getMessage() . "\n" . RUN_AGENT_MANUAL_USAGE . "\n");
        exit(64);
    }

    require_once __DIR__ . '/../autoload.php';

    try {
        $result = runAgentManualExecute($agentCode);
        fwrite(STDOUT, runAgentManualJson($result) . "\n");
        exit(($result['success'] ?? false) === true ? 0 : 1);
    } catch (Throwable $e) {
        fwrite(STDERR, runAgentManualJson([
            'success' => false,
            'error' => $e->getMessage(),
            'agent' => $agentCode,
        ]) . "\n");
        exit(1);
    }
}

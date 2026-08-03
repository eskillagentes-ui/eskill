<?php

declare(strict_types=1);

namespace App\Services\Agents;

use RuntimeException;

final class QaMergeGate
{
    private const REJECTION_REASON = 'qa_merge_gate_rejected';

    /** @var list<string> */
    public const REQUIRED_CHECK_IDS = [
        'php-lint',
        'phpunit-agents',
        'phpunit-unit',
        'playwright-readonly',
    ];

    /** @var array<string, string> */
    private const EVIDENCE_VARIABLES = [
        'php-lint' => 'QA_GATE_PHP_LINT',
        'phpunit-agents' => 'QA_GATE_PHPUNIT_AGENTS',
        'phpunit-unit' => 'QA_GATE_PHPUNIT_UNIT',
        'playwright-readonly' => 'QA_GATE_PLAYWRIGHT_READONLY',
    ];

    /** Valida somente evidências fixas fornecidas pelo processo confiável de CI. */
    public function assertPasses(): void
    {
        if (array_keys(self::EVIDENCE_VARIABLES) !== self::REQUIRED_CHECK_IDS) {
            throw new RuntimeException(self::REJECTION_REASON);
        }

        foreach (self::EVIDENCE_VARIABLES as $variable) {
            if (getenv($variable) !== 'passed') {
                throw new RuntimeException(self::REJECTION_REASON);
            }
        }
    }
}

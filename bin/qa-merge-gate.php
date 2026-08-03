<?php

declare(strict_types=1);

use App\Services\Agents\QaMergeGate;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    (new QaMergeGate())->assertPasses();
} catch (\Throwable) {
    fwrite(STDERR, "qa_merge_gate_rejected\n");
    exit(1);
}

fwrite(STDOUT, "qa_merge_gate_passed\n");

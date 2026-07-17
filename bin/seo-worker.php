#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 🚀 STANDARD JOB WORKER
 * 
 * Processa jobs da fila padrão (JobService)
 * Substitui scripts ad-hoc de background.
 * 
 * Uso:
 *   php bin/seo-worker.php [--once]
 */

require __DIR__ . '/../vendor/autoload.php';

\App\Helpers\MlWriteAutomation::exitIfDisabledForCli(basename(__FILE__));

use App\Services\JobService;
use App\Services\QueueService;

// Settings
$runOnce = in_array('--once', $argv);
$verbose = in_array('--verbose', $argv) || in_array('-v', $argv);

if ($verbose) {
    echo "\n🤖 ESKILL WORKER\n";
    echo str_repeat("=", 60) . "\n";
    echo "Started at: " . date('Y-m-d H:i:s') . "\n";
    echo "Mode: " . ($runOnce ? "One-off" : "Daemon") . "\n";
    echo str_repeat("=", 60) . "\n\n";
}

$processed = 0;
$queue = new QueueService();
$jobService = new JobService();

do {
    try {
        $jobFound = false;

        // 1. Try Redis Queue (Blocking 20s)
        if ($verbose) echo "[" . date('H:i:s') . "] 👂 Listening to queue:process_job...\n";
        
        $msg = $queue->pop('process_job', 20);
        
        if ($msg && !empty($msg['payload']['job_id'])) {
            $jobId = (int)$msg['payload']['job_id'];
            if ($verbose) echo "[" . date('H:i:s') . "] 📥 Received Job #{$jobId} from Queue\n";
            
            // Fetch and process
            $jobData = $jobService->getJob($jobId);
            if ($jobData) {
                if ($jobData['status'] !== 'completed' && $jobData['status'] !== 'failed') {
                    $result = $jobService->processJob($jobData);
                    $status = $result['status'] ?? 'unknown';
                    if ($verbose) echo "[" . date('H:i:s') . "] -> Finished: {$status}\n";
                    $processed++;
                    $jobFound = true;
                } else {
                     if ($verbose) echo "[" . date('H:i:s') . "] -> Job already processed.\n";
                }
            } else {
                if ($verbose) echo "[" . date('H:i:s') . "] -> Job record not found in DB.\n";
            }
        }

        // 2. Fallback: Poll DB for pending items (missed by Redis or scheduled)
        // Only if we didn't just process one (to prioritize queue speed) or if queue was empty
        if (!$jobFound) {
            if ($verbose) echo "[" . date('H:i:s') . "] 🔎 Polling DB for pending jobs...\n";
            $dbJobs = $jobService->process(1); // Process 1 at a time
            
            if (!empty($dbJobs)) {
                $jobFound = true;
                $processed += count($dbJobs);
                if ($verbose) echo "[" . date('H:i:s') . "] -> Processed " . count($dbJobs) . " jobs from DB.\n";
            } else {
                if ($verbose) echo "[" . date('H:i:s') . "] -> No pending jobs.\n";
            }
        }

        // 3. Sleep logic
        if ($runOnce) break;
        
        if (!$jobFound) {
            // Idle state sleep
            if ($verbose) echo "zzZ (5s)\n";
            sleep(5);
        } else {
            // Busy state - minimal sleep to avoid CPU hugging if loop is tight
            usleep(100000); // 100ms
        }

    } catch (Exception $e) {
        echo "[" . date('H:i:s') . "] ❌ CRITICAL ERROR: " . $e->getMessage() . "\n";
        sleep(10);
    }
} while (!$runOnce);

if ($verbose) echo "\nTotal Processed: $processed\n";

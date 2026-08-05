<?php

declare(strict_types=1);

namespace App\Services\Pregao;

final class PregaoQaWorkerSignals
{
    private bool $requested = false;
    private mixed $process = null;

    public function install(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            throw new \RuntimeException('qa_worker_signals_unavailable');
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {
            $this->requestShutdown();
        });
        pcntl_signal(SIGINT, function (): void {
            $this->requestShutdown();
        });
    }

    /** @param resource|null $process */
    public function track(mixed $process): void
    {
        $this->process = $process;
        if ($this->requested) {
            PregaoQaWorkerProcess::terminate($this->process);
        }
    }

    public function isRequested(): bool
    {
        return $this->requested;
    }

    private function requestShutdown(): void
    {
        $this->requested = true;
        PregaoQaWorkerProcess::terminate($this->process);
    }
}

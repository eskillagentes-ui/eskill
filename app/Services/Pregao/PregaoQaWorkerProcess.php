<?php

declare(strict_types=1);

namespace App\Services\Pregao;

final class PregaoQaWorkerProcess
{
    private const SELECT_MICROSECONDS = 200_000;
    private const MAX_PROTOCOL_BUFFER_BYTES = 65_536;

    /**
     * @param resource $process
     * @param resource $stdout
     * @param resource $stderr
     * @param callable(string):void $onStdoutLine
     * @param callable():void $onTick
     * @param callable():bool $shutdownRequested
     * @return array{exit_code:int,stderr_present:bool}
     */
    public static function drain(
        mixed $process,
        mixed $stdout,
        mixed $stderr,
        callable $onStdoutLine,
        callable $onTick,
        callable $shutdownRequested,
        int $timeoutSeconds
    ): array {
        if (!is_resource($process) || !is_resource($stdout) || !is_resource($stderr) || $timeoutSeconds < 1) {
            throw new \InvalidArgumentException('qa_browser_process_invalid');
        }

        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);
        $stdoutBuffer = '';
        $stderrPresent = false;
        $startedAt = hrtime(true);
        $lastExitCode = null;

        try {
            while (true) {
                $onTick();
                if ($shutdownRequested()) {
                    throw new \RuntimeException('qa_worker_shutdown_requested');
                }
                if ((hrtime(true) - $startedAt) / 1_000_000_000 >= $timeoutSeconds) {
                    throw new \RuntimeException('qa_browser_timeout');
                }

                $status = proc_get_status($process);
                $running = is_array($status) && ($status['running'] ?? false) === true;
                if (!$running && is_int($status['exitcode'] ?? null) && $status['exitcode'] >= 0) {
                    $lastExitCode = $status['exitcode'];
                }

                $read = [];
                if (is_resource($stdout) && !feof($stdout)) {
                    $read[] = $stdout;
                }
                if (is_resource($stderr) && !feof($stderr)) {
                    $read[] = $stderr;
                }

                if ($read !== []) {
                    $write = null;
                    $except = null;
                    $selected = @stream_select($read, $write, $except, 0, self::SELECT_MICROSECONDS);
                    if ($selected === false) {
                        throw new \RuntimeException('qa_browser_stream_failed');
                    }
                    foreach ($read as $stream) {
                        $chunk = fread($stream, 8192);
                        if (!is_string($chunk) || $chunk === '') {
                            continue;
                        }
                        if ($stream === $stdout) {
                            $stdoutBuffer .= $chunk;
                            if (strlen($stdoutBuffer) > self::MAX_PROTOCOL_BUFFER_BYTES) {
                                throw new \RuntimeException('qa_browser_protocol_too_large');
                            }
                            self::emitCompleteLines($stdoutBuffer, $onStdoutLine);
                        } else {
                            $stderrPresent = true;
                        }
                    }
                } elseif ($running) {
                    usleep(10_000);
                }

                if (!$running && feof($stdout) && feof($stderr)) {
                    break;
                }
            }

            if ($stdoutBuffer !== '') {
                $onStdoutLine($stdoutBuffer);
            }
        } catch (\Throwable $exception) {
            self::terminate($process);
            self::closeStream($stdout);
            self::closeStream($stderr);
            if (is_resource($process)) {
                proc_close($process);
            }
            throw $exception;
        }

        self::closeStream($stdout);
        self::closeStream($stderr);
        $closedExitCode = proc_close($process);
        $exitCode = is_int($closedExitCode) && $closedExitCode >= 0
            ? $closedExitCode
            : ($lastExitCode ?? -1);

        return [
            'exit_code' => $exitCode,
            'stderr_present' => $stderrPresent,
        ];
    }

    /** @param resource $process */
    public static function terminate(mixed $process): void
    {
        if (!is_resource($process)) {
            return;
        }
        $status = proc_get_status($process);
        if (!is_array($status) || ($status['running'] ?? false) !== true) {
            return;
        }
        @proc_terminate($process);
        for ($attempt = 0; $attempt < 20; $attempt++) {
            usleep(50_000);
            $status = proc_get_status($process);
            if (!is_array($status) || ($status['running'] ?? false) !== true) {
                return;
            }
        }
        @proc_terminate($process, 9);
    }

    /** @param callable(string):void $onStdoutLine */
    private static function emitCompleteLines(string &$buffer, callable $onStdoutLine): void
    {
        while (($newline = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $newline + 1);
            $buffer = substr($buffer, $newline + 1);
            $onStdoutLine($line);
        }
    }

    /** @param resource|null $stream */
    private static function closeStream(mixed $stream): void
    {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
}

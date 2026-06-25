<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Infrastructure;

use GrailSignal\ContactFinder\Exceptions\OutputWriteException;

/**
 * Writes timestamped batch execution details to a plain-text log file.
 */
final readonly class ExecutionLogWriter
{
    public function __construct(private string $logPath)
    {
    }

    /**
     * @param list<string> $lines
     */
    public function write(array $lines): void
    {
        $this->writePayload($lines, false);
    }

    /**
     * @param list<string> $lines
     */
    public function append(array $lines): void
    {
        $this->writePayload($lines, true);
    }

    /**
     * @param list<string> $lines
     */
    private function writePayload(array $lines, bool $append): void
    {
        $directory = dirname($this->logPath);

        if (file_exists($directory) && !is_dir($directory)) {
            throw new OutputWriteException("Log directory could not be created: {$directory}");
        }

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new OutputWriteException("Log directory could not be created: {$directory}");
        }

        $payload = implode(PHP_EOL, $lines).PHP_EOL;
        $flags = $append ? FILE_APPEND : 0;

        if (file_put_contents($this->logPath, $payload, $flags) === false) {
            throw new OutputWriteException("Execution log could not be written: {$this->logPath}");
        }
    }
}




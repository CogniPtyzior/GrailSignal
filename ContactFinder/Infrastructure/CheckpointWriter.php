<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Infrastructure;

use GrailSignal\ContactFinder\Exceptions\OutputWriteException;
use JsonException;

/**
 * Persists lightweight progress snapshots for local reruns and interruption diagnostics.
 */
final readonly class CheckpointWriter
{
    public function __construct(private string $checkpointPath)
    {
    }

    /**
     * @param array<string, int|string> $checkpoint
     */
    public function write(array $checkpoint): void
    {
        $directory = dirname($this->checkpointPath);

        if (file_exists($directory) && !is_dir($directory)) {
            throw new OutputWriteException("Checkpoint directory could not be created: {$directory}");
        }

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new OutputWriteException("Checkpoint directory could not be created: {$directory}");
        }

        try {
            $payload = json_encode($checkpoint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OutputWriteException('Checkpoint could not be encoded as JSON.', 0, $exception);
        }

        if (file_put_contents($this->checkpointPath, $payload.PHP_EOL) === false) {
            throw new OutputWriteException("Checkpoint could not be written: {$this->checkpointPath}");
        }
    }
}

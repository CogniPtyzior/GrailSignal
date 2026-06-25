<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Infrastructure;

use GrailSignal\ContactFinder\Domain\ContactResult;
use GrailSignal\ContactFinder\Exceptions\OutputWriteException;
use GrailSignal\ContactFinder\Ports\ResultWriter;
use JsonException;

/**
 * Writes one JSON object per line so partial batch output remains usable after an interruption.
 */
final readonly class JsonLinesResultWriter implements ResultWriter
{
    public function __construct(private string $outputPath)
    {
    }

    /**
     * @param list<ContactResult> $results
     */
    public function write(array $results): void
    {
        $this->prepareFile(false);

        foreach ($results as $result) {
            $this->append($result);
        }
    }

    public function append(ContactResult $result): void
    {
        $this->prepareFile(true);

        try {
            $payload = json_encode(
                value: $result->toOutputRow(),
                flags: JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new OutputWriteException('Contact Finder result could not be encoded as JSONL.', 0, $exception);
        }

        if (file_put_contents($this->outputPath, $payload.PHP_EOL, FILE_APPEND) === false) {
            throw new OutputWriteException("Output JSONL could not be written: {$this->outputPath}");
        }
    }

    private function prepareFile(bool $append): void
    {
        $directory = dirname($this->outputPath);

        if (file_exists($directory) && !is_dir($directory)) {
            throw new OutputWriteException("Output directory could not be created: {$directory}");
        }

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new OutputWriteException("Output directory could not be created: {$directory}");
        }

        if (!$append && file_put_contents($this->outputPath, '') === false) {
            throw new OutputWriteException("Output JSONL could not be initialized: {$this->outputPath}");
        }
    }
}

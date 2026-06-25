<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Infrastructure;

use GrailSignal\ContactFinder\Domain\ContactResult;
use GrailSignal\ContactFinder\Exceptions\OutputWriteException;
use GrailSignal\ContactFinder\Ports\ResultWriter;
use JsonException;

/**
 * Writes Contact Finder result rows as pretty JSON for review and submission.
 */
final readonly class JsonResultWriter implements ResultWriter
{
    public function __construct(private string $outputPath)
    {
    }

    /**
     * @param list<ContactResult> $results
     */
    public function write(array $results): void
    {
        $directory = dirname($this->outputPath);

        if (file_exists($directory) && !is_dir($directory)) {
            throw new OutputWriteException("Output directory could not be created: {$directory}");
        }

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new OutputWriteException("Output directory could not be created: {$directory}");
        }

        try {
            $payload = json_encode(
                value: array_map(
                    static fn (ContactResult $result): array => $result->toOutputRow(),
                    $results,
                ),
                flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new OutputWriteException('Contact Finder results could not be encoded as JSON.', 0, $exception);
        }

        if (file_put_contents($this->outputPath, $payload.PHP_EOL) === false) {
            throw new OutputWriteException("Output JSON could not be written: {$this->outputPath}");
        }
    }
}




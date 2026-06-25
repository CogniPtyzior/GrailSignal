<?php

declare(strict_types=1);

use GrailSignal\ContactFinder\Application\ConfidenceScorer;
use GrailSignal\ContactFinder\Application\ContactMerger;
use GrailSignal\ContactFinder\Application\FindContacts;
use GrailSignal\ContactFinder\Application\ReviewPolicy;
use GrailSignal\ContactFinder\Domain\ContactResult;
use GrailSignal\ContactFinder\Domain\ReviewState;
use GrailSignal\ContactFinder\Exceptions\ContactFinderException;
use GrailSignal\ContactFinder\Exceptions\InvalidConfigurationException;
use GrailSignal\ContactFinder\Infrastructure\AllowAllSuppressionList;
use GrailSignal\ContactFinder\Infrastructure\CheckpointWriter;
use GrailSignal\ContactFinder\Infrastructure\CsvCompanyInputReader;
use GrailSignal\ContactFinder\Infrastructure\ExecutionLogWriter;
use GrailSignal\ContactFinder\Infrastructure\JsonLinesResultWriter;
use GrailSignal\ContactFinder\Infrastructure\JsonResultWriter;
use GrailSignal\ContactFinder\Infrastructure\JsonSuppressionList;
use GrailSignal\ContactFinder\Infrastructure\MockSourceProvider;
use GrailSignal\ContactFinder\Ports\SuppressionList;

/**
 * CLI entry point for the Contact Finder batch slice.
 */
require_once __DIR__.'/vendor/autoload.php';

$startedAt = microtime(true);
$runId = (new DateTimeImmutable())->format('Ymd-His-u');
$defaultLogPath = "Storage/ContactFinder/Logs/contact-finder-run-{$runId}.log";
$logWriter = new ExecutionLogWriter($defaultLogPath);
$outputPath = "Storage/ContactFinder/Results/contact-finder-results-{$runId}.json";
$logPath = $defaultLogPath;

try {
    $configPath = contactFinderConfigPath();

    if (!is_readable($configPath)) {
        throw new InvalidConfigurationException("Contact Finder config is not readable: {$configPath}");
    }

    $config = require $configPath;

    if (!is_array($config)) {
        throw new InvalidConfigurationException('Contact Finder config must return an array.');
    }

    /** @var array<string, mixed> $config */
    $inputCsvPath = contactFinderConfigString($config, 'input_csv_path');
    $mockSourcePath = contactFinderConfigString($config, 'mock_source_path');
    $outputDirectory = contactFinderConfigString($config, 'output_directory');
    $logsDirectory = contactFinderConfigString($config, 'logs_directory');
    $confidenceThreshold = contactFinderConfigConfidenceThreshold($config, 'confidence_threshold');
    $suppressionListPath = contactFinderConfigOptionalString($config, 'suppression_list_path');
    $outputFormat = contactFinderConfigOutputFormat($config, 'output_format');
    $checkpointDirectory = contactFinderConfigOptionalString($config, 'checkpoint_directory') ?? 'Storage/ContactFinder/Checkpoints';
    $checkpointEvery = contactFinderConfigOptionalPositiveInt($config, 'checkpoint_every') ?? 10;

    $outputPath = contactFinderBuildRunPath($outputDirectory, "contact-finder-results-{$runId}.{$outputFormat}");
    $logPath = contactFinderBuildRunPath($logsDirectory, "contact-finder-run-{$runId}.log");
    $logWriter = new ExecutionLogWriter($logPath);
    contactFinderAppendLog($logWriter, [
        'status=started',
        "run_id={$runId}",
        'started_at='.(new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        "input_csv_path={$inputCsvPath}",
        "mock_source_path={$mockSourcePath}",
        'suppression_list_path='.($suppressionListPath ?? 'none'),
        "output_path={$outputPath}",
        "log_path={$logPath}",
    ]);

    $inputReader = new CsvCompanyInputReader($inputCsvPath);
    $sourceProvider = new MockSourceProvider($mockSourcePath);

    $useCase = new FindContacts(
        companyInputReader: $inputReader,
        sourceProvider: $sourceProvider,
        contactMerger: new ContactMerger(),
        confidenceScorer: new ConfidenceScorer(),
        reviewPolicy: new ReviewPolicy($confidenceThreshold),
        suppressionList: contactFinderSuppressionList($suppressionListPath),
    );

    $checkpointPath = contactFinderBuildRunPath($checkpointDirectory, "contact-finder-checkpoint-{$runId}.json");
    $checkpointWriter = new CheckpointWriter($checkpointPath);
    $results = [];

    if ($outputFormat === 'jsonl') {
        $jsonLinesWriter = new JsonLinesResultWriter($outputPath);
        $rowCount = 0;

        foreach ($useCase->stream() as $result) {
            $rowCount++;
            $jsonLinesWriter->append($result);
            $results[] = $result;
            contactFinderAppendLog($logWriter, ["event=row_processed row={$rowCount} company={$result->companyName} state={$result->reviewState->value}"]);
            contactFinderWriteCheckpoint($checkpointWriter, $runId, $rowCount, $outputPath, $logPath, $checkpointEvery, false);
        }

        contactFinderWriteCheckpoint($checkpointWriter, $runId, $rowCount, $outputPath, $logPath, $checkpointEvery, true);
    } else {
        $results = $useCase->run();
        (new JsonResultWriter($outputPath))->write($results);
        contactFinderWriteCheckpoint($checkpointWriter, $runId, count($results), $outputPath, $logPath, $checkpointEvery, true);
    }

    contactFinderAppendLog($logWriter, [
        "event=results_written path={$outputPath} count=".count($results),
    ]);
    $summary = contactFinderSummary($results, $outputPath, $logPath, $startedAt);

    contactFinderAppendLog($logWriter, contactFinderLogLines($summary));

    fwrite(STDOUT, contactFinderStdout($summary));

    exit(0);
} catch (ContactFinderException $exception) {
    contactFinderReportFailure($exception, $logWriter, $outputPath, $logPath, $startedAt);

    exit(1);
} catch (Throwable $exception) {
    contactFinderReportFailure($exception, $logWriter, $outputPath, $logPath, $startedAt);

    exit(1);
}

/**
 * @param list<string> $lines
 */
function contactFinderAppendLog(ExecutionLogWriter $logWriter, array $lines): void
{
    $logWriter->append($lines);
}

function contactFinderConfigPath(): string
{
    $configPath = getenv('CONTACT_FINDER_CONFIG_PATH');

    if ($configPath === false || trim($configPath) === '') {
        return __DIR__.'/contact_finder.config.php';
    }

    return $configPath;
}

/**
 * @param array<string, mixed> $config
 */
function contactFinderConfigString(array $config, string $key): string
{
    if (!array_key_exists($key, $config) || !is_string($config[$key]) || trim($config[$key]) === '') {
        throw new InvalidConfigurationException("Contact Finder config value must be a non-empty string: {$key}");
    }

    return $config[$key];
}

/**
 * @param array<string, mixed> $config
 */
function contactFinderConfigInt(array $config, string $key): int
{
    if (!array_key_exists($key, $config) || !is_int($config[$key])) {
        throw new InvalidConfigurationException("Contact Finder config value must be an integer: {$key}");
    }

    return $config[$key];
}

/**
 * @param array<string, mixed> $config
 */
function contactFinderConfigConfidenceThreshold(array $config, string $key): int
{
    $threshold = contactFinderConfigInt($config, $key);

    if ($threshold < 0 || $threshold > 100) {
        throw new InvalidConfigurationException("Contact Finder config value must be between 0 and 100: {$key}");
    }

    return $threshold;
}

function contactFinderConfigOutputFormat(array $config, string $key): string
{
    $format = contactFinderConfigOptionalString($config, $key) ?? 'json';

    if (!in_array($format, ['json', 'jsonl'], true)) {
        throw new InvalidConfigurationException("Contact Finder config value must be json or jsonl: {$key}");
    }

    return $format;
}

/**
 * @param array<string, mixed> $config
 */
function contactFinderConfigOptionalPositiveInt(array $config, string $key): ?int
{
    if (!array_key_exists($key, $config) || $config[$key] === null) {
        return null;
    }

    if (!is_int($config[$key]) || $config[$key] <= 0) {
        throw new InvalidConfigurationException("Contact Finder config value must be a positive integer: {$key}");
    }

    return $config[$key];
}

/**
 * @param array<string, mixed> $config
 */
function contactFinderConfigOptionalString(array $config, string $key): ?string
{
    if (!array_key_exists($key, $config) || $config[$key] === null) {
        return null;
    }

    if (!is_string($config[$key]) || trim($config[$key]) === '') {
        throw new InvalidConfigurationException("Contact Finder config value must be null or a non-empty string: {$key}");
    }

    return $config[$key];
}

function contactFinderSuppressionList(?string $path): SuppressionList
{
    if ($path === null) {
        return new AllowAllSuppressionList();
    }

    return new JsonSuppressionList($path);
}

function contactFinderBuildRunPath(string $directory, string $filename): string
{
    if (str_ends_with($directory, '/') || str_ends_with($directory, '\\')) {
        return $directory.$filename;
    }

    $separator = str_contains($directory, '\\') ? '\\' : '/';

    return $directory.$separator.$filename;
}

function contactFinderWriteCheckpoint(
    CheckpointWriter $checkpointWriter,
    string $runId,
    int $processedCount,
    string $outputPath,
    string $logPath,
    int $checkpointEvery,
    bool $force,
): void {
    if (!$force && $processedCount % $checkpointEvery !== 0) {
        return;
    }

    $checkpointWriter->write([
        'run_id' => $runId,
        'last_processed_row' => $processedCount,
        'processed_count' => $processedCount,
        'output_path' => $outputPath,
        'log_path' => $logPath,
        'updated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    ]);
}
function contactFinderReportFailure(
    Throwable $exception,
    ExecutionLogWriter $logWriter,
    string $outputPath,
    string $logPath,
    float $startedAt,
): void {
    $summary = contactFinderFailureSummary($exception, $outputPath, $logPath, $startedAt);

    try {
        contactFinderAppendLog($logWriter, contactFinderLogLines($summary));
    } catch (Throwable $logException) {
        fwrite(STDERR, 'Also failed to write execution log: '.$logException->getMessage().PHP_EOL);
    }

    fwrite(STDERR, contactFinderFailureStderr($summary));
}

/**
 * @return array<string, int|float|string>
 */
function contactFinderFailureSummary(Throwable $exception, string $outputPath, string $logPath, float $startedAt): array
{
    return [
        'status' => 'failed',
        'output_path' => $outputPath,
        'log_path' => $logPath,
        'error_type' => contactFinderErrorType($exception),
        'error_class' => $exception::class,
        'error_message' => $exception->getMessage(),
        'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
    ];
}

function contactFinderErrorType(Throwable $exception): string
{
    if ($exception instanceof ContactFinderException) {
        return $exception->errorType();
    }

    return 'unexpected_error';
}

/**
 * @param list<ContactResult> $results
 *
 * @return array<string, int|float|string>
 */
function contactFinderSummary(array $results, string $outputPath, string $logPath, float $startedAt): array
{
    return [
        'status' => 'success',
        'output_path' => $outputPath,
        'log_path' => $logPath,
        'input_count' => count($results),
        'output_count' => count($results),
        'usable_count' => contactFinderCountState($results, ReviewState::Usable),
        'review_count' => contactFinderCountReviews($results),
        'cannot_verify_count' => contactFinderCountState($results, ReviewState::CannotVerify),
        'conflict_count' => contactFinderCountState($results, ReviewState::Conflict),
        'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
    ];
}

/**
 * @param list<ContactResult> $results
 */
function contactFinderCountState(array $results, ReviewState $state): int
{
    return count(array_filter(
        $results,
        static fn (ContactResult $result): bool => $result->reviewState === $state,
    ));
}

/**
 * @param list<ContactResult> $results
 */
function contactFinderCountReviews(array $results): int
{
    return count(array_filter(
        $results,
        static fn (ContactResult $result): bool => $result->needsHumanReview,
    ));
}

/**
 * @param array<string, int|float|string> $summary
 *
 * @return list<string>
 */
function contactFinderLogLines(array $summary): array
{
    $lines = [];

    foreach ($summary as $key => $value) {
        $lines[] = "{$key}={$value}";
    }

    return $lines;
}

/**
 * @param array<string, int|float|string> $summary
 */
function contactFinderStdout(array $summary): string
{
    return sprintf(
        "Contact Finder completed successfully.\n".
        "Inputs: %d\n".
        "Outputs: %d\n".
        "Usable: %d\n".
        "Needs review: %d\n".
        "Cannot verify: %d\n".
        "Conflicts: %d\n".
        "Results: %s\n".
        "Log: %s\n",
        $summary['input_count'],
        $summary['output_count'],
        $summary['usable_count'],
        $summary['review_count'],
        $summary['cannot_verify_count'],
        $summary['conflict_count'],
        $summary['output_path'],
        $summary['log_path'],
    );
}

/**
 * @param array<string, int|float|string> $summary
 */
function contactFinderFailureStderr(array $summary): string
{
    return sprintf(
        "Contact Finder failed.\nError: %s\nLog: %s\n",
        $summary['error_message'],
        $summary['log_path'],
    );
}










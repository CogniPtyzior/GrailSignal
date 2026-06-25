<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Tests\Functional;

use PHPUnit\Framework\TestCase;

/**
 * Covers the public CLI entry point used to run the batch without Laravel or Artisan.
 */
final class RunContactFinderCliTest extends TestCase
{
    /** @var list<string> */
    private array $generatedFiles = [];

    /** @var list<string> */
    private array $generatedDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->generatedFiles as $generatedFile) {
            if (is_file($generatedFile)) {
                unlink($generatedFile);
            }
        }

        foreach (array_reverse($this->generatedDirectories) as $generatedDirectory) {
            if (is_dir($generatedDirectory)) {
                rmdir($generatedDirectory);
            }
        }

        parent::tearDown();
    }

    public function test_cli_runner_writes_results_and_execution_log(): void
    {
        $paths = $this->runCli();

        $this->assertFileExists($paths['output']);
        $this->assertFileExists($paths['log']);
        $this->assertStringContainsString('status=started', (string) file_get_contents($paths['log']));
        $this->assertStringContainsString('event=results_written', (string) file_get_contents($paths['log']));
        $this->assertStringContainsString('event=results_written', (string) file_get_contents($paths['log']));
        $this->assertStringContainsString('status=success', (string) file_get_contents($paths['log']));
        $this->assertStringContainsString('input_count=30', (string) file_get_contents($paths['log']));
        $this->assertStringContainsString('output_count=30', (string) file_get_contents($paths['log']));
        $this->assertStringContainsString('usable_count=7', (string) file_get_contents($paths['log']));
        $this->assertStringContainsString('review_count=23', (string) file_get_contents($paths['log']));
        $this->assertStringContainsString('cannot_verify_count=12', (string) file_get_contents($paths['log']));
        $this->assertStringContainsString('conflict_count=1', (string) file_get_contents($paths['log']));

        $decoded = json_decode((string) file_get_contents($paths['output']), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);
        $this->assertCount(30, $decoded);
        $this->assertSame('Grail Signal Demo 001 SARL', $decoded[0]['company_name']);
        $this->assertJsonRowsComplyWithGrailSignalShape($decoded);
    }

    public function test_cli_runner_uses_unique_paths_for_successive_runs(): void
    {
        $first = $this->runCli();
        $second = $this->runCli();

        $this->assertNotSame($first['output'], $second['output']);
        $this->assertNotSame($first['log'], $second['log']);
        $this->assertFileExists($first['output']);
        $this->assertFileExists($second['output']);
        $this->assertFileExists($first['log']);
        $this->assertFileExists($second['log']);
    }

    public function test_cli_runner_logs_known_failures_and_returns_non_zero_exit_code(): void
    {
        $logDirectory = $this->tempDirectory('contact-finder-cli-logs-');
        $outputDirectory = $this->tempDirectory('contact-finder-cli-results-');
        $configPath = $this->writeConfig([
            'input_csv_path' => 'Data/missing-companies.csv',
            'mock_source_path' => 'Mocks/contact_signals.json',
            'suppression_list_path' => null,
            'output_directory' => $outputDirectory,
            'logs_directory' => $logDirectory,
            'confidence_threshold' => 70,
        ]);
        $previousConfigPath = getenv('CONTACT_FINDER_CONFIG_PATH');

        putenv("CONTACT_FINDER_CONFIG_PATH={$configPath}");

        try {
            $output = [];
            $exitCode = 0;

            exec(escapeshellarg(PHP_BINARY).' run_contact_finder.php 2>&1', $output, $exitCode);
        } finally {
            $previousConfigPath === false
                ? putenv('CONTACT_FINDER_CONFIG_PATH')
                : putenv("CONTACT_FINDER_CONFIG_PATH={$previousConfigPath}");
        }

        $stderr = implode(PHP_EOL, $output);

        $this->assertSame(1, $exitCode, $stderr);
        $this->assertStringContainsString('Contact Finder failed.', $stderr);
        $this->assertStringContainsString('Input CSV is not readable', $stderr);
        preg_match('/Log: (?<log>\S+)/', $stderr, $matches);
        $this->assertArrayHasKey('log', $matches);

        $this->generatedFiles[] = $matches['log'];

        $this->assertFileExists($matches['log']);
        $logContents = (string) file_get_contents($matches['log']);
        $this->assertStringContainsString('status=started', $logContents);
        $this->assertStringContainsString('status=failed', $logContents);
        $this->assertStringContainsString('error_type=invalid_input_data', $logContents);
        $this->assertStringContainsString('error_class=GrailSignal\ContactFinder\Exceptions\InvalidInputDataException', $logContents);
    }

    /**
     * @return array{output: string, log: string}
     */
    private function runCli(): array
    {
        $output = [];
        $exitCode = 1;

        exec(escapeshellarg(PHP_BINARY).' run_contact_finder.php 2>&1', $output, $exitCode);

        $stdout = implode(PHP_EOL, $output);

        $this->assertSame(0, $exitCode, $stdout);
        $this->assertStringContainsString('Contact Finder completed successfully.', $stdout);
        $this->assertStringContainsString('Usable: 7', $stdout);
        $this->assertStringContainsString('Needs review: 23', $stdout);
        preg_match('/Results: (?<output>\\S+)\\RLog: (?<log>\\S+)/', $stdout, $matches);
        $this->assertArrayHasKey('output', $matches);
        $this->assertArrayHasKey('log', $matches);
        $this->generatedFiles[] = $matches['output'];
        $this->generatedFiles[] = $matches['log'];

        return [
            'output' => $matches['output'],
            'log' => $matches['log'],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeConfig(array $config): string
    {
        $path = tempnam(sys_get_temp_dir(), 'contact-finder-config-');
        $this->assertIsString($path);

        file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($config, true).";\n");
        $this->generatedFiles[] = $path;

        return $path;
    }

    private function tempDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.bin2hex(random_bytes(6));
        mkdir($directory, 0775, true);
        $this->generatedDirectories[] = $directory;

        return $directory;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function assertJsonRowsComplyWithGrailSignalShape(array $rows): void
    {
        foreach ($rows as $row) {
            $this->assertSame(
                [
                    'company_name',
                    'contact_name',
                    'contact_role',
                    'contact_email_or_phone',
                    'confidence_score',
                    'source',
                    'needs_human_review',
                ],
                array_keys($row),
            );
            $this->assertIsString($row['company_name']);
            $this->assertNotSame('', $row['company_name']);
            $this->assertTrue(is_string($row['contact_name']) || $row['contact_name'] === null);
            $this->assertTrue(is_string($row['contact_role']) || $row['contact_role'] === null);
            $this->assertIsString($row['contact_email_or_phone']);
            $this->assertIsInt($row['confidence_score']);
            $this->assertGreaterThanOrEqual(0, $row['confidence_score']);
            $this->assertLessThanOrEqual(100, $row['confidence_score']);
            $this->assertIsString($row['source']);
            $this->assertNotSame('', $row['source']);
            $this->assertStringContainsString('decision=', $row['source']);
            $this->assertIsBool($row['needs_human_review']);

            if ($row['needs_human_review'] === true) {
                $this->assertSame('', $row['contact_email_or_phone']);
            } else {
                $this->assertNotSame('', $row['contact_email_or_phone']);
            }
        }
    }
}

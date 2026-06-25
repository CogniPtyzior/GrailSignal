<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Tests\Integration\Infrastructure;

use GrailSignal\ContactFinder\Exceptions\OutputWriteException;
use GrailSignal\ContactFinder\Infrastructure\ExecutionLogWriter;
use PHPUnit\Framework\TestCase;

/**
 * Covers execution-log file writing for the CLI runner.
 */
final class ExecutionLogWriterTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $tempFile) {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }

        parent::tearDown();
    }

    public function test_writer_creates_execution_log_file(): void
    {
        $path = sys_get_temp_dir().'/contact-finder-log-'.bin2hex(random_bytes(6)).'/run.log';
        $writer = new ExecutionLogWriter($path);

        $writer->write([
            'status=success',
            'input_count=1',
        ]);

        $this->assertFileExists($path);
        $this->assertStringContainsString('status=success', (string) file_get_contents($path));
    }

    public function test_writer_appends_to_existing_execution_log_file(): void
    {
        $path = sys_get_temp_dir().'/contact-finder-log-'.bin2hex(random_bytes(6)).'/run.log';
        $writer = new ExecutionLogWriter($path);

        $writer->append(['status=started']);
        $writer->append(['status=failed']);

        $contents = (string) file_get_contents($path);

        $this->assertStringContainsString('status=started', $contents);
        $this->assertStringContainsString('status=failed', $contents);
        $this->assertLessThan(strpos($contents, 'status=failed'), strpos($contents, 'status=started'));
    }

    public function test_writer_raises_output_exception_when_parent_path_is_a_file(): void
    {
        $parentFile = $this->tempFile();
        $writer = new ExecutionLogWriter($parentFile.DIRECTORY_SEPARATOR.'run.log');

        $this->expectException(OutputWriteException::class);
        $this->expectExceptionMessage('Log directory could not be created');

        $writer->write(['status=success']);
    }

    private function tempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'contact-finder-log-parent-');
        $this->assertIsString($path);
        $this->tempFiles[] = $path;

        return $path;
    }
}

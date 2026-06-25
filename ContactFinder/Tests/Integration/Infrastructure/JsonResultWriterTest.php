<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Tests\Integration\Infrastructure;

use GrailSignal\ContactFinder\Domain\ContactResult;
use GrailSignal\ContactFinder\Domain\ReviewState;
use GrailSignal\ContactFinder\Exceptions\OutputWriteException;
use GrailSignal\ContactFinder\Infrastructure\JsonResultWriter;
use PHPUnit\Framework\TestCase;

/**
 * Covers JSON result serialization through the result-writer port adapter.
 */
final class JsonResultWriterTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirectories = [];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $tempFile) {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }

        foreach ($this->tempDirectories as $tempDirectory) {
            $this->removeDirectory($tempDirectory);
        }

        parent::tearDown();
    }

    public function test_writer_creates_json_output_file(): void
    {
        $outputPath = $this->tempDirectory().DIRECTORY_SEPARATOR.'results.json';
        $result = new ContactResult(
            companyName: 'Grail Signal Demo 001 SARL',
            contactName: 'Daniel Ortega',
            contactRole: 'Owner',
            contactEmailOrPhone: 'd.ortega@cedarridgeplumbing.com',
            confidenceScore: 91,
            source: 'contact_signal=mock://contact-signal/cedar-ridge-plumbing; decision=usable',
            needsHumanReview: false,
            reviewState: ReviewState::Usable,
        );

        (new JsonResultWriter($outputPath))->write([$result]);

        $decoded = json_decode((string) file_get_contents($outputPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Grail Signal Demo 001 SARL', $decoded[0]['company_name']);
        $this->assertSame('d.ortega@cedarridgeplumbing.com', $decoded[0]['contact_email_or_phone']);
        $this->assertFalse($decoded[0]['needs_human_review']);
    }

    public function test_writer_raises_output_exception_when_parent_path_is_a_file(): void
    {
        $parentFile = $this->tempFile();
        $result = new ContactResult(
            companyName: 'Grail Signal Demo 001 SARL',
            contactName: 'Daniel Ortega',
            contactRole: 'Owner',
            contactEmailOrPhone: 'd.ortega@cedarridgeplumbing.com',
            confidenceScore: 91,
            source: 'contact_signal=mock://contact-signal/cedar-ridge-plumbing; decision=usable',
            needsHumanReview: false,
            reviewState: ReviewState::Usable,
        );

        $this->expectException(OutputWriteException::class);
        $this->expectExceptionMessage('Output directory could not be created');

        (new JsonResultWriter($parentFile.DIRECTORY_SEPARATOR.'results.json'))->write([$result]);
    }

    private function tempDirectory(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'contact-finder-json-'.bin2hex(random_bytes(6));
        mkdir($directory, 0775, true);
        $this->tempDirectories[] = $directory;

        return $directory;
    }

    private function tempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'contact-finder-json-parent-');
        $this->assertIsString($path);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}

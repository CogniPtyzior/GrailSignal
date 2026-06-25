<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Tests\Integration\Infrastructure;

use GrailSignal\ContactFinder\Exceptions\InvalidInputDataException;
use GrailSignal\ContactFinder\Infrastructure\CsvCompanyInputReader;
use PHPUnit\Framework\TestCase;

/**
 * Covers CSV parsing through the input-reader port adapter.
 */
final class CsvCompanyInputReaderTest extends TestCase
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

    public function test_reader_loads_company_inputs_from_csv(): void
    {
        $csvPath = $this->writeTempFile(
            "company_name,mailing_address\r\n".
            '"Grail Signal Demo 001 SARL","4821 Maple Ave, Lincoln, NE 68504"'."\r\n".
            '"Bayview Auto Repair","129 Harbor St, Tacoma, WA 98402"'."\r\n",
        );

        $inputs = (new CsvCompanyInputReader($csvPath))->read();

        $this->assertCount(2, $inputs);
        $this->assertSame('Grail Signal Demo 001 SARL', $inputs[0]->companyName);
        $this->assertSame('4821 Maple Ave, Lincoln, NE 68504', $inputs[0]->mailingAddress);
        $this->assertSame('Bayview Auto Repair', $inputs[1]->companyName);
    }

    public function test_reader_rejects_missing_required_columns(): void
    {
        $csvPath = $this->writeTempFile("name,address\r\nInvalid,Invalid\r\n");

        $this->expectException(InvalidInputDataException::class);
        $this->expectExceptionMessage('company_name and mailing_address');

        (new CsvCompanyInputReader($csvPath))->read();
    }

    public function test_reader_adds_line_number_to_invalid_domain_input(): void
    {
        $csvPath = $this->writeTempFile(
            "company_name,mailing_address\r\n".
            '"Grail Signal Demo 001 SARL","4821 Maple Ave, Lincoln, NE 68504"'."\r\n".
            '"Bayview Auto Repair",""'."\r\n",
        );

        $this->expectException(InvalidInputDataException::class);
        $this->expectExceptionMessage('Input CSV row 3 is invalid: Mailing address is required.');

        (new CsvCompanyInputReader($csvPath))->read();
    }

    public function test_reader_adds_line_number_to_oversized_input(): void
    {
        $csvPath = $this->writeTempFile(
            "company_name,mailing_address\r\n".
            '"Bayview Auto Repair","'.str_repeat('A', 501).'"'."\r\n",
        );

        $this->expectException(InvalidInputDataException::class);
        $this->expectExceptionMessage('Input CSV row 2 is invalid: Mailing address must not exceed 500 characters.');

        (new CsvCompanyInputReader($csvPath))->read();
    }

    public function test_reader_ignores_empty_rows(): void
    {
        $csvPath = $this->writeTempFile(
            "company_name,mailing_address\r\n".
            "\r\n".
            '"Bayview Auto Repair","129 Harbor St, Tacoma, WA 98402"'."\r\n",
        );

        $inputs = (new CsvCompanyInputReader($csvPath))->read();

        $this->assertCount(1, $inputs);
        $this->assertSame('Bayview Auto Repair', $inputs[0]->companyName);
    }

    private function writeTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'contact-finder-csv-');
        $this->assertIsString($path);
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}

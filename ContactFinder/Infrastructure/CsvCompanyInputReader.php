<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Infrastructure;

use GrailSignal\ContactFinder\Domain\CompanyInput;
use GrailSignal\ContactFinder\Exceptions\InvalidInputDataException;
use GrailSignal\ContactFinder\Ports\CompanyInputReader;
use InvalidArgumentException;

/**
 * Streams Contact Finder inputs from the configured CSV while preserving row-level diagnostics.
 */
final readonly class CsvCompanyInputReader implements CompanyInputReader
{
    public function __construct(private string $csvPath)
    {
    }

    /**
     * @return iterable<CompanyInput>
     */
    public function stream(): iterable
    {
        if (!is_readable($this->csvPath)) {
            throw new InvalidInputDataException("Input CSV is not readable: {$this->csvPath}");
        }

        $handle = fopen($this->csvPath, 'rb');

        if ($handle === false) {
            throw new InvalidInputDataException("Input CSV could not be opened: {$this->csvPath}");
        }

        try {
            $header = fgetcsv($handle, null, ',', '"', '');

            if ($header === false) {
                return;
            }

            $columns = $this->mapColumns($header);
            $lineNumber = 1;

            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $lineNumber++;

                if ($this->isEmptyRow($row)) {
                    continue;
                }

                try {
                    yield new CompanyInput(
                        companyName: $row[$columns['company_name']] ?? '',
                        mailingAddress: $row[$columns['mailing_address']] ?? '',
                    );
                } catch (InvalidArgumentException $exception) {
                    throw new InvalidInputDataException(
                        "Input CSV row {$lineNumber} is invalid: {$exception->getMessage()}",
                        0,
                        $exception,
                    );
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return list<CompanyInput>
     */
    public function read(): array
    {
        return array_values(iterator_to_array($this->stream(), false));
    }

    /**
     * @param list<string|null> $header
     *
     * @return array{company_name: int, mailing_address: int}
     */
    private function mapColumns(array $header): array
    {
        $normalized = array_map(
            static fn (?string $column): string => trim((string) $column),
            $header,
        );

        $companyNameIndex = array_search('company_name', $normalized, true);
        $mailingAddressIndex = array_search('mailing_address', $normalized, true);

        if ($companyNameIndex === false || $mailingAddressIndex === false) {
            throw new InvalidInputDataException('Input CSV must contain company_name and mailing_address columns.');
        }

        return [
            'company_name' => $companyNameIndex,
            'mailing_address' => $mailingAddressIndex,
        ];
    }

    /**
     * @param list<string|null> $row
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}

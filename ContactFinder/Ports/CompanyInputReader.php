<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Ports;

use GrailSignal\ContactFinder\Domain\CompanyInput;

/**
 * Reads unpaid-account inputs without exposing the application core to CSV, filesystem, or CLI details.
 */
interface CompanyInputReader
{
    /**
     * Streams input rows so large batches do not need to hold the whole CSV in memory.
     *
     * @return iterable<CompanyInput>
     */
    public function stream(): iterable;

    /**
     * @return list<CompanyInput>
     */
    public function read(): array;
}

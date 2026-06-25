<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Ports;

use GrailSignal\ContactFinder\Domain\ContactResult;

/**
 * Writes batch results without coupling the application core to JSON, stdout, or filesystem output.
 */
interface ResultWriter
{
    /**
     * @param list<ContactResult> $results
     */
    public function write(array $results): void;
}





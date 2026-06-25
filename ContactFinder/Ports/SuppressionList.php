<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Ports;

use GrailSignal\ContactFinder\Domain\CompanyInput;

/**
 * Checks whether a company or contact channel must be suppressed before any usable result is emitted.
 */
interface SuppressionList
{
    public function isSuppressed(CompanyInput $company, string $contactEmailOrPhone): bool;
}




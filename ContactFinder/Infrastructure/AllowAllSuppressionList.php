<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Infrastructure;

use GrailSignal\ContactFinder\Domain\CompanyInput;
use GrailSignal\ContactFinder\Ports\SuppressionList;

/**
 * Default suppression adapter for runs that do not provide an opt-out fixture yet.
 */
final readonly class AllowAllSuppressionList implements SuppressionList
{
    public function isSuppressed(CompanyInput $company, string $contactEmailOrPhone): bool
    {
        return false;
    }
}





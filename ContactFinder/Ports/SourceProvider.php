<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Ports;

use GrailSignal\ContactFinder\Domain\CompanyInput;
use GrailSignal\ContactFinder\Domain\ContactEvidence;

/**
 * Provides contact evidence for one company while hiding whether the data came from mocks or another allowed source.
 */
interface SourceProvider
{
    /**
     * @return list<ContactEvidence>
     */
    public function findEvidenceFor(CompanyInput $company): array;
}




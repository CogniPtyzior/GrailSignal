<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Exceptions;

use RuntimeException;

/**
 * Base exception for known Contact Finder failures that should be safe to show in batch output.
 */
class ContactFinderException extends RuntimeException
{
    public function errorType(): string
    {
        return 'contact_finder_error';
    }
}




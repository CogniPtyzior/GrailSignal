<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Exceptions;

/**
 * Raised when a mock source fixture cannot be read or does not match the expected mock-provider contract.
 */
final class InvalidMockSourceException extends ContactFinderException
{
    public function errorType(): string
    {
        return 'invalid_mock_source';
    }
}




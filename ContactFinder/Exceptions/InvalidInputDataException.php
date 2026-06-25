<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Exceptions;

/**
 * Raised when configured input data cannot be read or violates the expected input contract.
 */
final class InvalidInputDataException extends ContactFinderException
{
    public function errorType(): string
    {
        return 'invalid_input_data';
    }
}





<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Exceptions;

/**
 * Raised when runner configuration or optional policy configuration is missing or malformed.
 */
final class InvalidConfigurationException extends ContactFinderException
{
    public function errorType(): string
    {
        return 'invalid_configuration';
    }
}




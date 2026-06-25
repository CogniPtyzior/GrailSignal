<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Exceptions;

/**
 * Raised when a generated result file or execution log cannot be written.
 */
final class OutputWriteException extends ContactFinderException
{
    public function errorType(): string
    {
        return 'output_write';
    }
}




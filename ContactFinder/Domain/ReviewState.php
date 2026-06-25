<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Domain;

/**
 * Describes the review outcome used internally before a result is serialized for public output.
 */
enum ReviewState: string
{
    case Usable = 'usable';
    case ReviewRequired = 'review_required';
    case Conflict = 'conflict';
    case CannotVerify = 'cannot_verify';
}





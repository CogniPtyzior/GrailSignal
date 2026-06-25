<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Domain;

use InvalidArgumentException;

/**
 * Represents one input row from the unpaid-account dataset before any provider lookup is performed.
 */
final readonly class CompanyInput
{
    private const MAX_COMPANY_NAME_LENGTH = 255;
    private const MAX_MAILING_ADDRESS_LENGTH = 500;

    public string $companyName;

    public string $mailingAddress;

    public function __construct(string $companyName, string $mailingAddress)
    {
        $companyName = trim($companyName);
        $mailingAddress = trim($mailingAddress);

        if ($companyName === '') {
            throw new InvalidArgumentException('Company name is required.');
        }

        if ($this->textLength($companyName) > self::MAX_COMPANY_NAME_LENGTH) {
            throw new InvalidArgumentException('Company name must not exceed 255 characters.');
        }

        if ($mailingAddress === '') {
            throw new InvalidArgumentException('Mailing address is required.');
        }

        if ($this->textLength($mailingAddress) > self::MAX_MAILING_ADDRESS_LENGTH) {
            throw new InvalidArgumentException('Mailing address must not exceed 500 characters.');
        }

        $this->companyName = $companyName;
        $this->mailingAddress = $mailingAddress;
    }

    /**
     * Counts Unicode characters when possible so max-length checks do not over-reject valid business text.
     */
    private function textLength(string $value): int
    {
        if (preg_match_all('/./us', $value, $matches) === false) {
            return strlen($value);
        }

        return count($matches[0]);
    }
}




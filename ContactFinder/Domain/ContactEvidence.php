<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Domain;

use InvalidArgumentException;

/**
 * Captures one provider's raw contact signal while keeping its provenance attached to the fields it supplied.
 */
final readonly class ContactEvidence
{
    public string $provider;

    public ?string $contactName;

    public ?string $contactRole;

    public ?string $email;

    public ?string $phone;

    public ?int $providerConfidence;

    public string $sourceUrl;

    public function __construct(
        string $provider,
        string $sourceUrl,
        ?string $contactName = null,
        ?string $contactRole = null,
        ?string $email = null,
        ?string $phone = null,
        ?int $providerConfidence = null,
    ) {
        $provider = trim($provider);
        $sourceUrl = trim($sourceUrl);

        if ($provider === '') {
            throw new InvalidArgumentException('Evidence provider is required.');
        }

        if ($sourceUrl === '') {
            throw new InvalidArgumentException('Evidence source URL is required.');
        }

        if ($providerConfidence !== null && ($providerConfidence < 0 || $providerConfidence > 100)) {
            throw new InvalidArgumentException('Provider confidence must be between 0 and 100.');
        }

        $this->provider = $provider;
        $this->sourceUrl = $sourceUrl;
        $this->contactName = $this->normalizeOptionalText($contactName);
        $this->contactRole = $this->normalizeOptionalText($contactRole);
        $this->email = $this->normalizeOptionalText($email);
        $this->phone = $this->normalizeOptionalText($phone);
        $this->providerConfidence = $providerConfidence;
    }

    /**
     * A provider can support identity, channel, or both; this helper keeps later scoring logic readable.
     */
    public function hasAnyContactSignal(): bool
    {
        return $this->contactName !== null
            || $this->contactRole !== null
            || $this->email !== null
            || $this->phone !== null;
    }

    private function normalizeOptionalText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}




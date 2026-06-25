<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Domain;

use InvalidArgumentException;

/**
 * Represents the public output row after merge, scoring, and review policy have been applied.
 */
final readonly class ContactResult
{
    public string $companyName;

    public ?string $contactName;

    public ?string $contactRole;

    public string $contactEmailOrPhone;

    public int $confidenceScore;

    public string $source;

    public bool $needsHumanReview;

    public ReviewState $reviewState;

    public function __construct(
        string $companyName,
        ?string $contactName,
        ?string $contactRole,
        string $contactEmailOrPhone,
        int $confidenceScore,
        string $source,
        bool $needsHumanReview,
        ReviewState $reviewState,
    ) {
        $companyName = trim($companyName);
        $source = trim($source);
        $contactEmailOrPhone = trim($contactEmailOrPhone);

        if ($companyName === '') {
            throw new InvalidArgumentException('Result company name is required.');
        }

        if ($confidenceScore < 0 || $confidenceScore > 100) {
            throw new InvalidArgumentException('Confidence score must be between 0 and 100.');
        }

        if ($source === '') {
            throw new InvalidArgumentException('Result source provenance is required.');
        }

        if ($needsHumanReview && $contactEmailOrPhone !== '') {
            throw new InvalidArgumentException('Reviewed results must not expose a contact channel.');
        }

        if (!$needsHumanReview && $contactEmailOrPhone === '') {
            throw new InvalidArgumentException('Usable results must expose a contact channel.');
        }

        if ($needsHumanReview !== ($reviewState !== ReviewState::Usable)) {
            throw new InvalidArgumentException('Review state and human-review flag are inconsistent.');
        }

        if (!str_contains($source, 'decision='.$reviewState->value)) {
            throw new InvalidArgumentException('Result source decision must match review state.');
        }

        $this->companyName = $companyName;
        $this->contactName = $this->normalizeOptionalText($contactName);
        $this->contactRole = $this->normalizeOptionalText($contactRole);
        $this->contactEmailOrPhone = $contactEmailOrPhone;
        $this->confidenceScore = $confidenceScore;
        $this->source = $source;
        $this->needsHumanReview = $needsHumanReview;
        $this->reviewState = $reviewState;
    }

    /**
     * Returns the exact public output fields.
     *
     * @return array{
     *     company_name: string,
     *     contact_name: string|null,
     *     contact_role: string|null,
     *     contact_email_or_phone: string,
     *     confidence_score: int,
     *     source: string,
     *     needs_human_review: bool
     * }
     */
    public function toOutputRow(): array
    {
        return [
            'company_name' => $this->companyName,
            'contact_name' => $this->contactName,
            'contact_role' => $this->contactRole,
            'contact_email_or_phone' => $this->contactEmailOrPhone,
            'confidence_score' => $this->confidenceScore,
            'source' => $this->source,
            'needs_human_review' => $this->needsHumanReview,
        ];
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





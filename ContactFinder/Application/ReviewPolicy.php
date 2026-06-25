<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Application;

use GrailSignal\ContactFinder\Domain\CompanyInput;
use GrailSignal\ContactFinder\Domain\ContactResult;
use GrailSignal\ContactFinder\Domain\MergedContactEvidence;
use GrailSignal\ContactFinder\Domain\ReviewState;
use InvalidArgumentException;

/**
 * Applies the configured confidence threshold and converts scored evidence into the public result shape.
 */
final readonly class ReviewPolicy
{
    public function __construct(private int $confidenceThreshold)
    {
        if ($confidenceThreshold < 0 || $confidenceThreshold > 100) {
            throw new InvalidArgumentException('Confidence threshold must be between 0 and 100.');
        }
    }

    public function apply(CompanyInput $company, MergedContactEvidence $merged, int $confidenceScore): ContactResult
    {
        if ($confidenceScore < 0 || $confidenceScore > 100) {
            throw new InvalidArgumentException('Confidence score must be between 0 and 100.');
        }

        $reviewState = $this->reviewState($merged, $confidenceScore);
        $needsHumanReview = $reviewState !== ReviewState::Usable;

        return new ContactResult(
            companyName: $company->companyName,
            contactName: $merged->contactName,
            contactRole: $merged->contactRole,
            contactEmailOrPhone: $needsHumanReview ? '' : $this->bestContactChannel($merged),
            confidenceScore: $confidenceScore,
            source: $this->sourceSummary($merged, $reviewState),
            needsHumanReview: $needsHumanReview,
            reviewState: $reviewState,
        );
    }

    private function reviewState(MergedContactEvidence $merged, int $confidenceScore): ReviewState
    {
        if ($merged->hasSignal(MergedContactEvidence::SIGNAL_CANNOT_VERIFY)) {
            return ReviewState::CannotVerify;
        }

        if ($merged->hasSignal(MergedContactEvidence::SIGNAL_CONFLICT)) {
            return ReviewState::Conflict;
        }

        if ($merged->hasSignal(MergedContactEvidence::SIGNAL_PHONE_CONFLICT)) {
            return ReviewState::Conflict;
        }

        if ($confidenceScore < $this->confidenceThreshold || $this->bestContactChannel($merged) === '') {
            return ReviewState::ReviewRequired;
        }

        return ReviewState::Usable;
    }

    private function bestContactChannel(MergedContactEvidence $merged): string
    {
        return $merged->email ?? $merged->phone ?? '';
    }

    private function sourceSummary(MergedContactEvidence $merged, ReviewState $reviewState): string
    {
        return $merged->provenanceSummary().'; decision='.$reviewState->value;
    }
}





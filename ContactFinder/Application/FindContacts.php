<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Application;

use GrailSignal\ContactFinder\Domain\CompanyInput;
use GrailSignal\ContactFinder\Domain\ContactResult;
use GrailSignal\ContactFinder\Domain\MergedContactEvidence;
use GrailSignal\ContactFinder\Domain\ReviewState;
use GrailSignal\ContactFinder\Ports\CompanyInputReader;
use GrailSignal\ContactFinder\Ports\SourceProvider;
use GrailSignal\ContactFinder\Ports\SuppressionList;

/**
 * Orchestrates contact finding one input at a time so callers can stream results or collect them as needed.
 */
final readonly class FindContacts
{
    public function __construct(
        private CompanyInputReader $companyInputReader,
        private SourceProvider $sourceProvider,
        private ContactMerger $contactMerger,
        private ConfidenceScorer $confidenceScorer,
        private ReviewPolicy $reviewPolicy,
        private SuppressionList $suppressionList,
    ) {
    }

    /**
     * @return iterable<ContactResult>
     */
    public function stream(?callable $afterResult = null): iterable
    {
        $rowNumber = 0;

        foreach ($this->companyInputReader->stream() as $company) {
            $rowNumber++;
            $result = $this->findForCompany($company);

            if ($afterResult !== null) {
                $afterResult($result, $rowNumber);
            }

            yield $result;
        }
    }

    /**
     * Reads all input rows and returns exactly one result for each row, in input order.
     *
     * @return list<ContactResult>
     */
    public function run(): array
    {
        return array_values(iterator_to_array($this->stream(), false));
    }

    private function findForCompany(CompanyInput $company): ContactResult
    {
        $merged = $this->contactMerger->merge($this->sourceProvider->findEvidenceFor($company));
        $score = $this->confidenceScorer->score($merged);
        $result = $this->reviewPolicy->apply($company, $merged, $score);

        return $this->applySuppression($company, $merged, $result);
    }

    private function applySuppression(CompanyInput $company, MergedContactEvidence $merged, ContactResult $result): ContactResult
    {
        if (!$this->isSuppressed($company, $merged, $result)) {
            return $result;
        }

        return new ContactResult(
            companyName: $result->companyName,
            contactName: null,
            contactRole: null,
            contactEmailOrPhone: '',
            confidenceScore: 0,
            source: $this->suppressedSource($result),
            needsHumanReview: true,
            reviewState: ReviewState::ReviewRequired,
        );
    }

    private function isSuppressed(CompanyInput $company, MergedContactEvidence $merged, ContactResult $result): bool
    {
        foreach ($this->suppressionChannels($merged, $result) as $channel) {
            if ($this->suppressionList->isSuppressed($company, $channel)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function suppressionChannels(MergedContactEvidence $merged, ContactResult $result): array
    {
        return array_values(array_unique(array_filter([
            $result->contactEmailOrPhone,
            $merged->email,
            $merged->phone,
            '',
        ], static fn (?string $channel): bool => $channel !== null)));
    }

    /**
     * Replaces the previous review decision because suppression is the final gate before output.
     */
    private function suppressedSource(ContactResult $result): string
    {
        $source = preg_replace('/; decision=[a-z_]+/', '', $result->source) ?? $result->source;

        return $source.'; decision='.ReviewState::ReviewRequired->value.'; suppression=opt_out';
    }
}

<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Application;

use GrailSignal\ContactFinder\Domain\ContactEvidence;
use GrailSignal\ContactFinder\Domain\MergedContactEvidence;

/**
 * Computes an explainable confidence score from selected contact-channel evidence and supporting corroboration.
 */
final class ConfidenceScorer
{
    public function score(MergedContactEvidence $merged): int
    {
        if ($merged->hasSignal(MergedContactEvidence::SIGNAL_CANNOT_VERIFY)) {
            return 0;
        }

        $score = 40;
        $score += $this->sourceDiversityPoints($merged);
        $score += $this->agreementPoints($merged);
        $score += $this->channelPoints($merged);
        $score += $this->paymentInboxPoints($merged);
        $score += $this->rolePoints($merged);
        $score += $this->selectedChannelConfidencePoints($merged);
        $score += $this->strongSingleSourcePoints($merged);
        $score -= $this->riskPenalties($merged);

        return $this->applyPrecisionCaps($merged, max(0, min(100, $score)));
    }

    private function sourceDiversityPoints(MergedContactEvidence $merged): int
    {
        $sourceCount = count($merged->sourceUrlsByProvider);

        return match (true) {
            $sourceCount >= 3 => 15,
            $sourceCount === 2 => 10,
            default => 0,
        };
    }

    private function agreementPoints(MergedContactEvidence $merged): int
    {
        $points = 0;

        if ($merged->hasSignal(MergedContactEvidence::SIGNAL_NAME_AGREEMENT)) {
            $points += 15;
        }

        if ($merged->hasSignal(MergedContactEvidence::SIGNAL_PHONE_AGREEMENT)) {
            $points += 10;
        }

        return $points;
    }

    private function channelPoints(MergedContactEvidence $merged): int
    {
        $points = 0;

        if ($merged->email !== null) {
            $points += 10;
        }

        if ($merged->phone !== null) {
            $points += 5;
        }

        return $points;
    }

    private function paymentInboxPoints(MergedContactEvidence $merged): int
    {
        if ($merged->email === null) {
            return 0;
        }

        return $this->isPaymentInbox($merged->email) ? 5 : 0;
    }

    private function rolePoints(MergedContactEvidence $merged): int
    {
        $role = strtolower((string) $merged->contactRole);

        return match (true) {
            str_contains($role, 'accounts payable') => 15,
            str_contains($role, 'ap manager') => 15,
            preg_match('/\bap\b/', $role) === 1 => 15,
            str_contains($role, 'owner') => 10,
            str_contains($role, 'founder') => 10,
            str_contains($role, 'cfo') => 8,
            str_contains($role, 'finance director') => 8,
            str_contains($role, 'finance') => 8,
            str_contains($role, 'president') => 8,
            str_contains($role, 'office manager') => 5,
            str_contains($role, 'manager') => 4,
            default => 0,
        };
    }

    private function selectedChannelConfidencePoints(MergedContactEvidence $merged): int
    {
        $confidence = $this->selectedChannelConfidence($merged);

        if ($confidence === null || $confidence < 50) {
            return 0;
        }

        return min(10, intdiv($confidence - 50, 5));
    }

    private function strongSingleSourcePoints(MergedContactEvidence $merged): int
    {
        if (!$merged->hasSignal(MergedContactEvidence::SIGNAL_SINGLE_SOURCE) || $merged->email === null) {
            return 0;
        }

        $confidence = $this->selectedChannelConfidence($merged);

        if ($confidence === null || $confidence < 80) {
            return 0;
        }

        if ($this->isStrongPaymentInbox($merged)) {
            return 15;
        }

        return $merged->contactName !== null && $merged->contactRole !== null ? 10 : 0;
    }

    /**
     * Caps keep additive support signals from making an under-corroborated contact channel usable.
     */
    private function applyPrecisionCaps(MergedContactEvidence $merged, int $score): int
    {
        if ($merged->email === null && $merged->phone === null) {
            return 0;
        }

        if ($merged->hasSignal(MergedContactEvidence::SIGNAL_CONFLICT) || $merged->hasSignal(MergedContactEvidence::SIGNAL_PHONE_CONFLICT)) {
            $score = min($score, 49);
        }

        if ($merged->hasSignal(MergedContactEvidence::SIGNAL_REGISTERED_AGENT_ONLY)) {
            return 0;
        }

        if ($merged->hasSignal(MergedContactEvidence::SIGNAL_WEAK_EVIDENCE) && !$this->isStrongPaymentInbox($merged)) {
            $score = min($score, 59);
        }

        $channelConfidence = $this->selectedChannelConfidence($merged);

        if ($channelConfidence !== null && $channelConfidence < 60) {
            $score = min($score, 69);
        }

        if ($merged->email === null && !$merged->hasSignal(MergedContactEvidence::SIGNAL_PHONE_AGREEMENT)) {
            $score = min($score, 69);
        }

        return $score;
    }

    private function riskPenalties(MergedContactEvidence $merged): int
    {
        $penalty = 0;

        if ($merged->hasSignal(MergedContactEvidence::SIGNAL_CONFLICT)) {
            $penalty += 35;
        }

        if ($merged->hasSignal(MergedContactEvidence::SIGNAL_PHONE_CONFLICT)) {
            $penalty += 15;
        }

        if ($merged->hasSignal(MergedContactEvidence::SIGNAL_REGISTERED_AGENT_ONLY)) {
            $penalty += 35;
        }

        if ($merged->hasSignal(MergedContactEvidence::SIGNAL_SINGLE_SOURCE)) {
            $penalty += $this->strongSingleSourcePoints($merged) > 0 ? 10 : 20;
        }

        $strongPaymentInbox = $this->isStrongPaymentInbox($merged);

        if ($merged->hasSignal(MergedContactEvidence::SIGNAL_WEAK_EVIDENCE) && !$strongPaymentInbox) {
            $penalty += 15;
        }

        if ($merged->contactName === null && !$strongPaymentInbox) {
            $penalty += 15;
        }

        if ($merged->contactRole === null && !$strongPaymentInbox) {
            $penalty += 10;
        }

        return $penalty;
    }

    private function selectedChannelConfidence(MergedContactEvidence $merged): ?int
    {
        $values = [];

        foreach (['email', 'phone'] as $field) {
            $confidence = $merged->selectedEvidenceFor($field)?->providerConfidence;

            if ($confidence !== null) {
                $values[] = $confidence;
            }
        }

        return $values === [] ? null : max($values);
    }

    private function isStrongPaymentInbox(MergedContactEvidence $merged): bool
    {
        if ($merged->email === null || !$this->isPaymentInbox($merged->email)) {
            return false;
        }

        $confidence = $this->selectedChannelConfidence($merged);

        return $confidence !== null && $confidence >= 80;
    }

    private function isPaymentInbox(string $email): bool
    {
        $localPart = strtolower((string) strstr($email, '@', true));

        return in_array($localPart, ['billing', 'accounting', 'ap'], true);
    }
}


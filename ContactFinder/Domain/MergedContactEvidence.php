<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Domain;

/**
 * Represents provider evidence after deterministic merge, before confidence scoring and review gates.
 */
final readonly class MergedContactEvidence
{
    public const SIGNAL_CANNOT_VERIFY = 'cannot_verify';
    public const SIGNAL_CONFLICT = 'conflict';
    public const SIGNAL_REGISTERED_AGENT_ONLY = 'registered_agent_only';
    public const SIGNAL_WEAK_EVIDENCE = 'weak_evidence';
    public const SIGNAL_SINGLE_SOURCE = 'single_source';
    public const SIGNAL_NAME_AGREEMENT = 'name_agreement';
    public const SIGNAL_PHONE_AGREEMENT = 'phone_agreement';
    public const SIGNAL_PHONE_CONFLICT = 'phone_conflict';

    /**
     * @param list<ContactEvidence> $evidence
     * @param array<string, string> $sourceUrlsByProvider
     * @param array<string, string> $sourceUrlsByField
     * @param array<string, ContactEvidence> $selectedEvidenceByField
     * @param list<string> $signals
     */
    public function __construct(
        public ?string $contactName,
        public ?string $contactRole,
        public ?string $email,
        public ?string $phone,
        public array $evidence,
        public array $sourceUrlsByProvider,
        public array $sourceUrlsByField,
        public array $selectedEvidenceByField,
        public array $signals,
    ) {
    }

    public function hasEvidence(): bool
    {
        return $this->evidence !== [];
    }

    public function hasSignal(string $signal): bool
    {
        return in_array($signal, $this->signals, true);
    }

    public function selectedEvidenceFor(string $field): ?ContactEvidence
    {
        return $this->selectedEvidenceByField[$field] ?? null;
    }

    /**
     * Formats selected field provenance first, then falls back to raw source provenance for filtered signals.
     */
    public function provenanceSummary(): string
    {
        $parts = [];

        foreach ($this->sourceUrlsByField as $field => $sourceUrl) {
            $parts[] = "{$field}={$sourceUrl}";
        }

        if ($parts !== []) {
            return implode('; ', $parts);
        }

        foreach ($this->sourceUrlsByProvider as $provider => $sourceUrl) {
            $parts[] = "source_{$provider}={$sourceUrl}";
        }

        return $parts === [] ? 'sources=none' : implode('; ', $parts);
    }
}

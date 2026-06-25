<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Application;

use GrailSignal\ContactFinder\Domain\ContactEvidence;
use GrailSignal\ContactFinder\Domain\MergedContactEvidence;

/**
 * Merges independent mock-provider evidence without assigning a confidence score.
 */
final class ContactMerger
{
    /** @var list<string> */
    private const PERSONAL_EMAIL_DOMAINS = [
        'aol.com',
        'gmail.com',
        'hotmail.com',
        'icloud.com',
        'live.com',
        'msn.com',
        'outlook.com',
        'proton.me',
        'protonmail.com',
        'yahoo.com',
    ];

    /** @var array<string, list<string>> */
    private const NICKNAME_GROUPS = [
        'bob' => ['bob', 'robert'],
        'robert' => ['bob', 'robert'],
    ];

    /**
     * @param list<ContactEvidence> $evidence
     */
    public function merge(array $evidence): MergedContactEvidence
    {
        if ($evidence === []) {
            return new MergedContactEvidence(
                contactName: null,
                contactRole: null,
                email: null,
                phone: null,
                evidence: [],
                sourceUrlsByProvider: [],
                sourceUrlsByField: [],
                selectedEvidenceByField: [],
                signals: [MergedContactEvidence::SIGNAL_CANNOT_VERIFY],
            );
        }

        $contactNameEvidence = $this->selectEvidence(
            $this->prioritizeIdentityEvidence($evidence),
            static fn (ContactEvidence $item): ?string => $item->contactName,
        );
        $contactRoleEvidence = $this->selectRoleEvidence($evidence, $contactNameEvidence);
        $emailEvidence = $this->selectEvidence($evidence, fn (ContactEvidence $item): ?string => $this->businessEmail($item));
        $phoneEvidence = $this->selectPhoneEvidence($evidence);
        $sourceUrlsByProvider = $this->sourceUrlsByProvider($evidence);
        $signals = $this->signals($evidence);

        return new MergedContactEvidence(
            contactName: $contactNameEvidence?->contactName,
            contactRole: $contactRoleEvidence?->contactRole,
            email: $emailEvidence?->email,
            phone: $phoneEvidence?->phone,
            evidence: $evidence,
            sourceUrlsByProvider: $sourceUrlsByProvider,
            sourceUrlsByField: $this->sourceUrlsByField(
                contactNameEvidence: $contactNameEvidence,
                contactRoleEvidence: $contactRoleEvidence,
                emailEvidence: $emailEvidence,
                phoneEvidence: $phoneEvidence,
            ),
            selectedEvidenceByField: $this->selectedEvidenceByField(
                contactNameEvidence: $contactNameEvidence,
                contactRoleEvidence: $contactRoleEvidence,
                emailEvidence: $emailEvidence,
                phoneEvidence: $phoneEvidence,
            ),
            signals: $signals,
        );
    }

    /**
     * @param list<ContactEvidence> $evidence
     *
     * @return array<string, string>
     */
    private function sourceUrlsByProvider(array $evidence): array
    {
        $sources = [];

        foreach ($evidence as $item) {
            $sources[$item->provider] = $item->sourceUrl;
        }

        ksort($sources);

        return $sources;
    }

    /**
     * @return array<string, string>
     */
    private function sourceUrlsByField(
        ?ContactEvidence $contactNameEvidence,
        ?ContactEvidence $contactRoleEvidence,
        ?ContactEvidence $emailEvidence,
        ?ContactEvidence $phoneEvidence,
    ): array {
        $sources = [];

        if ($contactNameEvidence?->contactName !== null) {
            $sources['contact_name'] = $contactNameEvidence->sourceUrl;
        }

        if ($contactRoleEvidence?->contactRole !== null) {
            $sources['contact_role'] = $contactRoleEvidence->sourceUrl;
        }

        if ($emailEvidence?->email !== null) {
            $sources['email'] = $emailEvidence->sourceUrl;
        }

        if ($phoneEvidence?->phone !== null) {
            $sources['phone'] = $phoneEvidence->sourceUrl;
        }

        ksort($sources);

        return $sources;
    }

    /**
     * @return array<string, ContactEvidence>
     */
    private function selectedEvidenceByField(
        ?ContactEvidence $contactNameEvidence,
        ?ContactEvidence $contactRoleEvidence,
        ?ContactEvidence $emailEvidence,
        ?ContactEvidence $phoneEvidence,
    ): array {
        $selected = [];

        if ($contactNameEvidence?->contactName !== null) {
            $selected['contact_name'] = $contactNameEvidence;
        }

        if ($contactRoleEvidence?->contactRole !== null) {
            $selected['contact_role'] = $contactRoleEvidence;
        }

        if ($emailEvidence?->email !== null) {
            $selected['email'] = $emailEvidence;
        }

        if ($phoneEvidence?->phone !== null) {
            $selected['phone'] = $phoneEvidence;
        }

        ksort($selected);

        return $selected;
    }
    /**
     * @param list<ContactEvidence> $evidence
     *
     * @return list<string>
     */
    private function signals(array $evidence): array
    {
        $signals = [];

        if ($this->hasNameAgreement($evidence)) {
            $signals[] = MergedContactEvidence::SIGNAL_NAME_AGREEMENT;
        }

        if ($this->hasPhoneAgreement($evidence)) {
            $signals[] = MergedContactEvidence::SIGNAL_PHONE_AGREEMENT;
        }

        if ($this->hasPhoneConflict($evidence)) {
            $signals[] = MergedContactEvidence::SIGNAL_PHONE_CONFLICT;
        }

        if ($this->hasNameConflict($evidence)) {
            $signals[] = MergedContactEvidence::SIGNAL_CONFLICT;
        }

        if ($this->isRegisteredAgentOnly($evidence)) {
            $signals[] = MergedContactEvidence::SIGNAL_REGISTERED_AGENT_ONLY;
        }

        if ($this->isWeakEvidence($evidence)) {
            $signals[] = MergedContactEvidence::SIGNAL_WEAK_EVIDENCE;
        }

        if (count($evidence) === 1) {
            $signals[] = MergedContactEvidence::SIGNAL_SINGLE_SOURCE;
        }

        return $signals;
    }

    /**
     * @param list<ContactEvidence> $evidence
     */
    private function selectRoleEvidence(array $evidence, ?ContactEvidence $contactNameEvidence): ?ContactEvidence
    {
        if ($contactNameEvidence?->contactRole !== null) {
            return $contactNameEvidence;
        }

        return $this->selectEvidence(
            $this->prioritizeIdentityEvidence($evidence),
            static fn (ContactEvidence $item): ?string => $item->contactRole,
        );
    }

    /**
     * @param list<ContactEvidence> $evidence
     */
    private function selectPhoneEvidence(array $evidence): ?ContactEvidence
    {
        $phones = array_values(array_filter(
            array_map(fn (ContactEvidence $item): ?string => $this->normalizedPhone($item->phone), $evidence),
            static fn (?string $phone): bool => $phone !== null,
        ));

        if ($phones === []) {
            return null;
        }

        $counts = array_count_values($phones);
        arsort($counts);
        $selectedPhone = (string) array_key_first($counts);

        return $this->selectEvidence(
            $evidence,
            fn (ContactEvidence $item): ?string => $this->normalizedPhone($item->phone) === $selectedPhone ? $item->phone : null,
        );
    }

    /**
     * @param list<ContactEvidence> $evidence
     * @param callable(ContactEvidence): ?string $selector
     */
    private function selectEvidence(array $evidence, callable $selector): ?ContactEvidence
    {
        foreach ($evidence as $item) {
            $value = $selector($item);

            if ($value !== null) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param list<ContactEvidence> $evidence
     *
     * @return list<ContactEvidence>
     */
    private function prioritizeIdentityEvidence(array $evidence): array
    {
        usort(
            $evidence,
            fn (ContactEvidence $left, ContactEvidence $right): int => $this->identityPriority($left) <=> $this->identityPriority($right),
        );

        return $evidence;
    }

    private function identityPriority(ContactEvidence $evidence): int
    {
        if ($this->isRegisteredAgent($evidence)) {
            return 900 + $this->providerPriority($evidence);
        }

        return ($this->rolePriority($evidence->contactRole) * 100) + $this->providerPriority($evidence);
    }

    private function providerPriority(ContactEvidence $evidence): int
    {
        return match ($evidence->provider) {
            'business_registry' => 10,
            'business_directory' => 20,
            'contact_signal' => 30,
            default => 50,
        };
    }

    private function rolePriority(?string $role): int
    {
        $role = strtolower((string) $role);

        return match (true) {
            str_contains($role, 'accounts payable') => 0,
            str_contains($role, 'ap manager') => 0,
            preg_match('/\bap\b/', $role) === 1 => 0,
            str_contains($role, 'owner') => 1,
            str_contains($role, 'founder') => 1,
            str_contains($role, 'cfo') => 2,
            str_contains($role, 'finance director') => 2,
            str_contains($role, 'finance') => 2,
            str_contains($role, 'office manager') => 3,
            default => 5,
        };
    }

    private function businessEmail(ContactEvidence $evidence): ?string
    {
        if ($evidence->email === null || filter_var($evidence->email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $domain = strtolower((string) substr(strrchr($evidence->email, '@') ?: '', 1));

        if ($domain === '' || in_array($domain, self::PERSONAL_EMAIL_DOMAINS, true)) {
            return null;
        }

        return $evidence->email;
    }

    /**
     * @param list<ContactEvidence> $evidence
     */
    private function hasNameAgreement(array $evidence): bool
    {
        $names = $this->namedEvidence($evidence);

        if (count($names) < 2) {
            return false;
        }

        foreach ($names as $leftIndex => $left) {
            foreach (array_slice($names, $leftIndex + 1) as $right) {
                if ($this->namesAreCompatible($left->contactName, $right->contactName)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<ContactEvidence> $evidence
     */
    private function hasNameConflict(array $evidence): bool
    {
        $names = $this->namedEvidence($evidence);

        if (count($names) < 2) {
            return false;
        }

        foreach ($names as $leftIndex => $left) {
            foreach (array_slice($names, $leftIndex + 1) as $right) {
                if (!$this->namesAreCompatible($left->contactName, $right->contactName)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<ContactEvidence> $evidence
     */
    private function hasPhoneAgreement(array $evidence): bool
    {
        $phones = array_filter(
            array_map(fn (ContactEvidence $item): ?string => $this->normalizedPhone($item->phone), $evidence),
            static fn (?string $phone): bool => $phone !== null,
        );

        return max(array_count_values($phones) ?: [0]) > 1;
    }

    /**
     * @param list<ContactEvidence> $evidence
     */
    private function hasPhoneConflict(array $evidence): bool
    {
        $phones = array_values(array_unique(array_filter(
            array_map(fn (ContactEvidence $item): ?string => $this->normalizedPhone($item->phone), $evidence),
            static fn (?string $phone): bool => $phone !== null,
        )));

        return count($phones) > 1;
    }

    private function normalizedPhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $phone = trim($phone);

        if ($phone === '') {
            return null;
        }

        $prefix = str_starts_with($phone, '+') ? '+' : '';
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($prefix === '+' && strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return $digits;
        }

        return $digits === '' ? null : $prefix.$digits;
    }

    /**
     * @param list<ContactEvidence> $evidence
     */
    private function isRegisteredAgentOnly(array $evidence): bool
    {
        $withSignals = array_values(array_filter(
            $evidence,
            static fn (ContactEvidence $item): bool => $item->hasAnyContactSignal(),
        ));

        return count($withSignals) === 1
            && $this->isRegisteredAgent($withSignals[0])
            && $withSignals[0]->email === null
            && $withSignals[0]->phone === null;
    }

    /**
     * @param list<ContactEvidence> $evidence
     */
    private function isWeakEvidence(array $evidence): bool
    {
        if (count($evidence) !== 1) {
            return false;
        }

        $only = $evidence[0];

        return $this->isRegisteredAgentOnly($evidence)
            || $only->contactName === null
            || $only->contactRole === null;
    }

    /**
     * @param list<ContactEvidence> $evidence
     *
     * @return list<ContactEvidence>
     */
    private function namedEvidence(array $evidence): array
    {
        return array_values(array_filter(
            $evidence,
            static fn (ContactEvidence $item): bool => $item->contactName !== null,
        ));
    }

    private function isRegisteredAgent(ContactEvidence $evidence): bool
    {
        $role = strtolower((string) $evidence->contactRole);

        return $role === 'registered agent';
    }

    private function namesAreCompatible(?string $left, ?string $right): bool
    {
        if ($left === null || $right === null) {
            return false;
        }

        $leftParts = $this->normalizeNameParts($left);
        $rightParts = $this->normalizeNameParts($right);

        if ($leftParts === [] || $rightParts === []) {
            return false;
        }

        if ($leftParts === $rightParts) {
            return true;
        }

        $leftLast = $leftParts[array_key_last($leftParts)];
        $rightLast = $rightParts[array_key_last($rightParts)];

        if ($leftLast !== $rightLast) {
            return false;
        }

        return $this->firstNamesAreCompatible($leftParts[0], $rightParts[0]);
    }

    /**
     * @return list<string>
     */
    private function normalizeNameParts(string $name): array
    {
        $normalized = strtolower($name);
        $normalized = preg_replace('/\([^)]*\)/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b(dr|mr|mrs|ms)\.?\b/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^a-z\s]/', ' ', $normalized) ?? $normalized;

        return array_values(array_filter(
            preg_split('/\s+/', trim($normalized)) ?: [],
            static fn (string $part): bool => $part !== '',
        ));
    }

    private function firstNamesAreCompatible(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }

        if (strlen($left) === 1 && str_starts_with($right, $left)) {
            return true;
        }

        if (strlen($right) === 1 && str_starts_with($left, $right)) {
            return true;
        }

        return in_array($right, self::NICKNAME_GROUPS[$left] ?? [], true)
            || in_array($left, self::NICKNAME_GROUPS[$right] ?? [], true);
    }
}








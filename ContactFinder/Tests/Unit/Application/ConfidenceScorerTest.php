<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Tests\Unit\Application;

use GrailSignal\ContactFinder\Application\ConfidenceScorer;
use GrailSignal\ContactFinder\Application\ContactMerger;
use GrailSignal\ContactFinder\Domain\ContactEvidence;
use PHPUnit\Framework\TestCase;

/**
 * Covers explainable confidence scoring before review gates are applied.
 */
final class ConfidenceScorerTest extends TestCase
{
    public function test_score_is_high_for_correlated_multi_source_evidence(): void
    {
        $score = $this->scorer()->score($this->merger()->merge([
            new ContactEvidence(
                provider: 'business_registry',
                sourceUrl: 'mock://business-registry/gsf-001',
                contactName: 'Contact Demo 001',
                contactRole: 'Owner',
            ),
            new ContactEvidence(
                provider: 'business_directory',
                sourceUrl: 'mock://business-directory/gsf-001',
                contactName: 'Contact Demo 001',
                phone: '+33 1 99 00 01 01',
            ),
            new ContactEvidence(
                provider: 'contact_signal',
                sourceUrl: 'mock://contact-signal/gsf-001',
                email: 'contact.demo001@gsf-001.example',
                phone: '+33 1 99 00 01 01',
                providerConfidence: 88,
            ),
        ]));

        $this->assertGreaterThanOrEqual(70, $score);
    }

    public function test_score_is_low_for_single_weak_contact_signal_guess(): void
    {
        $score = $this->scorer()->score($this->merger()->merge([
            new ContactEvidence(
                provider: 'contact_signal',
                sourceUrl: 'mock://contact-signal/gsf-027',
                email: 'info@gsf-027.example',
                providerConfidence: 44,
            ),
        ]));

        $this->assertLessThan(70, $score);
    }

    public function test_score_is_low_for_registered_agent_only_evidence(): void
    {
        $score = $this->scorer()->score($this->merger()->merge([
            new ContactEvidence(
                provider: 'business_registry',
                sourceUrl: 'mock://business-registry/gsf-006',
                contactName: 'Contact Demo 006',
                contactRole: 'Registered Agent',
            ),
        ]));

        $this->assertSame(0, $score);
    }

    public function test_score_is_zero_when_contact_cannot_be_verified(): void
    {
        $score = $this->scorer()->score($this->merger()->merge([]));

        $this->assertSame(0, $score);
    }

    public function test_score_penalizes_conflicting_people(): void
    {
        $score = $this->scorer()->score($this->merger()->merge([
            new ContactEvidence(
                provider: 'business_registry',
                sourceUrl: 'mock://business-registry/gsf-013',
                contactName: 'Contact Demo 013A',
                contactRole: 'Manager',
            ),
            new ContactEvidence(
                provider: 'business_directory',
                sourceUrl: 'mock://business-directory/gsf-013',
                contactName: 'Contact Demo 013B',
                phone: '+33 4 65 71 13 13',
            ),
        ]));

        $this->assertLessThan(70, $score);
    }

    public function test_score_rewards_target_contact_roles_in_priority_order(): void
    {
        $apScore = $this->scoreForRole('Accounts Payable Manager');
        $apAbbreviationScore = $this->scoreForRole('AP Lead');
        $ownerScore = $this->scoreForRole('Owner');
        $financeScore = $this->scoreForRole('Finance Director');
        $officeScore = $this->scoreForRole('Office Manager');

        $this->assertGreaterThan($ownerScore, $apScore);
        $this->assertSame($apScore, $apAbbreviationScore);
        $this->assertGreaterThan($financeScore, $ownerScore);
        $this->assertGreaterThan($officeScore, $financeScore);
    }

    public function test_score_does_not_treat_embedded_ap_letters_as_accounts_payable(): void
    {
        $this->assertLessThan(
            $this->scoreForRole('AP Lead'),
            $this->scoreForRole('Map Coordinator'),
        );
    }

    public function test_score_caps_phone_only_without_phone_agreement_below_threshold(): void
    {
        $score = $this->scorer()->score($this->merger()->merge([
            new ContactEvidence(
                provider: 'business_registry',
                sourceUrl: 'mock://business-registry/gsf-008',
                contactName: 'Contact Demo 008',
                contactRole: 'Owner',
            ),
            new ContactEvidence(
                provider: 'business_directory',
                sourceUrl: 'mock://business-directory/gsf-008',
                contactName: 'C. Demo 008',
                phone: '+33 2 61 91 08 08',
            ),
        ]));

        $this->assertSame(69, $score);
    }

    public function test_score_allows_strong_single_source_payment_inbox(): void
    {
        $score = $this->scorer()->score($this->merger()->merge([
            new ContactEvidence(
                provider: 'contact_signal',
                sourceUrl: 'mock://contact-signal/gsf-015',
                email: 'ap@gsf-015.example',
                phone: '+33 2 61 91 15 15',
                providerConfidence: 85,
            ),
        ]));

        $this->assertGreaterThanOrEqual(70, $score);
    }

    public function test_score_keeps_weak_single_source_payment_inbox_below_threshold(): void
    {
        $score = $this->scorer()->score($this->merger()->merge([
            new ContactEvidence(
                provider: 'contact_signal',
                sourceUrl: 'mock://contact-signal/gsf-015',
                email: 'ap@gsf-015.example',
                phone: '+33 2 61 91 15 15',
                providerConfidence: 63,
            ),
        ]));

        $this->assertLessThan(70, $score);
    }

    public function test_score_penalizes_phone_conflicts(): void
    {
        $score = $this->scorer()->score($this->merger()->merge([
            new ContactEvidence(
                provider: 'business_registry',
                sourceUrl: 'mock://business-registry/example',
                contactName: 'Contact Demo A',
                contactRole: 'Owner',
            ),
            new ContactEvidence(
                provider: 'business_directory',
                sourceUrl: 'mock://business-directory/example',
                phone: '+33 4 65 71 13 13',
            ),
            new ContactEvidence(
                provider: 'contact_signal',
                sourceUrl: 'mock://contact-signal/example',
                email: 'contact@example.com',
                phone: '+33 4 65 71 99 99',
                providerConfidence: 80,
            ),
        ]));

        $this->assertLessThan(70, $score);
    }

    private function scorer(): ConfidenceScorer
    {
        return new ConfidenceScorer();
    }

    private function merger(): ContactMerger
    {
        return new ContactMerger();
    }

    private function scoreForRole(string $role): int
    {
        return $this->scorer()->score($this->merger()->merge([
            new ContactEvidence(
                provider: 'business_registry',
                sourceUrl: 'mock://business-registry/example',
                contactName: 'Contact Demo Role',
                contactRole: $role,
            ),
            new ContactEvidence(
                provider: 'business_directory',
                sourceUrl: 'mock://business-directory/example',
                contactName: 'Contact Demo Role',
            ),
            new ContactEvidence(
                provider: 'contact_signal',
                sourceUrl: 'mock://contact-signal/example',
                contactName: 'Contact Demo Role',
                email: 'role@example.com',
                providerConfidence: 82,
            ),
        ]));
    }
}
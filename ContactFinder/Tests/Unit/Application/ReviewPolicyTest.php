<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Tests\Unit\Application;

use GrailSignal\ContactFinder\Application\ContactMerger;
use GrailSignal\ContactFinder\Application\ReviewPolicy;
use GrailSignal\ContactFinder\Domain\CompanyInput;
use GrailSignal\ContactFinder\Domain\ContactEvidence;
use GrailSignal\ContactFinder\Domain\MergedContactEvidence;
use GrailSignal\ContactFinder\Domain\ReviewState;
use PHPUnit\Framework\TestCase;

/**
 * Covers the configured review threshold and public-result gating rules from CLARIFICATIONS.md.
 */
final class ReviewPolicyTest extends TestCase
{
    private const THRESHOLD = 70;

    public function test_policy_returns_usable_contact_when_score_meets_threshold(): void
    {
        $result = $this->policy()->apply(
            $this->company(),
            $this->merger()->merge([
                new ContactEvidence(
                    provider: 'business_registry',
                    sourceUrl: 'mock://business-registry/bayview-auto-repair',
                    contactName: 'Karen Liu',
                    contactRole: 'Owner',
                ),
                new ContactEvidence(
                    provider: 'contact_signal',
                    sourceUrl: 'mock://contact-signal/bayview-auto-repair',
                    contactName: 'Karen Liu',
                    contactRole: 'Owner',
                    email: 'karen@bayviewauto.com',
                    providerConfidence: 78,
                ),
            ]),
            75,
        );

        $this->assertSame('karen@bayviewauto.com', $result->contactEmailOrPhone);
        $this->assertFalse($result->needsHumanReview);
        $this->assertSame(ReviewState::Usable, $result->reviewState);
        $this->assertStringContainsString('decision=usable', $result->source);
    }

    public function test_policy_clears_contact_channel_when_score_is_below_threshold(): void
    {
        $result = $this->policy()->apply(
            $this->company(),
            $this->merger()->merge([
                new ContactEvidence(
                    provider: 'contact_signal',
                    sourceUrl: 'mock://contact-signal/riverside-print-sign',
                    email: 'info@riversideprint.biz',
                    providerConfidence: 41,
                ),
            ]),
            35,
        );

        $this->assertSame('', $result->contactEmailOrPhone);
        $this->assertTrue($result->needsHumanReview);
        $this->assertSame(ReviewState::ReviewRequired, $result->reviewState);
        $this->assertStringContainsString('decision=review_required', $result->source);
    }

    public function test_policy_marks_conflicts_for_review_even_with_high_score(): void
    {
        $result = $this->policy()->apply(
            $this->company(),
            $this->merger()->merge([
                new ContactEvidence(
                    provider: 'business_registry',
                    sourceUrl: 'mock://business-registry/fl/coastal-breeze-pool',
                    contactName: 'Tina Alvarez',
                    contactRole: 'Manager',
                ),
                new ContactEvidence(
                    provider: 'business_directory',
                    sourceUrl: 'mock://business-directory/coastal-breeze-pool',
                    contactName: 'Marcus Webb',
                    phone: '+1-941-555-0146',
                ),
            ]),
            80,
        );

        $this->assertSame('', $result->contactEmailOrPhone);
        $this->assertTrue($result->needsHumanReview);
        $this->assertSame(ReviewState::Conflict, $result->reviewState);
        $this->assertStringContainsString('decision=conflict', $result->source);
    }

    public function test_policy_marks_phone_conflicts_for_review_even_with_high_score(): void
    {
        $result = $this->policy()->apply(
            $this->company(),
            $this->merger()->merge([
                new ContactEvidence(
                    provider: 'business_registry',
                    sourceUrl: 'mock://business-registry/example',
                    contactName: 'Karen Liu',
                    contactRole: 'Owner',
                ),
                new ContactEvidence(
                    provider: 'business_directory',
                    sourceUrl: 'mock://business-directory/example',
                    contactName: 'Karen Liu',
                    phone: '+1-253-555-0192',
                ),
                new ContactEvidence(
                    provider: 'contact_signal',
                    sourceUrl: 'mock://contact-signal/example',
                    contactName: 'Karen Liu',
                    email: 'karen@example.com',
                    phone: '+1-253-555-0199',
                    providerConfidence: 90,
                ),
            ]),
            95,
        );

        $this->assertSame('', $result->contactEmailOrPhone);
        $this->assertTrue($result->needsHumanReview);
        $this->assertSame(ReviewState::Conflict, $result->reviewState);
        $this->assertStringContainsString('decision=conflict', $result->source);
    }

    public function test_policy_marks_absent_evidence_as_cannot_verify(): void
    {
        $result = $this->policy()->apply($this->company(), $this->merger()->merge([]), 0);

        $this->assertSame('', $result->contactEmailOrPhone);
        $this->assertTrue($result->needsHumanReview);
        $this->assertSame(ReviewState::CannotVerify, $result->reviewState);
        $this->assertStringContainsString('decision=cannot_verify', $result->source);
    }

    public function test_policy_uses_phone_when_usable_email_is_absent(): void
    {
        $result = $this->policy()->apply(
            $this->company(),
            $this->merger()->merge([
                new ContactEvidence(
                    provider: 'business_registry',
                    sourceUrl: 'mock://business-registry/maple-leaf-bakery',
                    contactName: 'Elena Martin',
                    contactRole: 'Owner',
                ),
                new ContactEvidence(
                    provider: 'business_directory',
                    sourceUrl: 'mock://business-directory/maple-leaf-bakery',
                    contactName: 'Elena Martin',
                    phone: '+1-802-555-0121',
                ),
                new ContactEvidence(
                    provider: 'contact_signal',
                    sourceUrl: 'mock://contact-signal/maple-leaf-bakery',
                    phone: '+1-802-555-0121',
                    providerConfidence: 75,
                ),
            ]),
            72,
        );

        $this->assertSame('+1-802-555-0121', $result->contactEmailOrPhone);
        $this->assertFalse($result->needsHumanReview);
    }

    public function test_policy_keeps_field_level_provenance_in_source(): void
    {
        $merged = $this->merger()->merge([
            new ContactEvidence(
                provider: 'business_registry',
                sourceUrl: 'mock://business-registry/ne/cedar-ridge-plumbing',
                contactName: 'Daniel Ortega',
                contactRole: 'Owner',
            ),
            new ContactEvidence(
                provider: 'contact_signal',
                sourceUrl: 'mock://contact-signal/cedar-ridge-plumbing',
                email: 'd.ortega@cedarridgeplumbing.com',
                providerConfidence: 84,
            ),
        ]);

        $result = $this->policy()->apply($this->company(), $merged, 80);

        $this->assertStringContainsString('contact_name=mock://business-registry/ne/cedar-ridge-plumbing', $result->source);
        $this->assertStringContainsString('email=mock://contact-signal/cedar-ridge-plumbing', $result->source);
    }

    private function policy(): ReviewPolicy
    {
        return new ReviewPolicy(self::THRESHOLD);
    }

    private function merger(): ContactMerger
    {
        return new ContactMerger();
    }

    private function company(): CompanyInput
    {
        return new CompanyInput('Bayview Auto Repair', '129 Harbor St, Tacoma, WA 98402');
    }
}

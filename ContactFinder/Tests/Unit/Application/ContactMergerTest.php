<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Tests\Unit\Application;

use GrailSignal\ContactFinder\Application\ContactMerger;
use GrailSignal\ContactFinder\Domain\ContactEvidence;
use GrailSignal\ContactFinder\Domain\MergedContactEvidence;
use PHPUnit\Framework\TestCase;

/**
 * Covers deterministic provider-evidence merging before confidence scoring is introduced.
 */
final class ContactMergerTest extends TestCase
{
    public function test_merge_preserves_provenance_and_detects_agreement(): void
    {
        $merged = $this->merger()->merge([
            new ContactEvidence(
                provider: 'business_registry',
                sourceUrl: 'mock://business-registry/ne/cedar-ridge-plumbing',
                contactName: 'Daniel Ortega',
                contactRole: 'Owner',
            ),
            new ContactEvidence(
                provider: 'business_directory',
                sourceUrl: 'mock://business-directory/cedar-ridge-plumbing',
                contactName: 'Daniel Ortega',
                phone: '+1-402-555-0148',
            ),
            new ContactEvidence(
                provider: 'contact_signal',
                sourceUrl: 'mock://contact-signal/cedar-ridge-plumbing',
                email: 'd.ortega@cedarridgeplumbing.com',
                providerConfidence: 84,
            ),
        ]);

        $this->assertSame('Daniel Ortega', $merged->contactName);
        $this->assertSame('Owner', $merged->contactRole);
        $this->assertSame('d.ortega@cedarridgeplumbing.com', $merged->email);
        $this->assertSame('+1-402-555-0148', $merged->phone);
        $this->assertTrue($merged->hasSignal(MergedContactEvidence::SIGNAL_NAME_AGREEMENT));
        $this->assertSame(
            'contact_name=mock://business-registry/ne/cedar-ridge-plumbing; contact_role=mock://business-registry/ne/cedar-ridge-plumbing; '.
            'email=mock://contact-signal/cedar-ridge-plumbing; phone=mock://business-directory/cedar-ridge-plumbing',
            $merged->provenanceSummary(),
        );
    }

    public function test_merge_detects_compatible_initial_and_last_name(): void
    {
        $merged = $this->merger()->merge([
            new ContactEvidence(
                provider: 'business_registry',
                sourceUrl: 'mock://business-registry/ma/harbor-light-electric',
                contactName: 'Sean Murphy',
                contactRole: 'Owner',
            ),
            new ContactEvidence(
                provider: 'business_directory',
                sourceUrl: 'mock://business-directory/harbor-light-electric',
                contactName: 'S. Murphy',
                phone: '+1-508-555-0160',
            ),
        ]);

        $this->assertTrue($merged->hasSignal(MergedContactEvidence::SIGNAL_NAME_AGREEMENT));
        $this->assertFalse($merged->hasSignal(MergedContactEvidence::SIGNAL_CONFLICT));
    }

    public function test_merge_detects_compatible_nickname_and_last_name(): void
    {
        $merged = $this->merger()->merge([
            new ContactEvidence(
                provider: 'business_registry',
                sourceUrl: 'mock://business-registry/pa/ironclad-welding',
                contactName: 'Robert Kowalski',
                contactRole: 'Owner',
            ),
            new ContactEvidence(
                provider: 'business_directory',
                sourceUrl: 'mock://business-directory/ironclad-welding',
                contactName: 'Bob Kowalski',
                phone: '+1-412-555-0184',
            ),
        ]);

        $this->assertTrue($merged->hasSignal(MergedContactEvidence::SIGNAL_NAME_AGREEMENT));
        $this->assertFalse($merged->hasSignal(MergedContactEvidence::SIGNAL_CONFLICT));
    }

    public function test_merge_detects_conflicting_names(): void
    {
        $merged = $this->merger()->merge([
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
        ]);

        $this->assertTrue($merged->hasSignal(MergedContactEvidence::SIGNAL_CONFLICT));
        $this->assertFalse($merged->hasSignal(MergedContactEvidence::SIGNAL_NAME_AGREEMENT));
    }

    public function test_merge_detects_registered_agent_only_evidence(): void
    {
        $merged = $this->merger()->merge([
            new ContactEvidence(
                provider: 'business_registry',
                sourceUrl: 'mock://business-registry/oh/northgate-hvac',
                contactName: 'Thomas Reed',
                contactRole: 'Registered Agent',
            ),
        ]);

        $this->assertSame('Thomas Reed', $merged->contactName);
        $this->assertTrue($merged->hasSignal(MergedContactEvidence::SIGNAL_REGISTERED_AGENT_ONLY));
        $this->assertTrue($merged->hasSignal(MergedContactEvidence::SIGNAL_WEAK_EVIDENCE));
        $this->assertTrue($merged->hasSignal(MergedContactEvidence::SIGNAL_SINGLE_SOURCE));
    }

    public function test_merge_detects_cannot_verify_when_no_evidence_exists(): void
    {
        $merged = $this->merger()->merge([]);

        $this->assertFalse($merged->hasEvidence());
        $this->assertTrue($merged->hasSignal(MergedContactEvidence::SIGNAL_CANNOT_VERIFY));
        $this->assertSame('sources=none', $merged->provenanceSummary());
    }

    public function test_merge_detects_repeated_phone_agreement(): void
    {
        $merged = $this->merger()->merge([
            new ContactEvidence(
                provider: 'business_directory',
                sourceUrl: 'mock://business-directory/sunbelt-roofing',
                phone: '+1-480-555-0133',
            ),
            new ContactEvidence(
                provider: 'contact_signal',
                sourceUrl: 'mock://contact-signal/sunbelt-roofing',
                email: 'office@sunbeltroofingaz.com',
                phone: '+1-480-555-0133',
                providerConfidence: 66,
            ),
        ]);

        $this->assertSame('+1-480-555-0133', $merged->phone);
        $this->assertTrue($merged->hasSignal(MergedContactEvidence::SIGNAL_PHONE_AGREEMENT));
        $this->assertFalse($merged->hasSignal(MergedContactEvidence::SIGNAL_CONFLICT));
    }

    public function test_merge_detects_phone_agreement_across_common_phone_formats(): void
    {
        $merged = $this->merger()->merge([
            new ContactEvidence(
                provider: 'business_directory',
                sourceUrl: 'mock://business-directory/example',
                phone: '+1 (480) 555-0133',
            ),
            new ContactEvidence(
                provider: 'contact_signal',
                sourceUrl: 'mock://contact-signal/example',
                phone: '+1 480 555 0133',
                providerConfidence: 80,
            ),
        ]);

        $this->assertSame('+1 (480) 555-0133', $merged->phone);
        $this->assertTrue($merged->hasSignal(MergedContactEvidence::SIGNAL_PHONE_AGREEMENT));
        $this->assertFalse($merged->hasSignal(MergedContactEvidence::SIGNAL_PHONE_CONFLICT));
    }

    public function test_merge_treats_us_country_code_prefixes_as_same_phone_for_comparison(): void
    {
        $merged = $this->merger()->merge([
            new ContactEvidence(
                provider: 'business_directory',
                sourceUrl: 'mock://business-directory/example',
                phone: '+1 (480) 555-0133',
            ),
            new ContactEvidence(
                provider: 'contact_signal',
                sourceUrl: 'mock://contact-signal/example',
                phone: '1 480 555 0133',
                providerConfidence: 80,
            ),
        ]);

        $this->assertSame('+1 (480) 555-0133', $merged->phone);
        $this->assertTrue($merged->hasSignal(MergedContactEvidence::SIGNAL_PHONE_AGREEMENT));
        $this->assertFalse($merged->hasSignal(MergedContactEvidence::SIGNAL_PHONE_CONFLICT));
    }

    public function test_merge_detects_phone_conflict(): void
    {
        $merged = $this->merger()->merge([
            new ContactEvidence(
                provider: 'business_directory',
                sourceUrl: 'mock://business-directory/example',
                phone: '+1-480-555-0133',
            ),
            new ContactEvidence(
                provider: 'contact_signal',
                sourceUrl: 'mock://contact-signal/example',
                phone: '+1-480-555-0199',
                providerConfidence: 80,
            ),
        ]);

        $this->assertTrue($merged->hasSignal(MergedContactEvidence::SIGNAL_PHONE_CONFLICT));
        $this->assertFalse($merged->hasSignal(MergedContactEvidence::SIGNAL_PHONE_AGREEMENT));
    }

    public function test_merge_detects_single_weak_contact_signal_guess(): void
    {
        $merged = $this->merger()->merge([
            new ContactEvidence(
                provider: 'contact_signal',
                sourceUrl: 'mock://contact-signal/riverside-print-sign',
                email: 'info@riversideprint.biz',
                providerConfidence: 41,
            ),
        ]);

        $this->assertSame('info@riversideprint.biz', $merged->email);
        $this->assertTrue($merged->hasSignal(MergedContactEvidence::SIGNAL_WEAK_EVIDENCE));
    }

    public function test_merge_does_not_apply_confidence_threshold_as_weak_evidence_rule(): void
    {
        $merged = $this->merger()->merge([
            new ContactEvidence(
                provider: 'contact_signal',
                sourceUrl: 'mock://contact-signal/sunbelt-roofing',
                contactName: 'Office Team',
                email: 'office@sunbeltroofingaz.com',
                providerConfidence: 66,
            ),
            new ContactEvidence(
                provider: 'business_directory',
                sourceUrl: 'mock://business-directory/sunbelt-roofing',
                phone: '+1-480-555-0133',
            ),
        ]);

        $this->assertFalse($merged->hasSignal(MergedContactEvidence::SIGNAL_WEAK_EVIDENCE));
    }

    public function test_merge_marks_single_source_as_weak_without_using_confidence_threshold(): void
    {
        $merged = $this->merger()->merge([
            new ContactEvidence(
                provider: 'business_directory',
                sourceUrl: 'mock://business-directory/magnolia-family-dental',
                contactName: 'Dr. Patel',
                phone: '+1-478-555-0117',
            ),
        ]);

        $this->assertTrue($merged->hasSignal(MergedContactEvidence::SIGNAL_SINGLE_SOURCE));
        $this->assertTrue($merged->hasSignal(MergedContactEvidence::SIGNAL_WEAK_EVIDENCE));
    }

    public function test_merge_prefers_role_from_same_evidence_as_selected_name(): void
    {
        $merged = $this->merger()->merge([
            new ContactEvidence(
                provider: 'business_registry',
                sourceUrl: 'mock://business-registry/example',
                contactName: 'Thomas Reed',
                contactRole: 'Registered Agent',
            ),
            new ContactEvidence(
                provider: 'business_directory',
                sourceUrl: 'mock://business-directory/example',
                contactName: 'Maria Gomez',
                contactRole: 'Office Manager',
                phone: '+1-208-555-0175',
            ),
        ]);

        $this->assertSame('Maria Gomez', $merged->contactName);
        $this->assertSame('Office Manager', $merged->contactRole);
        $this->assertSame('mock://business-directory/example', $merged->sourceUrlsByField['contact_name']);
        $this->assertSame('mock://business-directory/example', $merged->sourceUrlsByField['contact_role']);
    }

    public function test_merge_prefers_accounts_payable_contact_over_owner_when_both_are_available(): void
    {
        $merged = $this->merger()->merge([
            new ContactEvidence(
                provider: 'business_registry',
                sourceUrl: 'mock://business-registry/example',
                contactName: 'Morgan Owner',
                contactRole: 'Owner',
            ),
            new ContactEvidence(
                provider: 'contact_signal',
                sourceUrl: 'mock://contact-signal/example',
                contactName: 'Alex Payable',
                contactRole: 'Accounts Payable Manager',
                email: 'ap@example-business.com',
                providerConfidence: 90,
            ),
        ]);

        $this->assertSame('Alex Payable', $merged->contactName);
        $this->assertSame('Accounts Payable Manager', $merged->contactRole);
        $this->assertSame('ap@example-business.com', $merged->email);
        $this->assertSame('mock://contact-signal/example', $merged->sourceUrlsByField['contact_name']);
        $this->assertSame('mock://contact-signal/example', $merged->sourceUrlsByField['contact_role']);
    }

    public function test_merge_ignores_personal_email_domains_for_business_contact_output(): void
    {
        $merged = $this->merger()->merge([
            new ContactEvidence(
                provider: 'business_registry',
                sourceUrl: 'mock://business-registry/example',
                contactName: 'Alex Carter',
                contactRole: 'Owner',
            ),
            new ContactEvidence(
                provider: 'contact_signal',
                sourceUrl: 'mock://contact-signal/example',
                email: 'alex.carter@gmail.com',
                phone: '+1-480-555-0133',
                providerConfidence: 90,
            ),
        ]);

        $this->assertNull($merged->email);
        $this->assertSame('+1-480-555-0133', $merged->phone);
        $this->assertArrayNotHasKey('email', $merged->sourceUrlsByField);
    }

    private function merger(): ContactMerger
    {
        return new ContactMerger();
    }

}

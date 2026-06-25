<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Tests\Unit\Domain;

use GrailSignal\ContactFinder\Domain\CompanyInput;
use GrailSignal\ContactFinder\Domain\ContactEvidence;
use GrailSignal\ContactFinder\Domain\ContactResult;
use GrailSignal\ContactFinder\Domain\ReviewState;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DomainObjectsTest extends TestCase
{
    public function test_company_input_trims_values(): void
    {
        $input = new CompanyInput('  Grail Signal Demo 001 SARL  ', '  4821 Maple Ave, Lincoln, NE 68504  ');

        $this->assertSame('Grail Signal Demo 001 SARL', $input->companyName);
        $this->assertSame('4821 Maple Ave, Lincoln, NE 68504', $input->mailingAddress);
    }

    public function test_company_input_rejects_missing_required_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CompanyInput('', '4821 Maple Ave, Lincoln, NE 68504');
    }

    public function test_company_input_rejects_missing_mailing_address(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mailing address is required.');

        new CompanyInput('Grail Signal Demo 001 SARL', '');
    }

    public function test_company_input_accepts_values_at_maximum_lengths(): void
    {
        $input = new CompanyInput(str_repeat('A', 255), str_repeat('B', 500));

        $this->assertSame(str_repeat('A', 255), $input->companyName);
        $this->assertSame(str_repeat('B', 500), $input->mailingAddress);
    }

    public function test_company_input_counts_unicode_characters_for_length_limits(): void
    {
        $input = new CompanyInput(str_repeat('É', 255), str_repeat('é', 500));

        $this->assertSame(str_repeat('É', 255), $input->companyName);
        $this->assertSame(str_repeat('é', 500), $input->mailingAddress);
    }

    public function test_company_input_rejects_oversized_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Company name must not exceed 255 characters.');

        new CompanyInput(str_repeat('A', 256), '4821 Maple Ave, Lincoln, NE 68504');
    }

    public function test_company_input_rejects_oversized_mailing_address(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mailing address must not exceed 500 characters.');

        new CompanyInput('Grail Signal Demo 001 SARL', str_repeat('A', 501));
    }

    public function test_contact_evidence_keeps_provider_signal_and_provenance(): void
    {
        $evidence = new ContactEvidence(
            provider: 'contact_signal',
            sourceUrl: 'mock://contact-signal/cedar-ridge-plumbing',
            contactName: ' Daniel Ortega ',
            contactRole: ' Owner ',
            email: ' d.ortega@cedarridgeplumbing.com ',
            providerConfidence: 84,
        );

        $this->assertSame('contact_signal', $evidence->provider);
        $this->assertSame('mock://contact-signal/cedar-ridge-plumbing', $evidence->sourceUrl);
        $this->assertSame('Daniel Ortega', $evidence->contactName);
        $this->assertSame('Owner', $evidence->contactRole);
        $this->assertSame('d.ortega@cedarridgeplumbing.com', $evidence->email);
        $this->assertTrue($evidence->hasAnyContactSignal());
    }

    public function test_contact_evidence_rejects_invalid_provider_confidence(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ContactEvidence(
            provider: 'contact_signal',
            sourceUrl: 'mock://contact-signal/cedar-ridge-plumbing',
            providerConfidence: 101,
        );
    }

    public function test_contact_result_exposes_required_output_shape(): void
    {
        $result = new ContactResult(
            companyName: 'Grail Signal Demo 001 SARL',
            contactName: 'Daniel Ortega',
            contactRole: 'Owner',
            contactEmailOrPhone: 'd.ortega@cedarridgeplumbing.com',
            confidenceScore: 91,
            source: 'contact_signal=mock://contact-signal/cedar-ridge-plumbing; decision=usable',
            needsHumanReview: false,
            reviewState: ReviewState::Usable,
        );

        $this->assertSame([
            'company_name' => 'Grail Signal Demo 001 SARL',
            'contact_name' => 'Daniel Ortega',
            'contact_role' => 'Owner',
            'contact_email_or_phone' => 'd.ortega@cedarridgeplumbing.com',
            'confidence_score' => 91,
            'source' => 'contact_signal=mock://contact-signal/cedar-ridge-plumbing; decision=usable',
            'needs_human_review' => false,
        ], $result->toOutputRow());
    }

    public function test_contact_result_rejects_missing_source_provenance(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ContactResult(
            companyName: 'Grail Signal Demo 001 SARL',
            contactName: 'Daniel Ortega',
            contactRole: 'Owner',
            contactEmailOrPhone: 'd.ortega@cedarridgeplumbing.com',
            confidenceScore: 91,
            source: '',
            needsHumanReview: false,
            reviewState: ReviewState::Usable,
        );
    }

    public function test_contact_result_rejects_reviewed_result_with_contact_channel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Reviewed results must not expose a contact channel.');

        new ContactResult(
            companyName: 'Grail Signal Demo 001 SARL',
            contactName: 'Daniel Ortega',
            contactRole: 'Owner',
            contactEmailOrPhone: 'd.ortega@cedarridgeplumbing.com',
            confidenceScore: 50,
            source: 'contact_signal=mock://contact-signal/cedar-ridge-plumbing; decision=review_required',
            needsHumanReview: true,
            reviewState: ReviewState::ReviewRequired,
        );
    }

    public function test_contact_result_rejects_usable_result_without_contact_channel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Usable results must expose a contact channel.');

        new ContactResult(
            companyName: 'Grail Signal Demo 001 SARL',
            contactName: 'Contact Demo 001',
            contactRole: 'Owner',
            contactEmailOrPhone: '',
            confidenceScore: 91,
            source: 'email=mock://contact-signal/gsf-001; decision=usable',
            needsHumanReview: false,
            reviewState: ReviewState::Usable,
        );
    }
    public function test_contact_result_rejects_inconsistent_review_state_and_flag(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Review state and human-review flag are inconsistent.');

        new ContactResult(
            companyName: 'Grail Signal Demo 001 SARL',
            contactName: 'Daniel Ortega',
            contactRole: 'Owner',
            contactEmailOrPhone: 'contact.demo001@gsf-001.example',
            confidenceScore: 50,
            source: 'contact_signal=mock://contact-signal/cedar-ridge-plumbing; decision=review_required',
            needsHumanReview: false,
            reviewState: ReviewState::ReviewRequired,
        );
    }

    public function test_contact_result_rejects_source_decision_that_does_not_match_review_state(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Result source decision must match review state.');

        new ContactResult(
            companyName: 'Grail Signal Demo 001 SARL',
            contactName: 'Daniel Ortega',
            contactRole: 'Owner',
            contactEmailOrPhone: '',
            confidenceScore: 50,
            source: 'contact_signal=mock://contact-signal/cedar-ridge-plumbing; decision=usable',
            needsHumanReview: true,
            reviewState: ReviewState::ReviewRequired,
        );
    }

    public function test_review_state_values_match_decision_language(): void
    {
        $this->assertSame('usable', ReviewState::Usable->value);
        $this->assertSame('review_required', ReviewState::ReviewRequired->value);
        $this->assertSame('conflict', ReviewState::Conflict->value);
        $this->assertSame('cannot_verify', ReviewState::CannotVerify->value);
    }
}

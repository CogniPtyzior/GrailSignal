<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Tests\Unit\Application;

use GrailSignal\ContactFinder\Application\ConfidenceScorer;
use GrailSignal\ContactFinder\Application\ContactMerger;
use GrailSignal\ContactFinder\Application\FindContacts;
use GrailSignal\ContactFinder\Application\ReviewPolicy;
use GrailSignal\ContactFinder\Domain\CompanyInput;
use GrailSignal\ContactFinder\Domain\ContactEvidence;
use GrailSignal\ContactFinder\Domain\ReviewState;
use GrailSignal\ContactFinder\Ports\CompanyInputReader;
use GrailSignal\ContactFinder\Ports\SourceProvider;
use GrailSignal\ContactFinder\Ports\SuppressionList;
use PHPUnit\Framework\TestCase;

/**
 * Covers the application batch use case with in-memory ports, before CLI and filesystem composition.
 */
final class FindContactsTest extends TestCase
{
    public function test_run_returns_one_result_per_input_row_in_original_order(): void
    {
        $useCase = $this->useCase(
            companies: [
                new CompanyInput('Grail Signal Demo 001 SARL', '4821 Maple Ave, Lincoln, NE 68504'),
                new CompanyInput('Coastal Breeze Pool Service', '233 Seagrape Way, Sarasota, FL 34236'),
                new CompanyInput('Redwood Cabinetry', '509 Timber Ct, Eugene, OR 97401'),
            ],
            evidenceByCompanyName: [
                'Grail Signal Demo 001 SARL' => [
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
                ],
                'Coastal Breeze Pool Service' => [
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
                ],
            ],
        );

        $results = $useCase->run();

        $this->assertCount(3, $results);
        $this->assertSame('Grail Signal Demo 001 SARL', $results[0]->companyName);
        $this->assertSame('Coastal Breeze Pool Service', $results[1]->companyName);
        $this->assertSame('Redwood Cabinetry', $results[2]->companyName);
        $this->assertSame(ReviewState::Usable, $results[0]->reviewState);
        $this->assertSame(ReviewState::Conflict, $results[1]->reviewState);
        $this->assertSame(ReviewState::CannotVerify, $results[2]->reviewState);
        $this->assertStringContainsString('contact_name=mock://business-registry/ne/cedar-ridge-plumbing', $results[0]->source);
        $this->assertStringContainsString('email=mock://contact-signal/cedar-ridge-plumbing', $results[0]->source);
    }

    public function test_run_outputs_required_public_fields_and_gates_low_confidence_contacts(): void
    {
        $useCase = $this->useCase(
            companies: [
                new CompanyInput('Sunbelt Roofing Co', '7714 Desert Bloom Rd, Mesa, AZ 85207'),
            ],
            evidenceByCompanyName: [
                'Sunbelt Roofing Co' => [
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
                ],
            ],
        );

        $row = $useCase->run()[0]->toOutputRow();

        $this->assertSame(
            [
                'company_name',
                'contact_name',
                'contact_role',
                'contact_email_or_phone',
                'confidence_score',
                'source',
                'needs_human_review',
            ],
            array_keys($row),
        );
        $this->assertSame('', $row['contact_email_or_phone']);
        $this->assertTrue($row['needs_human_review']);
        $this->assertLessThan(70, $row['confidence_score']);
    }

    public function test_run_suppresses_opted_out_contact_channels(): void
    {
        $useCase = $this->useCase(
            companies: [
                new CompanyInput('Bayview Auto Repair', '129 Harbor St, Tacoma, WA 98402'),
            ],
            evidenceByCompanyName: [
                'Bayview Auto Repair' => [
                    new ContactEvidence(
                        provider: 'business_registry',
                        sourceUrl: 'mock://business-registry/wa/bayview-auto-repair',
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
                ],
            ],
            suppressedChannels: ['karen@bayviewauto.com'],
        );

        $result = $useCase->run()[0];

        $this->assertSame('', $result->contactEmailOrPhone);
        $this->assertTrue($result->needsHumanReview);
        $this->assertSame(ReviewState::ReviewRequired, $result->reviewState);
        $this->assertStringContainsString('decision=review_required', $result->source);
        $this->assertStringNotContainsString('decision=usable', $result->source);
        $this->assertStringContainsString('suppression=opt_out', $result->source);
    }

    public function test_run_applies_channel_suppression_even_when_review_policy_hides_the_channel(): void
    {
        $useCase = $this->useCase(
            companies: [
                new CompanyInput('Grail Signal Demo Review SARL', '14 rue Demo, 75002 Paris'),
            ],
            evidenceByCompanyName: [
                'Grail Signal Demo Review SARL' => [
                    new ContactEvidence(
                        provider: 'business_registry',
                        sourceUrl: 'mock://business-registry/review',
                        contactName: 'Contact Demo Review',
                        contactRole: 'Owner',
                    ),
                    new ContactEvidence(
                        provider: 'contact_signal',
                        sourceUrl: 'mock://contact-signal/review',
                        email: 'review@gsf-review.example',
                        providerConfidence: 52,
                    ),
                ],
            ],
            suppressedChannels: ['review@gsf-review.example'],
        );

        $result = $useCase->run()[0];

        $this->assertNull($result->contactName);
        $this->assertNull($result->contactRole);
        $this->assertSame('', $result->contactEmailOrPhone);
        $this->assertSame(0, $result->confidenceScore);
        $this->assertTrue($result->needsHumanReview);
        $this->assertSame(ReviewState::ReviewRequired, $result->reviewState);
        $this->assertStringContainsString('suppression=opt_out', $result->source);
    }
    /**
     * @param list<CompanyInput> $companies
     * @param array<string, list<ContactEvidence>> $evidenceByCompanyName
     * @param list<string> $suppressedChannels
     */
    private function useCase(array $companies, array $evidenceByCompanyName, array $suppressedChannels = []): FindContacts
    {
        return new FindContacts(
            companyInputReader: new InMemoryCompanyInputReader($companies),
            sourceProvider: new InMemorySourceProvider($evidenceByCompanyName),
            contactMerger: new ContactMerger(),
            confidenceScorer: new ConfidenceScorer(),
            reviewPolicy: new ReviewPolicy(70),
            suppressionList: new InMemorySuppressionList($suppressedChannels),
        );
    }
}

/**
 * @internal Test double for deterministic input rows.
 */
final readonly class InMemoryCompanyInputReader implements CompanyInputReader
{
    public function test_run_applies_channel_suppression_even_when_review_policy_hides_the_channel(): void
    {
        $useCase = $this->useCase(
            companies: [
                new CompanyInput('Grail Signal Demo Review SARL', '14 rue Demo, 75002 Paris'),
            ],
            evidenceByCompanyName: [
                'Grail Signal Demo Review SARL' => [
                    new ContactEvidence(
                        provider: 'business_registry',
                        sourceUrl: 'mock://business-registry/review',
                        contactName: 'Contact Demo Review',
                        contactRole: 'Owner',
                    ),
                    new ContactEvidence(
                        provider: 'contact_signal',
                        sourceUrl: 'mock://contact-signal/review',
                        email: 'review@gsf-review.example',
                        providerConfidence: 52,
                    ),
                ],
            ],
            suppressedChannels: ['review@gsf-review.example'],
        );

        $result = $useCase->run()[0];

        $this->assertNull($result->contactName);
        $this->assertNull($result->contactRole);
        $this->assertSame('', $result->contactEmailOrPhone);
        $this->assertSame(0, $result->confidenceScore);
        $this->assertTrue($result->needsHumanReview);
        $this->assertSame(ReviewState::ReviewRequired, $result->reviewState);
        $this->assertStringContainsString('suppression=opt_out', $result->source);
    }
    /**
     * @param list<CompanyInput> $companies
     */
    public function __construct(private array $companies)
    {
    }

    /**
     * @return iterable<CompanyInput>
     */
    public function stream(): iterable
    {
        yield from $this->companies;
    }

    /**
     * @return list<CompanyInput>
     */
    public function read(): array
    {
        return $this->companies;
    }
}

/**
 * @internal Test double for deterministic mock-source evidence.
 */
final readonly class InMemorySourceProvider implements SourceProvider
{
    /**
     * @param array<string, list<ContactEvidence>> $evidenceByCompanyName
     */
    public function __construct(private array $evidenceByCompanyName)
    {
    }

    /**
     * @return list<ContactEvidence>
     */
    public function findEvidenceFor(CompanyInput $company): array
    {
        return $this->evidenceByCompanyName[$company->companyName] ?? [];
    }
}

/**
 * @internal Test double for deterministic opt-out and suppression checks.
 */
final readonly class InMemorySuppressionList implements SuppressionList
{
    /**
     * @param list<string> $suppressedChannels
     */
    public function __construct(private array $suppressedChannels)
    {
    }

    public function isSuppressed(CompanyInput $company, string $contactEmailOrPhone): bool
    {
        return in_array(strtolower($contactEmailOrPhone), array_map('strtolower', $this->suppressedChannels), true);
    }
}

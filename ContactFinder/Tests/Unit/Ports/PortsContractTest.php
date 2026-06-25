<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Tests\Unit\Ports;

use GrailSignal\ContactFinder\Domain\CompanyInput;
use GrailSignal\ContactFinder\Domain\ContactEvidence;
use GrailSignal\ContactFinder\Domain\ContactResult;
use GrailSignal\ContactFinder\Domain\ReviewState;
use GrailSignal\ContactFinder\Ports\CompanyInputReader;
use GrailSignal\ContactFinder\Ports\ResultWriter;
use GrailSignal\ContactFinder\Ports\SourceProvider;
use GrailSignal\ContactFinder\Ports\SuppressionList;
use PHPUnit\Framework\TestCase;

final class PortsContractTest extends TestCase
{
    public function test_input_reader_port_returns_company_inputs(): void
    {
        $reader = new class implements CompanyInputReader {
            public function stream(): iterable
            {
                yield new CompanyInput('Grail Signal Demo 001 SARL', '12 rue Demo, 75001 Paris');
            }

            public function read(): array
            {
                return iterator_to_array($this->stream(), false);
            }
        };

        $inputs = $reader->read();

        $this->assertCount(1, $inputs);
        $this->assertInstanceOf(CompanyInput::class, $inputs[0]);
    }

    public function test_source_provider_port_returns_contact_evidence(): void
    {
        $provider = new class implements SourceProvider {
            public function findEvidenceFor(CompanyInput $company): array
            {
                return [
                    new ContactEvidence(
                        provider: 'business_registry',
                        sourceUrl: 'mock://business-registry/wa/bayview-auto-repair',
                        contactName: 'Karen Liu',
                        contactRole: 'Owner',
                    ),
                ];
            }
        };

        $evidence = $provider->findEvidenceFor(
            new CompanyInput('Grail Signal Demo 001 SARL', '129 Harbor St, Tacoma, WA 98402'),
        );

        $this->assertCount(1, $evidence);
        $this->assertInstanceOf(ContactEvidence::class, $evidence[0]);
    }

    public function test_result_writer_port_accepts_contact_results(): void
    {
        $writer = new class implements ResultWriter {
            /** @var list<ContactResult> */
            public array $written = [];

            public function write(array $results): void
            {
                $this->written = $results;
            }
        };

        $result = new ContactResult(
            companyName: 'Grail Signal Demo 001 SARL',
            contactName: 'Karen Liu',
            contactRole: 'Owner',
            contactEmailOrPhone: 'karen@bayviewauto.com',
            confidenceScore: 82,
            source: 'registry=mock://business-registry/wa/bayview-auto-repair; decision=usable',
            needsHumanReview: false,
            reviewState: ReviewState::Usable,
        );

        $writer->write([$result]);

        $this->assertSame([$result], $writer->written);
    }

    public function test_suppression_list_port_checks_company_and_contact_channel(): void
    {
        $suppressionList = new class implements SuppressionList {
            public function isSuppressed(CompanyInput $company, string $contactEmailOrPhone): bool
            {
                return $company->companyName === 'Grail Signal Demo 001 SARL'
                    && $contactEmailOrPhone === 'karen@bayviewauto.com';
            }
        };

        $this->assertTrue($suppressionList->isSuppressed(
            new CompanyInput('Grail Signal Demo 001 SARL', '129 Harbor St, Tacoma, WA 98402'),
            'karen@bayviewauto.com',
        ));
        $this->assertFalse($suppressionList->isSuppressed(
            new CompanyInput('Grail Signal Demo 001 SARL', '129 Harbor St, Tacoma, WA 98402'),
            'billing@bayviewauto.com',
        ));
    }
}

<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Tests\Integration\Infrastructure;

use GrailSignal\ContactFinder\Domain\CompanyInput;
use GrailSignal\ContactFinder\Exceptions\InvalidMockSourceException;
use GrailSignal\ContactFinder\Infrastructure\MockSourceProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the mock provider adapter against the Grail Signal fixture contract.
 */
final class MockSourceProviderTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $tempFile) {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }

        parent::tearDown();
    }

    public function test_provider_returns_evidence_for_exact_company_name(): void
    {
        $provider = new MockSourceProvider('Mocks/contact_signals.json');

        $evidence = $provider->findEvidenceFor(
            new CompanyInput('Grail Signal Demo 001 SARL', '12 rue Demo, 75001 Paris'),
        );

        $this->assertCount(3, $evidence);
        $this->assertSame('business_registry', $evidence[0]->provider);
        $this->assertSame('Contact Demo 001', $evidence[0]->contactName);
        $this->assertSame('mock://business-registry/gsf-001', $evidence[0]->sourceUrl);
        $this->assertSame('contact_signal', $evidence[2]->provider);
        $this->assertSame(84, $evidence[2]->providerConfidence);
    }

    public function test_provider_returns_empty_evidence_for_absent_company(): void
    {
        $provider = new MockSourceProvider('Mocks/contact_signals.json');

        $evidence = $provider->findEvidenceFor(
            new CompanyInput('Redwood Cabinetry', '509 Timber Ct, Eugene, OR 97401'),
        );

        $this->assertSame([], $evidence);
    }

    public function test_provider_preserves_null_fields_as_missing_contact_signals(): void
    {
        $provider = new MockSourceProvider($this->writeFixture([
            'Maple Leaf Bakery' => [
                'business_directory' => [
                    'name' => null,
                    'phone' => '+1-802-555-0121',
                    'source_url' => 'mock://business-directory/maple-leaf-bakery',
                ],
            ],
        ]));

        $evidence = $provider->findEvidenceFor(
            new CompanyInput('Maple Leaf Bakery', '612 Baker St, Burlington, VT 05401'),
        );

        $this->assertCount(1, $evidence);
        $this->assertSame('business_directory', $evidence[0]->provider);
        $this->assertNull($evidence[0]->contactName);
        $this->assertSame('+1-802-555-0121', $evidence[0]->phone);
        $this->assertTrue($evidence[0]->hasAnyContactSignal());
    }

    public function test_provider_rejects_invalid_json_fixture(): void
    {
        $path = $this->writeRawFixture('{invalid json');

        $this->expectException(InvalidMockSourceException::class);
        $this->expectExceptionMessage('invalid JSON');

        (new MockSourceProvider($path))->findEvidenceFor(
            new CompanyInput('Grail Signal Demo 001 SARL', '12 rue Demo, 75001 Paris'),
        );
    }

    public function test_provider_rejects_payload_without_source_url(): void
    {
        $provider = new MockSourceProvider($this->writeFixture([
            'Grail Signal Demo 001 SARL' => [
                'business_registry' => [
                    'name' => 'Contact Demo 001',
                    'role' => 'Owner',
                ],
            ],
        ]));

        $this->expectException(InvalidMockSourceException::class);
        $this->expectExceptionMessage('missing source_url');

        $provider->findEvidenceFor(
            new CompanyInput('Grail Signal Demo 001 SARL', '12 rue Demo, 75001 Paris'),
        );
    }

    public function test_provider_rejects_unknown_mock_provider(): void
    {
        $provider = new MockSourceProvider($this->writeFixture([
            'Grail Signal Demo 001 SARL' => [
                'external_scraper' => [
                    'email' => 'contact@gsf-001.example',
                    'source_url' => 'mock://external/gsf-001',
                ],
            ],
        ]));

        $this->expectException(InvalidMockSourceException::class);
        $this->expectExceptionMessage('provider is not allowed');

        $provider->findEvidenceFor(
            new CompanyInput('Grail Signal Demo 001 SARL', '12 rue Demo, 75001 Paris'),
        );
    }
    public function test_provider_rejects_payload_with_out_of_range_provider_confidence(): void
    {
        $provider = new MockSourceProvider($this->writeFixture([
            'Grail Signal Demo 001 SARL' => [
                'contact_signal' => [
                    'email' => 'd.ortega@cedarridgeplumbing.com',
                    'provider_confidence' => 101,
                    'source_url' => 'mock://contact-signal/cedar-ridge-plumbing',
                ],
            ],
        ]));

        $this->expectException(InvalidMockSourceException::class);
        $this->expectExceptionMessage('payload is invalid');

        $provider->findEvidenceFor(
            new CompanyInput('Grail Signal Demo 001 SARL', '12 rue Demo, 75001 Paris'),
        );
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function writeFixture(array $fixture): string
    {
        return $this->writeRawFixture((string) json_encode($fixture, JSON_THROW_ON_ERROR));
    }

    private function writeRawFixture(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'contact-finder-mocks-');
        $this->assertIsString($path);
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}

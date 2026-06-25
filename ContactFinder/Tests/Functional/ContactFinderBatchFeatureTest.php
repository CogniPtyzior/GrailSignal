<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Tests\Functional;

use GrailSignal\ContactFinder\Application\ConfidenceScorer;
use GrailSignal\ContactFinder\Application\ContactMerger;
use GrailSignal\ContactFinder\Application\FindContacts;
use GrailSignal\ContactFinder\Application\ReviewPolicy;
use GrailSignal\ContactFinder\Domain\ReviewState;
use GrailSignal\ContactFinder\Infrastructure\AllowAllSuppressionList;
use GrailSignal\ContactFinder\Infrastructure\CsvCompanyInputReader;
use GrailSignal\ContactFinder\Infrastructure\JsonResultWriter;
use GrailSignal\ContactFinder\Infrastructure\JsonSuppressionList;
use GrailSignal\ContactFinder\Infrastructure\MockSourceProvider;
use GrailSignal\ContactFinder\Ports\SuppressionList;
use JsonException;
use PHPUnit\Framework\TestCase;

/**
 * Covers the batch behavior through real file adapters using isolated temporary paths.
 */
final class ContactFinderBatchFeatureTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    /** @var list<string> */
    private array $tempDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $tempFile) {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }

        foreach (array_reverse($this->tempDirectories) as $tempDirectory) {
            if (is_dir($tempDirectory)) {
                foreach (glob($tempDirectory.'/*') ?: [] as $tempFile) {
                    if (is_file($tempFile)) {
                        unlink($tempFile);
                    }
                }

                rmdir($tempDirectory);
            }
        }

        parent::tearDown();
    }

    public function test_batch_outputs_shape_gating_conflicts_absent_mocks_and_provenance(): void
    {
        $csvPath = $this->writeTempFile(
            "company_name,mailing_address\r\n".
            '"Grail Signal Demo 001 SARL","4821 Maple Ave, Lincoln, NE 68504"'."\r\n".
            '"Sunbelt Roofing Co","7714 Desert Bloom Rd, Mesa, AZ 85207"'."\r\n".
            '"Coastal Breeze Pool Service","233 Seagrape Way, Sarasota, FL 34236"'."\r\n".
            '"Redwood Cabinetry","509 Timber Ct, Eugene, OR 97401"'."\r\n",
        );
        $mockPath = $this->writeTempFile(json_encode([
            'Grail Signal Demo 001 SARL' => [
                'business_registry' => [
                    'name' => 'Daniel Ortega',
                    'role' => 'Owner',
                    'source_url' => 'mock://business-registry/ne/cedar-ridge-plumbing',
                ],
                'business_directory' => [
                    'name' => 'Daniel Ortega',
                    'phone' => '+1-402-555-0148',
                    'source_url' => 'mock://business-directory/cedar-ridge-plumbing',
                ],
                'contact_signal' => [
                    'email' => 'd.ortega@cedarridgeplumbing.com',
                    'provider_confidence' => 84,
                    'source_url' => 'mock://contact-signal/cedar-ridge-plumbing',
                ],
            ],
            'Sunbelt Roofing Co' => [
                'business_directory' => [
                    'phone' => '+1-480-555-0133',
                    'source_url' => 'mock://business-directory/sunbelt-roofing',
                ],
                'contact_signal' => [
                    'email' => 'office@sunbeltroofingaz.com',
                    'phone' => '+1-480-555-0133',
                    'provider_confidence' => 66,
                    'source_url' => 'mock://contact-signal/sunbelt-roofing',
                ],
            ],
            'Coastal Breeze Pool Service' => [
                'business_registry' => [
                    'name' => 'Tina Alvarez',
                    'role' => 'Manager',
                    'source_url' => 'mock://business-registry/fl/coastal-breeze-pool',
                ],
                'business_directory' => [
                    'name' => 'Marcus Webb',
                    'phone' => '+1-941-555-0146',
                    'source_url' => 'mock://business-directory/coastal-breeze-pool',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $outputPath = $this->tempDirectory().'/results.json';

        $results = $this->useCase($csvPath, $mockPath)->run();

        (new JsonResultWriter($outputPath))->write($results);

        $rows = $this->readJsonRows($outputPath);

        $this->assertCount(4, $rows);
        $this->assertSame([
            'company_name',
            'contact_name',
            'contact_role',
            'contact_email_or_phone',
            'confidence_score',
            'source',
            'needs_human_review',
        ], array_keys($rows[0]));
        $this->assertSame('d.ortega@cedarridgeplumbing.com', $rows[0]['contact_email_or_phone']);
        $this->assertFalse($rows[0]['needs_human_review']);
        $this->assertStringContainsString('email=mock://contact-signal/cedar-ridge-plumbing', $rows[0]['source']);
        $this->assertSame('', $rows[1]['contact_email_or_phone']);
        $this->assertTrue($rows[1]['needs_human_review']);
        $this->assertStringContainsString('decision=review_required', $rows[1]['source']);
        $this->assertSame('', $rows[2]['contact_email_or_phone']);
        $this->assertTrue($rows[2]['needs_human_review']);
        $this->assertStringContainsString('decision='.ReviewState::Conflict->value, $rows[2]['source']);
        $this->assertSame('', $rows[3]['contact_email_or_phone']);
        $this->assertTrue($rows[3]['needs_human_review']);
        $this->assertStringContainsString('sources=none; decision='.ReviewState::CannotVerify->value, $rows[3]['source']);
    }

    public function test_batch_applies_configured_suppression_rules(): void
    {
        $csvPath = $this->writeTempFile(
            "company_name,mailing_address\r\n".
            '"Grail Signal Demo 001 SARL","4821 Maple Ave, Lincoln, NE 68504"'."\r\n",
        );
        $mockPath = $this->writeTempFile(json_encode([
            'Grail Signal Demo 001 SARL' => [
                'business_registry' => [
                    'name' => 'Daniel Ortega',
                    'role' => 'Owner',
                    'source_url' => 'mock://business-registry/ne/cedar-ridge-plumbing',
                ],
                'contact_signal' => [
                    'email' => 'd.ortega@cedarridgeplumbing.com',
                    'provider_confidence' => 84,
                    'source_url' => 'mock://contact-signal/cedar-ridge-plumbing',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $suppressionPath = $this->writeTempFile(json_encode([
            'channels' => ['d.ortega@cedarridgeplumbing.com'],
        ], JSON_THROW_ON_ERROR));

        $results = $this->useCase($csvPath, $mockPath, new JsonSuppressionList($suppressionPath))->run();

        $this->assertSame('', $results[0]->contactEmailOrPhone);
        $this->assertTrue($results[0]->needsHumanReview);
        $this->assertSame(ReviewState::ReviewRequired, $results[0]->reviewState);
        $this->assertStringContainsString('suppression=opt_out', $results[0]->source);
    }

    private function useCase(
        string $csvPath,
        string $mockPath,
        ?SuppressionList $suppressionList = null,
    ): FindContacts {
        return new FindContacts(
            companyInputReader: new CsvCompanyInputReader($csvPath),
            sourceProvider: new MockSourceProvider($mockPath),
            contactMerger: new ContactMerger(),
            confidenceScorer: new ConfidenceScorer(),
            reviewPolicy: new ReviewPolicy(70),
            suppressionList: $suppressionList ?? new AllowAllSuppressionList(),
        );
    }

    private function writeTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'contact-finder-feature-');
        $this->assertIsString($path);
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function tempDirectory(): string
    {
        $path = sys_get_temp_dir().'/contact-finder-feature-'.bin2hex(random_bytes(6));
        mkdir($path, 0775, true);
        $this->tempDirectories[] = $path;

        return $path;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readJsonRows(string $path): array
    {
        try {
            $rows = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->fail($exception->getMessage());
        }

        $this->assertIsArray($rows);

        return $rows;
    }
}

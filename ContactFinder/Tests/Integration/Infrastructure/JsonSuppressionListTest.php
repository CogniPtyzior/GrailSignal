<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Tests\Integration\Infrastructure;

use GrailSignal\ContactFinder\Domain\CompanyInput;
use GrailSignal\ContactFinder\Exceptions\InvalidConfigurationException;
use GrailSignal\ContactFinder\Infrastructure\JsonSuppressionList;
use PHPUnit\Framework\TestCase;

/**
 * Covers configurable opt-out/suppression rules loaded from JSON.
 */
final class JsonSuppressionListTest extends TestCase
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

    public function test_suppression_list_suppresses_companies_and_channels(): void
    {
        $path = $this->writeJson([
            'companies' => ['Bayview Auto Repair'],
            'channels' => ['billing@example.com'],
        ]);
        $suppressionList = new JsonSuppressionList($path);

        $this->assertTrue($suppressionList->isSuppressed(
            new CompanyInput('Bayview Auto Repair', '129 Harbor St, Tacoma, WA 98402'),
            'karen@bayviewauto.com',
        ));
        $this->assertTrue($suppressionList->isSuppressed(
            new CompanyInput('Grail Signal Demo 001 SARL', '4821 Maple Ave, Lincoln, NE 68504'),
            'billing@example.com',
        ));
        $this->assertFalse($suppressionList->isSuppressed(
            new CompanyInput('Grail Signal Demo 001 SARL', '4821 Maple Ave, Lincoln, NE 68504'),
            'd.ortega@cedarridgeplumbing.com',
        ));
    }

    public function test_suppression_list_rejects_invalid_shape(): void
    {
        $path = $this->writeJson(['companies' => 'Bayview Auto Repair']);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Suppression list values must be arrays.');

        (new JsonSuppressionList($path))->isSuppressed(
            new CompanyInput('Bayview Auto Repair', '129 Harbor St, Tacoma, WA 98402'),
            'karen@bayviewauto.com',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeJson(array $payload): string
    {
        $path = tempnam(sys_get_temp_dir(), 'contact-finder-suppression-');
        $this->assertIsString($path);
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->tempFiles[] = $path;

        return $path;
    }
}

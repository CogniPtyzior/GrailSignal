<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Infrastructure;

use GrailSignal\ContactFinder\Domain\CompanyInput;
use GrailSignal\ContactFinder\Domain\ContactEvidence;
use GrailSignal\ContactFinder\Exceptions\InvalidMockSourceException;
use GrailSignal\ContactFinder\Ports\SourceProvider;
use InvalidArgumentException;
use JsonException;

/**
 * Provides contact evidence from mock fixtures using exact lookup first, then safe canonical-name resolution.
 */
final class MockSourceProvider implements SourceProvider
{
    /** @var list<string> */
    private const ALLOWED_PROVIDERS = ['business_registry', 'business_directory', 'contact_signal'];
    /** @var array<string, array<string, array<string, mixed>>>|null */
    private ?array $fixtures = null;

    /** @var array<string, string>|null */
    private ?array $canonicalCompanyNames = null;

    public function __construct(private readonly string $mockSourcePath)
    {
    }

    /**
     * @return list<ContactEvidence>
     */
    public function findEvidenceFor(CompanyInput $company): array
    {
        $companyFixtures = $this->fixtures()[$company->companyName] ?? $this->fixtures()[$this->resolveCompanyName($company->companyName)] ?? null;

        if (!is_array($companyFixtures)) {
            return [];
        }

        $evidence = [];

        foreach ($companyFixtures as $provider => $payload) {
            $provider = (string) $provider;

            if (!in_array($provider, self::ALLOWED_PROVIDERS, true)) {
                throw new InvalidMockSourceException("Mock provider is not allowed for {$company->companyName}: {$provider}.");
            }

            if (!is_array($payload)) {
                throw new InvalidMockSourceException("Mock provider payload must be an object for {$company->companyName}.");
            }

            $sourceUrl = $this->requiredSourceUrl($company->companyName, $provider, $payload);

            try {
                $evidence[] = new ContactEvidence(
                    provider: $provider,
                    sourceUrl: $sourceUrl,
                    contactName: $this->optionalString($payload['name'] ?? null),
                    contactRole: $this->optionalString($payload['role'] ?? null),
                    email: $this->optionalString($payload['email'] ?? null),
                    phone: $this->optionalString($payload['phone'] ?? null),
                    providerConfidence: $this->optionalInt($payload['provider_confidence'] ?? null),
                );
            } catch (InvalidArgumentException $exception) {
                throw new InvalidMockSourceException(
                    "Mock provider payload is invalid for {$company->companyName} / {$provider}.",
                    0,
                    $exception,
                );
            }
        }

        return $evidence;
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function fixtures(): array
    {
        if ($this->fixtures !== null) {
            return $this->fixtures;
        }

        if (!is_readable($this->mockSourcePath)) {
            throw new InvalidMockSourceException("Mock source fixture is not readable: {$this->mockSourcePath}");
        }

        try {
            $decoded = json_decode(
                json: file_get_contents($this->mockSourcePath) ?: '',
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidMockSourceException("Mock source fixture is invalid JSON: {$this->mockSourcePath}", 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new InvalidMockSourceException("Mock source fixture must decode to an object: {$this->mockSourcePath}");
        }

        /** @var array<string, array<string, array<string, mixed>>> $decoded */
        $this->fixtures = $decoded;

        return $this->fixtures;
    }

    private function resolveCompanyName(string $companyName): string
    {
        $canonicalKey = $this->canonicalCompanyKey($companyName);

        return $this->canonicalCompanyNames()[$canonicalKey] ?? $companyName;
    }

    /**
     * @return array<string, string>
     */
    private function canonicalCompanyNames(): array
    {
        if ($this->canonicalCompanyNames !== null) {
            return $this->canonicalCompanyNames;
        }

        $index = [];

        foreach (array_keys($this->fixtures()) as $companyName) {
            $key = $this->canonicalCompanyKey($companyName);

            if (isset($index[$key]) && $index[$key] !== $companyName) {
                throw new InvalidMockSourceException("Mock source fixture has ambiguous company names: {$index[$key]} / {$companyName}");
            }

            $index[$key] = $companyName;
        }

        $this->canonicalCompanyNames = $index;

        return $this->canonicalCompanyNames;
    }

    private function canonicalCompanyKey(string $companyName): string
    {
        $normalized = strtolower($companyName);
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized) ?: $normalized;
        $normalized = preg_replace('/\b(sarl|sas|sasu|eurl|selarl|sa|scop|scic)\b/', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;

        return trim((string) preg_replace('/\s+/', ' ', $normalized));
    }
    private function optionalString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function optionalInt(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /**
     * Provenance is mandatory in the mock contract, because every emitted value must remain traceable.
     *
     * @param array<string, mixed> $payload
     */
    private function requiredSourceUrl(string $companyName, string $provider, array $payload): string
    {
        $sourceUrl = $this->optionalString($payload['source_url'] ?? null);

        if ($sourceUrl === null || trim($sourceUrl) === '') {
            throw new InvalidMockSourceException("Mock provider payload is missing source_url for {$companyName} / {$provider}.");
        }

        return $sourceUrl;
    }
}






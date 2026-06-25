<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Infrastructure;

use GrailSignal\ContactFinder\Domain\CompanyInput;
use GrailSignal\ContactFinder\Exceptions\InvalidConfigurationException;
use GrailSignal\ContactFinder\Ports\SuppressionList;
use JsonException;

/**
 * Loads optional opt-out/suppression rules from a small JSON fixture.
 */
final class JsonSuppressionList implements SuppressionList
{
    /** @var array{companies: list<string>, channels: list<string>}|null */
    private ?array $rules = null;

    public function __construct(private readonly string $path)
    {
    }

    public function isSuppressed(CompanyInput $company, string $contactEmailOrPhone): bool
    {
        $rules = $this->rules();

        return in_array($this->normalize($company->companyName), $rules['companies'], true)
            || in_array($this->normalize($contactEmailOrPhone), $rules['channels'], true);
    }

    /**
     * @return array{companies: list<string>, channels: list<string>}
     */
    private function rules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        if (!is_readable($this->path)) {
            throw new InvalidConfigurationException("Suppression list is not readable: {$this->path}");
        }

        try {
            $decoded = json_decode((string) file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidConfigurationException("Suppression list is invalid JSON: {$this->path}", 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new InvalidConfigurationException("Suppression list must decode to an object: {$this->path}");
        }

        $this->rules = [
            'companies' => $this->normalizedStringList($decoded['companies'] ?? []),
            'channels' => $this->normalizedStringList($decoded['channels'] ?? []),
        ];

        return $this->rules;
    }

    /**
     * @return list<string>
     */
    private function normalizedStringList(mixed $values): array
    {
        if (!is_array($values)) {
            throw new InvalidConfigurationException('Suppression list values must be arrays.');
        }

        $normalized = [];

        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new InvalidConfigurationException('Suppression list entries must be strings.');
            }

            $value = $this->normalize($value);

            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}




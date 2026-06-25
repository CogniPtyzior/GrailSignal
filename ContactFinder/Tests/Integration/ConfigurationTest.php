<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Tests\Integration;

use GrailSignal\ContactFinder\Application\ReviewPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Ensures the batch configuration centralizes the file paths and policy constants used by adapters.
 */
final class ConfigurationTest extends TestCase
{
    public function test_contact_finder_config_exposes_required_defaults(): void
    {
        $config = require 'contact_finder.config.php';

        $this->assertSame('Data/companies.csv', $config['input_csv_path']);
        $this->assertSame('Mocks/contact_signals.json', $config['mock_source_path']);
        $this->assertNull($config['suppression_list_path']);
        $this->assertSame('Storage/ContactFinder/Results', $config['output_directory']);
        $this->assertSame('Storage/ContactFinder/Logs', $config['logs_directory']);
        $this->assertSame(70, $config['confidence_threshold']);
        $this->assertSame('json', $config['output_format']);
    }

    public function test_confidence_threshold_can_build_review_policy(): void
    {
        $config = require 'contact_finder.config.php';

        $this->assertInstanceOf(ReviewPolicy::class, new ReviewPolicy($config['confidence_threshold']));
    }
}

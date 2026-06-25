<?php

declare(strict_types=1);

/**
 * Central configuration for the Grail Signal batch slice.
 *
 * Paths are relative to the repository root by default.
 */
return [
    'input_csv_path' => 'Data/companies.csv',
    'mock_source_path' => 'Mocks/contact_signals.json',
    'suppression_list_path' => null,
    'output_directory' => 'Storage/ContactFinder/Results',
    'logs_directory' => 'Storage/ContactFinder/Logs',
    'checkpoint_directory' => 'Storage/ContactFinder/Checkpoints',
    'checkpoint_every' => 10,
    'confidence_threshold' => 70,
    'output_format' => 'json',
];






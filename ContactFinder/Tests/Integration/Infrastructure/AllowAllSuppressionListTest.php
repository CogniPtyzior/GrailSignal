<?php

declare(strict_types=1);

namespace GrailSignal\ContactFinder\Tests\Integration\Infrastructure;

use GrailSignal\ContactFinder\Domain\CompanyInput;
use GrailSignal\ContactFinder\Infrastructure\AllowAllSuppressionList;
use PHPUnit\Framework\TestCase;

/**
 * Covers the default suppression adapter used when no opt-out fixture is configured.
 */
final class AllowAllSuppressionListTest extends TestCase
{
    public function test_allow_all_suppression_list_allows_contact_channels(): void
    {
        $suppressionList = new AllowAllSuppressionList();

        $this->assertFalse($suppressionList->isSuppressed(
            new CompanyInput('Bayview Auto Repair', '129 Harbor St, Tacoma, WA 98402'),
            'karen@bayviewauto.com',
        ));
    }
}

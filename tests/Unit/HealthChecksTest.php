<?php

declare(strict_types=1);

namespace RentalManager\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RentalManager\Kernel\Health\Checks;
use RentalManager\Kernel\Health\PdfSample;

final class HealthChecksTest extends TestCase
{
    public function testWordPressVersionIsCheckedWhileServerRemainsInformational(): void
    {
        $rows = Checks::information('nginx/1.26', '6.4');
        self::assertSame(['web_server', 'wordpress'], array_column($rows, 'id'));
        self::assertSame(['info', 'fail'], array_column($rows, 'status'));
        self::assertSame(['nginx/1.26', '6.4'], array_column($rows, 'detail'));
        self::assertSame(['info', 'fail'], array_column(Checks::information('', ''), 'status'));
        foreach (['7.0', '7.0.0', '7.1.4'] as $version) {
            self::assertSame('pass', Checks::information('Apache', $version)[1]->status);
        }
        self::assertSame('fail', Checks::information('Apache', '7.0-beta1')[1]->status);
    }

    public function testMemoryThresholdAndUnlimitedLimit(): void
    {
        foreach (['256M', '512m', '1G', '262144K', '268435456', '-1'] as $value) {
            self::assertTrue(Checks::memorySufficient($value), $value);
        }
        foreach (['128M', '255M', '0', '', 'unlimited', '256T', '256M garbage'] as $value) {
            self::assertFalse(Checks::memorySufficient($value), $value);
        }
    }

    public function testDatabaseVersionsIncludingMariaDbCompatibilityPrefix(): void
    {
        foreach (['8.0.0', '8.4.5-ubuntu', '10.6.0-MariaDB', '5.5.5-10.11.9-MariaDB'] as $value) {
            self::assertTrue(Checks::databaseSupported($value), $value);
        }
        foreach (['5.7.44', '10.5.9-MariaDB', '5.5.5-10.5.9-MariaDB', 'MariaDB', 'unknown'] as $value) {
            self::assertFalse(Checks::databaseSupported($value), $value);
        }
    }

    public function testArabicPdfUsesLocalFontsSyntheticDataAndBothDisclaimers(): void
    {
        $arabic = PdfSample::html();
        self::assertStringContainsString('lang="ar" dir="rtl"', $arabic);
        self::assertStringContainsString('إيصال تجريبي', $arabic);
        self::assertStringContainsString('هذا الإيصال ليس فاتورة ضريبية.', $arabic);
        self::assertStringContainsString('plexsansarabic', $arabic);
        self::assertStringNotContainsString('http', $arabic);
        self::assertStringContainsString('This receipt is not a tax invoice.', PdfSample::html('en'));
        self::assertStringContainsString('dir="ltr"', PdfSample::html('en'));
    }
}

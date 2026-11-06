<?php

declare(strict_types=1);

namespace RentalManager\Tests\Unit;

use Mpdf\Cache;
use Mpdf\Fonts\FontCache;
use Mpdf\Mpdf;
use Mpdf\Otl;
use PHPUnit\Framework\TestCase;
use RentalManager\Kernel\Health\PdfFonts;

final class PdfFontsTest extends TestCase
{
    public function testRegularAndBoldShapeArabicIntoInitialMedialAndFinalGlyphs(): void
    {
        $temporary = dirname(__DIR__) . '/artifacts/plex-unit';
        $config = PdfFonts::config($temporary);
        $pdf = new Mpdf($config);
        $otl = new Otl($pdf, new FontCache(new Cache($temporary . '/mpdf/ttfontdata')));
        foreach (['', 'B'] as $style) {
            $pdf->SetFont('plexsansarabic', $style);
            $shaped = $otl->applyOTL('ببب', $pdf->CurrentFont['useOTL']);
            self::assertNotSame('ببب', $shaped, 'Arabic must pass through contextual GSUB shaping.');
            self::assertCount(3, array_unique(mb_str_split($shaped)), 'Initial, medial and final forms must differ.');
        }
        self::assertFalse($pdf->autoLangToFont);
        self::assertFalse($pdf->useAdobeCJK);
        self::assertSame(['plexsansarabic'], array_keys($pdf->fontdata));
        self::assertSame(0, $pdf->fontdata['plexsansarabic']['useKashida']);
    }
}

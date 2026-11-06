<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Health;

/** One local Arabic/Latin family for both the CLI spike and the packaged web download. */
final class PdfFonts
{
    /** @return array<string, mixed> */
    public static function config(string $temporary): array
    {
        return [
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $temporary,
            'fontDir' => [dirname(__DIR__, 3) . '/assets/fonts'],
            'fontdata' => [
                'plexsansarabic' => [
                    'R' => 'IBMPlexSansArabic-Regular.ttf',
                    'B' => 'IBMPlexSansArabic-Bold.ttf',
                    'useOTL' => 0xFF,
                    'useKashida' => 0,
                ],
            ],
            'default_font' => 'plexsansarabic',
            // Both Arabic and Latin use this family; never select a script-specific fallback.
            'autoScriptToLang' => false,
            'autoLangToFont' => false,
            'useAdobeCJK' => false,
            'useSubstitutions' => false,
            'backupSubsFont' => [],
            'backupSIPFont' => '',
            'sans_fonts' => ['plexsansarabic', 'sans-serif'],
            'serif_fonts' => ['plexsansarabic', 'serif'],
            'mono_fonts' => ['plexsansarabic', 'monospace'],
        ];
    }
}

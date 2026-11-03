<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Health;

/** Synthetic spike data only; this is not the production receipt renderer. */
final class PdfSample
{
    /** @var array<string, array<string, string>> */
    private const MESSAGES = [
        'ar' => [
            'title' => 'إيصال تجريبي', 'sample' => 'عينة اختبار فقط - لا يمثل دفعة حقيقية',
            'owner' => 'المالك', 'renter' => 'المستأجر', 'unit' => 'الوحدة', 'period' => 'الفترة',
            'amount' => 'المبلغ', 'currency' => 'جنيه مصري', 'rent' => 'إيجار شهر يناير',
            'disclaimer' => 'هذا الإيصال ليس فاتورة ضريبية.',
        ],
        'en' => [
            'title' => 'Sample receipt', 'sample' => 'Test sample only - not a real payment',
            'owner' => 'Owner', 'renter' => 'Renter', 'unit' => 'Unit', 'period' => 'Period',
            'amount' => 'Amount', 'currency' => 'EGP', 'rent' => 'January rent',
            'disclaimer' => 'This receipt is not a tax invoice.',
        ],
    ];

    public static function html(string $locale = 'ar'): string
    {
        $locale = $locale === 'en' ? 'en' : 'ar';
        $t = array_map(
            static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
            self::MESSAGES[$locale]
        );
        $dir = $locale === 'ar' ? 'rtl' : 'ltr';
        return '<html lang="' . $locale . '" dir="' . $dir . '"><head><style>'
            . 'body{font-family:dejavusans;font-size:13pt;color:#17312e}h1{font-size:25pt}'
            . 'table{width:100%;border-collapse:collapse;margin-top:20px}td,th{padding:12px;border:1px solid #ccd5d3}'
            . '.notice{color:#586d68;font-size:11pt}.number{direction:ltr}</style></head><body>'
            . '<h1>' . $t['title'] . '</h1><p class="notice">' . $t['sample'] . '</p>'
            . '<p class="number">SAMPLE-2026-000001 | 2026-01-01</p>'
            . '<p>' . $t['owner'] . ': أحمد محمد<br>' . $t['renter'] . ': فاطمة علي<br>'
            . $t['unit'] . ': شقة ١٢ - القاهرة</p><table><tr><th>' . $t['period'] . '</th><th>'
            . $t['amount'] . '</th></tr><tr><td>' . $t['rent'] . '</td><td>٦٬٠٠٠٫٠٠ '
            . $t['currency'] . '</td></tr></table><p>' . $t['disclaimer'] . '</p></body></html>';
    }
}

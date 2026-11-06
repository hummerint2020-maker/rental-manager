<?php

declare(strict_types=1);

use RentalManager\Kernel\Health\PdfSample;
use RentalManager\Kernel\Health\PdfFonts;

// This operator-only spike never boots WordPress or accesses real renter data.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$artifacts = $root . '/tests/artifacts';
$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 1 && (count($arguments) !== 3 || $arguments[1] !== '--package')) {
    throw new RuntimeException('Usage: php bin/spike-pdf.php [--package /path/to/rental-manager]');
}
if (count($arguments) === 3) {
    $root = $arguments[2];
    if (!is_readable($root . '/vendor-prefixed/autoload.php')) {
        throw new RuntimeException('The package must contain the isolated release loader.');
    }
}
$temporary = $artifacts . '/mpdf';
if (!is_dir($temporary) && !mkdir($temporary, 0700, true)) {
    throw new RuntimeException('Cannot create the PDF spike workspace.');
}
if (is_readable($root . '/vendor-prefixed/autoload.php')) {
    require $root . '/vendor-prefixed/autoload.php';
    $pdf = new \RentalManager\Vendor\Mpdf\Mpdf(PdfFonts::config($temporary));
} else {
    // Native Windows development only; Linux CI must exercise the isolated release loader.
    require $root . '/vendor/autoload.php';
    fwrite(STDERR, "Development autoloader in use; release isolation is checked on Linux CI.\n");
    $pdf = new \Mpdf\Mpdf(PdfFonts::config($temporary));
}
$pdf->SetDirectionality('rtl');
$pdf->WriteHTML(PdfSample::html());
$output = $artifacts . '/receipt-sample.pdf';
$pdf->Output($output, 'F');
if (!is_file($output) || filesize($output) < 1000 || file_get_contents($output, false, null, 0, 5) !== '%PDF-') {
    throw new RuntimeException('The PDF spike did not produce a valid PDF file.');
}
fwrite(STDOUT, "Generated tests/artifacts/receipt-sample.pdf (synthetic Arabic RTL sample).\n");

<?php

declare(strict_types=1);

use RentalManager\Kernel\Health\PdfSample;

// This operator-only spike never boots WordPress or accesses real renter data.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$artifacts = $root . '/tests/artifacts';
$temporary = $artifacts . '/mpdf';
if (!is_dir($temporary) && !mkdir($temporary, 0700, true)) {
    throw new RuntimeException('Cannot create the PDF spike workspace.');
}
$config = [
    'mode' => 'utf-8',
    'format' => 'A4',
    'default_font' => 'dejavusans',
    'tempDir' => $temporary,
    'autoScriptToLang' => true,
    'autoLangToFont' => true,
];
if (is_readable($root . '/vendor-prefixed/autoload.php')) {
    require $root . '/vendor-prefixed/autoload.php';
    $pdf = new \RentalManager\Vendor\Mpdf\Mpdf($config);
} else {
    // Native Windows development only; Linux CI must exercise the isolated release loader.
    require $root . '/vendor/autoload.php';
    fwrite(STDERR, "Development autoloader in use; release isolation is checked on Linux CI.\n");
    $pdf = new \Mpdf\Mpdf($config);
}
$pdf->SetDirectionality('rtl');
$pdf->WriteHTML(PdfSample::html());
$output = $artifacts . '/receipt-sample.pdf';
$pdf->Output($output, 'F');
if (!is_file($output) || filesize($output) < 1000 || file_get_contents($output, false, null, 0, 5) !== '%PDF-') {
    throw new RuntimeException('The PDF spike did not produce a valid PDF file.');
}
fwrite(STDOUT, "Generated tests/artifacts/receipt-sample.pdf (synthetic Arabic RTL sample).\n");

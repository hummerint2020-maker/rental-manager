<?php

declare(strict_types=1);

// Validate the assembled artifact, rather than estimating the source checkout size.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 2) {
    throw new RuntimeException('Usage: php bin/check-package.php /path/to/package');
}
$root = $arguments[1] . '/rental-manager';
if (!is_dir($root . '/migrations')) {
    throw new RuntimeException('Missing runtime migrations directory.');
}
foreach (
    [
        'rental-manager.php', 'build/manifest.json', 'vendor-prefixed/autoload.php',
        'vendor/woocommerce/action-scheduler/action-scheduler.php', 'assets/fonts/OFL.txt',
    ] as $required
) {
    if (!is_file($root . '/' . $required)) {
        throw new RuntimeException('Missing runtime file: ' . $required);
    }
}
if (file_exists($root . '/vendor-prefixed/woocommerce/action-scheduler')) {
    throw new RuntimeException('Action Scheduler must ship unchanged outside Strauss.');
}
$fonts = [];
$bytes = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if ($file->isLink()) {
        throw new RuntimeException('Packages may not contain symbolic links.');
    }
    if (preg_match('~\.(ttf|otf|ttc|woff2?)$~i', $path)) {
        $fonts[] = $path;
    }
    if (preg_match('~/(ttfonts/|data/(font/|collations/|CJKdata\.php|linebrdict|lang2fonts\.css))~', $path)) {
        throw new RuntimeException('Unused mPDF resource in package: ' . $path);
    }
    $bytes += $file->getSize();
}
sort($fonts);
if ($fonts !== ['assets/fonts/IBMPlexSansArabic-Bold.ttf', 'assets/fonts/IBMPlexSansArabic-Regular.ttf']) {
    throw new RuntimeException('The package must contain only IBM Plex Sans Arabic Regular and Bold TTF.');
}
$archive = $arguments[1] . '/rental-manager.zip';
$zipBytes = is_file($archive) ? filesize($archive) : false;
// Decimal MB: enforce the stricter interpretation for both upload and extracted files.
if ($zipBytes === false || $zipBytes >= 8_000_000 || $bytes >= 8_000_000) {
    throw new RuntimeException('Plugin package must be under 8 MB, zipped and extracted.');
}
printf("Package: %d bytes extracted; %d bytes ZIP (both < 8,000,000).\n", $bytes, $zipBytes);

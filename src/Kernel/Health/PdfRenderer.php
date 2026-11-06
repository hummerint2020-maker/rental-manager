<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Health;

use ErrorException;
use Closure;
use FilesystemIterator;
use RentalManager\Vendor\Mpdf\Mpdf;
use RuntimeException;

/** Web spike: the plugin's isolated loader must already be active. */
final class PdfRenderer
{
    /** @param null|Closure(array<string, mixed>): Mpdf $createPdf */
    public function __construct(private readonly ?Closure $createPdf = null)
    {
    }

    public function render(string $uploads): string
    {
        $base = realpath($uploads);
        if ($base === false || !is_dir($base)) {
            throw new RuntimeException('PDF workspace unavailable.');
        }
        $directory = $base . DIRECTORY_SEPARATOR . 'rm-health-pdf-' . bin2hex(random_bytes(16));
        if (!@mkdir($directory, 0700)) {
            throw new RuntimeException('PDF workspace unavailable.');
        }
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            // Consume deprecations only within rendering so they cannot corrupt the PDF stream.
            // Other diagnostics remain strict; the previous handler is restored below.
            if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
                return true;
            }
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $config = PdfFonts::config($directory);
            $pdf = $this->createPdf === null ? new Mpdf($config) : ($this->createPdf)($config);
            $pdf->SetDirectionality('rtl');
            $pdf->WriteHTML(PdfSample::html());
            $body = $pdf->Output('', 'S');
            unset($pdf);
            if (!is_string($body) || !str_starts_with($body, '%PDF-') || strlen($body) < 1000) {
                throw new RuntimeException('PDF generation failed.');
            }
            return $body;
        } finally {
            try {
                if (!self::removeWorkspace($directory)) {
                    throw new RuntimeException('PDF workspace cleanup failed.');
                }
            } finally {
                restore_error_handler();
            }
        }
    }

    /** Only called with the newly created random workspace; never follows symbolic links. */
    private static function removeWorkspace(string $directory): bool
    {
        $clean = true;
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry->isLink() && $entry->isDir()) {
                $clean = self::removeWorkspace($entry->getPathname()) && $clean;
            } else {
                $clean = @unlink($entry->getPathname()) && $clean;
            }
        }
        return @rmdir($directory) && $clean;
    }
}

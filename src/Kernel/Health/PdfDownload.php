<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Health;

use RuntimeException;
use Throwable;

final class PdfDownload
{
    public function __construct(private readonly PdfRenderer $renderer = new PdfRenderer())
    {
    }

    public function send(): void
    {
        $response = $this->response();
        foreach ($response['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $response['body'];
        exit;
    }

    /** @return array{headers: array<string, string>, body: string} */
    public function response(): array
    {
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('You are not allowed to download the sample PDF.', 'rental-manager'),
                '',
                ['response' => 403]
            );
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('PDF downloads require POST.', 'rental-manager'), '', ['response' => 405]);
        }
        check_admin_referer('rm_health_pdf');
        try {
            $uploads = wp_upload_dir(null, false);
            if ($uploads['error'] || !wp_mkdir_p($uploads['basedir'])) {
                throw new RuntimeException('PDF workspace unavailable.');
            }
            $body = $this->renderer->render($uploads['basedir']);
        } catch (Throwable $error) {
            wp_die(
                esc_html__(
                    'The sample PDF could not be generated. Check hosting requirements and try again.',
                    'rental-manager'
                ),
                '',
                ['response' => 500]
            );
        }
        return [
            'headers' => [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="rental-manager-sample.pdf"',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'body' => $body,
        ];
    }
}

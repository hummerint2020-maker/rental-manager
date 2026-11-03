<?php

declare(strict_types=1);

namespace RentalManager\Tests\Integration;

use RentalManager\Kernel\Health\AdminPage;
use RentalManager\Kernel\Health\PdfDownload;
use RentalManager\Tests\Support\DatabaseTestCase;
use RuntimeException;

final class HealthPdfDownloadTest extends DatabaseTestCase
{
    private string $method;

    protected function setUp(): void
    {
        parent::setUp();
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $handler = static function ($message, $title = '', $args = []): void {
            throw new RuntimeException(
                is_scalar($message) ? (string) $message : 'WordPress rejected request.',
                (int) ($args['response'] ?? 0)
            );
        };
        add_filter('wp_die_handler', static fn (): callable => $handler);
    }

    protected function tearDown(): void
    {
        unset($_REQUEST['_wpnonce']);
        $_SERVER['REQUEST_METHOD'] = $this->method;
        parent::tearDown();
    }

    public function testDownloadRequiresSystemAdminEvenWithValidNonce(): void
    {
        $this->user('rm_client');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_REQUEST['_wpnonce'] = wp_create_nonce('rm_health_pdf');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You are not allowed to download');
        (new PdfDownload())->response();
    }

    public function testDownloadRequiresPost(): void
    {
        $this->user('administrator');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_REQUEST['_wpnonce'] = wp_create_nonce('rm_health_pdf');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PDF downloads require POST.');
        (new PdfDownload())->response();
    }

    public function testDownloadRejectsMissingAndUnrelatedNonces(): void
    {
        $this->user('administrator');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        foreach (['', wp_create_nonce('rm_health_checks')] as $nonce) {
            $_REQUEST['_wpnonce'] = $nonce;
            try {
                (new PdfDownload())->response();
                self::fail('The PDF action must require its own valid nonce.');
            } catch (RuntimeException $error) {
                self::assertSame(403, $error->getCode(), 'Nonce denial must happen before rendering can fail.');
            }
        }
    }

    public function testAuthorizedDownloadReturnsPdfAndCleansItsWorkspace(): void
    {
        $this->user('administrator');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_REQUEST['_wpnonce'] = wp_create_nonce('rm_health_pdf');
        self::assertNotFalse(has_action('admin_post_rm_health_pdf'));
        self::assertFalse(has_action('admin_post_nopriv_rm_health_pdf'));
        self::assertTrue(class_exists(\RentalManager\Vendor\Mpdf\Mpdf::class));
        self::assertFalse(class_exists(\Mpdf\Mpdf::class, false), 'The web action must not load unprefixed mPDF.');
        $uploads = wp_upload_dir(null, false);
        $before = glob($uploads['basedir'] . '/rm-health-pdf-*');
        $response = (new PdfDownload())->response();
        self::assertStringStartsWith('%PDF-', $response['body']);
        self::assertGreaterThan(1000, strlen($response['body']));
        self::assertSame([
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="rental-manager-sample.pdf"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $response['headers']);
        self::assertSame($before, glob($uploads['basedir'] . '/rm-health-pdf-*'));
        self::assertFalse(class_exists(\Mpdf\Mpdf::class, false));
    }

    public function testPdfFailureShowsOnlyTheTranslatedPublicError(): void
    {
        $this->user('administrator');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_REQUEST['_wpnonce'] = wp_create_nonce('rm_health_pdf');
        add_filter('upload_dir', static function (array $uploads): array {
            $uploads['error'] = '/private/hosting/path must not be disclosed';
            return $uploads;
        });
        ob_start();
        try {
            (new PdfDownload())->response();
            self::fail('PDF failures must be rejected.');
        } catch (RuntimeException $error) {
            self::assertSame(500, $error->getCode());
            self::assertSame(
                'The sample PDF could not be generated. Check hosting requirements and try again.',
                $error->getMessage()
            );
            self::assertSame('', ob_get_contents(), 'No raw warnings or exception details may be emitted.');
        } finally {
            ob_end_clean();
        }
    }

    public function testDownloadLabelsAndErrorsHaveArabicTranslations(): void
    {
        $catalog = new \MO();
        self::assertTrue($catalog->import_from_file(dirname(__DIR__, 2) . '/languages/rental-manager-ar.mo'));
        self::assertSame('تحميل ملف PDF التجريبي', $catalog->translate('Download sample PDF'));
        $messages = [
            'The sample PDF could not be generated. Check hosting requirements and try again.',
            'Web server software', 'WordPress version', 'Information',
        ];
        foreach ($messages as $message) {
            self::assertNotSame($message, $catalog->translate($message));
        }
    }

    public function testInformationIsEscapedAndPdfFormIsNonceProtectedOnPassiveGet(): void
    {
        $this->user('administrator');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $old = $_SERVER['SERVER_SOFTWARE'] ?? null;
        $_SERVER['SERVER_SOFTWARE'] = '<script>nginx</script>';
        try {
            ob_start();
            (new AdminPage())->render();
            $html = (string) ob_get_clean();
            self::assertStringContainsString('&lt;script&gt;nginx&lt;/script&gt;', $html);
            self::assertStringNotContainsString('<script>nginx</script>', $html);
            self::assertStringContainsString(esc_html($GLOBALS['wp_version']), $html);
            self::assertStringContainsString('Information', $html);
            self::assertStringContainsString('Download sample PDF', $html);
            self::assertStringContainsString('value="rm_health_pdf"', $html);
            self::assertStringContainsString(wp_create_nonce('rm_health_pdf'), $html);
        } finally {
            if ($old === null) {
                unset($_SERVER['SERVER_SOFTWARE']);
            } else {
                $_SERVER['SERVER_SOFTWARE'] = $old;
            }
        }
    }

    private function user(string $role): void
    {
        $factory = new \WP_UnitTest_Factory();
        $user = $factory->user->create(['role' => $role]);
        self::assertIsInt($user);
        wp_set_current_user($user);
    }
}

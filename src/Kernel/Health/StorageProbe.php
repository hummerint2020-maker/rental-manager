<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Health;

use Closure;
use RuntimeException;
use Throwable;

/** Temporary fallback-storage spike; T3.4 will use the real PrivateStorage adapter. */
final class StorageProbe
{
    /** @param Closure(string): array{status: int|string, body: string} $request */
    public function __construct(
        private readonly string $directory,
        private readonly string $url,
        private readonly Closure $request
    ) {
    }

    public function run(): Result
    {
        $files = [];
        $directories = [];
        $passed = false;
        $clean = true;
        try {
            $name = 'rm-health-' . bin2hex(random_bytes(16));
            $root = rtrim($this->directory, '/\\') . '/' . $name;
            $private = $root . '/rm-private';
            foreach ([$root, $private] as $path) {
                if (!@mkdir($path, 0755)) {
                    throw new RuntimeException('Cannot create probe directory.');
                }
                $directories[] = $path;
            }
            $marker = bin2hex(random_bytes(32));
            $contents = [
                $root . '/control.txt' => $marker,
                $private . '/.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
                    . "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
                $private . '/index.php' => '<?php http_response_code(404); exit;',
                $private . '/canary' => $marker,
            ];
            foreach ($contents as $path => $content) {
                $files[] = $path;
                if (@file_put_contents($path, $content) !== strlen($content)) {
                    throw new RuntimeException('Cannot write probe.');
                }
            }
            $base = rtrim($this->url, '/') . '/' . $name;
            $control = ($this->request)($base . '/control.txt');
            $response = ($this->request)($base . '/rm-private/canary');
            $passed = (int) $control['status'] === 200 && $control['body'] === $marker
                && in_array((int) $response['status'], [403, 404], true)
                && !str_contains($response['body'], $marker);
        } catch (Throwable $error) {
            // Transport errors, redirects and blocked public controls never count as a pass.
        } finally {
            foreach (array_reverse($files) as $path) {
                if (file_exists($path)) {
                    $clean = @unlink($path) && $clean;
                }
            }
            foreach (array_reverse($directories) as $path) {
                $clean = @rmdir($path) && $clean;
            }
        }
        return Checks::result('private_storage', $passed && $clean, 'Private storage HTTP canary');
    }
}

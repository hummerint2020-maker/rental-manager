<?php

declare(strict_types=1);

namespace RentalManager\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RuntimeException;

/** Checks dependencies that a class graph cannot represent, without executing source files. */
final class BoundaryInspector
{
    private Parser $parser;

    /** @var array<string, true> */
    private array $wordpressSymbols = [];

    private const MODULES = [
        'Platform', 'Identity', 'Settings', 'Units', 'Renters', 'Leasing', 'Billing',
        'Expenses', 'Maintenance', 'Documents', 'Notifications', 'Activity', 'Reporting',
    ];

    /** @var array<string, string> */
    private const TABLE_OWNERS = [
        'accounts' => 'Platform', 'plans' => 'Platform', 'plan_versions' => 'Platform',
        'plan_entitlements' => 'Platform', 'entitlement_definitions' => 'Platform',
        'subscriptions' => 'Platform', 'account_overrides' => 'Platform', 'account_usage' => 'Platform',
        'account_settings' => 'Settings', 'memberships' => 'Identity', 'invitations' => 'Identity',
        'support_grants' => 'Identity', 'login_identifiers' => 'Identity', 'units' => 'Units',
        'renters' => 'Renters', 'contracts' => 'Leasing', 'contract_amendments' => 'Leasing',
        'charges' => 'Billing', 'payment_submissions' => 'Billing', 'submission_items' => 'Billing',
        'submission_events' => 'Billing', 'transactions' => 'Billing', 'allocations' => 'Billing',
        'receipts' => 'Billing', 'deposit_settlements' => 'Billing', 'deposit_settlement_lines' => 'Billing',
        'deposit_transfers' => 'Billing', 'idempotency_keys' => 'Billing', 'expenses' => 'Expenses',
        'maintenance_requests' => 'Maintenance', 'maintenance_updates' => 'Maintenance',
        'documents' => 'Documents', 'notifications' => 'Notifications', 'activity' => 'Activity',
    ];

    public function __construct(string $wordpressStubs)
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $source = file_get_contents($wordpressStubs);
        if ($source === false) {
            throw new RuntimeException('WordPress symbol inventory is unavailable; run composer install.');
        }
        $nodes = $this->parser->parse($source) ?? [];
        foreach (
            (new NodeFinder())->find($nodes, static fn (Node $n) => $n instanceof Stmt\Function_
            || $n instanceof Stmt\ClassLike) as $declaration
        ) {
            if (
                ($declaration instanceof Stmt\Function_ || $declaration instanceof Stmt\ClassLike)
                && $declaration->name !== null
            ) {
                $this->wordpressSymbols[strtolower($declaration->name->toString())] = true;
            }
        }
    }

    /** @return list<string> */
    public function inspect(string $source, string $path): array
    {
        $path = str_replace('\\', '/', $path);
        if (!str_starts_with($path, 'src/')) {
            return [];
        }
        $module = null;
        $layer = null;
        if (preg_match('#^src/Modules/([^/]+)/([^/]+)#', $path, $parts)) {
            $module = $parts[1];
            $layer = $parts[2];
            if (
                !in_array($module, self::MODULES, true)
                || !in_array(
                    $layer,
                    ['Api', 'Domain', 'Application', 'Infrastructure', 'Presentation', 'module.php'],
                    true
                )
            ) {
                return [$path . ':1: Unrecognized module or layer; declare its architecture before adding code.'];
            }
        } elseif (!str_starts_with($path, 'src/Kernel/')) {
            return [$path . ':1: Production source must belong to Kernel or a declared module.'];
        }

        $infrastructure = $layer === 'Infrastructure'
            || (bool) preg_match(
                '#^src/Kernel/(Container|Db|Migrations|Outbox|Jobs|Sequences|Storage|Audit|Health|Http|Security|'
                    . 'I18n)/#',
                $path
            );
        $pure = in_array($layer, ['Domain', 'Application', 'Api'], true)
            || (bool) preg_match('#^src/Kernel/(Money|Time|Id|Tenancy|Errors)/#', $path);
        $nodes = $this->parser->parse($source) ?? [];
        if ($layer !== 'module.php' && !(new NodeFinder())->findFirstInstanceOf($nodes, Stmt\Namespace_::class)) {
            return [$path . ':1: Production declarations require a namespace matching their source directory.'];
        }
        $traverser = new NodeTraverser(new NameResolver());
        $nodes = (new NodeFinder())->find($traverser->traverse($nodes), static fn (Node $node) => true);
        $errors = [];
        foreach ($nodes as $node) {
            $reason = null;
            if (in_array($layer, ['Domain', 'Application'], true)) {
                $reference = $node instanceof Node\Name ? $node->toString()
                    : ($node instanceof String_ ? $node->value : '');
                if (str_starts_with(strtolower(ltrim($reference, '\\')), 'rentalmanager\\kernel\\container\\')) {
                    $reason = 'Service locator/container dependencies are forbidden in Domain and Application.';
                }
            }
            if ($node instanceof Stmt\Namespace_) {
                $expected = 'RentalManager\\' . str_replace('/', '\\', dirname(substr($path, 4)));
                if ($node->name === null || $node->name->toString() !== $expected) {
                    $reason = 'Namespace must match its source directory: ' . $expected . '.';
                }
            }
            if (!$infrastructure) {
                if (
                    ($node instanceof Expr\Variable && $node->name === 'wpdb')
                    || ($node instanceof Expr\PropertyFetch && $node->name instanceof Node\Identifier
                        && $node->name->toString() === 'wpdb')
                    || ($node instanceof Expr\ArrayDimFetch && $node->var instanceof Expr\Variable
                        && $node->var->name === 'GLOBALS' && $node->dim instanceof String_
                        && $node->dim->value === 'wpdb')
                ) {
                    $reason = '$wpdb is restricted to Infrastructure.';
                }
                if ($node instanceof Node\Name && isset($this->wordpressSymbols[strtolower($node->toString())])) {
                    $reason = 'WordPress symbols are restricted to Infrastructure: ' . $node->toString() . '.';
                }
                if (
                    $node instanceof String_
                    && (str_contains($node->value, '_') || str_starts_with(strtolower($node->value), 'wp'))
                    && isset($this->wordpressSymbols[strtolower(ltrim($node->value, '\\'))])
                ) {
                    $reason = 'WordPress callback symbols are restricted to Infrastructure: ' . $node->value . '.';
                }
            }
            if (
                $pure && $node instanceof Expr\Variable && is_string($node->name)
                && in_array(
                    $node->name,
                    ['GLOBALS', '_GET', '_POST', '_FILES', '_REQUEST', '_COOKIE', '_SESSION', '_SERVER'],
                    true
                )
            ) {
                $reason ??= 'Request and global state must enter through an infrastructure adapter.';
            }
            if ($pure && $node instanceof Node\Name && $this->isNativeEffect($node->toString())) {
                $reason = 'Native I/O is forbidden in pure layers: ' . $node->toString() . '.';
            }
            if ($pure && ($node instanceof Expr\Include_ || $node instanceof Expr\ShellExec)) {
                $reason = 'Native I/O is forbidden in pure layers: includes and shell execution.';
            }
            if ($node instanceof String_) {
                if (
                    $pure && preg_match(
                        '/^\s*(SELECT\b.*\bFROM|INSERT\s+INTO|UPDATE\b.*\bSET|DELETE\s+FROM|CREATE\s+TABLE)\b/s',
                        $node->value
                    )
                ) {
                    $reason = 'SQL belongs to Infrastructure.';
                }
                if ($module !== null && $module !== 'Reporting') {
                    preg_match_all('/\brm_([a-z_]+)\b/', $node->value, $tables);
                    foreach ($tables[1] as $table) {
                        $owner = self::TABLE_OWNERS[$table] ?? $module;
                        if ($owner !== $module) {
                            $reason = 'Cross-module table access is forbidden: rm_' . $table
                                . ' belongs to ' . $owner . '.';
                        }
                    }
                }
                if (
                    $module === 'Reporting'
                    && preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/', $node->value)
                ) {
                    $reason = 'Reporting SQL must be read-only.';
                }
            }
            if (
                $module === 'Reporting' && $node instanceof Expr\MethodCall
                && $node->name instanceof Node\Identifier
                && in_array(strtolower($node->name->toString()), ['insert', 'update', 'delete', 'replace'], true)
            ) {
                $reason = 'Reporting must not call database write methods.';
            }
            if ($reason !== null) {
                $errors[] = $path . ':' . $node->getStartLine() . ': ' . $reason;
            }
        }
        return array_values(array_unique($errors));
    }

    private function isNativeEffect(string $name): bool
    {
        return (bool) preg_match(
            '/^(file(_.*)?|fopen|fclose|fread|fwrite|fputs|fgets|fgetcsv|fputcsv|fpassthru|fseek|ftruncate|fflush|'
            . 'readfile|readlink|realpath|glob|scandir|opendir|readdir|closedir|mkdir|rmdir|unlink|rename|copy|'
            . 'is_file|is_dir|is_readable|is_writable|is_executable|is_link|filesize|filemtime|stat|lstat|'
            . 'chmod|chown|touch|tempnam|tmpfile|move_uploaded_file|is_uploaded_file|mail|header|setcookie|'
            . 'exec|system|passthru|shell_exec|proc_open|popen|curl_.*|mysqli_.*|pg_.*|fsockopen|stream_.*|'
            . 'PDO|PDOStatement|mysqli|SQLite3|SplFile.*|DirectoryIterator|RecursiveDirectoryIterator|'
            . 'FilesystemIterator|RecursiveIteratorIterator|Phar.*)$/i',
            ltrim($name, '\\')
        );
    }
}

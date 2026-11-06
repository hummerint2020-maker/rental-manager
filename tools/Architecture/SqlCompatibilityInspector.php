<?php

declare(strict_types=1);

namespace RentalManager\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\ParserFactory;

/** Static SQL compatibility guard, not a SQL validator or a substitute for the database matrix. */
final class SqlCompatibilityInspector
{
    /** @return list<string> */
    public function inspectPhp(string $source, string $path): array
    {
        $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
        return $this->walk($nodes, $path);
    }

    /** @param array<mixed> $nodes
     *  @return list<string>
     */
    private function walk(array $nodes, string $path): array
    {
        $errors = [];
        foreach ($nodes as $node) {
            if (is_array($node)) {
                array_push($errors, ...$this->walk($node, $path));
            } elseif ($node instanceof Node) {
                if ($this->isString($node)) {
                    foreach ($this->violations($this->stringValue($node)) as $feature) {
                        $errors[] = $path . ':' . $node->getStartLine() . ': MySQL 5.7 forbids ' . $feature . '.';
                    }
                } else {
                    foreach ($node->getSubNodeNames() as $name) {
                        array_push($errors, ...$this->walk([$node->$name], $path));
                    }
                }
            }
        }
        return $errors;
    }

    private function isString(Node $node): bool
    {
        return $node instanceof Scalar\String_ || $node instanceof Scalar\InterpolatedString
            || $node instanceof Expr\BinaryOp\Concat;
    }

    private function stringValue(Node $node): string
    {
        if ($node instanceof Scalar\String_) {
            return $node->value;
        }
        if ($node instanceof Expr\BinaryOp\Concat) {
            return $this->stringValue($node->left) . $this->stringValue($node->right);
        }
        if ($node instanceof Scalar\InterpolatedString) {
            return implode('', array_map(
                fn (Node $part): string => $part instanceof Node\InterpolatedStringPart
                    ? $part->value : $this->stringValue($part),
                $node->parts
            ));
        }
        // Preserve token boundaries around dynamic identifiers/values without executing PHP.
        return ' RM_DYNAMIC ';
    }

    /** @return list<string> */
    public function violations(string $sql): array
    {
        $errors = [];
        $statement = [];
        foreach (array_merge($this->tokens($sql), [';']) as $token) {
            if ($token === ';') {
                array_push($errors, ...$this->checkTokens($statement));
                $statement = [];
            } else {
                $statement[] = $token;
            }
        }
        return array_values(array_unique($errors));
    }

    /** @param list<string> $tokens
     *  @return list<string>
     */
    private function checkTokens(array $tokens): array
    {
        $text = implode(' ', $tokens);
        // Inspect schema fragments too: CREATE TABLE may assemble its column definitions separately.
        $patterns = [
            'CTEs' => '/\bWITH (?:RECURSIVE )?[A-Z_][A-Z0-9_$]*(?: \( [^)]*\))? AS \(/',
            'window functions' => '/\bOVER \(|\bWINDOW [A-Z_][A-Z0-9_$]* AS \(/',
            'SKIP LOCKED' => '/\bSKIP LOCKED\b/',
            'NOWAIT' => '/\bNOWAIT\b/',
            'JSON_TABLE' => '/\bJSON_TABLE \(/',
            'utf8mb4_0900 collations' => '/\bUTF8MB4_0900_[A-Z0-9_]+\b/',
            'CHECK constraints' => '/\bCHECK \(/',
        ];
        if (preg_match('/\b(?:CREATE|ALTER|INDEX|KEY)\b/', $text)) {
            $patterns['invisible indexes'] = '/\bINVISIBLE\b/';
        }
        $errors = [];
        foreach ($patterns as $feature => $pattern) {
            if (preg_match($pattern, $text)) {
                $errors[] = $feature;
            }
        }
        foreach ($tokens as $i => $token) {
            if (in_array($token, ['KEY', 'INDEX'], true)) {
                // DESC is forbidden only inside an index key list; ORDER BY DESC remains legal.
                $start = $i + 1;
                while (isset($tokens[$start]) && !in_array($tokens[$start], ['(', ',', ';'], true)) {
                    ++$start;
                }
                $depth = 0;
                for ($j = $start; isset($tokens[$j]); ++$j) {
                    $depth += ($tokens[$j] === '(' ? 1 : 0) - ($tokens[$j] === ')' ? 1 : 0);
                    if ($depth <= 0) {
                        break;
                    }
                    if ($tokens[$j] === 'DESC') {
                        $errors[] = 'descending indexes';
                    }
                }
            }
        }
        if (in_array($tokens[0] ?? '', ['INSERT', 'REPLACE'], true)) {
            $depth = 0;
            foreach ($tokens as $token) {
                $depth += ($token === '(' ? 1 : 0) - ($token === ')' ? 1 : 0);
                if ($depth === 0 && in_array($token, ['SELECT', 'ON'], true)) {
                    break; // SELECT aliases and ON DUPLICATE KEY UPDATE are supported.
                }
                if ($depth === 0 && $token === 'AS') {
                    $errors[] = 'INSERT row aliases';
                }
            }
        }
        return array_values(array_unique($errors));
    }

    /** @return list<string> */
    private function tokens(string $sql): array
    {
        $pattern = <<<'REGEX'
~'(?:[^'\\]|\\.|'')*'|"(?:[^"\\]|\\.|"")*"|`(?:[^`]|``)*`|/\*.*?\*/|--[\s][^\r\n]*|\#[^\r\n]*|[a-z_][a-z0-9_$]*|[^\s]~is
REGEX;
        preg_match_all($pattern, $sql, $matches);
        $tokens = [];
        foreach ($matches[0] as $token) {
            if (str_starts_with($token, '/*')) {
                if (preg_match('~^/\*(?:!|M!)(?:\d+)?\s*(.*?)\*/$~is', $token, $body)) {
                    array_push($tokens, ...$this->tokens($body[1]));
                }
            } elseif (str_starts_with($token, '--') || str_starts_with($token, '#')) {
                continue;
            } elseif ($token[0] === '`') {
                $identifier = strtoupper(trim($token, '`'));
                $tokens[] = str_starts_with($identifier, 'UTF8MB4_0900_') ? $identifier : 'RM_IDENTIFIER';
            } elseif ($token[0] === "'" || $token[0] === '"') {
                $tokens[] = end($tokens) === 'COLLATE' ? strtoupper(substr($token, 1, -1)) : '?';
            } else {
                $tokens[] = strtoupper($token);
            }
        }
        return $tokens;
    }
}

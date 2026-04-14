<?php

declare(strict_types=1);

namespace CxAI\CxPHP\Config\Parser;

use CxAI\CxPHP\Config\Exception\ParseException;

/**
 * Comprehensive Perl-style configuration file parser.
 *
 * Supports the following Perl configuration idioms typically found in legacy
 * CGI/mod_perl applications that share configuration with PHP front-ends:
 *
 *  1. Simple scalar assignments:
 *     ```perl
 *     $db_host = "localhost";
 *     $db_port = 3306;
 *     my $private_key = "secret";
 *     our $shared_var = "value";
 *     ```
 *
 *  2. Hash (associative array) assignments:
 *     ```perl
 *     %database = (
 *         host => "localhost",
 *         port => 3306,
 *         name => "studio",
 *     );
 *     ```
 *
 *  3. Array assignments:
 *     ```perl
 *     @allowed_ips = ("127.0.0.1", "10.0.0.1");
 *     ```
 *
 *  4. C-style key = value pairs (common in Perl App::Config / Config::Simple):
 *     ```
 *     db_host = localhost
 *     db_port = 3306
 *     ```
 *
 *  5. Heredoc strings (single-quoted non-interpolating and double-quoted):
 *     ```perl
 *     $message = <<'END';
 *     Multi-line
 *     text here
 *     END
 *
 *     $sql = <<"SQL";
 *     SELECT * FROM users
 *     WHERE active = 1
 *     SQL
 *     ```
 *
 *  6. qw() word list operator:
 *     ```perl
 *     @colors = qw(red green blue);
 *     @paths  = qw(/usr/bin /usr/local/bin);
 *     ```
 *
 *  7. Constant definitions:
 *     ```perl
 *     use constant PI => 3.14159;
 *     use constant DEBUG => 1;
 *     use constant {
 *         MAX_SIZE => 1024,
 *         MIN_SIZE => 64,
 *     };
 *     ```
 *
 *  8. Hash/array references:
 *     ```perl
 *     $config = {
 *         host => "localhost",
 *         port => 3306,
 *     };
 *     $list = ["item1", "item2"];
 *     ```
 *
 *  9. Nested data structures:
 *     ```perl
 *     %config = (
 *         database => {
 *             primary => { host => "db1", port => 3306 },
 *             replica => { host => "db2", port => 3306 },
 *         },
 *     );
 *     ```
 *
 * 10. Environment variable references:
 *     ```perl
 *     $home = $ENV{HOME};
 *     $path = $ENV{'PATH'};
 *     ```
 *
 * Comments beginning with `#` are stripped before parsing.
 * POD documentation blocks (=pod ... =cut) are also stripped.
 */
class PerlParser implements ParserInterface
{
    private const SUPPORTED_EXTENSIONS = ['pl', 'pm', 'perl', 'cfg', 'conf'];

    /**
     * Parsed constants from `use constant` declarations.
     *
     * @var array<string, mixed>
     */
    private array $constants = [];

    /** {@inheritdoc} */
    public function supports(string $filePath): bool
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($ext, self::SUPPORTED_EXTENSIONS, true);
    }

    /** {@inheritdoc} */
    public function parse(string $content, string $filePath = ''): array
    {
        $config = [];

        // Reset constants for each parse
        $this->constants = [];

        // Strip POD documentation first
        $content = $this->stripPod($content);

        // Strip comments
        $content = $this->stripComments($content);

        // Extract heredocs before other parsing (they need special handling)
        [$content, $heredocs] = $this->extractHeredocs($content);

        // Parse `use constant` declarations
        $config = array_merge($config, $this->parseConstants($content));

        // Parse %hash = ( key => value, ... );
        $config = array_merge($config, $this->parseHashes($content));

        // Parse @array = ("val1", "val2"); and @array = qw(...)
        $config = array_merge($config, $this->parseArrays($content));

        // Parse $scalar = value; (including heredocs, my/our declarations)
        $config = array_merge($config, $this->parseScalars($content, $heredocs));

        // Parse hash references: $var = { ... };
        $config = array_merge($config, $this->parseHashRefs($content));

        // Parse array references: $var = [ ... ];
        $config = array_merge($config, $this->parseArrayRefs($content));

        // Parse plain key = value lines (Config::Simple / App::Config style)
        $config = array_merge($config, $this->parsePlainKeyValue($content));

        return $config;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Strip POD documentation blocks.
     */
    private function stripPod(string $content): string
    {
        // Remove =pod ... =cut, =head1 ... =cut, etc.
        return preg_replace('/^=\w+.*?^=cut\s*$/ms', '', $content) ?? $content;
    }

    /** Remove Perl/shell-style comments. */
    private function stripComments(string $content): string
    {
        // Remove inline and full-line # comments, but preserve hashes inside strings
        // and inside qw() constructs
        $result = '';
        $inString = false;
        $stringChar = '';
        $i = 0;
        $len = strlen($content);

        while ($i < $len) {
            $char = $content[$i];

            // Handle string boundaries
            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
                $result .= $char;
                $i++;
                continue;
            }

            if ($inString && $char === $stringChar) {
                // Check for escaped quote
                $escapeCount = 0;
                $j = $i - 1;
                while ($j >= 0 && $content[$j] === '\\') {
                    $escapeCount++;
                    $j--;
                }
                if ($escapeCount % 2 === 0) {
                    $inString = false;
                }
                $result .= $char;
                $i++;
                continue;
            }

            // Handle comments outside strings
            if (!$inString && $char === '#') {
                // Skip to end of line
                while ($i < $len && $content[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            $result .= $char;
            $i++;
        }

        return $result;
    }

    /**
     * Extract heredoc strings and replace with placeholders.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function extractHeredocs(string $content): array
    {
        $heredocs = [];
        $counter = 0;

        // Match heredoc: <<'TAG' or <<"TAG" or <<TAG followed by content and closing tag
        // Pattern: $var = <<'TAG'; or $var = <<"TAG"; or $var = <<TAG;
        // Then content lines
        // Then TAG alone on a line (may have semicolon after)
        $pattern = '/=\s*<<([\'"]?)(\w+)\1\s*;?\s*\n(.*?)\n\2\b/s';

        $content = preg_replace_callback($pattern, function ($matches) use (&$heredocs, &$counter) {
            $body = $matches[3];

            $placeholder = "= \"__HEREDOC_{$counter}__\";";
            $heredocs["__HEREDOC_{$counter}__"] = $body;
            $counter++;

            return $placeholder;
        }, $content) ?? $content;

        return [$content, $heredocs];
    }

    /**
     * Parse `use constant` declarations.
     *
     * Supports both single constant and hash-style multiple constants:
     *   use constant NAME => value;
     *   use constant { NAME1 => value1, NAME2 => value2 };
     *
     * @return array<string, mixed>
     */
    private function parseConstants(string $content): array
    {
        $result = [];

        // Multiple constants first (to avoid partial matches): use constant { NAME1 => val1, NAME2 => val2 };
        $multiPattern = '/use\s+constant\s*\{\s*([^}]+?)\s*\}\s*;/s';
        if (preg_match_all($multiPattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $pairs = $this->parseConstantPairList($match[1]);
                foreach ($pairs as $name => $value) {
                    $result[$name] = $value;
                    $this->constants[$name] = $value;
                }
            }
        }

        // Single constant: use constant NAME => value;
        // Make sure we don't match inside the hash block pattern
        $singlePattern = '/use\s+constant\s+(\w+)\s*=>\s*([^;]+?)\s*;/';
        if (preg_match_all($singlePattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = $match[1];
                $rawValue = trim($match[2]);
                // Don't re-process hash block entries
                if (str_starts_with($rawValue, '{')) {
                    continue;
                }
                $value = $this->castValueForConstant($rawValue);
                $result[$name] = $value;
                $this->constants[$name] = $value;
            }
        }

        return $result;
    }

    /**
     * Parse constant pair list with simpler format.
     *
     * @return array<string, mixed>
     */
    private function parseConstantPairList(string $inner): array
    {
        $pairs = [];
        // Split by comma, handling possible newlines
        $tokens = preg_split('/,\s*/', trim($inner), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            return $pairs;
        }

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            // Match NAME => value where value is a simple literal
            if (preg_match('/^(\w+)\s*=>\s*(.+)$/s', $token, $m)) {
                $value = trim($m[2]);
                $pairs[trim($m[1])] = $this->castValueForConstant($value);
            }
        }

        return $pairs;
    }

    /**
     * Cast value specifically for constant definitions (don't convert 1/0 to bool).
     */
    private function castValueForConstant(string $raw): mixed
    {
        $raw = trim($raw, " \t\n\r");

        // Empty string
        if ($raw === '' || $raw === '""' || $raw === "''") {
            return '';
        }

        // Double-quoted string
        if (str_starts_with($raw, '"') && str_ends_with($raw, '"')) {
            return $this->unescapeDoubleQuoted(substr($raw, 1, -1));
        }

        // Single-quoted string
        if (str_starts_with($raw, "'") && str_ends_with($raw, "'")) {
            return $this->unescapeSingleQuoted(substr($raw, 1, -1));
        }

        // Explicit boolean keywords only
        $lower = strtolower($raw);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if ($lower === 'undef') {
            return null;
        }

        // Numeric
        if (is_numeric($raw)) {
            return str_contains($raw, '.') ? (float) $raw : (int) $raw;
        }

        return $raw;
    }

    /**
     * Parse `%hash = ( key => "value", ... );` blocks.
     *
     * @return array<string, array<string, mixed>>
     */
    private function parseHashes(string $content): array
    {
        $result = [];

        // Find all %name = ( patterns and extract balanced content
        $pattern = '/%(\w+)\s*=\s*\(/';
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $name = $match[1][0];
                $startPos = $match[0][1] + strlen($match[0][0]);

                // Extract balanced parentheses content
                $inner = $this->extractBalancedContent($content, $startPos - 1, '(', ')');
                if ($inner !== null) {
                    $result[$name] = $this->parsePairList($inner);
                }
            }
        }

        return $result;
    }

    /**
     * Parse `@array = ("val1", "val2");` and `@array = qw(...)` statements.
     *
     * @return array<string, list<mixed>>
     */
    private function parseArrays(string $content): array
    {
        $result = [];

        // Find all @name = ( patterns and extract balanced content
        $pattern = '/@(\w+)\s*=\s*\(/';
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $name = $match[1][0];
                $startPos = $match[0][1] + strlen($match[0][0]);

                // Extract balanced parentheses content
                $inner = $this->extractBalancedContent($content, $startPos - 1, '(', ')');
                if ($inner !== null) {
                    $result[$name] = $this->parseValueList($inner);
                }
            }
        }

        // qw() operator with proper delimiter matching
        // Match qw with paired delimiters: (), [], {}, <>, //, ||
        $qwPatterns = [
            '/@(\w+)\s*=\s*qw\s*\((.+?)\)\s*;/s',   // qw(...)
            '/@(\w+)\s*=\s*qw\s*\[(.+?)\]\s*;/s',   // qw[...]
            '/@(\w+)\s*=\s*qw\s*\{(.+?)\}\s*;/s',   // qw{...}
            '/@(\w+)\s*=\s*qw\s*<(.+?)>\s*;/s',     // qw<...>
            '/@(\w+)\s*=\s*qw\s*\/(.+?)\/\s*;/s',   // qw/.../
            '/@(\w+)\s*=\s*qw\s*\|(.+?)\|\s*;/s',   // qw|...|
        ];
        foreach ($qwPatterns as $qwPattern) {
            if (preg_match_all($qwPattern, $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $name = $match[1];
                    $inner = $match[2];
                    $result[$name] = $this->parseQwList($inner);
                }
            }
        }

        return $result;
    }

    /**
     * Parse `$scalar = "value";` assignments, including my/our declarations.
     *
     * @param array<string, string> $heredocs Extracted heredoc content
     * @return array<string, mixed>
     */
    private function parseScalars(string $content, array $heredocs = []): array
    {
        $result = [];

        // Match $varname = value; with optional my/our prefix
        // Supports: quoted string, number, bare word, heredoc placeholder, ENV reference
        $pattern = '/(?:(?:my|our|local)\s+)?\$(\w+)\s*=\s*('
            . '(?:"(?:[^"\\\\]|\\\\.)*")'          // double-quoted string
            . '|(?:\'(?:[^\'\\\\]|\\\\.)*\')'      // single-quoted string
            . '|\$ENV\s*\{[\'"]?\w+[\'"]?\}'       // ENV reference
            . '|__HEREDOC_\d+__'                    // heredoc placeholder
            . '|-?\d+(?:\.\d+)?'                    // number (including negative)
            . '|true|false|undef'                   // boolean/undef
            . '|qw\s*[(\[{<\/|].+?[\)\]}>\/|]'     // qw() for scalar context
            . '|\w+'                                // bare word
            . ')\s*;/is';

        if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            return $result;
        }

        foreach ($matches as $match) {
            $name = $match[1];
            $rawValue = trim($match[2]);

            // Check if it's a heredoc placeholder (may be quoted)
            $unquotedValue = trim($rawValue, '"\'');
            if (isset($heredocs[$unquotedValue])) {
                $result[$name] = $heredocs[$unquotedValue];
                continue;
            }

            // Check for ENV reference
            if (preg_match('/^\$ENV\s*\{[\'"]?(\w+)[\'"]?\}$/', $rawValue, $envMatch)) {
                $result[$name] = $this->getEnvValue($envMatch[1]);
                continue;
            }

            // Check for qw() in scalar context with proper delimiter matching
            $qwScalarPatterns = [
                '/^qw\s*\((.+?)\)$/s',   // qw(...)
                '/^qw\s*\[(.+?)\]$/s',   // qw[...]
                '/^qw\s*\{(.+?)\}$/s',   // qw{...}
                '/^qw\s*<(.+?)>$/s',     // qw<...>
                '/^qw\s*\/(.+?)\/$/s',   // qw/.../
                '/^qw\s*\|(.+?)\|$/s',   // qw|...|
            ];
            $qwMatched = false;
            foreach ($qwScalarPatterns as $qwPattern) {
                if (preg_match($qwPattern, $rawValue, $qwMatch)) {
                    $result[$name] = $this->parseQwList($qwMatch[1]);
                    $qwMatched = true;
                    break;
                }
            }
            if ($qwMatched) {
                continue;
            }

            $result[$name] = $this->castValue($rawValue);
        }

        return $result;
    }

    /**
     * Parse hash references: $var = { key => value, ... };
     *
     * @return array<string, array<string, mixed>>
     */
    private function parseHashRefs(string $content): array
    {
        $result = [];

        // Find all $name = { patterns and extract balanced content
        $pattern = '/\$(\w+)\s*=\s*\{/';
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $name = $match[1][0];
                $startPos = $match[0][1] + strlen($match[0][0]);

                // Extract balanced braces content
                $inner = $this->extractBalancedContent($content, $startPos - 1, '{', '}');
                if ($inner !== null) {
                    $result[$name] = $this->parseNestedPairList($inner);
                }
            }
        }

        return $result;
    }

    /**
     * Parse array references: $var = [ val1, val2, ... ];
     *
     * @return array<string, list<mixed>>
     */
    private function parseArrayRefs(string $content): array
    {
        $result = [];

        // Find all $name = [ patterns and extract balanced content
        $pattern = '/\$(\w+)\s*=\s*\[/';
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $name = $match[1][0];
                $startPos = $match[0][1] + strlen($match[0][0]);

                // Extract balanced brackets content
                $inner = $this->extractBalancedContent($content, $startPos - 1, '[', ']');
                if ($inner !== null) {
                    $result[$name] = $this->parseNestedValueList($inner);
                }
            }
        }

        return $result;
    }

    /**
     * Extract content between balanced brackets.
     *
     * @param string $content The full content
     * @param int $startPos Position of opening bracket
     * @param string $open Opening bracket character
     * @param string $close Closing bracket character
     * @return string|null The content inside brackets, or null if unbalanced
     */
    private function extractBalancedContent(string $content, int $startPos, string $open, string $close): ?string
    {
        $len = strlen($content);
        if ($startPos >= $len || $content[$startPos] !== $open) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $stringChar = '';

        for ($i = $startPos; $i < $len; $i++) {
            $char = $content[$i];

            // Handle string boundaries
            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
                continue;
            }

            if ($inString) {
                if ($char === $stringChar) {
                    // Check for escaped quote
                    $escapeCount = 0;
                    $j = $i - 1;
                    while ($j >= 0 && $content[$j] === '\\') {
                        $escapeCount++;
                        $j--;
                    }
                    if ($escapeCount % 2 === 0) {
                        $inString = false;
                    }
                }
                continue;
            }

            // Handle brackets
            if ($char === $open) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;
                if ($depth === 0) {
                    // Found matching close bracket
                    return substr($content, $startPos + 1, $i - $startPos - 1);
                }
            }
        }

        return null;
    }

    /**
     * Parse plain `key = value` lines (Config::Simple / App::Config format).
     *
     * @return array<string, mixed>
     */
    private function parsePlainKeyValue(string $content): array
    {
        $result = [];
        $lines  = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and Perl variable declarations
            if ($line === '' ||
                str_starts_with($line, '$') ||
                str_starts_with($line, '%') ||
                str_starts_with($line, '@') ||
                str_starts_with($line, 'use ') ||
                str_starts_with($line, 'my ') ||
                str_starts_with($line, 'our ') ||
                str_starts_with($line, '{') ||
                str_starts_with($line, '}') ||
                str_contains($line, '=>')  // Skip hash-style key => value pairs
            ) {
                continue;
            }

            if (!preg_match('/^([\w.-]+)\s*=\s*(.+)$/', $line, $m)) {
                continue;
            }
            $result[trim($m[1])] = $this->castValue(trim($m[2]));
        }

        return $result;
    }

    /**
     * Parse a Perl pair list: `key => "value", key2 => 123, ...`
     *
     * @return array<string, mixed>
     */
    private function parsePairList(string $inner): array
    {
        $pairs = [];
        $tokens = $this->splitByComma($inner);

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            if (!preg_match('/^["\']?(\w+)["\']?\s*=>\s*(.+)$/s', $token, $m)) {
                continue;
            }
            $pairs[trim($m[1])] = $this->castValue(trim($m[2]));
        }

        return $pairs;
    }

    /**
     * Parse nested pair list that may contain hash/array references.
     *
     * @return array<string, mixed>
     */
    private function parseNestedPairList(string $inner): array
    {
        $pairs = [];
        $tokens = $this->splitByCommaBalanced($inner);

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            if (!preg_match('/^["\']?(\w+)["\']?\s*=>\s*(.+)$/s', $token, $m)) {
                continue;
            }
            $key = trim($m[1]);
            $rawValue = trim($m[2]);

            // Check for nested hash reference
            if (preg_match('/^\{(.+)\}$/s', $rawValue, $hashMatch)) {
                $pairs[$key] = $this->parseNestedPairList($hashMatch[1]);
                continue;
            }

            // Check for nested array reference
            if (preg_match('/^\[(.+)\]$/s', $rawValue, $arrayMatch)) {
                $pairs[$key] = $this->parseNestedValueList($arrayMatch[1]);
                continue;
            }

            $pairs[$key] = $this->castValue($rawValue);
        }

        return $pairs;
    }

    /**
     * Parse a Perl value list: `"val1", "val2", 42, ...`
     *
     * @return list<mixed>
     */
    private function parseValueList(string $inner): array
    {
        $values = [];
        $tokens = $this->splitByComma($inner);

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            $values[] = $this->castValue($token);
        }

        return $values;
    }

    /**
     * Parse nested value list that may contain hash/array references.
     *
     * @return list<mixed>
     */
    private function parseNestedValueList(string $inner): array
    {
        $values = [];
        $tokens = $this->splitByCommaBalanced($inner);

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            // Check for nested hash reference
            if (preg_match('/^\{(.+)\}$/s', $token, $hashMatch)) {
                $values[] = $this->parseNestedPairList($hashMatch[1]);
                continue;
            }

            // Check for nested array reference
            if (preg_match('/^\[(.+)\]$/s', $token, $arrayMatch)) {
                $values[] = $this->parseNestedValueList($arrayMatch[1]);
                continue;
            }

            $values[] = $this->castValue($token);
        }

        return $values;
    }

    /**
     * Parse qw() word list - splits on whitespace.
     *
     * @return list<string>
     */
    private function parseQwList(string $inner): array
    {
        $words = preg_split('/\s+/', trim($inner), -1, PREG_SPLIT_NO_EMPTY);
        return $words !== false ? $words : [];
    }

    /**
     * Split string by comma, respecting quoted strings.
     *
     * @return list<string>
     */
    private function splitByComma(string $inner): array
    {
        $tokens = preg_split('/,(?=(?:[^"\']*["\'][^"\']*["\'])*[^"\']*$)/', $inner);
        return $tokens !== false ? $tokens : [];
    }

    /**
     * Split string by comma, respecting balanced brackets and quoted strings.
     *
     * @return list<string>
     */
    private function splitByCommaBalanced(string $inner): array
    {
        $tokens = [];
        $current = '';
        $depth = 0;
        $inString = false;
        $stringChar = '';

        for ($i = 0, $len = strlen($inner); $i < $len; $i++) {
            $char = $inner[$i];

            // Handle string boundaries
            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
                $current .= $char;
                continue;
            }

            if ($inString) {
                if ($char === $stringChar) {
                    // Check for escaped quote by looking backwards in original string
                    $escapeCount = 0;
                    $j = $i - 1;
                    while ($j >= 0 && $inner[$j] === '\\') {
                        $escapeCount++;
                        $j--;
                    }
                    if ($escapeCount % 2 === 0) {
                        $inString = false;
                    }
                }
                $current .= $char;
                continue;
            }

            // Handle brackets
            if ($char === '{' || $char === '[' || $char === '(') {
                $depth++;
                $current .= $char;
                continue;
            }

            if ($char === '}' || $char === ']' || $char === ')') {
                $depth--;
                $current .= $char;
                continue;
            }

            // Split on comma at depth 0
            if ($char === ',' && $depth === 0) {
                $tokens[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if ($current !== '') {
            $tokens[] = $current;
        }

        return $tokens;
    }

    /**
     * Get environment variable value.
     */
    private function getEnvValue(string $name): ?string
    {
        if (isset($_ENV[$name])) {
            return $_ENV[$name];
        }
        $value = getenv($name);
        return $value !== false ? $value : null;
    }

    /**
     * Cast a raw Perl value string to the appropriate PHP type.
     */
    private function castValue(string $raw): mixed
    {
        $raw = trim($raw, " \t\n\r");

        // Empty string
        if ($raw === '' || $raw === '""' || $raw === "''") {
            return '';
        }

        // Double-quoted string – strip quotes and process escape sequences
        if (str_starts_with($raw, '"') && str_ends_with($raw, '"')) {
            return $this->unescapeDoubleQuoted(substr($raw, 1, -1));
        }

        // Single-quoted string – strip quotes, minimal escape processing
        if (str_starts_with($raw, "'") && str_ends_with($raw, "'")) {
            return $this->unescapeSingleQuoted(substr($raw, 1, -1));
        }

        // Boolean / undef - including Perl's common use of 0/1 for false/true
        $lower = strtolower($raw);
        if ($lower === 'true' || $raw === '1') {
            return true;
        }
        if ($lower === 'false' || $raw === '0') {
            return false;
        }
        if ($lower === 'undef') {
            return null;
        }

        // Check if it's a constant reference
        if (isset($this->constants[$raw])) {
            return $this->constants[$raw];
        }

        // Hexadecimal (must be before numeric check)
        if (preg_match('/^0x[0-9a-fA-F]+$/', $raw)) {
            return (int) hexdec($raw);
        }

        // Binary (must be before numeric check)
        if (preg_match('/^0b[01]+$/', $raw)) {
            return (int) bindec(substr($raw, 2));
        }

        // Octal - numbers starting with 0 followed by only 0-7 digits
        // Must be before generic numeric check
        if (preg_match('/^0[0-7]+$/', $raw) && strlen($raw) > 1) {
            return (int) octdec($raw);
        }

        // Numeric - handle various formats (decimal integers and floats)
        if (is_numeric($raw)) {
            return str_contains($raw, '.') ? (float) $raw : (int) $raw;
        }

        return $raw;
    }

    /**
     * Process escape sequences in double-quoted strings.
     */
    private function unescapeDoubleQuoted(string $str): string
    {
        $replacements = [
            '\\n' => "\n",
            '\\t' => "\t",
            '\\r' => "\r",
            '\\\\' => '\\',
            '\\"' => '"',
            '\\$' => '$',
            '\\@' => '@',
        ];

        return strtr($str, $replacements);
    }

    /**
     * Process escape sequences in single-quoted strings.
     * In Perl, only \\ and \' are escaped in single-quoted strings.
     */
    private function unescapeSingleQuoted(string $str): string
    {
        $replacements = [
            "\\'" => "'",
            '\\\\' => '\\',
        ];

        return strtr($str, $replacements);
    }
}

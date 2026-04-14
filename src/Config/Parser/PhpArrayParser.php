<?php

declare(strict_types=1);

namespace CxAI\CxPHP\Config\Parser;

use CxAI\CxPHP\Config\Exception\ParseException;

/**
 * Parses PHP files that return an array, e.g.:
 *
 * ```php
 * <?php
 * return [
 *     'debug' => true,
 *     'timezone' => 'UTC',
 * ];
 * ```
 *
 * This is the native format used by frameworks such as Laravel, Symfony, and
 * CodeIgniter and is the primary format for the Studio `public_html/config`
 * directory.
 */
class PhpArrayParser implements ParserInterface
{
    private const SUPPORTED_EXTENSIONS = ['php'];

    /** {@inheritdoc} */
    public function supports(string $filePath): bool
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($ext, self::SUPPORTED_EXTENSIONS, true);
    }

    /** {@inheritdoc} */
    public function parse(string $content, string $filePath = ''): array
    {
        if ($filePath !== '' && is_file($filePath)) {
            return $this->loadFile($filePath);
        }

        // Write to a temp file and include it when only raw content is given.
        $tmp = tempnam(sys_get_temp_dir(), 'cxphp_');
        if ($tmp === false) {
            throw new ParseException('Unable to create temporary file for PHP config parsing.');
        }

        try {
            file_put_contents($tmp, $content);
            return $this->loadFile($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Safely include a PHP config file and return its array value.
     *
     * @return array<string, mixed>
     */
    private function loadFile(string $filePath): array
    {
        if (!is_readable($filePath)) {
            throw new ParseException("Config file is not readable: {$filePath}");
        }

        $result = (static function () use ($filePath) {
            return include $filePath;
        })();

        if (!is_array($result)) {
            throw new ParseException(
                "PHP config file must return an array, got " . gettype($result) . ": {$filePath}"
            );
        }

        return $result;
    }
}

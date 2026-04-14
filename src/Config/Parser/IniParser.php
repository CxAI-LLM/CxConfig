<?php

declare(strict_types=1);

namespace CxAI\CxPHP\Config\Parser;

use CxAI\CxPHP\Config\Exception\ParseException;

/**
 * Parses INI-style configuration files.
 *
 * Supports sections and type-cast values (bool, int, float).
 *
 * Example format:
 * ```ini
 * [database]
 * host = localhost
 * port = 3306
 * name = studio
 * ```
 */
class IniParser implements ParserInterface
{
    private const SUPPORTED_EXTENSIONS = ['ini'];

    /** @var bool Whether to process INI sections */
    private bool $processSections;

    public function __construct(bool $processSections = true)
    {
        $this->processSections = $processSections;
    }

    /** {@inheritdoc} */
    public function supports(string $filePath): bool
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($ext, self::SUPPORTED_EXTENSIONS, true);
    }

    /** {@inheritdoc} */
    public function parse(string $content, string $filePath = ''): array
    {
        $result = @parse_ini_string($content, $this->processSections, INI_SCANNER_TYPED);

        if ($result === false) {
            throw new ParseException("Failed to parse INI config" . ($filePath ? ": {$filePath}" : '.'));
        }

        return $result;
    }
}

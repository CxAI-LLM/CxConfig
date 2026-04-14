<?php

declare(strict_types=1);

namespace CxAI\CxPHP\Config\Parser;

use CxAI\CxPHP\Config\Exception\ParseException;

/**
 * Parses JSON configuration files.
 *
 * Example format:
 * ```json
 * {
 *   "app": {
 *     "name": "Studio",
 *     "debug": false
 *   }
 * }
 * ```
 */
class JsonParser implements ParserInterface
{
    private const SUPPORTED_EXTENSIONS = ['json'];

    /** {@inheritdoc} */
    public function supports(string $filePath): bool
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($ext, self::SUPPORTED_EXTENSIONS, true);
    }

    /** {@inheritdoc} */
    public function parse(string $content, string $filePath = ''): array
    {
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ParseException(
                "Failed to parse JSON config" . ($filePath ? " ({$filePath})" : '') . ": " . $e->getMessage(),
                0,
                $e
            );
        }

        if (!is_array($data)) {
            throw new ParseException(
                "JSON config must decode to an object/array" . ($filePath ? ": {$filePath}" : '.')
            );
        }

        return $data;
    }
}

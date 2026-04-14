<?php

declare(strict_types=1);

namespace CxAI\CxPHP\Config\Parser;

use CxAI\CxPHP\Config\Exception\ParseException;

/**
 * Parses YAML configuration files.
 *
 * Requires the `symfony/yaml` package.
 *
 * Example format:
 * ```yaml
 * app:
 *   name: Studio
 *   debug: false
 *   timezone: UTC
 * ```
 */
class YamlParser implements ParserInterface
{
    private const SUPPORTED_EXTENSIONS = ['yaml', 'yml'];

    /** {@inheritdoc} */
    public function supports(string $filePath): bool
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($ext, self::SUPPORTED_EXTENSIONS, true);
    }

    /** {@inheritdoc} */
    public function parse(string $content, string $filePath = ''): array
    {
        if (!class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            throw new ParseException(
                'YAML parsing requires the symfony/yaml package. Install it with: composer require symfony/yaml'
            );
        }

        try {
            $data = \Symfony\Component\Yaml\Yaml::parse($content);
        } catch (\Symfony\Component\Yaml\Exception\ParseException $e) {
            throw new ParseException(
                "Failed to parse YAML config" . ($filePath ? " ({$filePath})" : '') . ": " . $e->getMessage(),
                0,
                $e
            );
        }

        if (!is_array($data)) {
            throw new ParseException(
                "YAML config must decode to a mapping/sequence" . ($filePath ? ": {$filePath}" : '.')
            );
        }

        return $data;
    }
}

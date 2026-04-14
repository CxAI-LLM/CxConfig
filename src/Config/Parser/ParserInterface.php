<?php

declare(strict_types=1);

namespace CxAI\CxPHP\Config\Parser;

/**
 * Contract for all configuration file parsers.
 */
interface ParserInterface
{
    /**
     * Parse the given raw content into a PHP array.
     *
     * @param string $content  Raw file content.
     * @param string $filePath Original file path (used for error messages).
     *
     * @return array<string, mixed>
     *
     * @throws \CxAI\CxPHP\Config\Exception\ParseException
     */
    public function parse(string $content, string $filePath = ''): array;

    /**
     * Return TRUE when this parser can handle the given file.
     *
     * The default implementation matches on file extension.
     */
    public function supports(string $filePath): bool;
}

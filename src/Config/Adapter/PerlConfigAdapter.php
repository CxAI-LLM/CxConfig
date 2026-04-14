<?php

declare(strict_types=1);

namespace CxAI\CxPHP\Config\Adapter;

use CxAI\CxPHP\Config\ConfigInterface;
use CxAI\CxPHP\Config\Exception\ConfigException;
use CxAI\CxPHP\Config\Parser\PerlParser;

/**
 * Perl configuration adapter.
 *
 * Provides a `ConfigInterface` wrapper around the {@see PerlParser} so that
 * Perl-style configuration files used by legacy CGI/mod_perl components of
 * Studio can be consumed transparently alongside PHP configuration.
 *
 * Supported Perl config formats:
 *  - Scalar assignments (`$var = "value";`)
 *  - Hash assignments (`%hash = (key => "value");`)
 *  - Array assignments (`@list = ("a", "b");`)
 *  - Plain key = value pairs (Config::Simple / App::Config style)
 *
 * Example usage:
 * ```php
 * $adapter = new PerlConfigAdapter('/path/to/studio.conf');
 * $dbHost  = $adapter->get('database.host');
 * ```
 */
class PerlConfigAdapter implements ConfigInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    private PerlParser $parser;

    /**
     * @param string $filePath Path to the Perl config file (optional).
     */
    public function __construct(string $filePath = '')
    {
        $this->parser = new PerlParser();

        if ($filePath !== '') {
            $this->load($filePath);
        }
    }

    /**
     * Load a Perl configuration file.
     *
     * @throws ConfigException
     */
    public function load(string $filePath): void
    {
        if (!is_readable($filePath)) {
            throw new ConfigException("Perl config file is not readable: {$filePath}");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new ConfigException("Unable to read Perl config file: {$filePath}");
        }

        $this->data = array_merge($this->data, $this->parser->parse($content, $filePath));
    }

    /**
     * Merge additional data directly (useful for testing or programmatic setup).
     *
     * @param array<string, mixed> $data
     */
    public function merge(array $data): void
    {
        $this->data = array_merge($this->data, $data);
    }

    // -------------------------------------------------------------------------
    // ConfigInterface implementation
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->dotGet($this->data, $key) ?? $default;
    }

    /** {@inheritdoc} */
    public function has(string $key): bool
    {
        return $this->dotGet($this->data, $key) !== null;
    }

    /** {@inheritdoc} */
    public function set(string $key, mixed $value): void
    {
        $this->dotSet($this->data, $key, $value);
    }

    /** {@inheritdoc} */
    public function all(?string $namespace = null): array
    {
        if ($namespace === null) {
            return $this->data;
        }

        $value = $this->dotGet($this->data, $namespace);

        return is_array($value) ? $value : [];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function dotGet(array $data, string $key): mixed
    {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        $segments = explode('.', $key);
        $current  = $data;
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    private function dotSet(array &$data, string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $current  = &$data;
        foreach ($segments as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }
            $current = &$current[$segment];
        }
        $current = $value;
    }
}

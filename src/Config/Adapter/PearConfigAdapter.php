<?php

declare(strict_types=1);

namespace CxAI\CxPHP\Config\Adapter;

use CxAI\CxPHP\Config\ConfigInterface;
use CxAI\CxPHP\Config\Exception\ConfigException;
use CxAI\CxPHP\Config\Exception\ParseException;

/**
 * PHP PEAR-compatible configuration adapter.
 *
 * Provides a bridge to the PEAR Config package (pear/config) so that legacy
 * Studio configuration files managed via `Config_Container` can be loaded and
 * used through the CxPHP `ConfigInterface`.
 *
 * When the PEAR Config package is not installed the adapter falls back to a
 * native PHP implementation that understands the same INI-based format used by
 * PEAR Config by default.
 *
 * Supported PEAR Config container types (when package is installed):
 *   - phpArray  – PHP files returning an array
 *   - iniFile   – Standard INI files
 *   - xmlFile   – XML configuration files
 *   - apacheConf – Apache-style configuration
 *
 * @see https://pear.php.net/package/Config
 */
class PearConfigAdapter implements ConfigInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param string $filePath  Path to the PEAR-compatible config file.
     * @param string $type      PEAR container type: 'phpArray', 'iniFile', 'xmlFile'.
     */
    public function __construct(string $filePath = '', string $type = 'iniFile')
    {
        if ($filePath !== '') {
            $this->load($filePath, $type);
        }
    }

    /**
     * Load a configuration file using PEAR Config when available, or the
     * native fallback parser otherwise.
     *
     * @throws ConfigException
     */
    public function load(string $filePath, string $type = 'iniFile'): void
    {
        if (!is_readable($filePath)) {
            throw new ConfigException("Config file is not readable: {$filePath}");
        }

        if ($this->isPearAvailable()) {
            $this->loadViaPear($filePath, $type);
        } else {
            $this->loadNative($filePath, $type);
        }
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

    private function isPearAvailable(): bool
    {
        return class_exists('Config', false);
    }

    /**
     * Load configuration via the PEAR Config package.
     */
    private function loadViaPear(string $filePath, string $type): void
    {
        try {
            /** @var object $config */
            $configClass = 'Config';
            $config = new $configClass();
            $root   = $config->parseConfig($filePath, $type);

            $pearClass = 'PEAR';
            if ($pearClass::isError($root)) {
                throw new ConfigException('PEAR Config error: ' . $root->getMessage());
            }

            $this->data = $this->pearContainerToArray($root->toArray());
        } catch (\Throwable $e) {
            if ($e instanceof ConfigException) {
                throw $e;
            }
            throw new ConfigException(
                "PEAR Config failed to load {$filePath}: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Fallback: load config using PHP native functions.
     */
    private function loadNative(string $filePath, string $type): void
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new ConfigException("Unable to read config file: {$filePath}");
        }

        switch ($type) {
            case 'phpArray':
                $data = $this->nativeLoadPhp($filePath);
                break;
            case 'iniFile':
            default:
                $data = $this->nativeLoadIni($content, $filePath);
                break;
        }

        $this->data = $data;
    }

    /** @return array<string, mixed> */
    private function nativeLoadPhp(string $filePath): array
    {
        $result = (static function () use ($filePath) {
            return include $filePath;
        })();

        if (!is_array($result)) {
            throw new ParseException("PHP config must return an array: {$filePath}");
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function nativeLoadIni(string $content, string $filePath): array
    {
        $result = parse_ini_string($content, true, INI_SCANNER_TYPED);
        if ($result === false) {
            throw new ParseException("Failed to parse INI config: {$filePath}");
        }
        return $result;
    }

    /**
     * Flatten the PEAR Config container array structure into a simple
     * associative array.
     *
     * @param array<mixed> $pearArray
     * @return array<string, mixed>
     */
    private function pearContainerToArray(array $pearArray): array
    {
        $result = [];
        foreach ($pearArray as $key => $value) {
            if (is_array($value) && isset($value['type'])) {
                // PEAR Config node structure
                if (isset($value['children'])) {
                    $result[$key] = $this->pearContainerToArray($value['children']);
                } elseif (isset($value['content'])) {
                    $result[$key] = $value['content'];
                }
            } else {
                $result[$key] = is_array($value) ? $this->pearContainerToArray($value) : $value;
            }
        }
        return $result;
    }

    /**
     * Retrieve a value from a nested array using dot notation.
     */
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

    /**
     * Set a value in a nested array using dot notation.
     */
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

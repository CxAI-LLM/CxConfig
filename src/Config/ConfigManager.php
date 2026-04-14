<?php

declare(strict_types=1);

namespace CxAI\CxPHP\Config;

use CxAI\CxPHP\Config\Exception\ConfigException;
use CxAI\CxPHP\Config\Exception\ParseException;
use CxAI\CxPHP\Config\Parser\IniParser;
use CxAI\CxPHP\Config\Parser\JsonParser;
use CxAI\CxPHP\Config\Parser\ParserInterface;
use CxAI\CxPHP\Config\Parser\PerlParser;
use CxAI\CxPHP\Config\Parser\PhpArrayParser;
use CxAI\CxPHP\Config\Parser\YamlParser;

/**
 * Central configuration manager for the CxPHP library.
 *
 * Loads configuration files from one or more directories, auto-detects the
 * format by file extension, and exposes values through a simple dot-notation
 * API.
 *
 * Supported formats (out-of-the-box):
 *  - PHP array  (.php)
 *  - INI        (.ini, .conf, .cfg)
 *  - JSON       (.json)
 *  - YAML       (.yaml, .yml)  — requires `symfony/yaml`
 *  - Perl       (.pl, .pm, .perl, .conf, .cfg)
 *
 * Usage example:
 * ```php
 * $config = new ConfigManager('/path/to/studio/public_html/config');
 * echo $config->get('database.host');       // from database.php
 * echo $config->get('app.name', 'Studio');  // from app.php
 * ```
 *
 * Multiple directories or individual files can be loaded by chaining calls:
 * ```php
 * $config = new ConfigManager();
 * $config->loadDirectory('/app/config');
 * $config->loadFile('/etc/studio/overrides.ini');
 * ```
 */
class ConfigManager implements ConfigInterface
{
    /** @var array<string, mixed> Merged configuration data. */
    private array $data = [];

    /** @var list<ParserInterface> Registered parsers, checked in order. */
    private array $parsers = [];

    /** @var string[] File extensions to skip when scanning directories. */
    private array $ignoredExtensions = ['example', 'dist', 'bak', 'swp'];

    /** @var self|null Shared singleton instance used by the config() helper. */
    private static ?self $instance = null;

    /**
     * @param string|null $directory Optional directory to load immediately.
     */
    public function __construct(?string $directory = null)
    {
        $this->registerDefaultParsers();

        if ($directory !== null) {
            $this->loadDirectory($directory);
        }
    }

    // -------------------------------------------------------------------------
    // Singleton accessor (used by the config() global helper)
    // -------------------------------------------------------------------------

    /**
     * Return the shared singleton instance.
     *
     * The first time this is called the instance is lazily created and, when
     * APP_CONFIG_PATH is defined or a `config/` directory exists next to the
     * library root, that directory is loaded automatically.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            $configDir = defined('APP_CONFIG_PATH')
                ? APP_CONFIG_PATH
                : dirname(__DIR__, 2) . '/config';

            self::$instance = new self(is_dir($configDir) ? $configDir : null);
        }

        return self::$instance;
    }

    /**
     * Override the shared singleton (useful in tests or custom bootstrapping).
     */
    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    // -------------------------------------------------------------------------
    // Directory / file loading
    // -------------------------------------------------------------------------

    /**
     * Load all supported configuration files from a directory.
     *
     * Files are merged in alphabetical order. Subdirectories are NOT scanned
     * recursively – only top-level files are loaded.
     *
     * @throws ConfigException
     */
    public function loadDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            throw new ConfigException("Config directory does not exist: {$directory}");
        }

        $files = glob(rtrim($directory, '/\\') . '/*') ?: [];
        sort($files);

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, $this->ignoredExtensions, true)) {
                continue;
            }

            try {
                $this->loadFile($file);
            } catch (ParseException $e) {
                // Non-fatal: log and continue so one broken file doesn't halt startup
                trigger_error("CxPHP ConfigManager: " . $e->getMessage(), E_USER_WARNING);
            }
        }
    }

    /**
     * Load a single configuration file.
     *
     * The configuration key namespace is derived from the file's base name
     * (without extension) so that `database.php` populates `database.*`.
     *
     * @throws ConfigException  If the file cannot be read.
     * @throws ParseException   If no parser supports the file format.
     */
    public function loadFile(string $filePath): void
    {
        if (!is_readable($filePath)) {
            throw new ConfigException("Config file is not readable: {$filePath}");
        }

        $parser  = $this->resolveParser($filePath);
        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new ConfigException("Unable to read config file: {$filePath}");
        }

        $parsed    = $parser->parse($content, $filePath);
        $namespace = pathinfo($filePath, PATHINFO_FILENAME);

        $this->data[$namespace] = array_merge(
            $this->data[$namespace] ?? [],
            $parsed
        );
    }

    /**
     * Merge an array of values directly into the config repository.
     *
     * @param array<string, mixed> $data
     * @param string|null $namespace  Optional dot-path to merge under.
     */
    public function merge(array $data, ?string $namespace = null): void
    {
        if ($namespace === null) {
            $this->data = array_merge($this->data, $data);
            return;
        }

        $this->dotSet($this->data, $namespace, array_merge(
            (array) ($this->dotGet($this->data, $namespace) ?? []),
            $data
        ));
    }

    // -------------------------------------------------------------------------
    // Parser management
    // -------------------------------------------------------------------------

    /**
     * Register a custom parser. Custom parsers are tried BEFORE the defaults.
     */
    public function registerParser(ParserInterface $parser): void
    {
        array_unshift($this->parsers, $parser);
    }

    // -------------------------------------------------------------------------
    // ConfigInterface
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

    private function registerDefaultParsers(): void
    {
        $this->parsers = [
            new PhpArrayParser(),
            new IniParser(),
            new JsonParser(),
            new YamlParser(),
            new PerlParser(),
        ];
    }

    /**
     * Find the first parser that supports the given file path.
     *
     * @throws ParseException If no matching parser is found.
     */
    private function resolveParser(string $filePath): ParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($filePath)) {
                return $parser;
            }
        }

        throw new ParseException("No parser available for config file: {$filePath}");
    }

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

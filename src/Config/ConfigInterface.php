<?php

declare(strict_types=1);

namespace CxAI\CxPHP\Config;

/**
 * Interface for configuration containers.
 */
interface ConfigInterface
{
    /**
     * Get a configuration value by dot-notation key.
     *
     * @param string $key     The dot-notation key (e.g. "database.host").
     * @param mixed  $default Default value when the key does not exist.
     *
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Check whether a configuration key exists.
     */
    public function has(string $key): bool;

    /**
     * Set a configuration value.
     */
    public function set(string $key, mixed $value): void;

    /**
     * Return all configuration values, optionally scoped to a dot-notation namespace.
     *
     * When $namespace is supplied (e.g. "cxai") the array stored under that
     * key is returned.  When omitted the entire config tree is returned.
     *
     * @param string|null $namespace Optional dot-notation key to scope the result.
     * @return array<string, mixed>
     */
    public function all(?string $namespace = null): array;
}

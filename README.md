# CxConfig

Multi-format configuration system with unified dot-notation access for [CxLLM Studio](https://github.com/CxAI-LLM/Studio).

## Overview

CxConfig provides a unified interface for loading configuration from PHP arrays, INI files, JSON, YAML, and Perl-syntax config files. Access any value with dot-notation (`config('database.host')`) regardless of the underlying format.

## Components

| File | Purpose |
|------|---------|
| `ConfigManager.php` | Central config facade — dot-notation access, caching, env override |
| `ConfigInterface.php` | Contract for all config implementations |
| **Parsers** | |
| `Parser/PhpArrayParser.php` | Native PHP array config files (`.php`) |
| `Parser/IniParser.php` | INI/conf/cfg files with section support |
| `Parser/JsonParser.php` | JSON configuration files |
| `Parser/YamlParser.php` | YAML configuration (requires `symfony/yaml`) |
| `Parser/PerlParser.php` | Perl-syntax config files (`.pl`, `.pm`, `.conf`) |
| **Adapters** | |
| `Adapter/PearConfigAdapter.php` | PEAR Config compatibility layer |
| `Adapter/PerlConfigAdapter.php` | Perl `CxAI::CxPHP::Config` bridge adapter |
| **Exceptions** | |
| `Exception/ConfigException.php` | Configuration errors |
| `Exception/ParseException.php` | Format-specific parse failures |

## Architecture

```
                  ┌──────────────────┐
                  │  ConfigManager   │
                  │  (dot-notation)  │
                  └────────┬─────────┘
                           │ auto-detect
          ┌────────┬───────┼───────┬────────┐
          ▼        ▼       ▼       ▼        ▼
      ┌──────┐ ┌──────┐ ┌──────┐ ┌──────┐ ┌──────┐
      │ PHP  │ │ INI  │ │ JSON │ │ YAML │ │ Perl │
      │Parser│ │Parser│ │Parser│ │Parser│ │Parser│
      └──────┘ └──────┘ └──────┘ └──────┘ └──────┘
          ▲                                    ▲
          │              Adapters              │
      ┌───┴──────┐                     ┌───────┴────┐
      │ PEAR     │                     │ Perl       │
      │ Adapter  │                     │ Adapter    │
      └──────────┘                     └────────────┘
```

## Usage

```php
use CxAI\CxPHP\Config\ConfigManager;

$config = new ConfigManager('/path/to/config');

// Auto-detects format by extension
$config->load('database.php');    // PHP array
$config->load('services.ini');    // INI
$config->load('studio.conf');     // Perl-syntax

// Unified dot-notation access
$host = $config->get('database.host', 'localhost');
$port = $config->get('database.port', 3306);

// Env vars take precedence
// DATABASE_HOST=prod-db.example.com overrides config('database.host')
```

## Requirements

- PHP 8.0+ (8.3 recommended)
- Optional: `symfony/yaml` for YAML config support

## Namespace

```
CxAI\CxPHP\Config\
```

PSR-4 autoloading: `CxAI\CxPHP\Config\` → `src/Config/`

## Related Repositories

| Repository | Description |
|------------|-------------|
| [CxPHP](https://github.com/CxAI-LLM/CxPHP) | PHP 8.3 ACP Gateway (monorepo) |
| [CxPerl](https://github.com/CxAI-LLM/CxPerl) | Perl `CxAI::CxPHP::Config` (bridged via adapter) |
| [CxCluster](https://github.com/CxAI-LLM/CxCluster) | Cluster orchestration (config consumer) |
| [CxNode](https://github.com/CxAI-LLM/CxNode) | Node.js SDK (config consumer) |

## License

[Apache-2.0](LICENSE)

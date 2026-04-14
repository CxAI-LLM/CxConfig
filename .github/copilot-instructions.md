# CxConfig — GitHub Copilot Workspace Instructions

## Project overview

CxConfig is the multi-format configuration system for CxLLM Studio.
Namespace: `CxAI\CxPHP\Config\` → `src/Config/`

## Repository structure

```
src/Config/
  ConfigManager.php              Central config facade (dot-notation)
  ConfigInterface.php            Contract
  Parser/
    PhpArrayParser.php           .php config files
    IniParser.php                .ini/.conf/.cfg files
    JsonParser.php               .json files
    YamlParser.php               .yaml/.yml (requires symfony/yaml)
    PerlParser.php               Perl-syntax .pl/.pm/.conf
  Adapter/
    PearConfigAdapter.php        PEAR Config compatibility
    PerlConfigAdapter.php        Perl CxAI::CxPHP::Config bridge
  Exception/
    ConfigException.php          Configuration errors
    ParseException.php           Format-specific parse failures
```

## Code conventions

- PHP 8.0+ minimum; PHP 8.3 in production
- PSR-4 autoloading: `CxAI\CxPHP\Config\` → `src/Config/`
- `declare(strict_types=1)` in all files
- Strategy pattern: parsers auto-selected by file extension
- Env vars take precedence over file values via `env()` helper
- Three-parser system: PhpArray / INI / StudioConf + namespace-routing logic

## Key patterns

- **Strategy**: Parser implementations selected by file extension
- **Facade**: `ConfigManager` provides unified dot-notation interface
- **Adapter**: Bridge legacy PEAR and Perl config systems
- **Singleton**: `ConfigManager::getInstance()` for global access

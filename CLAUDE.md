# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Purpose

A CLI tool that converts Symfony bundle service configuration from XML format (removed in Symfony 8.0) to PHP DSL format. It's a best-effort converter — output may require manual review.

## Commands

```bash
# Install dependencies
composer install

# Run all tests
./vendor/bin/phpunit --testdox --colors=always

# Run a single test
./vendor/bin/phpunit --testdox --filter <TestMethodName>

# Run the converter
./bin/convert <source-xml-file-or-directory> [target-directory]
```

## Architecture

The codebase has two main classes:

**`src/XmlToPhpConfigConverter.php`** — Core engine. Parses XML with `DOMDocument` and generates PHP DSL strings. Each XML element type has a dedicated `process*()` method (e.g. `processServices()`, `processService()`, `processArguments()`). `formatString()` handles smart detection of PHP class names to emit `Foo::class` syntax. `formatValue()` auto-detects PHP scalar types.

**`src/ConvertCommand.php`** — Symfony Console command that drives the workflow: find XML files (using Symfony Finder), call the converter, write `.php` output files, then validate each converted file by loading both XML and PHP into a `ContainerBuilder`, dumping both back to XML with `XmlDumper`, and asserting they match.

**`bin/convert`** — Entry point: creates Symfony Console Application, registers `ConvertCommand`, runs it.

## Test Strategy

`tests/SymfonyXmlFixturesTest.php` uses a data provider to run the converter over all of Symfony's own DI XML fixtures (in `vendor/symfony/dependency-injection/Tests/Fixtures/xml/`). Each test converts XML → PHP, then validates by comparing XML dumps from both the original and converted containers. Several fixtures are explicitly skipped due to known limitations (inline services, service locators, etc.).

The CI workflow reinstalls `symfony/dependency-injection` from source (not dist) specifically to include those fixture files.

## Known Limitations

- Extension configs (non-core XML namespaces) are not supported
- Routing and Doctrine config files are excluded from directory scans
- Inline services may differ in PHP dumps (Symfony limitation)

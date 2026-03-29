# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Silverstripe module that generates YAML fixture files from existing DataObjects in the database. Intended as a developer tool for creating test fixtures and for use with the [Populate](https://github.com/silverstripe/silverstripe-populate) module. Requires Silverstripe CMS 5 and PHP 8.1+.

## Commands

```bash
# Lint (PHP_CodeSniffer with PSR-2 + Slevomat rules)
vendor/bin/phpcs

# Auto-fix lint issues
vendor/bin/phpcbf

# Run tests (must run inside DDEV for database access)
ddev exec vendor/bin/phpunit vendor/chrispenny/silverstripe-data-object-to-fixture/tests/
```

Note: This module lives inside a larger Silverstripe project. Run composer commands from the project root, not from within this package directory.

## Architecture

**FixtureService** (`src/Service/FixtureService.php`) is the public API. It accepts DataObjects via `addDataObject()`, recursively walks all relationships (has_one, has_many, many_many, many_many through), and outputs a sorted YAML fixture via `outputFixture()`.

The processing is stack-based (not recursive): `addDataObject()` pushes related DataObjects onto `$dataObjectStack` and processes them in a loop, preventing deep call stacks.

**Two manifests** track state during processing:
- **FixtureManifest** — maps class names to `Group` objects, each containing `Record` objects (the fixture data for individual DataObjects)
- **RelationshipManifest** — tracks class-to-class dependency edges and exclusions. Uses `KahnSorter` (Kahn's topological sort) to order groups so dependencies appear before dependents in the output YAML

**ORM layer** (`src/ORM/`):
- `Group` — represents a single class in the fixture (keyed by FQCN, contains Records)
- `Record` — represents a single DataObject instance (keyed by ID, contains field key/value pairs)

**Configuration hooks** (via Silverstripe YAML config):
- `exclude_from_fixture_relationships: 1` on a class — omits it entirely from traversal
- `excluded_fixture_relationships` array on a class — omits specific relationship names
- `field_classname_map` on a class — maps polymorphic `has_one` ID fields to the field storing the actual class name (required for `DataObject::class` relationships)

**Dev task** (`src/Task/GenerateFixtureFromDataObject.php`) — web UI at `/dev/tasks/generate-fixture-from-dataobject` for selecting a DataObject class and record, then viewing the generated fixture.

## Coding Standards

PSR-2 base with Slevomat rules. Key exceptions to be aware of:
- Method names can be PascalCase or snake_case (Silverstripe convention)
- `private static` properties are used for Silverstripe config (don't flag as unused)
- Late static binding is allowed
- `new Class()` with parentheses (not `new Class`)
- Null-safe operators (`?->`) are enforced

## Testing

### Setup

Tests use **PHPUnit** via Silverstripe's `SapphireTest` base class, bootstrapped through `vendor/silverstripe/framework/tests/bootstrap.php` (configured in `phpunit.xml.dist`). Run tests from the project root:

```bash
vendor/bin/phpunit
```

### Test directory structure

```
tests/
├── Helper/           # Unit tests for helper classes
├── Manifest/         # Unit tests for manifest classes
├── ORM/              # Unit tests for ORM value objects
├── Service/          # Integration tests for FixtureService
│   └── *.yml         # YAML fixture files alongside test classes
└── Mocks/            # Test-only DataObject classes
    ├── Models/       # Mock DataObjects (has_one targets, children, etc.)
    ├── Pages/        # Mock Page subclasses (top-level test subjects)
    └── Relations/    # Mock junction/through tables for many_many
```

Test class namespace mirrors `src/`: `ChrisPenny\DataObjectToFixture\Tests\{subdirectory}`.

### Writing tests

All test classes extend `SapphireTest`:

```php
use SilverStripe\Dev\SapphireTest;

class FixtureServiceTest extends SapphireTest
{
    protected static $fixture_file = 'FixtureServiceTest.yml';

    protected static $extra_dataobjects = [
        MockPage::class,
        MockChild::class,
    ];
}
```

Key conventions:
- **`$extra_dataobjects`** — register mock DataObject classes so SapphireTest builds their tables in the test database
- **`$fixture_file`** — path to a YAML fixture file (relative to the test class file) that populates the test database before each test
- **`$usesDatabase = true`** — use instead of `$fixture_file` when you need a database but want to create records programmatically
- **`objFromFixture(ClassName::class, 'identifier')`** — retrieve a record loaded from the fixture file

### Mock DataObject classes

Place mock classes in `tests/Mocks/`. Every mock must implement `TestOnly` and declare a unique `$table_name`:

```php
namespace ChrisPenny\DataObjectToFixture\Tests\Mocks\Pages;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

class MockPage extends DataObject implements TestOnly
{
    private static string $table_name = 'DOToFixture_MockPage';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $has_many = [
        'Children' => MockChild::class,
    ];
}
```

Prefix table names with `DOToFixture_` to avoid collisions with other modules in the test database.

After adding new mock DataObject classes, flush the Silverstripe manifest cache by appending `flush=1` to the test command:

```bash
ddev exec vendor/bin/phpunit vendor/chrispenny/silverstripe-data-object-to-fixture/tests/ '' flush=1
```

### Fixture files (YAML)

Fixture files use fully-qualified class names as keys and `=>ClassName.identifier` syntax for relationships:

```yaml
ChrisPenny\DataObjectToFixture\Tests\Mocks\Pages\MockPage:
  page1:
    Title: Page 1
    Children:
      - =>ChrisPenny\DataObjectToFixture\Tests\Mocks\Models\MockChild.child1

ChrisPenny\DataObjectToFixture\Tests\Mocks\Models\MockChild:
  child1:
    Title: Child 1
```

### Test categories

- **Pure unit tests** (Record, Group, FixtureManifest, KahnSorter) — test logic in isolation, may not need a database at all
- **Integration tests** (FixtureService, RelationshipManifest) — need mock DataObjects in the DB to exercise relationship traversal, config exclusions, and YAML output
- **`GenerateFixtureFromDataObject`** is marked `@codeCoverageIgnore` — skip it

### Testing private methods

Use Reflection when a private method contains logic worth testing directly:

```php
$reflectionClass = new ReflectionClass(FixtureService::class);
$method = $reflectionClass->getMethod('addDataObjectDbFields');
$method->setAccessible(true);
$method->invoke($service, $dataObject);
```

Prefer testing through public methods when practical. Only reach for Reflection when the private method has complex branching that's hard to exercise through the public API alone.

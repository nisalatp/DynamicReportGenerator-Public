# Test Suite — Dynamic Report Generator

PHPUnit 11 + Orchestra Testbench 9. Integration tests run against an in-memory
SQLite database, so **no external database server is required**.

## Running the tests

```bash
composer install        # pulls phpunit + testbench (require-dev)
composer test           # or: vendor/bin/phpunit
```

Readable output and a coverage summary:

```bash
vendor/bin/phpunit --testdox
composer test-coverage  # requires Xdebug or PCOV
```

## Capturing results to a file (for the report / evidence)

```bash
vendor/bin/phpunit --testdox | tee TEST_RESULTS.txt
vendor/bin/phpunit --coverage-text | tee COVERAGE.txt
```

## Continuous integration

`.github/workflows/tests.yml` runs the full suite on PHP 8.2 and 8.3 on every
push. The Actions run log is a timestamped, reproducible record of results and
can be cited as testing evidence in Chapter 4.

## Layout

```
tests/
├── TestCase.php            # Testbench base: sqlite :memory:, migrations, seed data
├── Fixtures/               # User, Order, OrderItem, Product, Category models
├── Unit/                   # Pure logic — DTO validation, builders, serializer
└── Integration/            # Engine end-to-end against a real (sqlite) database
```

### What is covered

| Area | Test |
|---|---|
| Aggregate whitelist / injection guard | `Unit/AggregateTest` |
| Sort direction validation | `Unit/SortTest` |
| Filter operator & value validation | `Unit/FilterLeafTest`, `Unit/FilterGroupTest` |
| DTO shape & immutability | `Unit/AttributeTest`, `Unit/ReportRequestTest` |
| AST JSON round-trip | `Unit/ReportSerializerTest` |
| Fluent builders | `Unit/ReportBuilderTest`, `Unit/FilterBuilderTest` |
| BFS join resolution (shortest path, cycles, no-path) | `Integration/JoinResolutionTest` |
| Query compilation, filters, sorts, pagination | `Integration/QueryCompilationTest` |
| SQL-injection parameter binding | `Integration/SqlInjectionTest` |
| GROUP BY / aggregates / HAVING (subquery wrapping) | `Integration/AggregateGroupByTest` |
| Virtual attributes & dependency injection | `Integration/VirtualAttributeTest` |
| Persistence, audit logging, assignment | `Integration/PersistenceAuditTest` |
| Memory-safe CSV streaming | `Integration/CsvExportTest` |
| Service provider, config, migrations, facade | `Integration/PackageBootTest` |

## Notes for maintainers (surfaced while writing these tests)

- **Virtual-attribute SQL fragments must reference the base table alias `t0`**
  (the engine compiles the base as `DB::table('users as t0')`). Fragments that
  reference the raw table name will fail once aliased.
- **`exportToCsv()` requires the report to define a sort**; Query Builder
  `chunk()` throws without an `ORDER BY`. Consider applying a default order.
- **`ReportSerializer::parseAttribute()` does not restore the `alias` field**,
  so attribute aliases are lost on a JSON round-trip (aggregate aliases survive).

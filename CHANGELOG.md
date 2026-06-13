# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

## [Unreleased]

### Added

- **Discovery-path regression test.** Loads the provider through the framework's real
  extension-discovery dispatch (`defs()` pass-through, else `services()` via the DSL loader),
  guarding against typed `Definition` objects being returned from `services()` — a regression the
  existing `Container::load()`-based tests cannot catch.

### Fixed

- **Non-mutating health checks.** Archive health checks now report missing/corrupted archives without
  rewriting registry status rows, and the CLI health command passes the active application context to
  the checker.
- **Private archive directories.** Service and CLI archive-directory creation now use owner-only
  `0700` permissions instead of world-readable `0755`.
- **Restore integrity and archive payload bounds.** Restore now always verifies archive checksums,
  enforces configured archive-size limits before and after decompression, and rejects encryption keys
  that are not exactly 32 bytes raw or base64-decoded.
- **Archive CLI destructive-operation guardrails.** `archive:manage archive` now rejects non-positive
  retention windows, reports the actual matching row count during `--dry-run`, and requires
  interactive confirmation unless `--force` is provided.
- **Destructive archive safety.** Archive operations now enforce explicit allowed/denied table policy,
  honor per-table retention `date_column` settings, wrap archive registration/search/delete in a
  transaction, and hard-delete only the primary keys captured in the exported snapshot.
- **Boot compatibility with framework 1.55.** The service provider declared its bindings via the
  DSL `services()` method but returned strongly-typed `DefinitionInterface` objects, which the
  framework's DSL service loader rejects (`"Service '<id>' must be an array"`). Under framework
  1.55 this threw during boot in dev/test and silently dropped the bindings in production. The
  method is now `defs()`, the strongly-typed pass-through path that accepts `DefinitionInterface`
  objects.

## [1.0.0] - 2026-06-07 — Initial release (extracted from Glueful framework 1.52.0)

Data-lifecycle archiving, extracted from framework core in **Glueful framework 1.52.0**.
Requires `glueful/framework >=1.52.0`, which removed the archive subsystem from core.
Archive had no core consumer, so there is no seam to bind — this is a self-contained
product that ships its own schema, service, and CLI.

### Added

- **`ArchiveService`** implementing `ArchiveServiceInterface` — table archiving
  (chunked export, gzip/bzip2 compression, optional AES-256-GCM encryption,
  SHA-256 checksums), transactional restore with `skip`/`overwrite` conflict
  resolution, cross-archive search, integrity verification, table-growth
  tracking, and archive summary/listing. Bound in the container via
  `ArchiveServiceProvider::services()` (interface factory + concrete alias).
- **`archive:manage` CLI command** (`Glueful\Extensions\Archive\Console\ManageCommand`,
  auto-discovered in `boot()`) with `archive`, `status`, `search`, `verify`,
  `health`, `cleanup`, `auto`, and `track` actions.
- **Config-gated schema** — three tables (`archive_registry`,
  `archive_search_index`, `archive_table_stats`) shipped as migrations that
  register only when `archive.enabled` (`ARCHIVE_DATABASE_SCHEMA`) is true.
- **`config/archive.php`** (the `archive` config key + `ARCHIVE_*` env), merged via
  the provider's `register()` — storage, compression, encryption, processing,
  retention policies, search, monitoring, schedule, and backup settings.
- **Dedicated private local storage disk** rooted at the configured archive path.

### Migration from framework core

Namespace map for any app/extension code referencing the moved classes:

```
Glueful\Services\Archive\*  →  Glueful\Extensions\Archive\*
```

For example:

```
Glueful\Services\Archive\ArchiveService           →  Glueful\Extensions\Archive\ArchiveService
Glueful\Services\Archive\ArchiveServiceInterface  →  Glueful\Extensions\Archive\ArchiveServiceInterface
```

Configuration moved from the core `config/archive.php` to this extension's
`config/archive.php` (same `archive` config key + `ARCHIVE_*` env). The schema is
now config-gated behind `ARCHIVE_DATABASE_SCHEMA` (`archive.enabled`) and shipped
by this extension rather than core.

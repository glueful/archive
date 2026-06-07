# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

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

# Archive Extension for Glueful

## Overview

Archive adds data-lifecycle archiving to your Glueful application: move aged
rows out of operational tables into compressed (optionally encrypted) archive
files, keep a searchable index of what was archived, and restore archived rows
back into a table when you need them again.

It is a self-contained product — there is no core seam to bind. Installing the
extension ships its own schema (registry, search index, table-stats), a
`ArchiveService` for programmatic use, and an `archive:manage` CLI for
operational tasks. The schema is **config-gated**: migrations only register when
you explicitly opt in via `ARCHIVE_DATABASE_SCHEMA` (→ `archive.enabled`).

## Features

- **Table archiving**: export rows older than a cutoff date, compress (gzip/bzip2)
  and optionally encrypt (AES-256-GCM), checksum, register, then delete the
  originals — all guarded by verification before the source rows are removed
- **Restore**: replay archived rows back into a target table inside a single
  transaction, with `skip`/`overwrite` conflict resolution and offset/limit slicing
- **Search**: query across compressed archives by user, endpoint, action, IP,
  and date range, backed by a per-archive search index built at archive time
- **Verification & integrity**: SHA-256 checksums with on-demand `verifyArchive()`
  and mandatory restore-time checksum verification
- **Table growth tracking**: record row counts / sizes per table and surface which
  tables exceed configured row/age thresholds and need archiving
- **`archive:manage` CLI**: `archive`, `status`, `search`, `verify`, `health`,
  `cleanup`, `auto`, and `track` actions for day-to-day operations
- **Config-gated schema**: ships its own migrations, registered only when
  `ARCHIVE_DATABASE_SCHEMA` is enabled — zero footprint until you opt in
- **Dedicated storage disk**: archives are written to a private local disk rooted
  at the configured storage path

## Installation

### Installation (Recommended)

**Install via Composer**

```bash
composer require glueful/archive

# Rebuild the extensions cache after adding new packages
php glueful extensions:cache
```

Composer discovers packages of type `glueful-extension`, but **installing does not auto-enable** them — the provider must be added to `config/extensions.php`'s `enabled` allow-list. The CLI does that for you:

```bash
# Enable (adds the provider FQCN to config/extensions.php + recompiles the cache)
php glueful extensions:enable archive

# Disable (removes it)
php glueful extensions:disable archive
```

In production, manage the `enabled` list in config and run `php glueful extensions:cache` in your deploy step.

This extension ships migrations, but the schema is **config-gated**. Set the opt-in
flag, then run the migrations:

```bash
# .env
ARCHIVE_DATABASE_SCHEMA=true
```

```bash
php glueful migrate:run
```

The migrations only register when `archive.enabled` (`ARCHIVE_DATABASE_SCHEMA`) is
true, so nothing is created until you opt in.

### Local Development Installation

To develop the extension locally, register it as a Composer **path repository** in your app's `composer.json`, then require and enable it:

```jsonc
// composer.json
"repositories": [
    { "type": "path", "url": "extensions/archive", "options": { "symlink": true } }
]
```

```bash
composer require glueful/archive:@dev
php glueful extensions:enable archive
```

Entries in `config/extensions.php` are plain string FQCNs (no `::class`) — prefer `extensions:enable` over editing by hand.

### Verify Installation

```bash
php glueful extensions:list
php glueful extensions:info archive
php glueful extensions:diagnose
```

Post-install checklist:

- Opt into the schema: set `ARCHIVE_DATABASE_SCHEMA=true`, then `php glueful migrate:run`
- Rebuild cache after Composer operations: `php glueful extensions:cache`
- Confirm the CLI is registered: `php glueful archive:manage status`

## Configuration

Configuration is loaded from the extension's `config/archive.php` and merged under
the `archive` key by the service provider. It reads `ARCHIVE_*` environment
variables. Key settings:

```env
# Opt-in gate for the schema/migrations (archive.enabled)
ARCHIVE_DATABASE_SCHEMA=true

# Compression: gzip | bzip2 | none
ARCHIVE_COMPRESSION=gzip
ARCHIVE_COMPRESSION_LEVEL=9

# Encryption (AES-256-GCM) — enabled when a 32-byte raw or base64-decoded key is present
ARCHIVE_ENCRYPTION_KEY=

# Processing
ARCHIVE_CHUNK_SIZE=10000
ARCHIVE_MEMORY_LIMIT=512M
ARCHIVE_MAX_EXECUTION_TIME=3600
ARCHIVE_VERIFY_CHECKSUMS=true

# Storage / limits
ARCHIVE_MAX_SIZE=1073741824   # 1GB

# Search indexing
ARCHIVE_ENABLE_SEARCH_INDEX=true
ARCHIVE_MAX_SEARCH_RESULTS=1000

# Scheduling preferences for the CLI auto/track workflows; this does not register
# a framework scheduler job by itself.
ARCHIVE_AUTO_ENABLED=true
ARCHIVE_FREQUENCY=weekly
ARCHIVE_MAX_PER_RUN=10
```

`config/archive.php` also defines `retention_policies` (per-table archive age /
row thresholds for `audit_logs`, `api_metrics`, `api_metrics_daily`,
`api_rate_limits`, and `notifications`), `allowed_tables`, `denied_tables`,
`monitoring` (health-check and alerting thresholds), `schedule`, and `backup`
sections — see the file for the full set of `ARCHIVE_*` overrides. Identity and
system tables such as `users`, `auth_sessions`, `api_keys`, and archive metadata
tables are denied by default.

## Usage

### CLI

The `archive:manage` command is the operational entry point. Its first argument
is the action (default `status`):

```bash
# Show archive system status
php glueful archive:manage status

# Archive rows in a table older than N days (default 90)
php glueful archive:manage archive audit_logs 90 --dry-run
php glueful archive:manage archive audit_logs 90 --force

# Search across archives
php glueful archive:manage search --user=<uuid> --start-date=2026-01-01 --limit=20

# Verify an archive's integrity
php glueful archive:manage verify --uuid=<archiveUuid>

# Run health checks, cleanup, the auto-archive workflow, or growth tracking
php glueful archive:manage health
php glueful archive:manage cleanup
php glueful archive:manage auto
php glueful archive:manage track audit_logs
```

Useful options: `--uuid`, `--user`, `--endpoint`, `--start-date`, `--end-date`,
`--limit`, `--format` (`table|json|csv`), `--dry-run`, `--force`, and
`--show-sensitive`. Search output redacts sensitive fields by default; use
`--show-sensitive` only in a trusted operator session.

Archive files are compressed plaintext unless `ARCHIVE_ENCRYPTION_KEY` is set.
Keep the archive storage path outside the web root, restrict filesystem access,
and set a 32-byte key for tables that may contain PII or regulated data.

### Programmatic (`ArchiveService`)

Resolve the service from the container via its interface:

```php
use Glueful\Extensions\Archive\ArchiveServiceInterface;
use Glueful\Extensions\Archive\DTOs\ArchiveSearchQuery;
use Glueful\Extensions\Archive\DTOs\ArchiveRestoreOptions;

$archive = app($context, ArchiveServiceInterface::class);

// Archive rows older than a cutoff
$result = $archive->archiveTable('audit_logs', new \DateTime('-90 days'));
// $result->archiveUuid, $result->recordCount, $result->fileSize, ...

// Search across archives
$results = $archive->searchArchives(new ArchiveSearchQuery(/* ... */));

// Restore an archive into its source table (or a target table)
$restore = $archive->restoreFromArchive($archiveUuid, new ArchiveRestoreOptions(
    conflictResolution: 'skip', // or 'overwrite'
));

// Integrity & housekeeping
$archive->verifyArchive($archiveUuid);
$archive->deleteArchive($archiveUuid);

// Stats & planning
$archive->trackTableGrowth('audit_logs');
$stats   = $archive->getTableStats('audit_logs');
$summary = $archive->getArchiveSummary();
$tables  = $archive->getTablesNeedingArchival();
$list    = $archive->getTableArchives('audit_logs');
```

### Schema

When enabled, the extension creates three tables:

- `archive_registry` — one row per archive (table, period, record count, file path,
  size, checksum, status, metadata)
- `archive_search_index` — per-archive index of searchable entity values
  (FK to `archive_registry`, cascade on delete)
- `archive_table_stats` — per-table growth tracking and archive thresholds

## Requirements

- PHP 8.3 or higher
- Glueful 1.52.0 or higher
- MySQL, PostgreSQL, or SQLite database
- `ext-openssl` for archive encryption (optional, when `ARCHIVE_ENCRYPTION_KEY` is set)

## License

MIT — licensed consistently with the Glueful framework.

## Support

For issues, feature requests, or questions, please create an issue in the repository.

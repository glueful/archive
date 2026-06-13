<?php

declare(strict_types=1);

namespace Glueful\Extensions\Archive\Tests\Integration;

use Glueful\Database\Connection;
use Glueful\Extensions\Archive\ArchiveHealthChecker;
use Glueful\Extensions\Archive\ArchiveService;
use Glueful\Extensions\Archive\DTOs\ArchiveRestoreOptions;
use PHPUnit\Framework\TestCase;

/**
 * Round-trip tests for {@see ArchiveService::restoreFromArchive()}.
 *
 * Each test runs on its own temp SQLite database with a minimal schema:
 *  - `archive_registry` matching the production migration shape
 *  - One sample data table that gets archived and restored
 *
 * Verifies the post-TG-4 contract: archived rows can actually be replayed,
 * conflict resolution honors skip/overwrite, offset/limit slice correctly,
 * and unsupported options fail loudly instead of silently.
 */
final class ArchiveRestoreTest extends TestCase
{
    private string $dbPath;
    private string $archiveDir;
    private Connection $connection;
    private ArchiveService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbPath = sys_get_temp_dir() . '/glueful-archive-restore-' . uniqid('', true) . '.sqlite';
        $this->archiveDir = sys_get_temp_dir() . '/glueful-archive-restore-' . uniqid('', true);

        $this->connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $this->dbPath],
            'pooling' => ['enabled' => false],
        ]);

        $pdo = $this->connection->getPDO();
        $pdo->exec(
            'CREATE TABLE archive_registry (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT UNIQUE NOT NULL,
                table_name TEXT NOT NULL,
                archive_date TEXT,
                period_start TEXT,
                period_end TEXT,
                record_count INTEGER,
                file_path TEXT,
                file_size INTEGER,
                compression_type TEXT DEFAULT "gzip",
                encryption_enabled INTEGER DEFAULT 1,
                checksum_sha256 TEXT,
                metadata TEXT,
                status TEXT DEFAULT "completed",
                created_at TEXT
            )'
        );

        $pdo->exec(
            'CREATE TABLE sample_records (
                uuid TEXT PRIMARY KEY,
                payload TEXT,
                created_at TEXT,
                deleted_at TEXT
            )'
        );

        $pdo->exec(
            'CREATE TABLE archive_table_stats (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                table_name TEXT UNIQUE NOT NULL,
                current_size_bytes INTEGER DEFAULT 0,
                current_row_count INTEGER DEFAULT 0,
                last_archive_date TEXT,
                next_archive_date TEXT,
                archive_threshold_rows INTEGER DEFAULT 100000,
                archive_threshold_days INTEGER DEFAULT 30,
                auto_archive_enabled INTEGER DEFAULT 1,
                created_at TEXT,
                updated_at TEXT
            )'
        );

        $pdo->exec(
            'CREATE TABLE api_metrics_daily (
                uuid TEXT PRIMARY KEY,
                payload TEXT,
                metric_date TEXT,
                created_at TEXT
            )'
        );

        $pdo->exec(
            'CREATE TABLE auth_sessions (
                uuid TEXT PRIMARY KEY,
                created_at TEXT
            )'
        );

        $this->service = new ArchiveService(
            connection: $this->connection,
            config: [
                'storage_path' => $this->archiveDir,
                'compression' => 'gzip',
                'verify_checksums' => true,
                'chunk_size' => 100,
                'allowed_tables' => ['sample_records', 'api_metrics_daily'],
                'retention_policies' => [
                    'sample_records' => ['date_column' => 'created_at'],
                    'api_metrics_daily' => ['date_column' => 'metric_date'],
                ],
            ]
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
        if (is_dir($this->archiveDir)) {
            foreach (glob($this->archiveDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->archiveDir);
        }
        parent::tearDown();
    }

    public function testRestoreReplaysAllArchivedRows(): void
    {
        $this->seedRecords(5);
        $archiveUuid = $this->archiveOldRows();
        $this->truncateSourceTable();

        $result = $this->service->restoreFromArchive($archiveUuid);

        self::assertTrue($result->success, $result->error ?? '');
        self::assertSame(5, $result->recordsRestored);
        self::assertSame('sample_records', $result->targetTable);
        self::assertCount(5, $this->fetchAllRecords());
    }

    public function testSkipConflictResolutionSkipsExistingRowsAndReportsThem(): void
    {
        $this->seedRecords(3);
        $archiveUuid = $this->archiveOldRows();
        $this->connection->getPDO()->exec(
            "INSERT INTO sample_records (uuid, payload, created_at) VALUES ('rec_001', 'existing', '2024-01-01')"
        );

        $result = $this->service->restoreFromArchive(
            $archiveUuid,
            new ArchiveRestoreOptions(conflictResolution: 'skip')
        );

        self::assertTrue($result->success);
        self::assertSame(2, $result->recordsRestored);
        self::assertTrue($result->hasConflicts());
        self::assertSame(1, $result->getConflictCount());
        self::assertSame(['uuid=rec_001'], $result->conflicts);
        self::assertCount(3, $this->fetchAllRecords());
    }

    public function testOverwriteConflictResolutionReplacesExistingRows(): void
    {
        $this->seedRecords(2);
        $archiveUuid = $this->archiveOldRows();

        // Mutate the existing row so we can verify it was actually overwritten
        $this->connection->getPDO()->exec(
            "UPDATE sample_records SET payload = 'mutated' WHERE uuid = 'rec_001'"
        );

        $result = $this->service->restoreFromArchive(
            $archiveUuid,
            new ArchiveRestoreOptions(conflictResolution: 'overwrite')
        );

        self::assertTrue($result->success);
        self::assertSame(2, $result->recordsRestored);
        self::assertFalse($result->hasConflicts());

        $rows = $this->fetchAllRecords();
        $byUuid = [];
        foreach ($rows as $row) {
            $byUuid[$row['uuid']] = $row['payload'];
        }
        self::assertSame('payload-1', $byUuid['rec_001']);
    }

    public function testLimitAndOffsetSliceArchivePayload(): void
    {
        $this->seedRecords(5);
        $archiveUuid = $this->archiveOldRows();
        $this->truncateSourceTable();

        $result = $this->service->restoreFromArchive(
            $archiveUuid,
            new ArchiveRestoreOptions(limit: 2, offset: 1)
        );

        self::assertTrue($result->success);
        self::assertSame(2, $result->recordsRestored);
        self::assertCount(2, $this->fetchAllRecords());
    }

    public function testMissingArchiveReturnsFailure(): void
    {
        $result = $this->service->restoreFromArchive('does-not-exist');

        self::assertFalse($result->success);
        self::assertNotNull($result->error);
        self::assertStringContainsString('not found', $result->error);
    }

    public function testUnsupportedConflictResolutionReturnsFailure(): void
    {
        $this->seedRecords(1);
        $archiveUuid = $this->archiveOldRows();

        $result = $this->service->restoreFromArchive(
            $archiveUuid,
            new ArchiveRestoreOptions(conflictResolution: 'rename')
        );

        self::assertFalse($result->success);
        self::assertNotNull($result->error);
        self::assertStringContainsString('Unsupported conflict resolution', $result->error);
    }

    public function testMissingTargetTableReturnsFailureInsteadOfSilentlySucceeding(): void
    {
        $this->seedRecords(1);
        $archiveUuid = $this->archiveOldRows();
        $this->connection->getPDO()->exec('DROP TABLE sample_records');

        // createTableIfNotExists must be false so we exercise the "table missing"
        // branch (not the "auto-create unsupported" branch).
        $result = $this->service->restoreFromArchive(
            $archiveUuid,
            new ArchiveRestoreOptions(createTableIfNotExists: false)
        );

        self::assertFalse($result->success);
        self::assertNotNull($result->error);
        self::assertStringContainsString("does not exist", $result->error);
    }

    public function testCreateTableIfNotExistsIsExplicitlyUnsupported(): void
    {
        $this->seedRecords(1);
        $archiveUuid = $this->archiveOldRows();
        $this->connection->getPDO()->exec('DROP TABLE sample_records');

        $result = $this->service->restoreFromArchive(
            $archiveUuid,
            ArchiveRestoreOptions::fullRestore()
        );

        self::assertFalse($result->success);
        self::assertNotNull($result->error);
        self::assertStringContainsString('Auto-creating', $result->error);
    }

    public function testArchiveRejectsDeniedSystemTables(): void
    {
        $result = $this->service->archiveTable('auth_sessions', new \DateTime('2030-01-01 00:00:00'));

        self::assertFalse($result->success);
        self::assertNotNull($result->error);
        self::assertStringContainsString('not allowed', $result->error);
    }

    public function testArchiveDirectoryIsCreatedPrivate(): void
    {
        self::assertSame(0700, fileperms($this->archiveDir) & 0777);
    }

    public function testArchiveUsesConfiguredDateColumnForExportAndDelete(): void
    {
        $stmt = $this->connection->getPDO()->prepare(
            'INSERT INTO api_metrics_daily (uuid, payload, metric_date, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute(['old-metric', 'old', '2020-01-01', '2035-01-01 00:00:00']);
        $stmt->execute(['new-metric', 'new', '2035-01-01', '2020-01-01 00:00:00']);

        $result = $this->service->archiveTable('api_metrics_daily', new \DateTime('2030-01-01 00:00:00'));

        self::assertTrue($result->success, $result->error ?? '');

        $rows = $this->connection->getPDO()
            ->query('SELECT uuid FROM api_metrics_daily ORDER BY uuid')
            ->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame(['new-metric'], $rows);
    }

    public function testArchiveDeletesOnlyCapturedPrimaryKeys(): void
    {
        $this->seedRecords(1);

        $pdo = $this->connection->getPDO();
        $trigger = <<<'SQL'
CREATE TRIGGER insert_newer_record_after_archive_delete
BEFORE DELETE ON sample_records
WHEN OLD.uuid = 'rec_001'
BEGIN
    INSERT INTO sample_records (uuid, payload, created_at)
    VALUES ('late_insert', 'late', '2020-01-01 00:00:00');
END
SQL;
        $pdo->exec($trigger);

        $result = $this->service->archiveTable('sample_records', new \DateTime('2030-01-01 00:00:00'));

        self::assertTrue($result->success, $result->error ?? '');
        self::assertSame(['late_insert'], array_column($this->fetchAllRecords(), 'uuid'));
    }

    public function testRestoreAlwaysRejectsChecksumMismatch(): void
    {
        $this->seedRecords(1);
        $archiveUuid = $this->archiveOldRows();
        $archive = $this->fetchArchiveRecord($archiveUuid);

        $tampered = gzencode((string) json_encode([
            'metadata' => ['compression' => 'gzip', 'encryption_enabled' => false],
            'data' => [
                ['uuid' => 'rec_999', 'payload' => 'tampered', 'created_at' => '2020-01-01 00:00:00'],
            ],
        ]));
        self::assertIsString($tampered);
        file_put_contents((string) $archive['file_path'], $tampered);
        $this->truncateSourceTable();

        $service = $this->makeService(['verify_checksums' => false]);
        $result = $service->restoreFromArchive($archiveUuid);

        self::assertFalse($result->success);
        self::assertStringContainsString('checksum mismatch', (string) $result->error);
        self::assertSame([], $this->fetchAllRecords());
    }

    public function testEncryptionKeyMustBeExactlyThirtyTwoBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Archive encryption key must be exactly 32 bytes');

        $this->makeService(['encryption_key' => 'short']);
    }

    public function testRestoreRejectsArchivesThatExpandPastConfiguredSizeLimit(): void
    {
        $this->seedRecords(1);
        $archiveUuid = $this->archiveOldRows();
        $this->truncateSourceTable();

        $service = $this->makeService(['max_archive_size' => 32]);
        $result = $service->restoreFromArchive($archiveUuid);

        self::assertFalse($result->success);
        self::assertStringContainsString('exceeds maximum archive size', (string) $result->error);
        self::assertSame([], $this->fetchAllRecords());
    }

    public function testHealthCheckReportsMissingArchivesWithoutMutatingStatus(): void
    {
        $this->connection->getPDO()->prepare(
            'INSERT INTO archive_registry
                (uuid, table_name, archive_date, record_count, file_path, file_size, checksum_sha256, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            'missing-archive',
            'sample_records',
            '2026-01-01',
            1,
            $this->archiveDir . '/missing.gz',
            10,
            'checksum',
            'completed',
            '2026-01-01 00:00:00',
        ]);

        $checker = new ArchiveHealthChecker($this->connection, ['storage_path' => $this->archiveDir]);
        $result = $checker->performHealthCheck();

        self::assertFalse($result->healthy);
        self::assertContains('missing-archive', $result->metrics['missing_archives'] ?? []);
        self::assertSame('completed', $this->fetchArchiveRecord('missing-archive')['status']);
    }

    public function testArchiveRegistryRecordsActualCompressionAndEncryptionState(): void
    {
        $this->seedRecords(1);
        $archiveUuid = $this->archiveOldRows();

        $archive = $this->fetchArchiveRecord($archiveUuid);

        self::assertSame('gzip', $archive['compression_type']);
        self::assertSame(0, (int) $archive['encryption_enabled']);
    }

    public function testVerifyAndDeleteArchiveUseStoredArchiveFile(): void
    {
        $this->seedRecords(1);
        $archiveUuid = $this->archiveOldRows();
        $archive = $this->fetchArchiveRecord($archiveUuid);

        self::assertTrue($this->service->verifyArchive($archiveUuid));
        self::assertTrue(file_exists((string) $archive['file_path']));

        self::assertTrue($this->service->deleteArchive($archiveUuid));
        self::assertFalse(file_exists((string) $archive['file_path']));
        self::assertSame([], $this->connection->getPDO()
            ->query("SELECT uuid FROM archive_registry WHERE uuid = '{$archiveUuid}'")
            ?->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function seedRecords(int $count): void
    {
        $stmt = $this->connection->getPDO()->prepare(
            "INSERT INTO sample_records (uuid, payload, created_at)
             VALUES (?, ?, '2020-01-01 00:00:00')"
        );
        for ($i = 1; $i <= $count; $i++) {
            $stmt->execute([
                sprintf('rec_%03d', $i),
                "payload-{$i}",
            ]);
        }
    }

    private function archiveOldRows(): string
    {
        $cutoff = new \DateTime('2030-01-01 00:00:00');
        $result = $this->service->archiveTable('sample_records', $cutoff);
        self::assertTrue($result->success, $result->error ?? '');
        self::assertNotNull($result->archiveUuid);

        return (string) $result->archiveUuid;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchArchiveRecord(string $archiveUuid): array
    {
        $stmt = $this->connection->getPDO()->prepare('SELECT * FROM archive_registry WHERE uuid = ?');
        $stmt->execute([$archiveUuid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        return $row;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function makeService(array $config): ArchiveService
    {
        return new ArchiveService(
            connection: $this->connection,
            config: array_merge([
                'storage_path' => $this->archiveDir,
                'compression' => 'gzip',
                'verify_checksums' => true,
                'chunk_size' => 100,
                'allowed_tables' => ['sample_records', 'api_metrics_daily'],
                'retention_policies' => [
                    'sample_records' => ['date_column' => 'created_at'],
                    'api_metrics_daily' => ['date_column' => 'metric_date'],
                ],
            ], $config)
        );
    }

    private function truncateSourceTable(): void
    {
        $this->connection->getPDO()->exec('DELETE FROM sample_records');
    }

    /**
     * @return list<array{uuid: string, payload: string, created_at: string}>
     */
    private function fetchAllRecords(): array
    {
        $stmt = $this->connection->getPDO()->query(
            'SELECT uuid, payload, created_at FROM sample_records ORDER BY uuid'
        );
        if ($stmt === false) {
            return [];
        }

        /** @var list<array{uuid: string, payload: string, created_at: string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $rows;
    }
}

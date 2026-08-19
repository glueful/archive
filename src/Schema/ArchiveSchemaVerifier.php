<?php

declare(strict_types=1);

namespace Glueful\Extensions\Archive\Schema;

use Glueful\Database\Connection;
use Glueful\Extensions\Schema\StructuralVerifierInterface;

/**
 * Structural verifier for glueful/archive (schema policy spec B7): each create migration proves
 * every table it creates with its load-bearing columns. Unknown basenames are never adoptable.
 */
final class ArchiveSchemaVerifier implements StructuralVerifierInterface
{
    public function source(): string
    {
        return 'glueful/archive';
    }

    /** @return list<string> */
    public function migrationBasenames(): array
    {
        return [
            '001_CreateArchiveSystemTables.php',
        ];
    }

    public function verify(Connection $db, string $migrationBasename): bool
    {
        return match ($migrationBasename) {
            '001_CreateArchiveSystemTables.php' => $this->tablesWithColumns($db, [
                'archive_registry' => ['table_name', 'file_path', 'checksum_sha256'],
                'archive_search_index' => ['archive_uuid', 'entity_type', 'entity_value'],
                'archive_table_stats' => ['table_name', 'archive_threshold_rows', 'auto_archive_enabled'],
            ]),
            default => false,
        };
    }

    /** @param array<string, list<string>> $expectations */
    private function tablesWithColumns(Connection $db, array $expectations): bool
    {
        $schema = $db->getSchemaBuilder();
        foreach ($expectations as $table => $columns) {
            if (!$schema->hasTable($table)) {
                return false;
            }
            foreach ($columns as $column) {
                if (!$schema->hasColumn($table, $column)) {
                    return false;
                }
            }
        }
        return true;
    }
}

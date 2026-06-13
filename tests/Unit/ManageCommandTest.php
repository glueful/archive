<?php

declare(strict_types=1);

namespace Glueful\Extensions\Archive\Tests\Unit;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Glueful\Extensions\Archive\ArchiveServiceInterface;
use Glueful\Extensions\Archive\ArchiveServiceProvider;
use Glueful\Extensions\Archive\Console\ManageCommand;
use Glueful\Extensions\Archive\DTOs\ArchiveResult;
use Glueful\Extensions\Archive\DTOs\ArchiveRestoreOptions;
use Glueful\Extensions\Archive\DTOs\ArchiveSearchQuery;
use Glueful\Extensions\Archive\DTOs\ArchiveSearchResult;
use Glueful\Extensions\Archive\DTOs\ArchiveSummary;
use Glueful\Extensions\Archive\DTOs\RestoreResult;
use Glueful\Extensions\Archive\DTOs\TableArchiveStats;
use Glueful\Extensions\ServiceProvider;
use Glueful\Services\FileFinder;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class ManageCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        // Drain any deferred command classes so test ordering does not leak
        // between tests or into other suites that read the deferred queue.
        ServiceProvider::flushDeferredCommands();
    }

    public function testCommandClassExistsAndExtendsBaseCommand(): void
    {
        self::assertTrue(class_exists(ManageCommand::class));
        self::assertTrue(is_subclass_of(ManageCommand::class, BaseCommand::class));
    }

    public function testCommandCarriesAsCommandAttributeWithArchiveManageName(): void
    {
        $reflection = new \ReflectionClass(ManageCommand::class);
        $attributes = $reflection->getAttributes(AsCommand::class);

        self::assertCount(1, $attributes, 'ManageCommand must carry exactly one #[AsCommand] attribute');

        /** @var AsCommand $instance */
        $instance = $attributes[0]->newInstance();
        self::assertSame('archive:manage', $instance->name);
    }

    public function testArchiveServicePropertyResolvesToExtensionInterface(): void
    {
        // Proves the moved-class imports re-point to the extension namespace:
        // if the import still pointed at Glueful\Services\Archive\... the type
        // here would be that (nonexistent) class, not the extension interface.
        $property = new \ReflectionProperty(ManageCommand::class, 'archiveService');

        $type = $property->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame(ArchiveServiceInterface::class, $type->getName());
    }

    public function testDiscoverCommandsRegistersCommandUnderConsoleBootedPath(): void
    {
        $console = new RecordingConsoleApplication();
        $container = $this->makeContainer(['console.application' => $console]);

        $provider = new ArchiveServiceProvider($container);
        $provider->boot($this->makeContext());

        $names = array_map(
            static fn (object $command): ?string => $command->getName(),
            $console->added
        );

        self::assertContains('archive:manage', $names);
    }

    public function testDiscoverCommandsIsNoOpWithoutConsoleApplication(): void
    {
        // When the console application is not yet created (Decision §7), the
        // command classes are deferred — discoverCommands must not throw and
        // must not register anything against a console app.
        $container = $this->makeContainer([]);

        $provider = new ArchiveServiceProvider($container);
        $provider->boot($this->makeContext());

        $deferred = ServiceProvider::flushDeferredCommands();
        self::assertContains(ManageCommand::class, $deferred);
    }

    public function testArchiveRejectsNonPositiveDaysBeforeCallingService(): void
    {
        $service = new RecordingArchiveService();
        $tester = $this->tester($service);

        $exitCode = $tester->execute(['action' => 'archive', 'table' => 'sample_records', 'days' => '0']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Days must be a positive integer', $tester->getDisplay());
        self::assertSame(0, $service->archiveCalls);
    }

    public function testArchiveDryRunShowsMatchingRecordCountWithoutArchiving(): void
    {
        $service = new RecordingArchiveService();
        $service->candidateCount = 7;
        $tester = $this->tester($service);

        $exitCode = $tester->execute([
            'action' => 'archive',
            'table' => 'sample_records',
            'days' => '30',
            '--dry-run' => true,
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Matching records: 7', $tester->getDisplay());
        self::assertSame(0, $service->archiveCalls);
    }

    public function testArchiveRequiresConfirmationWithoutForce(): void
    {
        $service = new RecordingArchiveService();
        $tester = $this->tester($service);
        $tester->setInputs(['no']);

        $exitCode = $tester->execute(['action' => 'archive', 'table' => 'sample_records', 'days' => '30']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Archive cancelled', $tester->getDisplay());
        self::assertSame(0, $service->archiveCalls);
    }

    public function testArchiveForceSkipsConfirmationAndArchives(): void
    {
        $service = new RecordingArchiveService();
        $tester = $this->tester($service);

        $exitCode = $tester->execute([
            'action' => 'archive',
            'table' => 'sample_records',
            'days' => '30',
            '--force' => true,
        ]);

        self::assertSame(0, $exitCode);
        self::assertSame(1, $service->archiveCalls);
    }

    public function testSearchRedactsSensitiveFieldsByDefault(): void
    {
        $service = new RecordingArchiveService();
        $service->searchRecords = [
            ['uuid' => 'record-1', 'email' => 'editor@example.com', 'token' => 'secret-token', 'payload' => 'safe'],
        ];
        $tester = $this->tester($service);

        $exitCode = $tester->execute(['action' => 'search', '--format' => 'json']);
        $display = $tester->getDisplay();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('[redacted]', $display);
        self::assertStringNotContainsString('editor@example.com', $display);
        self::assertStringNotContainsString('secret-token', $display);
        self::assertStringContainsString('safe', $display);
    }

    public function testSearchCanShowSensitiveFieldsWhenExplicitlyRequested(): void
    {
        $service = new RecordingArchiveService();
        $service->searchRecords = [
            ['uuid' => 'record-1', 'email' => 'editor@example.com', 'token' => 'secret-token', 'payload' => 'safe'],
        ];
        $tester = $this->tester($service);

        $exitCode = $tester->execute(['action' => 'search', '--format' => 'json', '--show-sensitive' => true]);
        $display = $tester->getDisplay();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('editor@example.com', $display);
        self::assertStringContainsString('secret-token', $display);
    }

    private function makeContext(): ApplicationContext
    {
        return new ApplicationContext(sys_get_temp_dir(), 'testing');
    }

    private function tester(RecordingArchiveService $service): CommandTester
    {
        $command = new ManageCommand();
        $container = $this->makeContainer([
            ArchiveServiceInterface::class => $service,
            FileFinder::class => new FileFinder(new NullLogger()),
            LoggerInterface::class => new NullLogger(),
        ]);

        $property = new \ReflectionProperty(BaseCommand::class, 'container');
        $property->setAccessible(true);
        $property->setValue($command, $container);

        return new CommandTester($command);
    }

    /**
     * @param array<string, mixed> $services
     */
    private function makeContainer(array $services): ContainerInterface
    {
        return new class ($services) implements ContainerInterface {
            /** @param array<string, mixed> $services */
            public function __construct(private array $services)
            {
            }

            public function get(string $id): mixed
            {
                if (!array_key_exists($id, $this->services)) {
                    throw new \RuntimeException("No container entry: {$id}");
                }

                return $this->services[$id];
            }

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }
        };
    }
}

final class RecordingArchiveService implements ArchiveServiceInterface
{
    public int $archiveCalls = 0;
    public int $candidateCount = 3;
    /** @var array<int, array<string, mixed>> */
    public array $searchRecords = [];

    public function archiveTable(string $table, \DateTime $cutoffDate): ArchiveResult
    {
        $this->archiveCalls++;
        return ArchiveResult::success('archive-uuid', 3, 128, '/tmp/archive.gz');
    }

    public function countArchivableRows(string $table, \DateTime $cutoffDate): int
    {
        return $this->candidateCount;
    }

    public function searchArchives(ArchiveSearchQuery $query): ArchiveSearchResult
    {
        return new ArchiveSearchResult($this->searchRecords, count($this->searchRecords), ['archive-uuid'], 0.0);
    }

    public function restoreFromArchive(string $archiveUuid, ?ArchiveRestoreOptions $options = null): RestoreResult
    {
        return RestoreResult::failure('not implemented');
    }

    public function verifyArchive(string $archiveUuid): bool
    {
        return true;
    }

    public function deleteArchive(string $archiveUuid): bool
    {
        return true;
    }

    public function getTableStats(string $table): ?TableArchiveStats
    {
        return new TableArchiveStats(
            tableName: $table,
            currentRowCount: 10,
            currentSizeBytes: 1024,
            lastArchiveDate: null,
            nextArchiveDate: null,
            needsArchive: true
        );
    }

    public function trackTableGrowth(string $table): void
    {
    }

    public function getArchiveSummary(): ArchiveSummary
    {
        return new ArchiveSummary(0, 0, 0, [], null, null);
    }

    public function getTablesNeedingArchival(): array
    {
        return [];
    }

    public function getTableArchives(string $table): array
    {
        return [];
    }
}

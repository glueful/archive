<?php

declare(strict_types=1);

namespace Glueful\Extensions\Archive\Tests\Unit;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Container\Definition\AliasDefinition;
use Glueful\Container\Definition\DefinitionInterface;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Container\Loader\DefaultServicesLoader;
use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Extensions\Archive\ArchiveService;
use Glueful\Extensions\Archive\ArchiveServiceInterface;
use Glueful\Extensions\Archive\ArchiveServiceProvider;
use Glueful\Security\RandomStringGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class ArchiveServiceProviderTest extends TestCase
{
    /** @var list<string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
        $this->tmpDirs = [];
    }

    public function testServicesAreKeyedByInterfaceAndConcreteClass(): void
    {
        $defs = ArchiveServiceProvider::defs();

        self::assertArrayHasKey(ArchiveServiceInterface::class, $defs);
        self::assertArrayHasKey(ArchiveService::class, $defs);

        self::assertInstanceOf(FactoryDefinition::class, $defs[ArchiveServiceInterface::class]);

        $alias = $defs[ArchiveService::class];
        self::assertInstanceOf(AliasDefinition::class, $alias);
        self::assertSame(ArchiveServiceInterface::class, $alias->getTarget());
    }

    /**
     * Discovery-path guard. Loads the provider the way ContainerFactory::loadExtensionDefinitions
     * does: a `defs()` map passes through as DefinitionInterface objects; a `services()` map is
     * compiled by DefaultServicesLoader, which REJECTS non-array specs. Fails loudly if typed
     * Definition objects are ever returned from `services()` (they belong in `defs()`) — a
     * regression the Container::load()-based tests cannot catch.
     */
    public function testLoadsThroughExtensionDiscoveryDispatch(): void
    {
        $provider = ArchiveServiceProvider::class;

        if (method_exists($provider, 'defs')) {
            $defs = (array) $provider::defs();
        } else {
            $defs = (new DefaultServicesLoader())->load($provider::services(), $provider, false);
        }

        self::assertNotEmpty($defs);
        self::assertArrayHasKey(ArchiveServiceInterface::class, $defs);
        foreach ($defs as $id => $def) {
            self::assertInstanceOf(
                DefinitionInterface::class,
                $def,
                "Definition for '{$id}' must be a DefinitionInterface after discovery-path loading"
            );
        }
    }

    public function testFactoryResolvesArchiveServiceFromContainer(): void
    {
        $sentinel = sys_get_temp_dir() . '/archive-' . uniqid('', true);
        $context = $this->makeContext(['storage' => ['path' => $sentinel]]);
        $container = $this->makeContainer($context);

        $defs = ArchiveServiceProvider::defs();
        /** @var FactoryDefinition $factory */
        $factory = $defs[ArchiveServiceInterface::class];

        $service = $factory->resolve($container);

        self::assertInstanceOf(ArchiveService::class, $service);
    }

    /**
     * Proves the config-key fix (Decision §8): the legacy provider passed
     * 'archive.config' (a key that does not exist → always []) and never passed
     * the ApplicationContext, so a configured archive.storage.path could never
     * reach the service. The fixed provider passes the full 'archive' config and
     * the context, so the service's getConfig('archive.storage.path') resolves to
     * the configured value. We assert via the service's effective base path.
     */
    public function testConfiguredStoragePathReachesService(): void
    {
        $sentinel = sys_get_temp_dir() . '/archive-sentinel-' . uniqid('', true);
        $this->tmpDirs[] = $sentinel;

        $context = $this->makeContext(['storage' => ['path' => $sentinel]]);
        $container = $this->makeContainer($context);

        $defs = ArchiveServiceProvider::defs();
        /** @var FactoryDefinition $factory */
        $factory = $defs[ArchiveServiceInterface::class];

        /** @var ArchiveService $service */
        $service = $factory->resolve($container);

        $ref = new \ReflectionProperty(ArchiveService::class, 'archiveBasePath');
        $ref->setAccessible(true);

        self::assertSame(
            $sentinel,
            $ref->getValue($service),
            'Configured archive.storage.path must reach the service via the passed context.'
        );
    }

    public function testBootDoesNotLoadMigrationsWhenDisabled(): void
    {
        $migrationManager = $this->createMock(MigrationManager::class);
        $migrationManager->expects(self::never())->method('addMigrationPath');

        $context = $this->makeContext([
            'enabled' => false,
            'storage' => ['path' => sys_get_temp_dir() . '/archive-noop'],
        ]);
        $container = $this->makeContainer($context, $migrationManager);

        $provider = new ArchiveServiceProvider($container);
        $provider->register($context);
        $provider->boot($context);
    }

    public function testBootLoadsMigrationsWithCorrectSourceAndPriorityWhenEnabled(): void
    {
        $migrationsDir = realpath(__DIR__ . '/../../migrations');
        self::assertIsString($migrationsDir);

        $migrationManager = $this->createMock(MigrationManager::class);
        $migrationManager->expects(self::once())
            ->method('addMigrationPath')
            ->with(
                self::callback(static fn (string $dir): bool => realpath($dir) === $migrationsDir),
                self::identicalTo(MigrationPriority::DEFAULT),
                self::identicalTo('glueful/archive')
            );

        $context = $this->makeContext([
            'enabled' => true,
            'storage' => ['path' => sys_get_temp_dir() . '/archive-noop'],
        ]);
        $container = $this->makeContainer($context, $migrationManager);

        $provider = new ArchiveServiceProvider($container);
        $provider->register($context);
        $provider->boot($context);
    }

    /**
     * Build a real ApplicationContext whose 'archive' config is supplied by a
     * ConfigurationLoader (modelling app/file config). This is the layer that
     * WINS over extension defaults merged via the provider's register()/
     * mergeConfig() — so a gate value set here survives register(), exactly as
     * a real app's config would.
     *
     * @param array<string, mixed> $archiveConfig
     */
    private function makeContext(array $archiveConfig): ApplicationContext
    {
        $context = new ApplicationContext(sys_get_temp_dir(), 'testing');
        $context->setConfigLoader($this->makeConfigLoader($archiveConfig));

        return $context;
    }

    /**
     * @param array<string, mixed> $archiveConfig
     */
    private function makeConfigLoader(array $archiveConfig): ConfigurationLoader
    {
        return new class ($archiveConfig) extends ConfigurationLoader {
            /** @param array<string, mixed> $archiveConfig */
            public function __construct(private array $archiveConfig)
            {
            }

            /** @return array<string, mixed> */
            public function loadConfig(string $name): array
            {
                return $name === 'archive' ? $this->archiveConfig : [];
            }
        };
    }

    /**
     * Minimal PSR-11 container that answers the keys the provider/factory use.
     */
    private function makeContainer(
        ApplicationContext $context,
        ?MigrationManager $migrationManager = null
    ): ContainerInterface {
        $services = [
            ApplicationContext::class => $context,
            'database' => $this->createMock(Connection::class),
            SchemaBuilderInterface::class => $this->createMock(SchemaBuilderInterface::class),
            RandomStringGenerator::class => $this->createMock(RandomStringGenerator::class),
        ];

        if ($migrationManager !== null) {
            $services[MigrationManager::class] = $migrationManager;
        }

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

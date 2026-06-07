<?php

declare(strict_types=1);

namespace Glueful\Extensions\Archive\Tests\Unit;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Glueful\Extensions\Archive\ArchiveServiceInterface;
use Glueful\Extensions\Archive\ArchiveServiceProvider;
use Glueful\Extensions\Archive\Console\ManageCommand;
use Glueful\Extensions\ServiceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;

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

    private function makeContext(): ApplicationContext
    {
        return new ApplicationContext(sys_get_temp_dir(), 'testing');
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

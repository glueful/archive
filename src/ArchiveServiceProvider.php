<?php

declare(strict_types=1);

namespace Glueful\Extensions\Archive;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Definition\AliasDefinition;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Security\RandomStringGenerator;
use Psr\Container\ContainerInterface;

final class ArchiveServiceProvider extends \Glueful\Extensions\ServiceProvider
{
    /**
     * @return array<string, mixed>
     */
    public static function services(): array
    {
        $defs = [];

        $defs[ArchiveServiceInterface::class] = new FactoryDefinition(
            ArchiveServiceInterface::class,
            static function (ContainerInterface $c): ArchiveService {
                // Resolve context so the service's getConfig() reads merged 'archive' config.
                $context = $c->get(ApplicationContext::class);

                // Config-key fix (Decision §8): the legacy provider passed the
                // nonexistent key 'archive.config' (always []), so configuration
                // never reached the service. Pass the full 'archive' config tree,
                // and pass the context so ArchiveService::getConfig() can resolve
                // canonical nested keys (e.g. archive.storage.path).
                $cfg = (array) config($context, 'archive', []);

                return new ArchiveService(
                    $c->get('database'),
                    $c->get(SchemaBuilderInterface::class),
                    $c->get(RandomStringGenerator::class),
                    $cfg,
                    $context
                );
            }
        );

        $defs[ArchiveService::class] = new AliasDefinition(
            ArchiveService::class,
            ArchiveServiceInterface::class
        );

        return $defs;
    }

    public function register(ApplicationContext $context): void
    {
        $this->mergeConfig('archive', require __DIR__ . '/../config/archive.php');

        // Self-gated schema: only register migrations when the opt-in gate is on.
        if ((bool) config($context, 'archive.enabled', false) === true) {
            $this->loadMigrationsFrom(
                __DIR__ . '/../migrations',
                MigrationPriority::DEFAULT,
                'glueful/archive'
            );
        }
    }

    public function boot(ApplicationContext $context): void
    {
        $this->discoverCommands('Glueful\\Extensions\\Archive\\Console', __DIR__ . '/Console');
    }
}

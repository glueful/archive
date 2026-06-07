<?php

declare(strict_types=1);

namespace Glueful\Extensions\Archive;

use Glueful\Bootstrap\ApplicationContext;

final class ArchiveServiceProvider extends \Glueful\Extensions\ServiceProvider
{
    public static function services(): array
    {
        return [];
    }

    public function register(ApplicationContext $context): void
    {
    }

    public function boot(ApplicationContext $context): void
    {
    }
}

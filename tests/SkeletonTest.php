<?php

declare(strict_types=1);

namespace Glueful\Extensions\Archive\Tests;

use Glueful\Extensions\Archive\ArchiveServiceProvider;
use Glueful\Extensions\ServiceProvider;
use PHPUnit\Framework\TestCase;

final class SkeletonTest extends TestCase
{
    public function testProviderExistsAndIsAServiceProvider(): void
    {
        self::assertTrue(class_exists(ArchiveServiceProvider::class));
        self::assertTrue(is_subclass_of(ArchiveServiceProvider::class, ServiceProvider::class));
    }
}

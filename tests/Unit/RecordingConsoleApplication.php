<?php

declare(strict_types=1);

namespace Glueful\Extensions\Archive\Tests\Unit;

/**
 * Minimal stand-in for the framework's console application.
 *
 * discoverCommands() resolves 'console.application' from the container and calls
 * add() for each discovered command. This fake records what was added so tests
 * can assert registration faithfully, without booting a real ConsoleApplication.
 */
final class RecordingConsoleApplication
{
    /** @var list<object> */
    public array $added = [];

    public function add(object $command): object
    {
        $this->added[] = $command;

        return $command;
    }
}

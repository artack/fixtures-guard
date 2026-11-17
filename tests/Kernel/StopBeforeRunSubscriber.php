<?php

declare(strict_types=1);

namespace Artack\FixturesGuard\Tests\Kernel;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Disables every command after the guard let it through, so a test sees the exit code 113 instead of a
 * command that touches a database.
 */
final class StopBeforeRunSubscriber implements EventSubscriberInterface
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => ['onConsoleCommand', -1024],
        ];
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $event->disableCommand();
    }
}

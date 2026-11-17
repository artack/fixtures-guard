<?php

declare(strict_types=1);

namespace Artack\FixturesGuard\Tests\Kernel;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Stands in for a command of a project that loads the fixtures itself and so needs additional_commands.
 */
#[AsCommand(name: 'app:db:reset', description: 'Rebuilds the database with the fixtures.')]
final class ResetDatabaseCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return Command::SUCCESS;
    }
}

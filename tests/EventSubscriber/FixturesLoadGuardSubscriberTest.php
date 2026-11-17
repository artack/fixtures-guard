<?php

declare(strict_types=1);

namespace Artack\FixturesGuard\Tests\EventSubscriber;

use Artack\FixturesGuard\EventSubscriber\FixturesLoadGuardSubscriber;
use Artack\FixturesGuard\Exception\FixturesLoadBlockedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

#[CoversClass(FixturesLoadGuardSubscriber::class)]
#[CoversClass(FixturesLoadBlockedException::class)]
final class FixturesLoadGuardSubscriberTest extends TestCase
{
    public function testListensToTheConsoleCommandEvent(): void
    {
        self::assertSame([ConsoleEvents::COMMAND => 'onConsoleCommand'], FixturesLoadGuardSubscriber::getSubscribedEvents());
    }

    /**
     * @param list<string> $aliases
     */
    #[DataProvider('provideGuardedCommands')]
    public function testStopsTheGuardedCommandsOnProd(?string $name, array $aliases): void
    {
        $this->expectException(FixturesLoadBlockedException::class);

        $this->createSubscriber('prod', 'prod')->onConsoleCommand($this->createEvent($name, $aliases));
    }

    /**
     * @return iterable<string, array{?string, list<string>}>
     */
    public static function provideGuardedCommands(): iterable
    {
        yield 'doctrine fixtures bundle' => ['doctrine:fixtures:load', []];
        yield 'foundry' => ['foundry:load-fixtures', ['foundry:load-stories', 'foundry:load-story']];
        yield 'foundry before 2.6.1' => ['foundry:load-stories', []];
        yield 'a future command of a guarded namespace' => ['foundry:reset', []];
        yield 'guarded by an alias only' => ['app:fixtures', ['doctrine:fixtures:load']];
        yield 'without a name, guarded by an alias' => [null, ['foundry:load-fixtures']];
    }

    /**
     * @param list<string> $aliases
     */
    #[DataProvider('provideUnguardedCommands')]
    public function testLeavesOtherCommandsAloneOnProd(?string $name, array $aliases): void
    {
        $this->expectNotToPerformAssertions();

        $this->createSubscriber('prod', 'prod', additionalCommands: ['app:db:reset'])->onConsoleCommand($this->createEvent($name, $aliases));
    }

    /**
     * @return iterable<string, array{?string, list<string>}>
     */
    public static function provideUnguardedCommands(): iterable
    {
        yield 'migrations' => ['doctrine:migrations:migrate', []];
        yield 'a namespace that only starts like a guarded one' => ['doctrine:fixtures', []];
        yield 'foundry makers' => ['make:factory', []];
        yield 'only the exact additional name counts' => ['app:db:reset-cache', []];
        yield 'without a name and aliases' => [null, []];
    }

    public function testLeavesTheEventAloneWithoutCommand(): void
    {
        $this->expectNotToPerformAssertions();

        $this->createSubscriber('prod', 'prod')->onConsoleCommand(new ConsoleCommandEvent(null, new ArrayInput([]), new NullOutput()));
    }

    public function testStopsTheAdditionalCommandsOnProd(): void
    {
        $this->expectException(FixturesLoadBlockedException::class);

        $this->createSubscriber('prod', 'prod', additionalCommands: ['app:db:reset'])->onConsoleCommand($this->createEvent('app:db:reset'));
    }

    public function testStopsTheAdditionalCommandsUnderAnAlias(): void
    {
        $this->expectException(FixturesLoadBlockedException::class);

        $this->createSubscriber('prod', 'prod', additionalCommands: ['app:db:reset'])->onConsoleCommand($this->createEvent('app:database:reset', ['app:db:reset']));
    }

    /**
     * @param list<string> $resettableStages
     */
    #[DataProvider('provideEnvironmentsThatMayReplaceTheDatabase')]
    public function testLetsTheFixturesLoadWhereTheDatabaseMayBeReplaced(string $kernelEnvironment, ?string $stage, array $resettableStages): void
    {
        $this->expectNotToPerformAssertions();

        $this->createSubscriber($kernelEnvironment, $stage, $resettableStages)->onConsoleCommand($this->createEvent('doctrine:fixtures:load'));
    }

    /**
     * @return iterable<string, array{string, ?string, list<string>}>
     */
    public static function provideEnvironmentsThatMayReplaceTheDatabase(): iterable
    {
        yield 'local development' => ['dev', 'dev', ['stag']];
        yield 'tests' => ['test', 'test', ['stag']];
        yield 'tests with the dev stage of .env' => ['test', 'dev', ['stag']];
        yield 'dev kernel with the test stage' => ['dev', 'test', ['stag']];
        yield 'local without resettable stages' => ['dev', 'dev', []];
        yield 'stag, which runs the prod kernel' => ['prod', 'stag', ['stag']];
        yield 'another resettable stage' => ['prod', 'review', ['stag', 'review']];
    }

    /**
     * @param list<string> $resettableStages
     */
    #[DataProvider('provideEnvironmentsWhereTheDataMustStay')]
    public function testStopsTheFixturesWhereTheDataMustStay(string $kernelEnvironment, ?string $stage, array $resettableStages): void
    {
        $this->expectException(FixturesLoadBlockedException::class);

        $this->createSubscriber($kernelEnvironment, $stage, $resettableStages)->onConsoleCommand($this->createEvent('doctrine:fixtures:load'));
    }

    /**
     * @return iterable<string, array{string, ?string, list<string>}>
     */
    public static function provideEnvironmentsWhereTheDataMustStay(): iterable
    {
        yield 'prod' => ['prod', 'prod', ['stag']];
        yield 'prod kernel with the dev stage of .env' => ['prod', 'dev', ['stag']];
        yield 'prod kernel with the test stage' => ['prod', 'test', ['stag']];
        yield 'prod kernel without stage' => ['prod', null, ['stag']];
        yield 'prod kernel with an empty stage' => ['prod', '', ['stag']];
        yield 'prod kernel with an unknown stage' => ['prod', 'staging', ['stag']];
        yield 'stag without resettable stages' => ['prod', 'stag', []];
        yield 'dev kernel on prod' => ['dev', 'prod', ['stag']];
        yield 'dev kernel without stage' => ['dev', null, ['stag']];
        yield 'an unknown kernel environment with the dev stage' => ['staging', 'dev', ['stag']];
    }

    /**
     * @param list<string> $resettableStages
     */
    #[DataProvider('provideMessages')]
    public function testTellsWhereTheCommandMayRun(string $stageEnv, ?string $stage, array $resettableStages, string $expectedMessage): void
    {
        $this->expectException(FixturesLoadBlockedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        new FixturesLoadGuardSubscriber('prod', $stageEnv, $stage, $resettableStages, [])->onConsoleCommand($this->createEvent('foundry:load-fixtures', ['foundry:load-stories']));
    }

    /**
     * @return iterable<string, array{string, ?string, list<string>, string}>
     */
    public static function provideMessages(): iterable
    {
        yield 'one resettable stage' => [
            'APP_STAGE',
            'prod',
            ['stag'],
            'artack/fixtures-guard stopped "foundry:load-fixtures": it replaces the database, which may only happen locally (kernel environment and APP_STAGE both "dev" or "test") or where APP_STAGE is "stag". Here the kernel environment is "prod" and APP_STAGE is "prod".',
        ];
        yield 'several resettable stages and another variable' => [
            'DEPLOY_STAGE',
            'prod',
            ['stag', 'review'],
            'artack/fixtures-guard stopped "foundry:load-fixtures": it replaces the database, which may only happen locally (kernel environment and DEPLOY_STAGE both "dev" or "test") or where DEPLOY_STAGE is "stag" or "review". Here the kernel environment is "prod" and DEPLOY_STAGE is "prod".',
        ];
        yield 'no resettable stage and no stage' => [
            'APP_STAGE',
            null,
            [],
            'artack/fixtures-guard stopped "foundry:load-fixtures": it replaces the database, which may only happen locally (kernel environment and APP_STAGE both "dev" or "test"). Here the kernel environment is "prod" and APP_STAGE is not set.',
        ];
    }

    /**
     * @param list<string> $resettableStages
     * @param list<string> $additionalCommands
     */
    private function createSubscriber(string $kernelEnvironment, ?string $stage, array $resettableStages = ['stag'], array $additionalCommands = []): FixturesLoadGuardSubscriber
    {
        return new FixturesLoadGuardSubscriber($kernelEnvironment, 'APP_STAGE', $stage, $resettableStages, $additionalCommands);
    }

    /**
     * @param list<string> $aliases
     */
    private function createEvent(?string $name, array $aliases = []): ConsoleCommandEvent
    {
        $command = new Command($name);
        $command->setAliases($aliases);

        return new ConsoleCommandEvent($command, new ArrayInput([]), new NullOutput());
    }
}

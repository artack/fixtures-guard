<?php

declare(strict_types=1);

namespace Artack\FixturesGuard\Tests;

use Artack\FixturesGuard\EventSubscriber\FixturesLoadGuardSubscriber;
use Artack\FixturesGuard\Exception\FixturesLoadBlockedException;
use Artack\FixturesGuard\FixturesGuardBundle;
use Artack\FixturesGuard\Tests\Kernel\TestKernel;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;

use function get_error_handler;
use function get_exception_handler;
use function getenv;
use function putenv;
use function restore_error_handler;
use function restore_exception_handler;

/**
 * Runs the commands of the installed fixture bundles through the real console, so the tests fail when a
 * bundle renames its command or the guard is no longer wired to the console events.
 */
#[CoversClass(FixturesGuardBundle::class)]
#[CoversClass(FixturesLoadGuardSubscriber::class)]
#[CoversClass(FixturesLoadBlockedException::class)]
final class ConsoleTest extends TestCase
{
    /**
     * @var array<string, array{mixed, mixed, string|false}>
     */
    private array $originalEnv = [];

    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    #[Override]
    public static function setUpBeforeClass(): void
    {
        // the kernel runs without debug and so would not notice a changed bundle in an old cache
        new Filesystem()->remove(TestKernel::CACHE_DIR);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->errorHandler = get_error_handler();
        $this->exceptionHandler = get_exception_handler();
    }

    #[Override]
    protected function tearDown(): void
    {
        // FrameworkBundle registers Symfony's error handler on every boot when symfony/runtime is missing
        while (get_error_handler() !== $this->errorHandler) {
            restore_error_handler();
        }

        while (get_exception_handler() !== $this->exceptionHandler) {
            restore_exception_handler();
        }

        foreach ($this->originalEnv as $name => [$env, $server, $getenv]) {
            unset($_ENV[$name], $_SERVER[$name]);

            if (null !== $env) {
                $_ENV[$name] = $env;
            }

            if (null !== $server) {
                $_SERVER[$name] = $server;
            }

            putenv(false === $getenv ? $name : $name.'='.$getenv);
        }

        $this->originalEnv = [];
    }

    #[DataProvider('provideFixtureCommands')]
    public function testStopsTheFixtureCommandsOnProd(string $command): void
    {
        $this->setEnv('APP_STAGE', 'prod');

        $this->expectException(FixturesLoadBlockedException::class);
        $this->expectExceptionMessageIsOrContains('Here the kernel environment is "prod" and APP_STAGE is "prod".');

        $this->runCommand('prod', $command);
    }

    #[DataProvider('provideFixtureCommands')]
    public function testLetsTheFixtureCommandsRunOnStag(string $command): void
    {
        $this->setEnv('APP_STAGE', 'stag');

        self::assertSame(ConsoleCommandEvent::RETURN_CODE_DISABLED, $this->runCommand('prod', $command));
    }

    #[DataProvider('provideFixtureCommands')]
    public function testLetsTheFixtureCommandsRunLocally(string $command): void
    {
        $this->setEnv('APP_STAGE', 'dev');

        self::assertSame(ConsoleCommandEvent::RETURN_CODE_DISABLED, $this->runCommand('dev', $command));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideFixtureCommands(): iterable
    {
        yield 'doctrine fixtures bundle' => ['doctrine:fixtures:load'];
        yield 'doctrine fixtures bundle, abbreviated' => ['d:f:l'];
        yield 'foundry' => ['foundry:load-fixtures'];
        yield 'foundry, alias load-stories' => ['foundry:load-stories'];
        yield 'foundry, alias load-story' => ['foundry:load-story'];
    }

    public function testLeavesOtherCommandsAloneOnProd(): void
    {
        $this->setEnv('APP_STAGE', 'prod');

        self::assertSame(ConsoleCommandEvent::RETURN_CODE_DISABLED, $this->runCommand('prod', 'about'));
    }

    public function testStopsTheFixturesWithoutStage(): void
    {
        $this->unsetEnv('APP_STAGE');

        $this->expectException(FixturesLoadBlockedException::class);
        $this->expectExceptionMessageIsOrContains('APP_STAGE is not set.');

        $this->runCommand('prod', 'doctrine:fixtures:load');
    }

    public function testLeavesOtherCommandsAloneWithoutStage(): void
    {
        $this->unsetEnv('APP_STAGE');

        self::assertSame(ConsoleCommandEvent::RETURN_CODE_DISABLED, $this->runCommand('prod', 'about'));
    }

    public function testStopsTheAdditionalCommandsOnProd(): void
    {
        $this->setEnv('APP_STAGE', 'prod');

        $this->expectException(FixturesLoadBlockedException::class);

        $this->runCommand('prod', 'app:db:reset', ['additional_commands' => ['app:db:reset']]);
    }

    public function testLetsTheAdditionalCommandsRunOnStag(): void
    {
        $this->setEnv('APP_STAGE', 'stag');

        self::assertSame(ConsoleCommandEvent::RETURN_CODE_DISABLED, $this->runCommand('prod', 'app:db:reset', ['additional_commands' => ['app:db:reset']]));
    }

    public function testReadsTheStageFromTheConfiguredVariable(): void
    {
        $this->setEnv('APP_STAGE', 'prod');
        $this->setEnv('DEPLOY_STAGE', 'stag');

        self::assertSame(ConsoleCommandEvent::RETURN_CODE_DISABLED, $this->runCommand('prod', 'doctrine:fixtures:load', ['stage_env' => 'DEPLOY_STAGE']));
    }

    public function testIgnoresTheDefaultVariableWhenAnotherIsConfigured(): void
    {
        $this->setEnv('APP_STAGE', 'stag');
        $this->unsetEnv('DEPLOY_STAGE');

        $this->expectException(FixturesLoadBlockedException::class);
        $this->expectExceptionMessageIsOrContains('DEPLOY_STAGE is not set.');

        $this->runCommand('prod', 'doctrine:fixtures:load', ['stage_env' => 'DEPLOY_STAGE']);
    }

    public function testLetsTheFixturesRunOnTheConfiguredStages(): void
    {
        $this->setEnv('APP_STAGE', 'review');

        self::assertSame(ConsoleCommandEvent::RETURN_CODE_DISABLED, $this->runCommand('prod', 'foundry:load-fixtures', ['resettable_stages' => ['review']]));
    }

    public function testConfiguredStagesReplaceTheDefault(): void
    {
        $this->setEnv('APP_STAGE', 'stag');

        $this->expectException(FixturesLoadBlockedException::class);

        $this->runCommand('prod', 'foundry:load-fixtures', ['resettable_stages' => ['review']]);
    }

    public function testStopsTheFixturesOnEveryServerWithoutResettableStages(): void
    {
        $this->setEnv('APP_STAGE', 'stag');

        $this->expectException(FixturesLoadBlockedException::class);

        $this->runCommand('prod', 'foundry:load-fixtures', ['resettable_stages' => []]);
    }

    /**
     * @param array<string, mixed> $guardConfig
     */
    #[DataProvider('provideInvalidConfigs')]
    public function testRejectsAnInvalidConfig(array $guardConfig, string $expectedMessage): void
    {
        $this->setEnv('APP_STAGE', 'prod');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains($expectedMessage);

        $this->runCommand('prod', 'about', $guardConfig);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidConfigs(): iterable
    {
        yield 'an empty variable name' => [['stage_env' => ''], 'artack_fixtures_guard.stage_env'];
        yield 'dev as resettable stage' => [['resettable_stages' => ['stag', 'dev']], 'must not contain dev or test'];
        yield 'test as resettable stage' => [['resettable_stages' => ['test']], 'must not contain dev or test'];
        yield 'an empty resettable stage' => [['resettable_stages' => ['']], 'artack_fixtures_guard.resettable_stages'];
        yield 'an empty additional command' => [['additional_commands' => ['']], 'artack_fixtures_guard.additional_commands'];
    }

    /**
     * @param array<string, mixed> $guardConfig
     */
    private function runCommand(string $kernelEnvironment, string $command, array $guardConfig = []): int
    {
        $kernel = new TestKernel($kernelEnvironment, $guardConfig);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        try {
            return $application->run(new ArrayInput(['command' => $command, '--no-interaction' => true]), new BufferedOutput());
        } finally {
            $kernel->shutdown();
        }
    }

    private function setEnv(string $name, string $value): void
    {
        $this->rememberEnv($name);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name.'='.$value);
    }

    private function unsetEnv(string $name): void
    {
        $this->rememberEnv($name);
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);
    }

    private function rememberEnv(string $name): void
    {
        $this->originalEnv[$name] ??= [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
    }
}

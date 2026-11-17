<?php

declare(strict_types=1);

namespace Artack\FixturesGuard\Tests\Kernel;

use Artack\FixturesGuard\FixturesGuardBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\FixturesBundle\DoctrineFixturesBundle;
use Override;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Zenstruck\Foundry\ZenstruckFoundryBundle;

use function dirname;
use function md5;
use function serialize;

/**
 * A kernel with the real fixture bundles. No command reaches its database: StopBeforeRunSubscriber disables
 * every command the guard lets through.
 */
final class TestKernel extends Kernel
{
    public const string CACHE_DIR = __DIR__.'/../../build/test-kernel';

    /**
     * @param array<string, mixed> $guardConfig
     */
    public function __construct(
        string $environment,
        private readonly array $guardConfig = [],
    ) {
        parent::__construct($environment, false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new DoctrineFixturesBundle();
        yield new ZenstruckFoundryBundle();
        yield new FixturesGuardBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'fixtures-guard',
                'router' => ['enabled' => false, 'resource' => 'unused'],
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
            ]);
            $container->loadFromExtension('doctrine', [
                'dbal' => ['url' => 'sqlite:///:memory:'],
                'orm' => [],
            ]);

            if ([] !== $this->guardConfig) {
                $container->loadFromExtension('artack_fixtures_guard', $this->guardConfig);
            }

            // keeps the console error listener from writing the stopped commands to stderr
            $container->register('logger', NullLogger::class);
            $container->register(StopBeforeRunSubscriber::class)->addTag('kernel.event_subscriber');
            $container->register(ResetDatabaseCommand::class)->addTag('console.command');
        });
    }

    #[Override]
    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    #[Override]
    public function getCacheDir(): string
    {
        return self::CACHE_DIR.'/'.$this->environment.'-'.md5(serialize($this->guardConfig)).'/cache';
    }

    #[Override]
    public function getLogDir(): string
    {
        return self::CACHE_DIR.'/'.$this->environment.'-'.md5(serialize($this->guardConfig)).'/log';
    }
}

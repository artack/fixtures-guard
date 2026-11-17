<?php

declare(strict_types=1);

namespace Artack\FixturesGuard;

use Artack\FixturesGuard\EventSubscriber\FixturesLoadGuardSubscriber;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function array_any;
use function assert;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;

final class FixturesGuardBundle extends AbstractBundle
{
    protected string $extensionAlias = 'artack_fixtures_guard';

    public function configure(DefinitionConfigurator $definition): void
    {
        $rootNode = $definition->rootNode();
        assert($rootNode instanceof ArrayNodeDefinition);

        $rootNode
            ->children()
                ->scalarNode('stage_env')
                    ->info('Name of the environment variable that holds the stage of the server.')
                    ->defaultValue('APP_STAGE')
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('resettable_stages')
                    ->info('Stages whose database may be rebuilt with fixtures. dev and test only count together with a dev or test kernel, so they are not allowed here.')
                    ->defaultValue(['stag'])
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->validate()
                        ->ifTrue(static fn (mixed $stages): bool => is_array($stages) && array_any($stages, static fn (mixed $stage): bool => in_array($stage, FixturesLoadGuardSubscriber::LOCAL_ENVIRONMENTS, true)))
                        ->thenInvalid(sprintf('%%s must not contain %s: a server that misses its own stage falls back to the one in .env, which would then allow fixtures on it.', implode(' or ', FixturesLoadGuardSubscriber::LOCAL_ENVIRONMENTS)))
                    ->end()
                ->end()
                ->arrayNode('additional_commands')
                    ->info('Exact names of further commands to guard, e.g. a command of the project that loads the fixtures itself.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
            ->end();
    }

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $stageEnv = $config['stage_env'];
        assert(is_string($stageEnv));

        $container->services()
            ->set('artack_fixtures_guard.subscriber', FixturesLoadGuardSubscriber::class)
            ->args([
                '%kernel.environment%',
                $stageEnv,
                sprintf('%%env(default::%s)%%', $stageEnv),
                $config['resettable_stages'],
                $config['additional_commands'],
            ])
            ->tag('kernel.event_subscriber');
    }
}

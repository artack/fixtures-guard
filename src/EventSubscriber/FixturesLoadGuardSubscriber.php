<?php

declare(strict_types=1);

namespace Artack\FixturesGuard\EventSubscriber;

use Artack\FixturesGuard\Exception\FixturesLoadBlockedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function array_any;
use function array_map;
use function implode;
use function in_array;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * Stops the commands that empty the database and load fixtures before they run where the data must stay.
 *
 * They may only run locally (kernel environment and stage both dev or test) or on a stage whose database
 * may be rebuilt. A server that misses its own stage falls back to the one in .env, usually dev - hence a
 * dev or test stage only counts together with a dev or test kernel.
 */
final readonly class FixturesLoadGuardSubscriber implements EventSubscriberInterface
{
    /**
     * Every command of these namespaces counts, under its name or any of its aliases, so a renamed command
     * stays guarded.
     *
     * @var list<string>
     */
    public const array GUARDED_PREFIXES = ['doctrine:fixtures:', 'foundry:'];

    /**
     * @var list<string>
     */
    public const array LOCAL_ENVIRONMENTS = ['dev', 'test'];

    /**
     * @param list<string> $resettableStages   stages whose database may be rebuilt
     * @param list<string> $additionalCommands exact names of further commands to guard
     */
    public function __construct(
        private string $kernelEnvironment,
        private string $stageEnv,
        private ?string $stage,
        private array $resettableStages,
        private array $additionalCommands,
    ) {}

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => 'onConsoleCommand',
        ];
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();

        if (null === $command || !$this->isGuarded($command) || $this->mayReplaceDatabase()) {
            return;
        }

        throw new FixturesLoadBlockedException(sprintf('artack/fixtures-guard stopped "%s": it replaces the database, which may only happen %s. Here the kernel environment is "%s" and %s.', $command->getName(), $this->describeAllowed(), $this->kernelEnvironment, $this->describeStage()));
    }

    private function isGuarded(Command $command): bool
    {
        foreach ([$command->getName(), ...$command->getAliases()] as $name) {
            if (!is_string($name)) {
                continue;
            }

            if (in_array($name, $this->additionalCommands, true)
                || array_any(self::GUARDED_PREFIXES, static fn (string $prefix): bool => str_starts_with($name, $prefix))) {
                return true;
            }
        }

        return false;
    }

    private function mayReplaceDatabase(): bool
    {
        if (in_array($this->stage, $this->resettableStages, true)) {
            return true;
        }

        return in_array($this->kernelEnvironment, self::LOCAL_ENVIRONMENTS, true)
            && in_array($this->stage, self::LOCAL_ENVIRONMENTS, true);
    }

    private function describeAllowed(): string
    {
        $local = sprintf('locally (kernel environment and %s both "dev" or "test")', $this->stageEnv);

        if ([] === $this->resettableStages) {
            return $local;
        }

        return sprintf('%s or where %s is %s', $local, $this->stageEnv, implode(' or ', array_map(static fn (string $stage): string => sprintf('"%s"', $stage), $this->resettableStages)));
    }

    private function describeStage(): string
    {
        if (null === $this->stage) {
            return sprintf('%s is not set', $this->stageEnv);
        }

        return sprintf('%s is "%s"', $this->stageEnv, $this->stage);
    }
}

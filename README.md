artack/fixtures-guard
=====================

> Symfony bundle that stops Doctrine fixtures and Foundry from wiping a database whose data must stay.

[![Latest Release](https://img.shields.io/packagist/v/artack/fixtures-guard.svg)](https://packagist.org/packages/artack/fixtures-guard)
[![CI](https://github.com/artack/fixtures-guard/actions/workflows/ci.yaml/badge.svg)](https://github.com/artack/fixtures-guard/actions/workflows/ci.yaml)
[![MIT License](https://img.shields.io/packagist/l/artack/fixtures-guard.svg)](LICENSE)
[![Total Downloads](https://img.shields.io/packagist/dt/artack/fixtures-guard.svg)](https://packagist.org/packages/artack/fixtures-guard)

Developed by [ARTACK WebLab GmbH](https://www.artack.ch) in Zurich, Switzerland.

Why
---

`doctrine:fixtures:load` and Foundry's `foundry:load-fixtures` empty the database before they load. As long as the fixture bundles are dev dependencies, production servers don't have these commands. Once a server rebuilds its database from fixtures, for instance a staging server that runs the `prod` kernel, the bundles move to `require`. From then on every server has the commands, production included, and one `bin/console` on the wrong server wipes its data.

This bundle stops these commands before they start, unless the server's stage allows them.

Features
--------

- Stops the commands of the [Doctrine fixtures bundle](https://github.com/doctrine/DoctrineFixturesBundle) and [Foundry](https://github.com/zenstruck/foundry) wherever the data must stay, and lets them run locally and on the stages you name.
- Guards whole command namespaces (`doctrine:fixtures:*`, `foundry:*`) by name and by alias, so a renamed command stays guarded.
- Fails safe: without a stage, the commands are blocked rather than allowed.
- Throws an exception, so a blocked attempt shows up in your error tracking.
- Works without configuration.
- Requires PHP 8.5 or newer and Symfony 7.4 or 8.x.

Installation
------------

Install the bundle via [Composer](https://getcomposer.org):

```shell
$ composer require artack/fixtures-guard
```

> ⚠️ Require it in `require`, not in `require-dev`: the bundle has to run on the servers it protects. Symfony Flex registers it in `config/bundles.php` for all environments. Installed with `--dev`, Flex registers it for `dev` and `test` only, and production stays unprotected.

Without Flex, register the bundle yourself:

```php
// config/bundles.php
return [
    // ...
    Artack\FixturesGuard\FixturesGuardBundle::class => ['all' => true],
];
```

Then give every server its stage in the environment variable `APP_STAGE`, e.g. `APP_STAGE=prod` on production and `APP_STAGE=stag` on staging. Locally, `APP_STAGE=dev` in `.env` does.

How it decides
--------------

A guarded command runs if

1. the stage is one of the resettable stages (default: `stag`), or
2. the kernel environment and the stage are both `dev` or `test`.

Everywhere else the bundle throws `Artack\FixturesGuard\Exception\FixturesLoadBlockedException` before the command starts:

| Kernel environment | `APP_STAGE`                               | Fixtures |
|--------------------|-------------------------------------------|----------|
| `dev`              | `dev`                                     | run      |
| `test`             | `dev` or `test`                           | run      |
| `prod`             | `stag`                                    | run      |
| `prod`             | `prod`                                    | blocked  |
| `prod`             | `dev`, because the server misses its own  | blocked  |
| `prod`             | not set                                   | blocked  |
| `dev`              | `prod`                                    | blocked  |

Rule 2 needs both because `.env` usually ships `APP_STAGE=dev` with every release: a server process that misses its own stage reads `dev`, so the kernel environment has to agree.

```
artack/fixtures-guard stopped "foundry:load-fixtures": it replaces the database, which may only happen
locally (kernel environment and APP_STAGE both "dev" or "test") or where APP_STAGE is "stag".
Here the kernel environment is "prod" and APP_STAGE is "prod".
```

Configuration
-------------

The configuration is optional:

```yaml
# config/packages/artack_fixtures_guard.yaml
artack_fixtures_guard:
    stage_env: APP_STAGE        # the environment variable that holds the stage
    resettable_stages: [stag]   # stages whose database may be rebuilt; [] allows the fixtures only locally
    additional_commands: []     # exact names of further commands to guard
```

- `resettable_stages` replaces the default. It must not contain `dev` or `test`, see [How it decides](#how-it-decides).
- `additional_commands` can only add commands. The built-in namespaces always stay guarded.

Guarded commands
----------------

Every command whose name or one of its aliases starts with `doctrine:fixtures:` or `foundry:`, which today means:

- `doctrine:fixtures:load` of the Doctrine fixtures bundle,
- `foundry:load-fixtures` with its aliases `foundry:load-stories` and `foundry:load-story` (Foundry 2.6.1 and newer), and `foundry:load-stories` of Foundry 2.6.0.

Abbreviations such as `d:f:l` are covered too: Symfony resolves them before the guard sees the command.

Guard your own commands with `additional_commands`. A project command that loads the fixtures by calling another command's `run()` needs it: that call skips the console events, so the guard only sees your command. Add a test that runs it with a production stage and expects `FixturesLoadBlockedException`, because a typo in the name goes unnoticed otherwise.

Limits
------

The bundle protects against mistakes, not against intent:

- It knows the commands by name. A fixture command outside the two namespaces needs `additional_commands`.
- It doesn't guard other commands that destroy data, such as `doctrine:schema:drop` or `doctrine:database:drop`. You can add them to `additional_commands`, but that is your project's decision and not a promise of this bundle.
- It reads the stage from the environment, so `APP_STAGE=stag bin/console doctrine:fixtures:load` passes on production.
- It only guards the console. Code that empties the database outside a console command is not covered.

Development
-----------

All checks run in Docker with PHP 8.5 and 8.6:

```shell
$ make build    # build the images
$ make verify   # coding standard, the test matrix, PHPStan and the coverage gate
$ make help     # all targets
```

`make test` runs PHPUnit with PHP 8.5 and 8.6, each with the lowest and the highest dependencies. `make coverage` fails below 100% line and method coverage. GitHub Actions runs the same test matrix plus php-cs-fixer and PHPStan; the coverage gate only runs locally.

License
-------

This bundle is released under the [MIT License](LICENSE).

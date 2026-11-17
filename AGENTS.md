# AGENTS.md

`artack/fixtures-guard` is a Symfony bundle that stops the fixture commands where the data must stay. README.md describes the behaviour; keep it in sync when the rules change.

## Commands

Everything runs in Docker through `make`, the host's PHP is not needed. Run `make build` once.

- `make verify`: php-cs-fixer, the PHPUnit matrix, PHPStan and the coverage gate. Run it before every commit.
- `make test`: PHPUnit with PHP 8.5 and 8.6, each with the lowest and the highest dependencies, one after the other. It rewrites `vendor/` and ends with PHP 8.5 and the highest dependencies.
- `make test-php86-lowest` and the other three: a single run of the matrix.
- `make phpcs-fix`: fix the coding standard.
- `make coverage`: PHPUnit with pcov, fails below 100%.

## Rules

- Coverage of `src/` stays at 100% of lines and methods. CI does not check it, `make coverage` does.
- PHPUnit is strict about coverage metadata: every test class names the `src/` classes it runs with `#[CoversClass]`.
- PHPStan runs at level max on `src/` and `tests/`. Fix types instead of adding `ignoreErrors`.
- `tests/ConsoleTest.php` runs the real commands of `doctrine/doctrine-fixtures-bundle` and `zenstruck/foundry` through the console. When a bundle adds or renames a command, add its names to `provideFixtureCommands()`.
- `additional_commands` may only add commands. Don't add an option that removes or replaces the built-in namespaces.

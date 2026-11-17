<?php

declare(strict_types=1);

namespace Artack\FixturesGuard\Exception;

use RuntimeException;

/**
 * Thrown when a command that replaces the database is about to run where the data must stay.
 */
final class FixturesLoadBlockedException extends RuntimeException
{
}

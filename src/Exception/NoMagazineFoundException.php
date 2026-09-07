<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when an inbound ActivityPub object cannot be routed to any magazine:
 * it names no known audience/to/cc magazine, and the instance has no
 * 'random' magazine to fall back to.
 */
final class NoMagazineFoundException extends \Exception
{
}

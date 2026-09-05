<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Twig\Runtime\PushExtensionRuntime;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PushExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('push_application_server_key', [PushExtensionRuntime::class, 'applicationServerKey']),
        ];
    }
}

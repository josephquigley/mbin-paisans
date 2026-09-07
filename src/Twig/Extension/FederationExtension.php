<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Twig\Runtime\FederationExtensionRuntime;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class FederationExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('read_only_reply_notice', [FederationExtensionRuntime::class, 'readOnlyReplyNotice']),
        ];
    }
}

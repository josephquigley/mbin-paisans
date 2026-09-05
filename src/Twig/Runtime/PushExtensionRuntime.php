<?php

declare(strict_types=1);

namespace App\Twig\Runtime;

use App\Repository\SiteRepository;
use Twig\Extension\RuntimeExtensionInterface;

class PushExtensionRuntime implements RuntimeExtensionInterface
{
    public function __construct(private readonly SiteRepository $siteRepository)
    {
    }

    /**
     * The VAPID public key a browser needs to create a push subscription. Empty when
     * the instance has no keypair yet, in which case a subscription cannot be made
     * and the prompt that uses this must not offer one.
     */
    public function applicationServerKey(): string
    {
        $site = $this->siteRepository->findAll()[0] ?? null;

        if (null === $site) {
            return '';
        }

        return $site->pushPublicKey ?? '';
    }
}

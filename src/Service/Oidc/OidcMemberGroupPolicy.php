<?php

declare(strict_types=1);

namespace App\Service\Oidc;

use App\Provider\OidcResourceOwner;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Decides whether a login may enter this instance at all, from a group claim.
 *
 * Disabled unless OAUTH_OIDC_MEMBER_GROUP names a group, and that default is
 * the important one: an instance that does not configure this admits everyone
 * the provider was willing to issue a token to, which is how Mbin has always
 * behaved and what every instance that does its admission control at the
 * provider still wants.
 *
 * Where it is configured, it is a second lock rather than the only one. A
 * provider that restricts the client to a group refuses to issue a token to
 * anyone else, and that refusal is better: it happens before this application
 * sees the person at all. This gate exists for what that arrangement cannot
 * cover: a client restriction removed by accident, a second client pointed at
 * the same instance, a provider that has no such feature.
 *
 * **It fails closed**, which is the one place it differs from
 * OidcAdminGroupPolicy. That policy reads the same claim from the same places
 * and treats an unreadable answer as "do not promote", because the safe
 * default for granting power is to withhold it. Here the safe default for
 * granting entry is also to withhold it, and the two land on opposite
 * behaviour from identical input: no claim anywhere means no entry.
 *
 * That is a real risk and it is taken deliberately. A provider that silently
 * stops emitting the claim (a renamed group, a scope that stopped being
 * granted, a misconfigured second client) locks every member out at once.
 * OidcAuthenticator therefore exempts users who are already administrators of
 * this instance, so the people who would have to fix it can still get in. On
 * an instance with MBIN_SSO_ONLY_MODE there is no password login behind which
 * to shelter, and that exemption is the whole recovery path.
 */
class OidcMemberGroupPolicy
{
    private ?string $memberGroup;
    private LoggerInterface $logger;

    public function __construct(
        ?string $memberGroup,
        private readonly OidcGroupClaims $groupClaims,
        ?LoggerInterface $logger = null,
    ) {
        $memberGroup = trim((string) $memberGroup);
        $this->memberGroup = '' === $memberGroup ? null : $memberGroup;
        $this->logger = $logger ?? new NullLogger();
    }

    public function isEnabled(): bool
    {
        return null !== $this->memberGroup;
    }

    public function group(): ?string
    {
        return $this->memberGroup;
    }

    /**
     * @param array<string, mixed> $idTokenClaims
     */
    public function permits(array $idTokenClaims, OidcResourceOwner $resourceOwner): bool
    {
        if (null === $this->memberGroup) {
            return true;
        }

        $groups = $this->groupClaims->resolve($idTokenClaims, $resourceOwner);

        if (null === $groups) {
            $this->logger->warning('OIDC login refused: the id_token carries no groups claim and the userinfo endpoint is not HTTPS, so membership of {group} cannot be established', [
                'group' => $this->memberGroup,
            ]);

            return false;
        }

        return \in_array($this->memberGroup, $groups, true);
    }
}

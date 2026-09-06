<?php

declare(strict_types=1);

namespace App\Service\Oidc;

use App\Provider\OidcResourceOwner;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Decides whether a login should carry admin rights, from a group claim.
 *
 * Disabled unless OAUTH_OIDC_ADMIN_GROUP names a group. That default is the
 * important one: an instance that does not configure this keeps granting admin
 * the only way Mbin has ever granted it, with `bin/console mbin:user:admin`,
 * and no claim from any provider can change who its administrators are.
 *
 * Promotion only. Losing the group in the provider does not remove an existing
 * Mbin admin, and that asymmetry is deliberate rather than unfinished: on an
 * instance with MBIN_SSO_ONLY_MODE set there is no password login to fall back
 * on, so a provider that stops emitting the claim (a renamed group, a bad
 * migration, a scope that silently stopped being granted) would lock every
 * administrator out of their own instance at once. Removing admin is therefore
 * left as a local, deliberate act.
 *
 * Where the group list comes from, and how far it is trusted, is
 * OidcGroupClaims. What belongs here is only what this policy does when that
 * reader cannot answer: it declines to promote. OidcMemberGroupPolicy reads
 * exactly the same input and refuses the login instead, which is why the
 * reading is shared and the default is not.
 */
class OidcAdminGroupPolicy
{
    private ?string $adminGroup;
    private LoggerInterface $logger;

    public function __construct(
        ?string $adminGroup,
        private readonly OidcGroupClaims $groupClaims,
        ?LoggerInterface $logger = null,
    ) {
        $adminGroup = trim((string) $adminGroup);
        $this->adminGroup = '' === $adminGroup ? null : $adminGroup;
        $this->logger = $logger ?? new NullLogger();
    }

    public function isEnabled(): bool
    {
        return null !== $this->adminGroup;
    }

    public function group(): ?string
    {
        return $this->adminGroup;
    }

    /**
     * @param array<string, mixed> $idTokenClaims
     */
    public function shouldPromote(array $idTokenClaims, OidcResourceOwner $resourceOwner): bool
    {
        if (null === $this->adminGroup) {
            return false;
        }

        $groups = $this->groupClaims->resolve($idTokenClaims, $resourceOwner);

        if (null === $groups) {
            $this->logger->warning('OIDC admin group ignored: the id_token carries no groups claim and the userinfo endpoint is not HTTPS');

            return false;
        }

        return \in_array($this->adminGroup, $groups, true);
    }
}
